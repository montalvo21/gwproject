<?php
require_once __DIR__ . '/vendor/autoload.php';
/**
 * Plugin Name: Glasswing Voluntariado
 * Description: Plugin personalizado para gestión de voluntariado (Países, Proyectos, Emparejamientos).
 * Version: 1.0
 * Author: Carlos Montalvo
 */

if (!defined('ABSPATH')) exit;

// Activación del plugin
register_activation_hook(__FILE__, 'gw_manager_activate');
function gw_manager_activate() {
    // Aquí puedes crear tablas si deseas
}

// CPT Países
add_action('init', function () {
    register_post_type('pais', [
        'labels' => [
            'name' => 'Países',
            'singular_name' => 'País'
        ],
        'public' => true,
        'has_archive' => true,
        'menu_icon' => 'dashicons-admin-site',
        'supports' => ['title'],
        'show_in_menu' => true
    ]);
});

// CPT Proyectos
add_action('init', function () {
    register_post_type('proyecto', [
        'labels' => [
            'name' => 'Proyectos',
            'singular_name' => 'Proyecto'
        ],
        'public' => true,
        'has_archive' => true,
        'menu_icon' => 'dashicons-portfolio',
        'supports' => ['title', 'editor'],
        'show_in_menu' => true
    ]);
});

// Panel personalizado en el admin
add_action('admin_menu', function () {
    add_menu_page('Panel Glasswing', 'Panel Glasswing', 'manage_options', 'gw-panel', 'gw_render_panel', 'dashicons-groups');
    add_submenu_page('gw-panel', 'Emparejar', 'Emparejar', 'manage_options', 'gw-emparejar', 'gw_emparejar_page');
});

function gw_render_panel() {
    echo '<div class="wrap"><h1>Panel de Administración Glasswing</h1><p>Aquí irán los accesos a Emparejamientos y Gestión avanzada.</p></div>';
}


function gw_emparejar_page() {
    global $wpdb;
    $mensaje = '';

    // Eliminar emparejamiento
    if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
        $wpdb->delete($wpdb->prefix . 'gw_emparejamientos', ['id' => intval($_GET['delete'])]);
        $mensaje = 'Emparejamiento eliminado correctamente.';
    }

    // Guardar emparejamiento nuevo
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['gw_emparejar_nonce']) && wp_verify_nonce($_POST['gw_emparejar_nonce'], 'gw_emparejar')) {
        $user_id = intval($_POST['user_id']);
        $proyecto_id = intval($_POST['proyecto_id']);
        $tipo = sanitize_text_field($_POST['tipo']);

        $wpdb->insert($wpdb->prefix . 'gw_emparejamientos', [
            'user_id' => $user_id,
            'proyecto_id' => $proyecto_id,
            'tipo' => $tipo
        ]);

        $mensaje = '¡Emparejamiento guardado!';
    }

    $proyectos = get_posts(['post_type' => 'proyecto', 'numberposts' => -1]);
    $usuarios = get_users(['role__in' => ['coach', 'coordinador_pais']]);
    $emparejamientos = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}gw_emparejamientos ORDER BY fecha DESC");

    ?>
    <div class="wrap">
        <h1>Emparejar Usuarios con Proyectos</h1>
        <?php if ($mensaje) echo "<div class='notice notice-success'><p>$mensaje</p></div>"; ?>
        <form method="post">
            <?php wp_nonce_field('gw_emparejar', 'gw_emparejar_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="user_id">Usuario</label></th>
                    <td>
                        <select name="user_id" id="user_id">
                            <option value="">Seleccionar usuario</option>
                            <?php foreach ($usuarios as $user): ?>
                                <option value="<?= $user->ID ?>"><?= $user->display_name ?> (<?= implode(', ', $user->roles) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="proyecto_id">Proyecto</label></th>
                    <td>
                        <select name="proyecto_id" id="proyecto_id">
                            <option value="">Seleccionar proyecto</option>
                            <?php foreach ($proyectos as $p): ?>
                                <option value="<?= $p->ID ?>"><?= $p->post_title ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="tipo">Tipo</label></th>
                    <td>
                        <select name="tipo" id="tipo">
                            <option value="coach">Coach</option>
                            <option value="coordinador">Coordinador</option>
                        </select>
                    </td>
                </tr>
            </table>
            <p><input type="submit" class="button button-primary" value="Emparejar"></p>
        </form>

        <hr>
        <h2>Emparejamientos existentes</h2>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Usuario</th>
                    <th>Proyecto</th>
                    <th>Tipo</th>
                    <th>Fecha</th>
                    <th>Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($emparejamientos as $e): ?>
                    <tr>
                        <td><?= $e->id ?></td>
                        <td><?= get_userdata($e->user_id)->display_name ?></td>
                        <td><?= get_the_title($e->proyecto_id) ?></td>
                        <td><?= esc_html($e->tipo) ?></td>
                        <td><?= $e->fecha ?></td>
                        <td><a href="<?= admin_url('admin.php?page=gw-emparejar&delete=' . $e->id) ?>" class="button button-small">Eliminar</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}
// Metabox dinámico para Proyectos
add_action('add_meta_boxes', function () {
    add_meta_box(
        'gw_proyecto_meta',
        'Información adicional del Proyecto',
        'gw_proyecto_meta_callback',
        'proyecto',
        'side'
    );
});

function gw_proyecto_meta_callback($post) {
    $pais_seleccionado = get_post_meta($post->ID, '_gw_pais_relacionado', true);
    $coach_seleccionado = get_post_meta($post->ID, '_gw_coach_asignado', true);

    // Obtener todos los países (CPT pais)
    $paises = get_posts(['post_type' => 'pais', 'numberposts' => -1]);

    // Obtener todos los usuarios con rol 'coach'
    $coaches = get_users(['role' => 'coach']);

    echo '<label for="gw_pais_relacionado">País relacionado:</label>';
    echo '<select id="gw_pais_relacionado" name="gw_pais_relacionado" class="widefat">';
    echo '<option value="">Seleccionar país</option>';
    foreach ($paises as $pais) {
        $selected = ($pais_seleccionado == $pais->ID) ? 'selected' : '';
        echo "<option value='{$pais->ID}' {$selected}>{$pais->post_title}</option>";
    }
    echo '</select><br><br>';

    echo '<label for="gw_coach_asignado">Coach asignado:</label>';
    echo '<select id="gw_coach_asignado" name="gw_coach_asignado" class="widefat">';
    echo '<option value="">Seleccionar coach</option>';
    foreach ($coaches as $coach) {
        $selected = ($coach_seleccionado == $coach->ID) ? 'selected' : '';
        echo "<option value='{$coach->ID}' {$selected}>{$coach->display_name}</option>";
    }
    echo '</select>';
}

// Guardar metadatos de proyecto (IDs)
add_action('save_post', function ($post_id) {
    if (isset($_POST['gw_pais_relacionado'])) {
        update_post_meta($post_id, '_gw_pais_relacionado', intval($_POST['gw_pais_relacionado']));
    }
    if (isset($_POST['gw_coach_asignado'])) {
        update_post_meta($post_id, '_gw_coach_asignado', intval($_POST['gw_coach_asignado']));
    }
});

// Shortcode para formulario de inscripción a capacitaciones
add_shortcode('gw_academia_form', 'gw_academia_form_shortcode');

function gw_academia_form_shortcode() {
    if (!is_user_logged_in()) {
        return '<p>Por favor inicia sesión para ver tus capacitaciones.</p>';
    }

    $user = wp_get_current_user();
    $mensaje = '';

    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['gw_academia_nonce']) && wp_verify_nonce($_POST['gw_academia_nonce'], 'gw_academia')) {
        $capacitacion = sanitize_text_field($_POST['capacitacion']);
        $fecha = sanitize_text_field($_POST['fecha']);
        $hora = sanitize_text_field($_POST['hora']);

        update_user_meta($user->ID, 'gw_capacitacion', $capacitacion);
        update_user_meta($user->ID, 'gw_fecha', $fecha);
        update_user_meta($user->ID, 'gw_hora', $hora);

        // Crear evento de Google Calendar y obtener enlace de Google Meet
        require_once plugin_dir_path(__FILE__) . 'google-calendar.php';

        $summary = "Capacitación: $capacitacion";
        $description = "Sesión en vivo de capacitación Glasswing";
        $start = date('c', strtotime("$fecha $hora"));
        $end = date('c', strtotime("$fecha $hora +1 hour"));

        $meet_link = create_google_meet_event($summary, $description, $start, $end, $user->user_email);

        $mensaje = "Te has inscrito a <strong>$capacitacion</strong> el día <strong>$fecha</strong> a las <strong>$hora</strong>.";

        // Enviar correo de confirmación
        $to = $user->user_email;
        $subject = 'Confirmación de inscripción a capacitación';
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        $mensaje_email = "
            <h2>Hola {$user->display_name},</h2>
            <p>Has sido inscrito a la capacitación <strong>$capacitacion</strong>.</p>
            <p><strong>Fecha:</strong> $fecha<br>
            <strong>Hora:</strong> $hora</p>
            <p>Enlace de la sesión por Google Meet:<br>
            <a href=\"$meet_link\">$meet_link</a></p>
            <p>¡Gracias por participar!</p>
        ";

        wp_mail($to, $subject, $mensaje_email, $headers);
    }

    ob_start();
    ?>
    <div class="gw-academia-form">
        <h2>Selecciona tu capacitación</h2>
        <?php if ($mensaje): ?>
            <div class="notice notice-success"><p><?php echo $mensaje; ?></p></div>
        <?php endif; ?>
        <form method="post">
            <?php wp_nonce_field('gw_academia', 'gw_academia_nonce'); ?>

            <p>
                <label for="capacitacion">Capacitación:</label><br>
                <select name="capacitacion" id="capacitacion" required>
                    <option value="">Selecciona una</option>
                    <option value="Voluntariado Básico">Voluntariado Básico</option>
                    <option value="Educación en Comunidad">Educación en Comunidad</option>
                    <option value="Capacitación Avanzada">Capacitación Avanzada</option>
                </select>
            </p>
            <p>
                <label for="fecha">Fecha:</label><br>
                <input type="date" name="fecha" id="fecha" required>
            </p>
            <p>
                <label for="hora">Hora:</label><br>
                <input type="time" name="hora" id="hora" required>
            </p>

            <p><input type="submit" value="Confirmar inscripción" class="button button-primary"></p>
        </form>
    </div>
    <?php
    return ob_get_clean();
}
// Shortcode para mostrar capacitaciones inscritas
add_shortcode('gw_mis_capacitaciones', 'gw_mis_capacitaciones_shortcode');

function gw_mis_capacitaciones_shortcode() {
    if (!is_user_logged_in()) {
        return '<p>Por favor inicia sesión para ver tus capacitaciones.</p>';
    }

    $user = wp_get_current_user();
    $capacitacion = get_user_meta($user->ID, 'gw_capacitacion', true);
    $fecha = get_user_meta($user->ID, 'gw_fecha', true);
    $hora = get_user_meta($user->ID, 'gw_hora', true);

    if (!$capacitacion || !$fecha || !$hora) {
        return '<p>No tienes ninguna capacitación registrada.</p>';
    }

    $output = '<div class="gw-mis-capacitaciones">';
    $output .= '<h2>Mis Capacitaciones</h2>';
    $output .= "<p><strong>Capacitación:</strong> {$capacitacion}</p>";
    $output .= "<p><strong>Fecha:</strong> {$fecha}</p>";
    $output .= "<p><strong>Hora:</strong> {$hora}</p>";
    $output .= '</div>';

    return $output;
}
// Shortcode para página de inicio visual con login Nextend Social Login
add_shortcode('gw_login_home', 'gw_login_home_shortcode');
function gw_login_home_shortcode() {
    if (is_user_logged_in() && !(defined('REST_REQUEST') && REST_REQUEST) && !(defined('DOING_AJAX') && DOING_AJAX)) {
        $user = wp_get_current_user();
        // Redirección automática según rol
        if (in_array('administrator', $user->roles) || in_array('coach', $user->roles) || in_array('coordinador_pais', $user->roles)) {
            wp_redirect(site_url('/panel-administrativo')); exit;
        } else {
            wp_redirect(site_url('/academia')); exit;
        }
    }

    ob_start();
    ?>
    <style>
        .gw-login-container {max-width: 420px; margin: 40px auto; padding: 40px; background: #fff; border-radius: 12px; box-shadow: 0 0 20px rgba(0,0,0,0.1); font-family: sans-serif; text-align: center;}
        .gw-login-google {margin: 18px 0;}
        .gw-login-or {margin: 22px 0 14px; color: #aaa;}
        .gw-voluntario-registro {margin-top: 28px; text-align:left;}
        .gw-voluntario-registro input {width:100%;margin-bottom:10px;padding:8px;}
        .gw-voluntario-registro button {width:100%;padding:10px;border:none;border-radius:6px;background:#39a746;color:#fff;font-weight:bold;}
    </style>
    <div style="position:relative;">
        <div style="position:absolute;top:18px;right:32px;z-index:2;">
            <a href="<?php echo site_url('/academia'); ?>" class="button" style="margin-right:10px;background:#2962ff;color:#fff;border:none;padding:8px 20px;border-radius:6px;text-decoration:none;">Ir a Academia</a>
            <a href="<?php echo site_url('/panel-administrativo'); ?>" class="button" style="background:#00c853;color:#fff;border:none;padding:8px 20px;border-radius:6px;text-decoration:none;">Ir al Panel</a>
        </div>
    </div>
    <div class="gw-login-container">
        <h2>Iniciar sesión</h2>
        <div class="gw-login-google">
            <?php echo do_shortcode('[nextend_social_login provider="google" style="icon"]'); ?>
        </div>
        <div class="gw-login-or">— o ingresa con tu correo —</div>
        <?php
        wp_login_form([
            'echo' => true,
            'redirect' => '', // la redirección la manejamos arriba
            'form_id' => 'gw_loginform',
            'label_username' => 'Correo electrónico',
            'label_password' => 'Contraseña',
            'label_remember' => 'Recordarme',
            'label_log_in' => 'Entrar',
            'remember' => true,
        ]);
        ?>
        <div style="margin-top:18px;">
            <a href="<?php echo wp_lostpassword_url(); ?>">¿Olvidaste tu contraseña?</a>
        </div>
        <hr>
        <div class="gw-voluntario-registro">
            <h4>¿Eres voluntario nuevo?</h4>
            <form method="post">
                <input type="text" name="gw_reg_nombre" placeholder="Nombre completo" required>
                <input type="email" name="gw_reg_email" placeholder="Correo electrónico" required>
                <input type="password" name="gw_reg_pass" placeholder="Contraseña" required>
                <button type="submit" name="gw_reg_submit">Registrarme como voluntario</button>
            </form>
            <?php
            if (isset($_POST['gw_reg_submit'])) {
                $nombre = sanitize_text_field($_POST['gw_reg_nombre']);
                $correo = sanitize_email($_POST['gw_reg_email']);
                $pass = $_POST['gw_reg_pass'];
                if (username_exists($correo) || email_exists($correo)) {
                    echo '<div style="color:#b00; margin:10px 0;">Este correo ya está registrado.</div>';
                } else {
                    $uid = wp_create_user($correo, $pass, $correo);
                    wp_update_user(['ID'=>$uid, 'display_name'=>$nombre]);
                    $user = get_user_by('id', $uid);
                    $user->set_role('voluntario');
                    echo '<div style="color:#008800;margin:10px 0;">¡Registro exitoso! Ahora puedes iniciar sesión.</div>';
                }
            }
            ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
// Redirección automática después de login, según el rol del usuario
add_filter('login_redirect', 'gw_redireccionar_por_rol', 10, 3);
function gw_redireccionar_por_rol($redirect_to, $request, $user) {
    if (is_wp_error($user)) {
        return $redirect_to;
    }
    if (in_array('administrator', $user->roles) || in_array('coach', $user->roles) || in_array('coordinador_pais', $user->roles)) {
        return site_url('/panel-administrativo');
    }
    if (in_array('voluntario', $user->roles)) {
        return site_url('/academia');
    }
    // Por defecto
    return site_url('/');
}
// Redirección automática para Nextend Social Login (Google)
add_filter('nsl_login_redirect_url', function($url, $provider, $user) {
    if ($user && is_a($user, 'WP_User')) {
        if (in_array('administrator', $user->roles) || in_array('coach', $user->roles) || in_array('coordinador_pais', $user->roles)) {
            return site_url('/panel-administrativo');
        }
        if (in_array('voluntario', $user->roles)) {
            return site_url('/academia');
        }
    }
    return $url;
}, 10, 3);