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
        'public' => false,
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
        'public' => true, // Cambiado a true para que aparezca en el menú lateral de WordPress
        'has_archive' => true,
        'menu_icon' => 'dashicons-portfolio',
        'supports' => ['title'],
        'show_in_menu' => true
    ]);
});

// CPT Capacitaciones
add_action('init', function () {
    register_post_type('capacitacion', [
        'labels' => [
            'name' => 'Capacitaciones',
            'singular_name' => 'Capacitación'
        ],
        'public' => true,
        'has_archive' => true,
        'menu_icon' => 'dashicons-welcome-learn-more',
        'supports' => ['title', 'editor'],
        'show_in_menu' => true
    ]);
});


// Shortcode para mostrar capacitaciones inscritas
add_shortcode('gw_mis_capacitaciones', 'gw_mis_capacitaciones_shortcode');

function gw_mis_capacitaciones_shortcode() {
    if (!is_user_logged_in()) {
        return '<p>Por favor inicia sesión para ver tus capacitaciones.</p>';
    }

    $user = wp_get_current_user();
    $capacitacion_id = get_user_meta($user->ID, 'gw_capacitacion_id', true);
    $fecha = get_user_meta($user->ID, 'gw_fecha', true);
    $hora = get_user_meta($user->ID, 'gw_hora', true);

    if (!$capacitacion_id || !$fecha || !$hora) {
        return '<p>No tienes ninguna capacitación registrada.</p>';
    }

    $capacitacion_title = get_the_title($capacitacion_id);
    $capacitacion_url = get_permalink($capacitacion_id);

    $output = '<div class="gw-mis-capacitaciones">';
    $output .= '<h2>Mis Capacitaciones</h2>';
    $output .= "<p><strong>Capacitación:</strong> <a href=\"{$capacitacion_url}\">{$capacitacion_title}</a></p>";
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
            wp_redirect(site_url('/index.php/portal-voluntario')); exit;
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
            <a href="<?php echo site_url('/index.php/portal-voluntario'); ?>" class="button" style="margin-right:10px;background:#2962ff;color:#fff;border:none;padding:8px 20px;border-radius:6px;text-decoration:none;">Ir a Academia</a>
            <a href="<?php echo site_url('/panel-administrativo'); ?>" class="button" style="background:#00c853;color:#fff;border:none;padding:8px 20px;border-radius:6px;text-decoration:none;">Ir al Panel</a>
        </div>
    </div>
    <div class="gw-login-container">
        <h2>Iniciar sesión</h2>
        <?php
        // --- Mensaje visual de país detectado por QR/link (LOGIN) ---
        if (isset($_GET['gw_pais'])) {
            $pais_id = intval($_GET['gw_pais']);
            $pais_post = get_post($pais_id);
            if ($pais_post && $pais_post->post_type === 'pais') {
                echo '<div style="background:#e3f6ff;color:#125ea6;font-weight:bold;padding:12px 22px;margin-bottom:20px;border-radius:9px;border-left:7px solid #199cff;">
                    Bienvenido/a, estás registrándote para el país: <span style="color:#196ed6;font-weight:bold;">'.esc_html($pais_post->post_title).'</span>
                </div>';
            }
        }
        ?>
        <div class="gw-login-google">
            <?php echo do_shortcode('[nextend_social_login provider="google" style="icon"]'); ?>
        </div>
        <div class="gw-login-or">— o ingresa con tu correo —</div>
        <?php
        // --- Mensaje visual de país detectado por QR/link (LOGIN) ---
        if (isset($_GET['gw_pais'])) {
            $pais_id = intval($_GET['gw_pais']);
            $pais_post = get_post($pais_id);
            if ($pais_post && $pais_post->post_type === 'pais') {
                echo '<div style="background:#e3f6ff;color:#125ea6;font-weight:bold;padding:12px 22px;margin-bottom:20px;border-radius:9px;border-left:7px solid #199cff;">
                    Bienvenido/a, estás iniciando sesión para el país: <span style="color:#196ed6;font-weight:bold;">'.esc_html($pais_post->post_title).'</span>
                </div>';
            }
        }
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
    <?php
    // --- Mensaje visual de país detectado por QR/link (REGISTRO) ---
    if (isset($_GET['gw_pais'])) {
        $pais_id = intval($_GET['gw_pais']);
        $pais_post = get_post($pais_id);
        if ($pais_post && $pais_post->post_type === 'pais') {
            echo '<div style="background:#e3f6ff;color:#125ea6;font-weight:bold;padding:12px 22px;margin-bottom:20px;border-radius:9px;border-left:7px solid #199cff;">
                Bienvenido/a, estás registrándote para el país: <span style="color:#196ed6;font-weight:bold;">'.esc_html($pais_post->post_title).'</span>
            </div>';
        }
    }
    ?>
    <form method="post">
        <input type="text" name="gw_reg_nombre" placeholder="Nombre completo" required>
        <input type="email" name="gw_reg_email" placeholder="Correo electrónico" required>
        <input type="password" name="gw_reg_pass" placeholder="Contraseña" required>
        <?php
        // Obtener países desde el CPT 'pais'
        $paises = get_posts(['post_type' => 'pais', 'numberposts' => -1, 'orderby'=>'title','order'=>'ASC']);

        // BLOQUE: detectar parámetro país y si usuario ya tiene país
        $pais_id_preasignado = isset($_GET['gw_pais']) ? intval($_GET['gw_pais']) : '';
        $asignar_automaticamente = false;
        $current_user = null;
        if (is_user_logged_in()) {
            $current_user = wp_get_current_user();
            $tiene_pais = get_user_meta($current_user->ID, 'gw_pais_id', true);
            // Si el usuario ingresa con el QR/link y tiene país diferente, actualizarlo automáticamente
            if ($pais_id_preasignado && (!$tiene_pais || $tiene_pais != $pais_id_preasignado)) {
                update_user_meta($current_user->ID, 'gw_pais_id', $pais_id_preasignado);
                $asignar_automaticamente = true;
            }
        } else if ($pais_id_preasignado) {
            $asignar_automaticamente = true;
        }
        ?>
        <select name="gw_reg_pais" required>
            <option value="">Selecciona tu país</option>
            <?php
            // Renderizado del selector de país según si hay preasignación
            if ($asignar_automaticamente && $pais_id_preasignado) {
                foreach ($paises as $pais) {
                    if ($pais->ID == $pais_id_preasignado) {
                        echo '<option value="'.$pais->ID.'" selected>'.esc_html($pais->post_title).'</option>';
                    }
                }
                echo "<script>
                document.addEventListener('DOMContentLoaded',function(){
                    var sel = document.querySelector('select[name=\"gw_reg_pais\"]');
                    if(sel) { sel.setAttribute('readonly','readonly'); sel.setAttribute('disabled','disabled'); }
                });
                </script>";
                // Campo oculto para que llegue en el POST aunque esté deshabilitado
                echo '<input type="hidden" name="gw_reg_pais" value="'.$pais_id_preasignado.'" />';
            } else {
                foreach ($paises as $pais) {
                    echo '<option value="'.$pais->ID.'">'.esc_html($pais->post_title).'</option>';
                }
            }
            ?>
        </select>
        <button type="submit" name="gw_reg_submit">Registrarme como voluntario</button>
    </form>
    <?php
    if (isset($_POST['gw_reg_submit'])) {
        $nombre = sanitize_text_field($_POST['gw_reg_nombre']);
        $correo = sanitize_email($_POST['gw_reg_email']);
        $pass = $_POST['gw_reg_pass'];
        // Usar país del QR/link si viene, sino lo que seleccionó
        $pais_id = $pais_id_preasignado ? $pais_id_preasignado : intval($_POST['gw_reg_pais']);
        if (username_exists($correo) || email_exists($correo)) {
            echo '<div style="color:#b00; margin:10px 0;">Este correo ya está registrado.</div>';
        } else {
            $uid = wp_create_user($correo, $pass, $correo);
            wp_update_user(['ID'=>$uid, 'display_name'=>$nombre]);
            $user = get_user_by('id', $uid);
            $user->set_role('voluntario');
            // Guardar país como user_meta
            update_user_meta($uid, 'gw_pais_id', $pais_id);
            // Asignar automáticamente el flujo de charlas según país
            $charlas_flujo = get_post_meta($pais_id, '_gw_charlas', true);
            if (!is_array($charlas_flujo)) $charlas_flujo = [];
            update_user_meta($uid, 'gw_charlas_asignadas', $charlas_flujo);
            // Iniciar sesión automáticamente
            wp_set_auth_cookie($uid, true);
            // Redirigir al portal del país (ajusta la URL al slug correcto)
            $pais_url = get_permalink($pais_id);
            echo '<script>window.location.href="'.esc_url($pais_url).'";</script>';
            echo '<div style="color:#008800;margin:10px 0;">¡Registro exitoso! Redirigiendo...</div>';
            exit;
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
        return site_url('/index.php/portal-voluntario');
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
            return site_url('/index.php/portal-voluntario');
        }
    }
    return $url;
}, 10, 3);
// Mostrar botón "Mi progreso" en la página de detalles de capacitación para voluntarios
add_filter('the_content', function($content) {
    if (!is_singular('capacitacion')) return $content;
    if (!is_user_logged_in()) return $content;

    $user = wp_get_current_user();
    if (!in_array('voluntario', $user->roles)) return $content;

    // URL de tu página de progreso (ajusta el slug si es diferente)
    $progreso_url = site_url('/mi-progreso/');

    // Botón visual
    $boton = '<div style="margin:30px 0;text-align:center;">
        <a href="'.$progreso_url.'" style="background:#2186eb;color:#fff;padding:13px 38px;font-size:1.13rem;border-radius:8px;text-decoration:none;font-weight:600;box-shadow:0 1px 8px #c8cfe7;">Mi progreso</a>
    </div>';

    // Puedes colocarlo al inicio, al final o ambos
    return $boton . $content;
});

// ========== BOTONES ADMIN/TESTING PARA PASOS 5 Y 6 ==========
add_action('wp_footer', function() {
    if (!is_user_logged_in()) return;
    $user = wp_get_current_user();
    $is_admin = (in_array('administrator', $user->roles) || isset($_GET['testing']) || current_user_can('manage_options'));
    if (!$is_admin) return;

    // Determinar paso actual por URL
    $url = $_SERVER['REQUEST_URI'];
    $show_step5 = false;
    $show_step6 = false;
    // Detectar si estamos en paso 5 (charlas) o paso 6 (capacitaciones)
    if (preg_match('#/charlas#', $url) || preg_match('#/index.php/portal-voluntario#', $url) || preg_match('#/paso-5#', $url)) {
        $show_step5 = true;
    }
    if (preg_match('#/capacitacion#', $url) || preg_match('#/paso-6#', $url)) {
        $show_step6 = true;
    }
    // Si se usa algún identificador de pantalla de charlas/capacitaciones personalizado, agregar aquí.
    // Mostrar siempre ambos si en modo testing (por seguridad)
    if (isset($_GET['testing'])) { $show_step5 = true; $show_step6 = true; }

    // Botones para paso 6 (Capacitaciones)
    if ($show_step6) {
        ?>
        <div id="gw-admin-testing-controls-step6" style="position:fixed;bottom:24px;right:24px;z-index:9999;background:rgba(255,255,255,0.97);border:2px solid #00695c;padding:18px 25px;border-radius:12px;box-shadow:0 2px 16px #b4c7e7;">
            <div style="font-weight:bold;margin-bottom:8px;color:#00695c;">[Capacitaciones] Modo Admin/Testing</div>
            <button onclick="gwStep6AdminBackToStep5()" class="button button-secondary" style="margin-right:8px;">Regresar a Charlas (Paso 5)</button>
            <button onclick="gwStep6AdminBackToMenu()" class="button button-secondary" style="margin-right:8px;">Regresar al menú de Capacitaciones</button>
            <button onclick="gwStep6TestingContinue()" class="button button-primary">Continuar Test</button>
        </div>
        <script>
        function gwStep6AdminBackToStep5() {
            // Borra metas de step6 y también de charla actual, luego recarga al menú principal de charlas/paso 5
            var ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
            var data = new FormData();
            data.append('action', 'gw_admin_reset_step6_and_charlas');
            fetch(ajaxurl, {method:'POST', credentials:'same-origin', body:data})
            .then(function(){ window.location.href = '<?php echo site_url('/index.php/portal-voluntario'); ?>'; });
        }
        function gwStep6AdminBackToMenu() {
            // Borra solo meta de step6 y recarga en menú de capacitaciones
            var ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
            var data = new FormData();
            data.append('action', 'gw_admin_reset_step6');
            fetch(ajaxurl, {method:'POST', credentials:'same-origin', body:data})
            .then(function(){ window.location.href = '<?php echo site_url('/capacitacion'); ?>'; });
        }
        function gwStep6TestingContinue() {
            window.location.reload();
        }
        </script>
        <?php
    }
});

// AJAX para borrar metas de paso 5 (charlas)
add_action('wp_ajax_gw_admin_reset_charlas', function() {
    $user = wp_get_current_user();
    // Borrar meta de paso actual y charla actual (ajustar las keys según tu implementación)
    delete_user_meta($user->ID, 'gw_step5');
    delete_user_meta($user->ID, 'gw_charla_actual');
    // También puedes borrar otras metas relacionadas si aplica
    wp_die();
});
// AJAX para borrar metas de step6 (capacitaciones)
add_action('wp_ajax_gw_admin_reset_step6', function() {
    $user = wp_get_current_user();
    delete_user_meta($user->ID, 'gw_step6');
    wp_die();
});
// AJAX para borrar metas de step6 y charlas (volver a paso 5)
add_action('wp_ajax_gw_admin_reset_step6_and_charlas', function() {
    $user = wp_get_current_user();
    delete_user_meta($user->ID, 'gw_step6');
    delete_user_meta($user->ID, 'gw_step5');
    delete_user_meta($user->ID, 'gw_charla_actual');
    wp_die();
});

// Shortcode: Progreso del voluntario
add_shortcode('gw_progreso_voluntario', function() {
    if (!is_user_logged_in()) {
        return '<p>Debes iniciar sesión para ver tu progreso.</p>';
    }

    $user = wp_get_current_user();
    // Si es voluntario, solo mostrar su progreso
    if (in_array('voluntario', $user->roles)) {
        return mostrar_progreso_voluntario($user);
    }

    // Para administradores, coaches o coordinadores: mostrar tabla resumen o detalle
    $selected_user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : null;
    if ($selected_user_id) {
        // Mostrar formulario individual para el voluntario seleccionado
        return mostrar_panel_admin_progreso($selected_user_id);
    } else {
        // Mostrar listado de todos los voluntarios y su progreso
        return mostrar_tabla_progreso_admin();
    }
});

// Muestra una tabla con el progreso de todos los voluntarios
function mostrar_tabla_progreso_admin() {
    $voluntarios = get_users(['role' => 'voluntario']);
    // Agregar al administrador actual para pruebas de progreso
    if (current_user_can('manage_options')) {
        $current = wp_get_current_user();
        $exists = false;
        foreach ($voluntarios as $u) {
            if ($u->ID === $current->ID) { $exists = true; break; }
        }
        if (!$exists) {
            array_unshift($voluntarios, $current);
        }
    }
    ob_start();
    echo '<div><h2>Resumen de Progreso de Voluntarios</h2>';
    echo '<table class="widefat striped"><thead><tr>
            <th>Nombre</th><th>Correo</th>
            <th>Charlas</th>
            <th>Capacitación</th><th>Fecha</th><th>Hora</th><th>Acción</th>
          </tr></thead><tbody>';
    foreach ($voluntarios as $v) {
        $cap_id = get_user_meta($v->ID, 'gw_capacitacion_id', true);
        $cap_title = $cap_id ? get_the_title($cap_id) : '-';
        $fecha = get_user_meta($v->ID, 'gw_fecha', true) ?: '-';
        $hora = get_user_meta($v->ID, 'gw_hora', true) ?: '-';
        $link = add_query_arg('user_id', $v->ID, get_permalink()); // ensure correct URL if used in front-end
        echo '<tr>';
        echo '<td>' . esc_html($v->display_name) . '</td>';
        echo '<td>' . esc_html($v->user_email) . '</td>';
        // Obtener charlas asignadas
        $charlas_asignadas = get_user_meta($v->ID, 'gw_charlas_asignadas', true);
        if (!is_array($charlas_asignadas)) $charlas_asignadas = [];
        $lista_charlas = [];
        foreach ($charlas_asignadas as $charla_key) {
            $estado = get_user_meta($v->ID, 'gw_' . $charla_key, true) ? '✅' : '❌';
            $lista_charlas[] = esc_html($charla_key) . ' ' . $estado;
        }
        echo '<td>' . implode('<br>', $lista_charlas) . '</td>';
        echo '<td>' . esc_html($cap_title) . '</td>';
        echo '<td>' . esc_html($fecha) . '</td>';
        echo '<td>' . esc_html($hora) . '</td>';
        // Botón Revisar documentos
        echo '<td><button type="button" class="button button-small gw-revisar-docs" data-user-id="' . $v->ID . '">Revisar documentos</button></td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    return ob_get_clean();
}

// Mostrar solo el progreso (para voluntario)
function mostrar_progreso_voluntario($user) {
    $total_charlas = 4;
    $completadas = 0;
    $charlas = [
        'charla1' => get_user_meta($user->ID, 'gw_charla1', true),
        'charla2' => get_user_meta($user->ID, 'gw_charla2', true),
        'charla3' => get_user_meta($user->ID, 'gw_charla3', true),
        'charla4' => get_user_meta($user->ID, 'gw_charla4', true),
        'charla5' => get_user_meta($user->ID, 'gw_charla5', true),
    ];
    foreach($charlas as $done) { if ($done) $completadas++; }
    $porcentaje = intval(($completadas / $total_charlas) * 100);
    ob_start();
    ?>
    <div id="progreso">
    <script>
      document.addEventListener('DOMContentLoaded', function() {
        window.location.hash = 'progreso';
      });
    </script>
    <div style="max-width:500px;margin:40px auto;">
        <h2>Progreso de tus charlas/capacitaciones</h2>
        <div style="background:#eee;border-radius:18px;overflow:hidden;margin:24px 0;">
            <div style="background:#3a7bd5;width:<?php echo $porcentaje; ?>%;height:32px;transition:.5s;border-radius:18px 0 0 18px;text-align:right;color:#fff;line-height:32px;font-weight:bold;padding-right:18px;">
                <?php echo $porcentaje; ?>%
            </div>
        </div>
        <ul style="list-style:none;padding-left:0;">
            <?php foreach($charlas as $nombre=>$done): ?>
                <li style="margin:8px 0;"><?php echo ucfirst($nombre); ?> <?php echo $done ? "✅" : "❌"; ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    </div>
    <?php
    return ob_get_clean();
}

// Handler para guardar charlas asociadas al país y sincronizar a voluntarios
// Elimina cualquier duplicado anterior de este handler.
if (has_action('wp_ajax_gw_guardar_charlas_pais')) {
    // Si ya hay un handler registrado, lo eliminamos primero para evitar duplicados
    remove_all_actions('wp_ajax_gw_guardar_charlas_pais');
}
add_action('wp_ajax_gw_guardar_charlas_pais', function() {
    // Solo permitir a administradores o coordinadores de país
    if (!current_user_can('manage_options') && !current_user_can('coordinador_pais')) {
        wp_send_json_error(['msg' => 'No autorizado']);
    }
    $pais_id = intval($_POST['pais_id']);
    $charlas = isset($_POST['charlas']) ? (array)$_POST['charlas'] : [];
    // Si los checkboxes llegan como 'charlas[]', también puede venir como $_POST['charlas']
    // Si llegan como 'gw_charlas[]', usamos ese nombre
    if (empty($charlas) && isset($_POST['gw_charlas'])) {
        $charlas = (array)$_POST['gw_charlas'];
    }
    update_post_meta($pais_id, '_gw_charlas', $charlas);

    // Sincronizar charlas en voluntarios de este país
    $users = get_users([
        'role' => 'voluntario',
        'meta_key' => 'gw_pais_id',
        'meta_value' => $pais_id
    ]);
    foreach ($users as $user) {
        update_user_meta($user->ID, 'gw_charlas_asignadas', $charlas);
    }

    wp_send_json_success();
});


// Panel extendido para coordinador/coach: ver y modificar progreso
function mostrar_panel_admin_progreso($user_id = null) {
    if (!$user_id && current_user_can('manage_options')) {
        echo '<p>Debes seleccionar un voluntario para ver y modificar su progreso.</p>';
        return;
    }
    if (!$user_id) {
        $user = wp_get_current_user();
        $user_id = $user->ID;
    }
    $charlas = get_user_meta($user_id, 'gw_charlas_asignadas', true);
    if (!is_array($charlas) || empty($charlas)) {
        // Fallback si no hay asignaciones
        $charlas = ['charla1', 'charla2', 'charla3', 'charla4'];
    }
    // Calcular progreso
    $completadas = 0;
    foreach ($charlas as $charla) {
        $val = get_user_meta($user_id, 'gw_'.$charla, true);
        if ($val) $completadas++;
    }
    $porcentaje = intval(($completadas / count($charlas)) * 100);

    // Mostrar aviso si completó todas las charlas
    $puede_seleccionar_capacitacion = ($completadas === count($charlas));
    if ($puede_seleccionar_capacitacion) {
        echo '<div style="margin:24px 0;padding:12px;background:#e3fce3;border-left:5px solid #4caf50;">
              <strong>Este voluntario ya completó todas las charlas generales.</strong><br>
              Ahora puedes asignarle capacitaciones especializadas desde el panel correspondiente.
            </div>';
    }

    echo '<h2>Administrar progreso del voluntario</h2>';
    echo '<form method="post">';
    foreach ($charlas as $charla) {
        $val = get_user_meta($user_id, 'gw_'.$charla, true);
        $checked = $val ? 'checked' : '';
        echo '<p><label><input type="checkbox" name="gw_'.$charla.'" '.$checked.'> '.ucfirst($charla).'</label></p>';
    }
    echo '<p><button type="submit" name="gw_guardar_progreso" class="button button-primary">Guardar progreso</button></p>';
    echo '<p><button type="submit" name="gw_reset_progreso" class="button button-secondary" style="color:red;">Reiniciar progreso</button></p>';
    echo '</form>';

    if (isset($_POST['gw_guardar_progreso'])) {
        foreach ($charlas as $charla) {
            update_user_meta($user_id, 'gw_'.$charla, isset($_POST['gw_'.$charla]) ? 1 : '');
        }
        echo '<div class="updated"><p>Progreso actualizado.</p></div>';
    }

    if (isset($_POST['gw_reset_progreso'])) {
        foreach ($charlas as $charla) {
            delete_user_meta($user_id, 'gw_'.$charla);
        }
        echo '<div class="updated"><p>Progreso reiniciado.</p></div>';
    }
}
// Forzar el CPT 'pais' a no ser público, solo gestionable por admin.
add_action('init', function() {
    global $wp_post_types;
    if (isset($wp_post_types['pais'])) {
        $wp_post_types['pais']->public = false;
        $wp_post_types['pais']->show_ui = true;
        $wp_post_types['pais']->show_in_menu = true;
        $wp_post_types['pais']->has_archive = false;
        $wp_post_types['pais']->rewrite = false;
    }
}, 100);

// --- INICIO BLOQUE METABOX CAPACITACION ---
add_action('add_meta_boxes', function() {
    add_meta_box(
        'gw_capacitacion_detalles',
        'Detalles de Capacitación',
        'gw_capacitacion_detalles_metabox_callback',
        'capacitacion',
        'normal',
        'default'
    );
});

function gw_capacitacion_detalles_metabox_callback($post) {
    // Obtener proyectos disponibles
    $proyectos = get_posts([
        'post_type' => 'proyecto',
        'numberposts' => -1,
        'orderby' => 'title',
        'order' => 'ASC'
    ]);
    $proyecto_actual = get_post_meta($post->ID, '_gw_proyecto_relacionado', true);
    // Obtener países disponibles
    $paises = get_posts([
        'post_type' => 'pais',
        'numberposts' => -1,
        'orderby' => 'title',
        'order' => 'ASC'
    ]);
    $pais_actual = get_post_meta($post->ID, '_gw_pais_relacionado', true);
    // Obtener coaches disponibles
    $coaches = get_users(['role' => 'coach']);
    $coach_actual = get_post_meta($post->ID, '_gw_coach_asignado', true);
    // Obtener sesiones
    $sesiones = get_post_meta($post->ID, '_gw_sesiones', true);
    if (!is_array($sesiones)) $sesiones = [];
    // Para mostrar al menos un bloque vacío si no hay sesiones
    if (empty($sesiones)) $sesiones = [[]];
    wp_nonce_field('gw_capacitacion_detalles_metabox', 'gw_capacitacion_detalles_metabox_nonce');
    ?>
    <p>
        <label for="gw_proyecto_relacionado"><strong>Proyecto relacionado:</strong></label><br>
        <select name="gw_proyecto_relacionado" id="gw_proyecto_relacionado" style="width: 100%; max-width: 400px;">
            <option value="">Selecciona proyecto</option>
            <?php foreach($proyectos as $proy): ?>
                <option value="<?php echo $proy->ID; ?>" <?php selected($proyecto_actual, $proy->ID); ?>>
                    <?php echo esc_html($proy->post_title); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </p>
    <p>
        <label for="gw_coach_asignado"><strong>Coach responsable:</strong></label><br>
        <select name="gw_coach_asignado" id="gw_coach_asignado" style="width: 100%; max-width: 400px;">
            <option value="">Selecciona coach</option>
            <?php foreach($coaches as $coach): ?>
                <option value="<?php echo $coach->ID; ?>" <?php selected($coach_actual, $coach->ID); ?>>
                    <?php echo esc_html($coach->display_name); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </p>
    <p>
        <label for="gw_pais_relacionado"><strong>País relacionado:</strong></label><br>
        <select name="gw_pais_relacionado" id="gw_pais_relacionado" style="width: 100%; max-width: 400px;">
            <option value="">Selecciona país</option>
            <?php foreach($paises as $pais): ?>
                <option value="<?php echo $pais->ID; ?>" <?php selected($pais_actual, $pais->ID); ?>>
                    <?php echo esc_html($pais->post_title); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </p>
    <hr>
    <h4 style="margin-bottom:10px;">Sesiones</h4>
    <div id="gw-sesiones-list-metabox">
        <?php foreach ($sesiones as $idx => $sesion): ?>
            <div class="gw-sesion-block-metabox" style="border:1px solid #ccc;padding:12px;margin-bottom:12px;border-radius:8px;">
                <label>Modalidad:
                    <select name="sesion_modalidad[]" class="gw-sesion-modalidad-metabox" required>
                        <option value="Presencial" <?php selected((isset($sesion['modalidad'])?$sesion['modalidad']:''),'Presencial'); ?>>Presencial</option>
                        <option value="Virtual" <?php selected((isset($sesion['modalidad'])?$sesion['modalidad']:''),'Virtual'); ?>>Virtual</option>
                    </select>
                </label>
                <label style="margin-left:18px;">Fecha:
                    <input type="date" name="sesion_fecha[]" value="<?php echo isset($sesion['fecha']) ? esc_attr($sesion['fecha']) : ''; ?>" required>
                </label>
                <label style="margin-left:18px;">Hora:
                    <input type="time" name="sesion_hora[]" value="<?php echo isset($sesion['hora']) ? esc_attr($sesion['hora']) : ''; ?>" required>
                </label>
                <label class="gw-lugar-label-metabox" style="margin-left:18px;<?php if(isset($sesion['modalidad']) && strtolower($sesion['modalidad'])=='virtual') echo 'display:none;'; ?>">
                    Lugar físico:
                    <input type="text" name="sesion_lugar[]" value="<?php echo isset($sesion['lugar']) ? esc_attr($sesion['lugar']) : ''; ?>" <?php if(isset($sesion['modalidad']) && strtolower($sesion['modalidad'])=='virtual') echo 'disabled'; ?> >
                </label>
                <label class="gw-link-label-metabox" style="margin-left:18px;<?php if(!isset($sesion['modalidad']) || strtolower($sesion['modalidad'])!='virtual') echo 'display:none;'; ?>">
                    Link:
                    <input type="url" name="sesion_link[]" value="<?php echo isset($sesion['link']) ? esc_attr($sesion['link']) : ''; ?>" <?php if(!isset($sesion['modalidad']) || strtolower($sesion['modalidad'])!='virtual') echo 'disabled'; ?>>
                </label>
                <button type="button" class="gw-remove-sesion-metabox button button-small" style="margin-left:18px;">Eliminar</button>
            </div>
        <?php endforeach; ?>
    </div>
    <button type="button" id="gw-add-sesion-metabox" class="button button-secondary">Agregar sesión</button>
    <script>
    (function(){
        function updateLabelsMetabox(block) {
            var modalidad = block.querySelector('.gw-sesion-modalidad-metabox').value;
            var lugarLabel = block.querySelector('.gw-lugar-label-metabox');
            var lugarInput = lugarLabel.querySelector('input');
            var linkLabel = block.querySelector('.gw-link-label-metabox');
            var linkInput = linkLabel.querySelector('input');
            if (modalidad.toLowerCase() === 'virtual') {
                lugarLabel.style.display = 'none';
                lugarInput.disabled = true;
                linkLabel.style.display = '';
                linkInput.disabled = false;
            } else {
                lugarLabel.style.display = '';
                lugarInput.disabled = false;
                linkLabel.style.display = 'none';
                linkInput.disabled = true;
            }
        }
        document.querySelectorAll('.gw-sesion-block-metabox').forEach(function(block){
            block.querySelector('.gw-sesion-modalidad-metabox').addEventListener('change', function(){
                updateLabelsMetabox(block);
            });
            // Eliminar sesión
            block.querySelector('.gw-remove-sesion-metabox').addEventListener('click', function(){
                if(document.querySelectorAll('.gw-sesion-block-metabox').length > 1){
                    block.parentNode.removeChild(block);
                }
            });
            updateLabelsMetabox(block);
        });
        document.getElementById('gw-add-sesion-metabox').addEventListener('click', function(){
            var container = document.getElementById('gw-sesiones-list-metabox');
            var html = `
            <div class="gw-sesion-block-metabox" style="border:1px solid #ccc;padding:12px;margin-bottom:12px;border-radius:8px;">
                <label>Modalidad:
                    <select name="sesion_modalidad[]" class="gw-sesion-modalidad-metabox" required>
                        <option value="Presencial">Presencial</option>
                        <option value="Virtual">Virtual</option>
                    </select>
                </label>
                <label style="margin-left:18px;">Fecha:
                    <input type="date" name="sesion_fecha[]" required>
                </label>
                <label style="margin-left:18px;">Hora:
                    <input type="time" name="sesion_hora[]" required>
                </label>
                <label class="gw-lugar-label-metabox" style="margin-left:18px;">
                    Lugar físico:
                    <input type="text" name="sesion_lugar[]">
                </label>
                <label class="gw-link-label-metabox" style="margin-left:18px;display:none;">
                    Link:
                    <input type="url" name="sesion_link[]" disabled>
                </label>
                <button type="button" class="gw-remove-sesion-metabox button button-small" style="margin-left:18px;">Eliminar</button>
            </div>
            `;
            var temp = document.createElement('div');
            temp.innerHTML = html;
            var block = temp.firstElementChild;
            block.querySelector('.gw-sesion-modalidad-metabox').addEventListener('change', function(){
                updateLabelsMetabox(block);
            });
            block.querySelector('.gw-remove-sesion-metabox').addEventListener('click', function(){
                if(document.querySelectorAll('.gw-sesion-block-metabox').length > 1){
                    block.parentNode.removeChild(block);
                }
            });
            updateLabelsMetabox(block);
            container.appendChild(block);
        });
    })();
    </script>
    <style>
        .gw-sesion-block-metabox label {font-weight:normal;}
    </style>
<?php
}

add_action('save_post_capacitacion', function($post_id) {
    // Guardar los campos del metabox
    if (!isset($_POST['gw_capacitacion_detalles_metabox_nonce']) || !wp_verify_nonce($_POST['gw_capacitacion_detalles_metabox_nonce'], 'gw_capacitacion_detalles_metabox')) {
        return;
    }
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;
    // Proyecto relacionado
    $proyecto_id = isset($_POST['gw_proyecto_relacionado']) ? intval($_POST['gw_proyecto_relacionado']) : '';
    update_post_meta($post_id, '_gw_proyecto_relacionado', $proyecto_id);
    // Coach responsable
    $coach_id = isset($_POST['gw_coach_asignado']) ? intval($_POST['gw_coach_asignado']) : '';
    update_post_meta($post_id, '_gw_coach_asignado', $coach_id);
    // País relacionado
    $pais_id = isset($_POST['gw_pais_relacionado']) ? intval($_POST['gw_pais_relacionado']) : '';
    update_post_meta($post_id, '_gw_pais_relacionado', $pais_id);
    // Sesiones
    // Sesiones
    if (!empty($_POST['sesion_modalidad']) && is_array($_POST['sesion_modalidad'])) {
        $sesiones = [];
        foreach ($_POST['sesion_modalidad'] as $i => $mod) {
            $modalidad = sanitize_text_field($mod);
            $fecha = sanitize_text_field($_POST['sesion_fecha'][$i] ?? '');
            $hora = sanitize_text_field($_POST['sesion_hora'][$i] ?? '');
            $lugar = isset($_POST['sesion_lugar'][$i]) ? sanitize_text_field($_POST['sesion_lugar'][$i]) : '';
            $link = isset($_POST['sesion_link'][$i]) ? sanitize_text_field($_POST['sesion_link'][$i]) : '';
            $sesion = [
                'modalidad' => $modalidad,
                'fecha' => $fecha,
                'hora' => $hora,
            ];
            if (strtolower($modalidad) === 'virtual') {
                $sesion['link'] = $link;
            } else {
                $sesion['lugar'] = $lugar;
            }
            $sesiones[] = $sesion;
        }
        update_post_meta($post_id, '_gw_sesiones', $sesiones);
    }
});
// --- FIN BLOQUE METABOX CAPACITACION ---
// [gw_panel_admin] shortcode and implementation moved from gw-admin.php below:

// Shortcode para Panel Administrativo
add_shortcode('gw_panel_admin', function() {
    if (!current_user_can('manage_options')) {
        return 'No tienes permisos para ver este panel.';
    }
    ob_start();
    ?>
    <style>
    .gw-admin-panel-wrap {
        display: flex;
        min-height: 600px;
        font-family: 'Segoe UI', Arial, sans-serif;
        background: #f7f8fa;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 4px 24px #cdd6e1;
        margin: 30px auto 40px;
        max-width: 1100px;
    }
    .gw-admin-menu {
        width: 240px;
        background: #23395d;
        color: #fff;
        padding: 0;
        border-right: 1px solid #e0e0e0;
        min-height: 600px;
    }
    .gw-admin-menu ul {
        list-style: none;
        margin: 0;
        padding: 0;
    }
    .gw-admin-menu li {
        margin: 0;
        border-bottom: 1px solid #2d4a7a;
    }
    .gw-admin-menu button {
        display: block;
        width: 100%;
        padding: 18px 28px;
        background: none;
        border: none;
        text-align: left;
        color: inherit;
        font-size: 1.13em;
        font-weight: 500;
        cursor: pointer;
        transition: background 0.17s;
    }
    .gw-admin-menu button.active,
    .gw-admin-menu button:hover {
        background: #31568d;
        color: #fff;
        outline: none;
    }
    .gw-admin-content {
        flex: 1;
        padding: 36px 48px 40px 48px;
        background: #fff;
        min-height: 600px;
    }
    @media (max-width: 900px) {
        .gw-admin-panel-wrap { flex-direction: column; }
        .gw-admin-menu { width: 100%; min-height: unset; border-right: none; border-bottom: 1px solid #e0e0e0;}
        .gw-admin-content { padding: 28px 10px 28px 10px;}
    }
    </style>
    <div class="gw-admin-panel-wrap">
        <nav class="gw-admin-menu">
            <ul>
                <li><button type="button" class="gw-admin-tab-btn active" data-tab="paises">Gestión de países</button></li>
                <li><button type="button" class="gw-admin-tab-btn" data-tab="usuarios">Gestión de usuarios</button></li>
                <li><button type="button" class="gw-admin-tab-btn" data-tab="charlas">Charlas</button></li>
                <li><button type="button" class="gw-admin-tab-btn" data-tab="proyectos">Proyectos</button></li>
                <li><button type="button" class="gw-admin-tab-btn" data-tab="capacitaciones">Capacitaciones</button></li>
                <li><button type="button" class="gw-admin-tab-btn" data-tab="progreso">Progreso del voluntario</button></li>
                <li><button type="button" class="gw-admin-tab-btn" data-tab="ausencias">Seguimiento de ausencias</button></li>
                <li><button type="button" class="gw-admin-tab-btn" data-tab="reportes">Reportes y listados</button></li>
            </ul>
        </nav>
        <section class="gw-admin-content">
            <div class="gw-admin-tab-content" id="gw-admin-tab-paises" style="display:block;">
            <?php
// Obtener países
$paises = get_posts([
    'post_type' => 'pais',
    'numberposts' => -1,
    'orderby' => 'title',
    'order' => 'ASC'
]);
// Obtener todas las charlas
$charlas = get_posts([
    'post_type' => 'charla',
    'numberposts' => -1,
    'orderby' => 'title',
    'order' => 'ASC'
]);

if (empty($paises)) {
    echo '<p>No hay países registrados aún.</p>';
} else {
    echo '<div style="max-width:700px;">';
    foreach ($paises as $pais) {
        $charlas_asociadas = get_post_meta($pais->ID, '_gw_charlas', true);
        if (!is_array($charlas_asociadas)) $charlas_asociadas = [];
        echo '<div style="border:1px solid #c8d6e5;padding:18px;border-radius:9px;margin-bottom:20px;background:#fafdff;">';
        // Título del país y botón Generar link/QR
        echo '<h3 style="margin:0 0 12px 0;display:flex;align-items:center;gap:10px;">' . esc_html($pais->post_title)
            . ' <button type="button" class="button button-secondary gw-generar-qr-btn" data-pais-id="' . $pais->ID . '" data-pais-nombre="' . esc_attr($pais->post_title) . '">Generar link/QR</button></h3>';
        echo '<form method="post" class="gw-form-charlas-pais" data-pais="'.$pais->ID.'">';
        echo '<label><strong>Charlas asociadas:</strong></label><br>';
        foreach ($charlas as $charla) {
            $checked = in_array($charla->ID, $charlas_asociadas) ? 'checked' : '';
            echo '<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="gw_charlas[]" value="' . $charla->ID . '" '.$checked.'> ' . esc_html($charla->post_title) . '</label>';
        }
        echo '<button type="submit" class="button button-primary" style="margin-top:10px;">Guardar</button>';
        echo '<span class="gw-charlas-guardado" style="margin-left:18px;color:#1e7e34;display:none;">Guardado</span>';
        echo '</form>';
        echo '</div>';
    }
    echo '</div>';
}
?>
<script>
document.querySelectorAll('.gw-form-charlas-pais').forEach(form => {
    form.addEventListener('submit', function(e){
        e.preventDefault();
        const paisId = this.getAttribute('data-pais');
        const checkboxes = this.querySelectorAll('input[type="checkbox"][name="gw_charlas[]"]');
        const selected = Array.from(checkboxes).filter(cb => cb.checked).map(cb => cb.value);
        var data = new FormData();
        data.append('action', 'gw_guardar_charlas_pais');
        data.append('pais_id', paisId);
        selected.forEach(cid => data.append('charlas[]', cid));
        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
            credentials: 'same-origin',
            body: data
        }).then(r=>r.json()).then(res=>{
            if(res && res.success) {
                this.querySelector('.gw-charlas-guardado').style.display = '';
                setTimeout(() => { this.querySelector('.gw-charlas-guardado').style.display = 'none'; }, 1800);
            }
        });
    });
});
</script>
<!-- Modal QR para países -->
<div id="gw-qr-modal" style="display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;z-index:99999;background:rgba(30,40,50,0.36);">
  <div style="background:#fff;max-width:370px;margin:8% auto;padding:34px 28px 28px 28px;border-radius:15px;box-shadow:0 4px 40px #3050884d;position:relative;">
    <button id="gw-qr-modal-cerrar" style="position:absolute;top:15px;right:20px;background:transparent;border:none;font-size:22px;cursor:pointer;">&times;</button>
    <div style="text-align:center;">
      <h3 id="gw-qr-modal-title" style="margin-bottom:10px;">QR de país</h3>
      <div id="gw-qr-modal-qr"></div>
      <div style="margin:17px 0 7px;">
        <input id="gw-qr-modal-link" type="text" style="width:90%;padding:7px;" readonly />
      </div>
      <button id="gw-qr-modal-copy" class="button button-primary" style="margin-top:4px;">Copiar link</button>
    </div>
  </div>
</div>
<?php
// --- DEJAR SOLO UNA DE ESTAS DOS LÍNEAS ACTIVA, SEGÚN EL ENTORNO ---

// [DESARROLLO CON NGROK]
// Cuando uses ngrok para pruebas en otros dispositivos, deja esta línea activa:
$gw_qr_base = 'https://b97e34cfbb1f.ngrok-free.app/gwproject';

// [PRODUCCIÓN O LOCALHOST]
// Cuando subas a producción o regreses a localhost, comenta la línea de arriba y descomenta esta:
// $gw_qr_base = site_url('/');
?>
<script>
document.querySelectorAll('.gw-generar-qr-btn').forEach(btn => {
  btn.addEventListener('click', function(){
    var paisId = this.getAttribute('data-pais-id');
    var paisNombre = this.getAttribute('data-pais-nombre');
    var url = '<?php echo $gw_qr_base; ?>?gw_pais=' + paisId;
    // Línea importante: CAMBIA el valor de $gw_qr_base según el entorno.
    // Cuando subas a producción, usa site_url('/'); y elimina la línea de ngrok.
    var qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=210x210&data=' + encodeURIComponent(url);
    document.getElementById('gw-qr-modal-title').innerText = "Link/QR para " + paisNombre;
    document.getElementById('gw-qr-modal-qr').innerHTML = '<img src="'+qrUrl+'" alt="QR" style="max-width:210px;">';
    document.getElementById('gw-qr-modal-link').value = url;
    document.getElementById('gw-qr-modal').style.display = '';
  });
});
document.getElementById('gw-qr-modal-cerrar').onclick = function(){
  document.getElementById('gw-qr-modal').style.display = 'none';
};
document.getElementById('gw-qr-modal-copy').onclick = function(){
  var inp = document.getElementById('gw-qr-modal-link');
  inp.select(); inp.setSelectionRange(0, 99999);
  document.execCommand('copy');
  document.getElementById('gw-qr-modal-copy').innerText = '¡Copiado!';
  setTimeout(function(){
    document.getElementById('gw-qr-modal-copy').innerText = 'Copiar link';
  }, 1500);
};
</script>
            </div>
            <div class="gw-admin-tab-content" id="gw-admin-tab-usuarios" style="display:none;">
                <h2>Gestión de usuarios</h2>
                <?php
                // Mostrar gestión de usuarios (acceso a usuarios de WP)
                echo '<p>Gestiona los usuarios desde el menú lateral de WordPress (<b>Usuarios</b>).</p>';
                ?>
            </div>
            <div class="gw-admin-tab-content" id="gw-admin-tab-charlas" style="display:none;">
                <h2>Charlas</h2>
                <div style="max-width:700px;">
                <!-- Formulario para agregar charla -->
                <div style="margin-bottom:16px;">
                  <form id="gw-form-nueva-charla" style="display:flex;gap:10px;align-items:center;">
                    <input type="text" id="gw-nueva-charla-title" placeholder="Nombre de la charla" required style="padding:7px;width:230px;">
                    <button type="submit" class="button button-primary">Agregar charla</button>
                    <span id="gw-charla-guardado" style="color:#388e3c;display:none;">Guardado</span>
                  </form>
                </div>
                <script>
                (function(){
                  var form = document.getElementById('gw-form-nueva-charla');
                  if(form){
                    form.addEventListener('submit', function(e){
                      e.preventDefault();
                      var titulo = document.getElementById('gw-nueva-charla-title').value;
                      if(!titulo) return;
                      var data = new FormData();
                      data.append('action', 'gw_agregar_charla');
                      data.append('titulo', titulo);
                      fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                        method:'POST',
                        credentials:'same-origin',
                        body: data
                      }).then(r=>r.json()).then(res=>{
                        if(res.success){
                          document.getElementById('gw-nueva-charla-title').value = '';
                          document.getElementById('gw-charla-guardado').style.display = '';
                          setTimeout(()=>{document.getElementById('gw-charla-guardado').style.display='none';}, 1600);
                          // Actualizar listado de charlas
                          document.getElementById('gw-listado-charlas').innerHTML = res.html;
                          // Re-inicializar formularios de sesiones
                          if(typeof gwInitSesionesCharlasPanel === 'function') gwInitSesionesCharlasPanel();
                        }
                      });
                    });
                  }
                })();
                </script>
                <!-- Listado de charlas -->
                <div id="gw-listado-charlas">
                <?php
                // Listado de charlas
                function gw_render_listado_charlas_panel() {
                    $charlas = get_posts([
                        'post_type' => 'charla',
                        'numberposts' => -1,
                        'orderby' => 'title',
                        'order' => 'ASC'
                    ]);
                    if (empty($charlas)) {
                        echo '<p>No hay charlas registradas aún.</p>';
                    } else {
                        foreach ($charlas as $charla) {
                            // Obtener sesiones
                            $sesiones = get_post_meta($charla->ID, '_gw_sesiones', true);
                            if (!is_array($sesiones)) $sesiones = [];
                            if (empty($sesiones)) $sesiones = [[]];
                            echo '<div style="border:1px solid #c8d6e5;padding:18px;border-radius:9px;margin-bottom:20px;background:#fafdff;">';
                            echo '<h3 style="margin:0 0 12px 0;">' . esc_html($charla->post_title) . '</h3>';
                            echo '<form method="post" class="gw-form-sesiones-charla" data-charla="'.$charla->ID.'">';
                            wp_nonce_field('gw_sesiones_charla_'.$charla->ID, 'gw_sesiones_charla_nonce');
                            echo '<div id="gw-sesiones-list-'.$charla->ID.'">';
                            foreach ($sesiones as $idx => $sesion) {
                                ?>
                                <div class="gw-sesion-block-panel" style="border:1px solid #ccc;padding:12px;margin-bottom:12px;border-radius:8px;">
                                    <label>Modalidad:
                                        <select name="sesion_modalidad[]" class="gw-sesion-modalidad-panel" required>
                                            <option value="Presencial" <?php selected((isset($sesion['modalidad'])?$sesion['modalidad']:''),'Presencial'); ?>>Presencial</option>
                                            <option value="Virtual" <?php selected((isset($sesion['modalidad'])?$sesion['modalidad']:''),'Virtual'); ?>>Virtual</option>
                                        </select>
                                    </label>
                                    <label style="margin-left:18px;">Fecha:
                                        <input type="date" name="sesion_fecha[]" value="<?php echo isset($sesion['fecha']) ? esc_attr($sesion['fecha']) : ''; ?>" required>
                                    </label>
                                    <label style="margin-left:18px;">Hora:
                                        <input type="time" name="sesion_hora[]" value="<?php echo isset($sesion['hora']) ? esc_attr($sesion['hora']) : ''; ?>" required>
                                    </label>
                                    <label class="gw-lugar-label-panel" style="margin-left:18px;<?php if(isset($sesion['modalidad']) && strtolower($sesion['modalidad'])=='virtual') echo 'display:none;'; ?>">
                                        Lugar físico:
                                        <input type="text" name="sesion_lugar[]" value="<?php echo isset($sesion['lugar']) ? esc_attr($sesion['lugar']) : ''; ?>" <?php if(isset($sesion['modalidad']) && strtolower($sesion['modalidad'])=='virtual') echo 'disabled'; ?> >
                                    </label>
                                    <label class="gw-link-label-panel" style="margin-left:18px;<?php if(!isset($sesion['modalidad']) || strtolower($sesion['modalidad'])!='virtual') echo 'display:none;'; ?>">
                                        Link:
                                        <input type="url" name="sesion_link[]" value="<?php echo isset($sesion['link']) ? esc_attr($sesion['link']) : ''; ?>" <?php if(!isset($sesion['modalidad']) || strtolower($sesion['modalidad'])!='virtual') echo 'disabled'; ?>>
                                    </label>
                                    <button type="button" class="gw-remove-sesion-panel button button-small" style="margin-left:18px;">Eliminar</button>
                                </div>
                                <?php
                            }
                            echo '</div>';
                            echo '<button type="button" class="gw-add-sesion-panel button button-secondary">Agregar sesión</button>';
                            echo '<button type="submit" class="button button-primary" style="margin-left:14px;">Guardar sesiones</button>';
                            echo '<span class="gw-sesiones-guardado" style="margin-left:18px;color:#1e7e34;display:none;">Guardado</span>';
                            echo '</form>';
                            echo '</div>';
                        }
                    }
                }
                gw_render_listado_charlas_panel();
                ?>
                </div>
                <script>
                function gwInitSesionesCharlasPanel() {
                    // Elimina listeners previos para evitar duplicados
                    document.querySelectorAll('.gw-form-sesiones-charla').forEach(function(form){
                        if(form._gwInit) return; // solo inicializar una vez
                        form._gwInit = true;
                        var charlaId = form.getAttribute('data-charla');
                        var container = form.querySelector('#gw-sesiones-list-'+charlaId);
                        // Función para actualizar labels según modalidad
                        function updateLabelsPanel(block) {
                            var modalidad = block.querySelector('.gw-sesion-modalidad-panel').value;
                            var lugarLabel = block.querySelector('.gw-lugar-label-panel');
                            var lugarInput = lugarLabel.querySelector('input');
                            var linkLabel = block.querySelector('.gw-link-label-panel');
                            var linkInput = linkLabel.querySelector('input');
                            if (modalidad.toLowerCase() === 'virtual') {
                                lugarLabel.style.display = 'none';
                                lugarInput.disabled = true;
                                linkLabel.style.display = '';
                                linkInput.disabled = false;
                            } else {
                                lugarLabel.style.display = '';
                                lugarInput.disabled = false;
                                linkLabel.style.display = 'none';
                                linkInput.disabled = true;
                            }
                        }
                        // Inicializar bloques existentes
                        form.querySelectorAll('.gw-sesion-block-panel').forEach(function(block){
                            block.querySelector('.gw-sesion-modalidad-panel').addEventListener('change', function(){
                                updateLabelsPanel(block);
                            });
                            block.querySelector('.gw-remove-sesion-panel').addEventListener('click', function(){
                                if(form.querySelectorAll('.gw-sesion-block-panel').length > 1){
                                    block.parentNode.removeChild(block);
                                }
                            });
                            updateLabelsPanel(block);
                        });
                        // Agregar sesión nueva
                        form.querySelector('.gw-add-sesion-panel').addEventListener('click', function(){
                            var html = `
                            <div class="gw-sesion-block-panel" style="border:1px solid #ccc;padding:12px;margin-bottom:12px;border-radius:8px;">
                                <label>Modalidad:
                                    <select name="sesion_modalidad[]" class="gw-sesion-modalidad-panel" required>
                                        <option value="Presencial">Presencial</option>
                                        <option value="Virtual">Virtual</option>
                                    </select>
                                </label>
                                <label style="margin-left:18px;">Fecha:
                                    <input type="date" name="sesion_fecha[]" required>
                                </label>
                                <label style="margin-left:18px;">Hora:
                                    <input type="time" name="sesion_hora[]" required>
                                </label>
                                <label class="gw-lugar-label-panel" style="margin-left:18px;">
                                    Lugar físico:
                                    <input type="text" name="sesion_lugar[]">
                                </label>
                                <label class="gw-link-label-panel" style="margin-left:18px;display:none;">
                                    Link:
                                    <input type="url" name="sesion_link[]" disabled>
                                </label>
                                <button type="button" class="gw-remove-sesion-panel button button-small" style="margin-left:18px;">Eliminar</button>
                            </div>
                            `;
                            var temp = document.createElement('div');
                            temp.innerHTML = html;
                            var block = temp.firstElementChild;
                            block.querySelector('.gw-sesion-modalidad-panel').addEventListener('change', function(){
                                updateLabelsPanel(block);
                            });
                            block.querySelector('.gw-remove-sesion-panel').addEventListener('click', function(){
                                if(form.querySelectorAll('.gw-sesion-block-panel').length > 1){
                                    block.parentNode.removeChild(block);
                                }
                            });
                            updateLabelsPanel(block);
                            container.appendChild(block);
                        });
                        // Guardar AJAX
                        form.addEventListener('submit', function(e){
                            e.preventDefault();
                            var data = new FormData(form);
                            data.append('action','gw_guardar_sesiones_charla');
                            data.append('charla_id', charlaId);
                            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                                method: 'POST',
                                credentials: 'same-origin',
                                body: data
                            }).then(r=>r.json()).then(res=>{
                                if(res && res.success){
                                    // Recargar listado de charlas (para reflejar cambios, sesiones, etc)
                                    if(res.html){
                                        document.getElementById('gw-listado-charlas').innerHTML = res.html;
                                        if(typeof gwInitSesionesCharlasPanel === 'function') gwInitSesionesCharlasPanel();
                                    } else {
                                        form.querySelector('.gw-sesiones-guardado').style.display = '';
                                        setTimeout(function(){form.querySelector('.gw-sesiones-guardado').style.display='none';}, 1800);
                                    }
                                }
                            });
                        });
                    });
                }
                // Inicializar al cargar
                gwInitSesionesCharlasPanel();
                </script>
                <style>
                .gw-sesion-block-panel label {font-weight:normal;}
                </style>
                </div>
            </div>
<?php
// AJAX handler para agregar nueva charla y devolver listado actualizado
add_action('wp_ajax_gw_agregar_charla', function(){
  if (!current_user_can('manage_options')) wp_send_json_error();
  $titulo = sanitize_text_field($_POST['titulo'] ?? '');
  if (!$titulo) wp_send_json_error(['msg'=>'Falta el título']);
  $id = wp_insert_post([
    'post_title' => $titulo,
    'post_type' => 'charla',
    'post_status' => 'publish',
  ]);
  if (!$id) wp_send_json_error(['msg'=>'Error al guardar']);
  // Devolver listado actualizado
  $charlas = get_posts([
    'post_type' => 'charla',
    'numberposts' => -1,
    'orderby' => 'title',
    'order' => 'ASC'
  ]);
  ob_start();
  if(empty($charlas)) {
    echo '<p>No hay charlas registradas aún.</p>';
  } else {
    foreach ($charlas as $charla) {
        // Obtener sesiones
        $sesiones = get_post_meta($charla->ID, '_gw_sesiones', true);
        if (!is_array($sesiones)) $sesiones = [];
        if (empty($sesiones)) $sesiones = [[]];
        echo '<div style="border:1px solid #c8d6e5;padding:18px;border-radius:9px;margin-bottom:20px;background:#fafdff;">';
        echo '<h3 style="margin:0 0 12px 0;">' . esc_html($charla->post_title) . '</h3>';
        echo '<form method="post" class="gw-form-sesiones-charla" data-charla="'.$charla->ID.'">';
        wp_nonce_field('gw_sesiones_charla_'.$charla->ID, 'gw_sesiones_charla_nonce');
        echo '<div id="gw-sesiones-list-'.$charla->ID.'">';
        foreach ($sesiones as $idx => $sesion) {
            ?>
            <div class="gw-sesion-block-panel" style="border:1px solid #ccc;padding:12px;margin-bottom:12px;border-radius:8px;">
                <label>Modalidad:
                    <select name="sesion_modalidad[]" class="gw-sesion-modalidad-panel" required>
                        <option value="Presencial" <?php selected((isset($sesion['modalidad'])?$sesion['modalidad']:''),'Presencial'); ?>>Presencial</option>
                        <option value="Virtual" <?php selected((isset($sesion['modalidad'])?$sesion['modalidad']:''),'Virtual'); ?>>Virtual</option>
                    </select>
                </label>
                <label style="margin-left:18px;">Fecha:
                    <input type="date" name="sesion_fecha[]" value="<?php echo isset($sesion['fecha']) ? esc_attr($sesion['fecha']) : ''; ?>" required>
                </label>
                <label style="margin-left:18px;">Hora:
                    <input type="time" name="sesion_hora[]" value="<?php echo isset($sesion['hora']) ? esc_attr($sesion['hora']) : ''; ?>" required>
                </label>
                <label class="gw-lugar-label-panel" style="margin-left:18px;<?php if(isset($sesion['modalidad']) && strtolower($sesion['modalidad'])=='virtual') echo 'display:none;'; ?>">
                    Lugar físico:
                    <input type="text" name="sesion_lugar[]" value="<?php echo isset($sesion['lugar']) ? esc_attr($sesion['lugar']) : ''; ?>" <?php if(isset($sesion['modalidad']) && strtolower($sesion['modalidad'])=='virtual') echo 'disabled'; ?> >
                </label>
                <label class="gw-link-label-panel" style="margin-left:18px;<?php if(!isset($sesion['modalidad']) || strtolower($sesion['modalidad'])!='virtual') echo 'display:none;'; ?>">
                    Link:
                    <input type="url" name="sesion_link[]" value="<?php echo isset($sesion['link']) ? esc_attr($sesion['link']) : ''; ?>" <?php if(!isset($sesion['modalidad']) || strtolower($sesion['modalidad'])!='virtual') echo 'disabled'; ?>>
                </label>
                <button type="button" class="gw-remove-sesion-panel button button-small" style="margin-left:18px;">Eliminar</button>
            </div>
            <?php
        }
        echo '</div>';
        echo '<button type="button" class="gw-add-sesion-panel button button-secondary">Agregar sesión</button>';
        echo '<button type="submit" class="button button-primary" style="margin-left:14px;">Guardar sesiones</button>';
        echo '<span class="gw-sesiones-guardado" style="margin-left:18px;color:#1e7e34;display:none;">Guardado</span>';
        echo '</form>';
        echo '</div>';
    }
  }
  $html = ob_get_clean();
  wp_send_json_success(['html'=>$html]);
});
?>
            <?php
// AJAX handler para agregar nueva charla y devolver listado actualizado
add_action('wp_ajax_gw_agregar_charla', function(){
  if (!current_user_can('manage_options')) wp_send_json_error();
  $titulo = sanitize_text_field($_POST['titulo'] ?? '');
  if (!$titulo) wp_send_json_error(['msg'=>'Falta el título']);
  $id = wp_insert_post([
    'post_title' => $titulo,
    'post_type' => 'charla',
    'post_status' => 'publish',
  ]);
  if (!$id) wp_send_json_error(['msg'=>'Error al guardar']);
  // Devolver listado actualizado
  $charlas = get_posts([
    'post_type' => 'charla',
    'numberposts' => -1,
    'orderby' => 'title',
    'order' => 'ASC'
  ]);
  ob_start();
  if(empty($charlas)) {
    echo '<p>No hay charlas registradas aún.</p>';
  } else {
    foreach ($charlas as $charla) {
        // Obtener sesiones
        $sesiones = get_post_meta($charla->ID, '_gw_sesiones', true);
        if (!is_array($sesiones)) $sesiones = [];
        if (empty($sesiones)) $sesiones = [[]];
        echo '<div style="border:1px solid #c8d6e5;padding:18px;border-radius:9px;margin-bottom:20px;background:#fafdff;">';
        echo '<h3 style="margin:0 0 12px 0;">' . esc_html($charla->post_title) . '</h3>';
        echo '<form method="post" class="gw-form-sesiones-charla" data-charla="'.$charla->ID.'">';
        wp_nonce_field('gw_sesiones_charla_'.$charla->ID, 'gw_sesiones_charla_nonce');
        echo '<div id="gw-sesiones-list-'.$charla->ID.'">';
        foreach ($sesiones as $idx => $sesion) {
            ?>
            <div class="gw-sesion-block-panel" style="border:1px solid #ccc;padding:12px;margin-bottom:12px;border-radius:8px;">
                <label>Modalidad:
                    <select name="sesion_modalidad[]" class="gw-sesion-modalidad-panel" required>
                        <option value="Presencial" <?php selected((isset($sesion['modalidad'])?$sesion['modalidad']:''),'Presencial'); ?>>Presencial</option>
                        <option value="Virtual" <?php selected((isset($sesion['modalidad'])?$sesion['modalidad']:''),'Virtual'); ?>>Virtual</option>
                    </select>
                </label>
                <label style="margin-left:18px;">Fecha:
                    <input type="date" name="sesion_fecha[]" value="<?php echo isset($sesion['fecha']) ? esc_attr($sesion['fecha']) : ''; ?>" required>
                </label>
                <label style="margin-left:18px;">Hora:
                    <input type="time" name="sesion_hora[]" value="<?php echo isset($sesion['hora']) ? esc_attr($sesion['hora']) : ''; ?>" required>
                </label>
                <label class="gw-lugar-label-panel" style="margin-left:18px;<?php if(isset($sesion['modalidad']) && strtolower($sesion['modalidad'])=='virtual') echo 'display:none;'; ?>">
                    Lugar físico:
                    <input type="text" name="sesion_lugar[]" value="<?php echo isset($sesion['lugar']) ? esc_attr($sesion['lugar']) : ''; ?>" <?php if(isset($sesion['modalidad']) && strtolower($sesion['modalidad'])=='virtual') echo 'disabled'; ?> >
                </label>
                <label class="gw-link-label-panel" style="margin-left:18px;<?php if(!isset($sesion['modalidad']) || strtolower($sesion['modalidad'])!='virtual') echo 'display:none;'; ?>">
                    Link:
                    <input type="url" name="sesion_link[]" value="<?php echo isset($sesion['link']) ? esc_attr($sesion['link']) : ''; ?>" <?php if(!isset($sesion['modalidad']) || strtolower($sesion['modalidad'])!='virtual') echo 'disabled'; ?>>
                </label>
                <button type="button" class="gw-remove-sesion-panel button button-small" style="margin-left:18px;">Eliminar</button>
            </div>
            <?php
        }
        echo '</div>';
        echo '<button type="button" class="gw-add-sesion-panel button button-secondary">Agregar sesión</button>';
        echo '<button type="submit" class="button button-primary" style="margin-left:14px;">Guardar sesiones</button>';
        echo '<span class="gw-sesiones-guardado" style="margin-left:18px;color:#1e7e34;display:none;">Guardado</span>';
        echo '</form>';
        echo '</div>';
    }
  }
  $html = ob_get_clean();
  wp_send_json_success(['html'=>$html]);
});
?>
            <div class="gw-admin-tab-content" id="gw-admin-tab-proyectos" style="display:none;">
<h2>Proyectos</h2>
<?php if (current_user_can('manage_options') || current_user_can('coordinador_pais')): ?>
<div style="max-width:500px;margin-bottom:28px;">
  <form id="gw-form-nuevo-proyecto">
    <label for="gw-nuevo-proyecto-title"><b>Nuevo proyecto:</b></label><br>
    <input type="text" id="gw-nuevo-proyecto-title" name="titulo" required style="width:82%;max-width:340px;padding:7px;margin:8px 0;">
    <button type="submit" class="button button-primary">Agregar</button>
    <span id="gw-proyecto-guardado" style="margin-left:12px;color:#388e3c;display:none;">Guardado</span>
  </form>
</div>
<script>
(function(){
  var form = document.getElementById('gw-form-nuevo-proyecto');
  if(form){
    form.addEventListener('submit', function(e){
      e.preventDefault();
      var titulo = document.getElementById('gw-nuevo-proyecto-title').value;
      if(!titulo) return;
      var data = new FormData();
      data.append('action', 'gw_nuevo_proyecto');
      data.append('titulo', titulo);
      fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
        method:'POST',
        credentials:'same-origin',
        body: data
      }).then(r=>r.json()).then(res=>{
        if(res.success){
          document.getElementById('gw-nuevo-proyecto-title').value = '';
          document.getElementById('gw-proyecto-guardado').style.display = '';
          setTimeout(()=>{document.getElementById('gw-proyecto-guardado').style.display='none';}, 1600);
          // Actualizar listado
          document.getElementById('gw-listado-proyectos').innerHTML = res.html;
        }
      });
    });
  }
})();
</script>
<?php endif; ?>
<div id="gw-listado-proyectos">
<?php
// Mostrar lista de proyectos actuales:
$proyectos = get_posts([
    'post_type' => 'proyecto',
    'numberposts' => -1,
    'orderby' => 'title',
    'order' => 'ASC'
]);
if(empty($proyectos)) {
    echo '<p>No hay proyectos registrados aún.</p>';
} else {
    echo '<ul style="padding-left:12px;">';
    foreach($proyectos as $proy){
        $edit_url = admin_url('post.php?post='.$proy->ID.'&action=edit');
        echo '<li style="margin-bottom:8px;"><b>'.esc_html($proy->post_title).'</b> <a href="'.$edit_url.'" target="_blank" style="margin-left:8px;font-size:0.94em;">Editar en WordPress</a></li>';
    }
    echo '</ul>';
}
?>
</div>
            </div>
            <div class="gw-admin-tab-content" id="gw-admin-tab-capacitaciones" style="display:none;">
                <h2>Capacitaciones</h2>
                <div id="gw-capacitacion-wizard">
                    <style>
                    .gw-wizard-steps {
                        display: flex; justify-content: space-between; margin-bottom: 30px; margin-top:10px;
                    }
                    .gw-wizard-step {
                        flex:1; text-align:center; padding:12px 0; position:relative;
                        background: #f7fafd;
                        border-radius: 8px;
                        font-weight: 600; color: #1d3557;
                        cursor:pointer;
                        /* Cambia este color para modificar el tema principal del wizard */
                        border:2px solid #31568d; /* <-- EDITA este color para el tema */
                        transition:.2s;
                        margin:0 4px;
                    }
                    .gw-wizard-step.active, .gw-wizard-step:hover {
                        background: #31568d;
                        color:#fff;
                    }
                    .gw-wizard-form { background: #fff; padding: 24px 30px; border-radius: 14px; box-shadow:0 2px 10px #dde7f2; max-width:560px;margin:0 auto 28px; }
                    .gw-wizard-form label { display:block; margin-top:14px;font-weight:500;}
                    .gw-wizard-form input, .gw-wizard-form select { width:100%; padding:9px; margin-top:5px; border-radius:6px; border:1px solid #bcd; }
                    .gw-wizard-sesiones { margin-top: 18px; }
                    .gw-wizard-sesion { border:1px solid #bfd9f7; border-radius:8px; padding:14px; margin-bottom:12px; display:flex; flex-wrap:wrap; align-items:center; gap:12px;}
                    .gw-wizard-sesion input, .gw-wizard-sesion select { width:auto; min-width:130px;}
                    .gw-wizard-sesion .remove-sesion { background:#d50000;color:#fff;border:none;padding:7px 16px;border-radius:6px;margin-left:18px;cursor:pointer;}
                    .gw-wizard-form .add-sesion { margin-top:8px;background:#31568d;color:#fff;padding:7px 20px;border:none;border-radius:6px;}
                    .gw-capacitacion-list {max-width:700px; margin:0 auto;}
                    .gw-cap-edit {color:#1e88e5; margin-left:14px; text-decoration:underline;cursor:pointer;}
                    .gw-cap-delete {color:#e53935; margin-left:8px; text-decoration:underline;cursor:pointer;}
                    </style>
                    <div class="gw-wizard-steps">
                        <div class="gw-wizard-step active" data-step="1">Proyecto</div>
                        <div class="gw-wizard-step" data-step="2">Coach</div>
                        <div class="gw-wizard-step" data-step="3">País</div>
                        <div class="gw-wizard-step" data-step="4">Sesiones</div>
                    </div>
                    <form class="gw-wizard-form" id="gw-capacitacion-form">
                        <div class="gw-wizard-step-content step-1">
                            <label>Nombre de la capacitación:</label>
                            <input type="text" name="titulo" required placeholder="Nombre de la capacitación">
                            <label>Proyecto relacionado:</label>
                            <select name="proyecto" required>
                                <option value="">Selecciona un proyecto</option>
                                <?php
                                $proyectos = get_posts(['post_type'=>'proyecto','numberposts'=>-1,'orderby'=>'title','order'=>'ASC']);
                                foreach($proyectos as $proy){
                                    echo '<option value="'.$proy->ID.'">'.esc_html($proy->post_title).'</option>';
                                }
                                ?>
                            </select>
                            <button type="button" class="next-step" style="float:right;margin-top:16px;">Siguiente →</button>
                        </div>
                        <div class="gw-wizard-step-content step-2" style="display:none;">
                            <label>Coach responsable:</label>
                            <select name="coach" required>
                                <option value="">Selecciona un coach</option>
                                <?php
                                $coaches = get_users(['role'=>'coach']);
                                foreach($coaches as $coach){
                                    echo '<option value="'.$coach->ID.'">'.esc_html($coach->display_name).'</option>';
                                }
                                ?>
                            </select>
                            <button type="button" class="prev-step" style="margin-top:16px;">← Anterior</button>
                            <button type="button" class="next-step" style="float:right;margin-top:16px;">Siguiente →</button>
                        </div>
                        <div class="gw-wizard-step-content step-3" style="display:none;">
                            <label>País relacionado:</label>
                            <select name="pais" required>
                                <option value="">Selecciona un país</option>
                                <?php
                                $paises = get_posts(['post_type'=>'pais','numberposts'=>-1,'orderby'=>'title','order'=>'ASC']);
                                foreach($paises as $pais){
                                    echo '<option value="'.$pais->ID.'">'.esc_html($pais->post_title).'</option>';
                                }
                                ?>
                            </select>
                            <button type="button" class="prev-step" style="margin-top:16px;">← Anterior</button>
                            <button type="button" class="next-step" style="float:right;margin-top:16px;">Siguiente →</button>
                        </div>
                        <div class="gw-wizard-step-content step-4" style="display:none;">
                            <div class="gw-wizard-sesiones"></div>
                            <button type="button" class="add-sesion">Agregar sesión</button>
                            <div style="margin-top:16px;">
                                <button type="button" class="prev-step">← Anterior</button>
                                <button type="submit" class="button button-primary" style="float:right;">Guardar capacitación</button>
                            </div>
                        </div>
                        <input type="hidden" name="edit_id" value="">
                    </form>
                </div>
                <div class="gw-capacitacion-list">
                    <h3 style="margin-top:36px;">Capacitaciones registradas</h3>
                    <div id="gw-capacitaciones-listado">
                    <?php
                    $caps = get_posts(['post_type'=>'capacitacion','numberposts'=>-1,'orderby'=>'title','order'=>'ASC']);
                    if(empty($caps)){
                        echo '<p>No hay capacitaciones registradas.</p>';
                    } else {
                        echo '<ul>';
                        foreach($caps as $cap){
                            $proy = get_post_meta($cap->ID, '_gw_proyecto_relacionado', true);
                            $proy_title = $proy ? get_the_title($proy) : '-';
                            echo '<li><b>'.esc_html($cap->post_title).'</b> <span style="color:#aaa;">(Proyecto: '.$proy_title.')</span> <span class="gw-cap-edit" data-id="'.$cap->ID.'">Editar</span> <span class="gw-cap-delete" data-id="'.$cap->ID.'">Eliminar</span></li>';
                        }
                        echo '</ul>';
                    }
                    ?>
                    </div>
                </div>
                <script>
                // Wizard steps JS
                (function(){
                    let currentStep = 1;
                    function showStep(n){
                        document.querySelectorAll('.gw-wizard-step-content').forEach(div=>{
                            div.style.display='none';
                        });
                        document.querySelector('.step-'+n).style.display = '';
                        document.querySelectorAll('.gw-wizard-step').forEach(btn=>btn.classList.remove('active'));
                        document.querySelector('.gw-wizard-step[data-step="'+n+'"]').classList.add('active');
                    }
                    document.querySelectorAll('.next-step').forEach(btn=>{
                        btn.onclick = function(){ if(currentStep<4){ showStep(++currentStep); } };
                    });
                    document.querySelectorAll('.prev-step').forEach(btn=>{
                        btn.onclick = function(){ if(currentStep>1){ showStep(--currentStep); } };
                    });
                    // Add sesiones
                    const sesionesWrap = document.querySelector('.gw-wizard-sesiones');
                    function addSesion(data){
                        data = data||{};
                        let sesion = document.createElement('div');
                        sesion.className = 'gw-wizard-sesion';
                        sesion.innerHTML = `
                            <select name="sesion_modalidad[]"><option value="Presencial"${data.modalidad=="Presencial"?" selected":""}>Presencial</option><option value="Virtual"${data.modalidad=="Virtual"?" selected":""}>Virtual</option></select>
                            <input type="date" name="sesion_fecha[]" value="${data.fecha||""}" required>
                            <input type="time" name="sesion_hora[]" value="${data.hora||""}" required>
                            <input type="text" name="sesion_lugar[]" placeholder="Lugar físico" value="${data.lugar||""}" ${data.modalidad=="Virtual"?"style='display:none;'":""}>
                            <input type="url" name="sesion_link[]" placeholder="Link (si es virtual)" value="${data.link||""}" ${data.modalidad!="Virtual"?"style='display:none;'":""}>
                            <button type="button" class="remove-sesion">Eliminar</button>
                        `;
                        // Toggle fields (mostrar/ocultar y limpiar según modalidad)
                        let modalidad = sesion.querySelector('select');
                        let lugarInput = sesion.querySelector('input[name="sesion_lugar[]"]');
                        let linkInput = sesion.querySelector('input[name="sesion_link[]"]');
                        function updateFields(){
                            let isVirtual = modalidad.value=="Virtual";
                            if(isVirtual){
                                lugarInput.style.display = "none";
                                lugarInput.value = "";
                                linkInput.style.display = "";
                                // No limpiar linkInput, permite edición
                            } else {
                                lugarInput.style.display = "";
                                linkInput.style.display = "none";
                                linkInput.value = "";
                            }
                        }
                        modalidad.onchange = updateFields;
                        sesion.querySelector('.remove-sesion').onclick = function(){
                            sesionesWrap.removeChild(sesion);
                        };
                        updateFields();
                        sesionesWrap.appendChild(sesion);
                    }
                    document.querySelector('.add-sesion').onclick = function(){
                        addSesion();
                    };
                    // AJAX submit
                    document.getElementById('gw-capacitacion-form').onsubmit = function(e){
                        e.preventDefault();
                        const form = e.target;
                        var data = new FormData(form);
                        data.append('action','gw_guardar_capacitacion_wizard');
                        fetch('<?php echo admin_url('admin-ajax.php'); ?>',{method:'POST',credentials:'same-origin',body:data})
                        .then(r=>r.json()).then(res=>{
                            if(res.success){
                                form.reset();
                                sesionesWrap.innerHTML="";
                                currentStep=1;showStep(1);
                                if (res && typeof res.html === 'string' && res.html.trim() !== '' && res.html !== 'undefined') {
                                document.getElementById('gw-capacitaciones-listado').innerHTML = res.html;
                                } else {
                                 document.getElementById('gw-capacitaciones-listado').innerHTML = '<p>No hay capacitaciones registradas.</p>';
                        };
                            } else {
                                alert('Error: '+(res.msg||'No se pudo guardar'));
                            }
                        });
                    };
                    // Editar y Eliminar
                    document.getElementById('gw-capacitaciones-listado').onclick = function(e){
                        if(e.target.classList.contains('gw-cap-edit')){
                            let id = e.target.getAttribute('data-id');
                            fetch('<?php echo admin_url('admin-ajax.php'); ?>?action=gw_obtener_capacitacion&id='+id)
                            .then(r=>r.json()).then(res=>{
                                if(res.success){
                                    const d = res.data;
                                    document.querySelector('input[name="titulo"]').value = d.titulo||'';
                                    document.querySelector('select[name="proyecto"]').value = d.proyecto;
                                    document.querySelector('select[name="coach"]').value = d.coach;
                                    document.querySelector('select[name="pais"]').value = d.pais;
                                    sesionesWrap.innerHTML = '';
                                    (d.sesiones||[]).forEach(s=>addSesion(s));
                                    document.querySelector('input[name="edit_id"]').value = id;
                                    currentStep=1;showStep(1);
                                    window.scrollTo(0,document.getElementById('gw-capacitacion-wizard').offsetTop-40);
                                }
                            });
                        }
                        if(e.target.classList.contains('gw-cap-delete')){
                            if(!confirm("¿Eliminar esta capacitación?")) return;
                            let id = e.target.getAttribute('data-id');
                            var data = new FormData();
                            data.append('action','gw_eliminar_capacitacion');
                            data.append('id',id);
                            fetch('<?php echo admin_url('admin-ajax.php'); ?>',{method:'POST',credentials:'same-origin',body:data})
                            .then(r=>r.json()).then(res=>{
                                if(res.success){
                                    document.getElementById('gw-capacitaciones-listado').innerHTML = res.html;
                                }
                            });
                        }
                    };
                    // Paso inicial
                    showStep(currentStep);
                })();
                </script>
            </div>
            <div class="gw-admin-tab-content" id="gw-admin-tab-progreso" style="display:none;">
                <h2>Progreso del voluntario</h2>
                <?php
                // Mostrar el shortcode de progreso del voluntario (admin)
                echo do_shortcode('[gw_progreso_voluntario]');
                ?>
            </div>
            <div class="gw-admin-tab-content" id="gw-admin-tab-ausencias" style="display:none;">
                <h2>Seguimiento de ausencias</h2>
                <p>Aquí va la gestión de seguimiento de ausencias.</p>
            </div>
            <div class="gw-admin-tab-content" id="gw-admin-tab-reportes" style="display:none;">
                <h2>Reportes y listados</h2>
                <p>Aquí va la gestión de reportes y listados.</p>
            </div>
        </section>
    </div>
    <script>
    (function(){
        const btns = document.querySelectorAll('.gw-admin-tab-btn');
        const tabs = document.querySelectorAll('.gw-admin-tab-content');
        btns.forEach(btn => {
            btn.addEventListener('click', function() {
                btns.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                const tabId = 'gw-admin-tab-' + btn.dataset.tab;
                tabs.forEach(tab => {
                    if(tab.id === tabId) tab.style.display = 'block';
                    else tab.style.display = 'none';
                });
            });
        });
    })();
    </script>
    <?php
    return ob_get_clean();
});
// AJAX handler para guardar charlas de país
add_action('wp_ajax_gw_guardar_charlas_pais', function() {
    if (!current_user_can('manage_options')) wp_send_json_error();
    $pais_id = intval($_POST['pais_id']);
    // Recibe array de IDs de charla
    $charlas = [];
    if (isset($_POST['charlas'])) {
        if (is_array($_POST['charlas'])) {
            $charlas = array_map('intval', $_POST['charlas']);
        } else {
            // Si viene como string (por ejemplo, un solo checkbox), conviértelo a array
            $charlas = [intval($_POST['charlas'])];
        }
    }
    update_post_meta($pais_id, '_gw_charlas', $charlas);
    wp_send_json_success();
});
// AJAX para guardar sesiones de charla desde el panel admin
add_action('wp_ajax_gw_guardar_sesiones_charla', function() {
    if (!current_user_can('manage_options')) wp_send_json_error();
    $charla_id = intval($_POST['charla_id'] ?? 0);
    if (!$charla_id) wp_send_json_error(['msg'=>'ID inválido']);
    // Verificar nonce
    $nonce_field = 'gw_sesiones_charla_nonce';
    if (!isset($_POST[$nonce_field]) || !wp_verify_nonce($_POST[$nonce_field], 'gw_sesiones_charla_'.$charla_id)) {
        wp_send_json_error(['msg'=>'Nonce inválido']);
    }
    // Procesar sesiones
    $sesiones = [];
    if (!empty($_POST['sesion_modalidad']) && is_array($_POST['sesion_modalidad'])) {
        foreach ($_POST['sesion_modalidad'] as $i => $mod) {
            $modalidad = sanitize_text_field($mod);
            $fecha = sanitize_text_field($_POST['sesion_fecha'][$i] ?? '');
            $hora = sanitize_text_field($_POST['sesion_hora'][$i] ?? '');
            $lugar = isset($_POST['sesion_lugar'][$i]) ? sanitize_text_field($_POST['sesion_lugar'][$i]) : '';
            $link = isset($_POST['sesion_link'][$i]) ? sanitize_text_field($_POST['sesion_link'][$i]) : '';
            $sesion = [
                'modalidad' => $modalidad,
                'fecha' => $fecha,
                'hora' => $hora,
            ];
            if (strtolower($modalidad) === 'virtual') {
                $sesion['link'] = $link;
            } else {
                $sesion['lugar'] = $lugar;
            }
            $sesiones[] = $sesion;
        }
    }
    update_post_meta($charla_id, '_gw_sesiones', $sesiones);
    // Devolver listado actualizado para recarga AJAX
    $charlas = get_posts([
        'post_type' => 'charla',
        'numberposts' => -1,
        'orderby' => 'title',
        'order' => 'ASC'
    ]);
    ob_start();
    if(empty($charlas)) {
        echo '<p>No hay charlas registradas aún.</p>';
    } else {
        foreach ($charlas as $charla) {
            $sesiones = get_post_meta($charla->ID, '_gw_sesiones', true);
            if (!is_array($sesiones)) $sesiones = [];
            if (empty($sesiones)) $sesiones = [[]];
            echo '<div style="border:1px solid #c8d6e5;padding:18px;border-radius:9px;margin-bottom:20px;background:#fafdff;">';
            echo '<h3 style="margin:0 0 12px 0;">' . esc_html($charla->post_title) . '</h3>';
            echo '<form method="post" class="gw-form-sesiones-charla" data-charla="'.$charla->ID.'">';
            wp_nonce_field('gw_sesiones_charla_'.$charla->ID, 'gw_sesiones_charla_nonce');
            echo '<div id="gw-sesiones-list-'.$charla->ID.'">';
            foreach ($sesiones as $idx => $sesion) {
                ?>
                <div class="gw-sesion-block-panel" style="border:1px solid #ccc;padding:12px;margin-bottom:12px;border-radius:8px;">
                    <label>Modalidad:
                        <select name="sesion_modalidad[]" class="gw-sesion-modalidad-panel" required>
                            <option value="Presencial" <?php selected((isset($sesion['modalidad'])?$sesion['modalidad']:''),'Presencial'); ?>>Presencial</option>
                            <option value="Virtual" <?php selected((isset($sesion['modalidad'])?$sesion['modalidad']:''),'Virtual'); ?>>Virtual</option>
                        </select>
                    </label>
                    <label style="margin-left:18px;">Fecha:
                        <input type="date" name="sesion_fecha[]" value="<?php echo isset($sesion['fecha']) ? esc_attr($sesion['fecha']) : ''; ?>" required>
                    </label>
                    <label style="margin-left:18px;">Hora:
                        <input type="time" name="sesion_hora[]" value="<?php echo isset($sesion['hora']) ? esc_attr($sesion['hora']) : ''; ?>" required>
                    </label>
                    <label class="gw-lugar-label-panel" style="margin-left:18px;<?php if(isset($sesion['modalidad']) && strtolower($sesion['modalidad'])=='virtual') echo 'display:none;'; ?>">
                        Lugar físico:
                        <input type="text" name="sesion_lugar[]" value="<?php echo isset($sesion['lugar']) ? esc_attr($sesion['lugar']) : ''; ?>" <?php if(isset($sesion['modalidad']) && strtolower($sesion['modalidad'])=='virtual') echo 'disabled'; ?> >
                    </label>
                    <label class="gw-link-label-panel" style="margin-left:18px;<?php if(!isset($sesion['modalidad']) || strtolower($sesion['modalidad'])!='virtual') echo 'display:none;'; ?>">
                        Link:
                        <input type="url" name="sesion_link[]" value="<?php echo isset($sesion['link']) ? esc_attr($sesion['link']) : ''; ?>" <?php if(!isset($sesion['modalidad']) || strtolower($sesion['modalidad'])!='virtual') echo 'disabled'; ?>>
                    </label>
                    <button type="button" class="gw-remove-sesion-panel button button-small" style="margin-left:18px;">Eliminar</button>
                </div>
                <?php
            }
            echo '</div>';
            echo '<button type="button" class="gw-add-sesion-panel button button-secondary">Agregar sesión</button>';
            echo '<button type="submit" class="button button-primary" style="margin-left:14px;">Guardar sesiones</button>';
            echo '<span class="gw-sesiones-guardado" style="margin-left:18px;color:#1e7e34;display:none;">Guardado</span>';
            echo '</form>';
            echo '</div>';
        }
    }
    $html = ob_get_clean();
    wp_send_json_success(['html'=>$html]);
});
// AJAX handler para agregar nuevo proyecto y devolver listado actualizado
add_action('wp_ajax_gw_nuevo_proyecto', function(){
    if (!current_user_can('manage_options') && !current_user_can('coordinador_pais')) wp_send_json_error();
    $titulo = sanitize_text_field($_POST['titulo'] ?? '');
    if (!$titulo) wp_send_json_error(['msg'=>'Falta el título']);
    $id = wp_insert_post([
        'post_title' => $titulo,
        'post_type' => 'proyecto',
        'post_status' => 'publish',
    ]);
    if (!$id) wp_send_json_error(['msg'=>'Error al guardar']);
    // Devolver listado actualizado:
    $proyectos = get_posts([
        'post_type' => 'proyecto',
        'numberposts' => -1,
        'orderby' => 'title',
        'order' => 'ASC'
    ]);
    ob_start();
    if(empty($proyectos)) {
        echo '<p>No hay proyectos registrados aún.</p>';
    } else {
        echo '<ul style="padding-left:12px;">';
        foreach($proyectos as $proy){
            $edit_url = admin_url('post.php?post='.$proy->ID.'&action=edit');
            echo '<li style="margin-bottom:8px;"><b>'.esc_html($proy->post_title).'</b> <a href="'.$edit_url.'" target="_blank" style="margin-left:8px;font-size:0.94em;">Editar en WordPress</a></li>';
        }
        echo '</ul>';
    }
    $html = ob_get_clean();
    wp_send_json_success(['html'=>$html]);
});

// AJAX: Guardar capacitación (crear o editar)
add_action('wp_ajax_gw_guardar_capacitacion_wizard', function() {
    if (!current_user_can('manage_options')) wp_send_json_error(['msg'=>'Sin permisos']);
    $titulo = sanitize_text_field($_POST['titulo'] ?? 'Capacitación '.date('d/m/Y H:i'));
    $edit_id = intval($_POST['edit_id']??0);
    $proyecto = intval($_POST['proyecto']??0);
    $coach = intval($_POST['coach']??0);
    $pais = intval($_POST['pais']??0);
    $sesiones = [];
    if(!empty($_POST['sesion_modalidad'])){
        foreach($_POST['sesion_modalidad'] as $i=>$mod){
            $modalidad = sanitize_text_field($mod);
            $sesion = [
                'modalidad' => $modalidad,
                'fecha' => sanitize_text_field($_POST['sesion_fecha'][$i]??''),
                'hora' => sanitize_text_field($_POST['sesion_hora'][$i]??''),
            ];
            if(strtolower($modalidad)=='virtual'){
                $sesion['lugar'] = '';
                $sesion['link'] = sanitize_text_field($_POST['sesion_link'][$i]??'');
            } else {
                $sesion['lugar'] = sanitize_text_field($_POST['sesion_lugar'][$i]??'');
                $sesion['link'] = '';
            }
            $sesiones[] = $sesion;
        }
    }
    $post_args = [
        'post_type'=>'capacitacion',
        'post_status'=>'publish',
        'post_title'=>$titulo,
    ];
    if($edit_id){
        $post_args['ID'] = $edit_id;
        $id = wp_update_post($post_args);
    } else {
        $id = wp_insert_post($post_args);
    }
    if(!$id) wp_send_json_error(['msg'=>'No se pudo guardar']);
    update_post_meta($id,'_gw_proyecto_relacionado',$proyecto);
    update_post_meta($id,'_gw_coach_asignado',$coach);
    update_post_meta($id,'_gw_pais_relacionado',$pais);
    update_post_meta($id,'_gw_sesiones',$sesiones);

    // Asignar la capacitación al voluntario actual
    $user_id = get_current_user_id();
    if ($user_id) {
        update_user_meta($user_id, 'gw_capacitacion_id', $id);
        // Si hay al menos una sesión, guarda fecha y hora de la primera sesión
        if (!empty($sesiones[0]['fecha'])) {
            update_user_meta($user_id, 'gw_fecha', $sesiones[0]['fecha']);
        }
        if (!empty($sesiones[0]['hora'])) {
            update_user_meta($user_id, 'gw_hora', $sesiones[0]['hora']);
        }
    }

    // Refrescar listado
    ob_start();
    $caps = get_posts(['post_type'=>'capacitacion','numberposts'=>-1,'orderby'=>'title','order'=>'ASC']);
    if(empty($caps)){
        echo '<p>No hay capacitaciones registradas.</p>';
    } else {
        echo '<ul>';
        foreach($caps as $cap){
            $proy = get_post_meta($cap->ID, '_gw_proyecto_relacionado', true);
            $proy_title = $proy ? get_the_title($proy) : '-';
            echo '<li><b>'.esc_html($cap->post_title).'</b> <span style="color:#aaa;">(Proyecto: '.$proy_title.')</span> <span class="gw-cap-edit" data-id="'.$cap->ID.'">Editar</span> <span class="gw-cap-delete" data-id="'.$cap->ID.'">Eliminar</span></li>';
        }
        echo '</ul>';
    }
    $html = ob_get_clean();
    // Control para asegurar que $html nunca sea undefined ni vacío
    if (!isset($html) || $html === false || trim($html) === '') {
        $html = '<p>No hay capacitaciones registradas.</p>';
    }
    error_log('[GW DEBUG] HTML listado de capacitaciones: ' . $html);
    wp_send_json_success(['html'=>$html]);
});

// AJAX: Obtener datos para editar capacitación
add_action('wp_ajax_gw_obtener_capacitacion', function(){
    if (!current_user_can('manage_options')) wp_send_json_error(['msg'=>'Sin permisos']);
    $id = intval($_GET['id']??0);
    if(!$id) wp_send_json_error(['msg'=>'ID inválido']);
    $data = [
        'titulo' => get_the_title($id),
        'proyecto' => get_post_meta($id,'_gw_proyecto_relacionado',true),
        'coach' => get_post_meta($id,'_gw_coach_asignado',true),
        'pais' => get_post_meta($id,'_gw_pais_relacionado',true),
        'sesiones' => get_post_meta($id,'_gw_sesiones',true)
    ];
    wp_send_json_success(['data'=>$data]);
});

// AJAX: Eliminar capacitación
add_action('wp_ajax_gw_eliminar_capacitacion', function(){
    if (!current_user_can('manage_options')) wp_send_json_error(['msg'=>'Sin permisos']);
    $id = intval($_POST['id']??0);
    if($id) wp_delete_post($id,true);
    // Refrescar listado
    ob_start();
    $caps = get_posts(['post_type'=>'capacitacion','numberposts'=>-1,'orderby'=>'title','order'=>'ASC']);
    if(empty($caps)){
        echo '<p>No hay capacitaciones registradas.</p>';
    } else {
        echo '<ul>';
        foreach($caps as $cap){
            $proy = get_post_meta($cap->ID, '_gw_proyecto_relacionado', true);
            $proy_title = $proy ? get_the_title($proy) : '-';
            echo '<li><b>'.esc_html($cap->post_title).'</b> <span style="color:#aaa;">(Proyecto: '.$proy_title.')</span> <span class="gw-cap-edit" data-id="'.$cap->ID.'">Editar</span> <span class="gw-cap-delete" data-id="'.$cap->ID.'">Eliminar</span></li>';
        }
        echo '</ul>';
    }
    $html = ob_get_clean();
    wp_send_json_success(['html'=>$html]);
});

// =================== INICIO BLOQUE REVISIÓN/ACEPTACIÓN DE DOCUMENTOS ===================
// Modal y lógica para visualizar, aprobar o rechazar documentos de voluntario
add_action('wp_footer', function() {
    if (current_user_can('manage_options')) {
        ?>
        <!-- Modal pop-up para revisión de documentos -->
        <div id="gw-modal-revision-docs" style="display:none;position:fixed;z-index:99999;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.40);align-items:center;justify-content:center;">
            <div style="background:#fff;padding:34px 42px 30px 42px;border-radius:16px;max-width:480px;min-width:330px;position:relative;box-shadow:0 2px 22px #31568d8f;">
            <button id="gw-close-modal-revision-docs" style="position:absolute;top:12px;right:18px;font-size:1.7em;background:none;border:none;cursor:pointer;color:#223;">&times;</button>
            <div id="gw-modal-docs-content">
                <!-- Contenido cargado por AJAX -->
                <div style="text-align:center;"><span class="spinner is-active"></span> Cargando documentos...</div>
            </div>
            </div>
        </div>
        <script>
        (function(){
            // Abrir modal al hacer click en botón "Revisar documentos"
            document.body.addEventListener('click', function(e){
                if (e.target.classList.contains('gw-revisar-docs')) {
                    var userId = e.target.getAttribute('data-user-id');
                    var modal = document.getElementById('gw-modal-revision-docs');
                    var content = document.getElementById('gw-modal-docs-content');
                    modal.style.display = 'flex';
                    content.innerHTML = '<div style="text-align:center;"><span class="spinner is-active"></span> Cargando documentos...</div>';
                    // AJAX para obtener docs
                    var data = new FormData();
                    data.append('action','gw_obtener_docs_voluntario');
                    data.append('user_id',userId);
                    fetch('<?php echo admin_url('admin-ajax.php'); ?>',{method:'POST',credentials:'same-origin',body:data})
                    .then(r=>r.text()).then(html=>{
                        content.innerHTML = html;
                    });
                }
            });
            // Cerrar modal
            document.getElementById('gw-close-modal-revision-docs').onclick = function(){
                document.getElementById('gw-modal-revision-docs').style.display = 'none';
            };
            // Aprobar/rechazar documento
            document.body.addEventListener('click', function(e){
                if (e.target.classList.contains('gw-aprobar-doc') || e.target.classList.contains('gw-rechazar-doc')) {
                    var btn = e.target;
                    var docId = btn.getAttribute('data-doc-id');
                    var accion = btn.classList.contains('gw-aprobar-doc') ? 'aprobar' : 'rechazar';
                    var userId = btn.getAttribute('data-user-id');
                    var data = new FormData();
                    data.append('action','gw_actualizar_estado_doc_voluntario');
                    data.append('doc_id',docId);
                    data.append('estado',accion=='aprobar'?'aprobado':'rechazado');
                    data.append('user_id',userId);
                    btn.disabled = true;
                    fetch('<?php echo admin_url('admin-ajax.php'); ?>',{method:'POST',credentials:'same-origin',body:data})
                    .then(r=>r.json()).then(res=>{
                        if(res.success){
                            // Refrescar lista de docs
                            var data2 = new FormData();
                            data2.append('action','gw_obtener_docs_voluntario');
                            data2.append('user_id',userId);
                            fetch('<?php echo admin_url('admin-ajax.php'); ?>',{method:'POST',credentials:'same-origin',body:data2})
                            .then(r=>r.text()).then(html=>{
                                document.getElementById('gw-modal-docs-content').innerHTML = html;
                            });
                        } else {
                            alert('Error: '+(res.data&&res.data.msg?res.data.msg:'No se pudo actualizar'));
                        }
                    });
                }
            });
        })();
        </script>
        <style>
        #gw-modal-revision-docs {align-items:center;justify-content:center;}
        #gw-modal-revision-docs .spinner {display:inline-block;vertical-align:middle;}
        #gw-modal-docs-content table {width:100%;border-collapse:collapse;margin-top:10px;}
        #gw-modal-docs-content th, #gw-modal-docs-content td {padding:8px 6px;border-bottom:1px solid #e0e0e0;}
        #gw-modal-docs-content th {background:#f5f7fa;}
        .gw-aprobar-doc, .gw-rechazar-doc {margin-right:7px;}
        </style>
        <?php
    }
});

// AJAX: Obtener documentos subidos por voluntario
add_action('wp_ajax_gw_obtener_docs_voluntario', function() {
    if (!current_user_can('manage_options')) wp_die('Sin permisos');
    global $wpdb;
    $user_id = intval($_POST['user_id'] ?? 0);
    if (!$user_id) { echo '<p>ID inválido.</p>'; wp_die(); }
    $docs = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}voluntario_docs WHERE user_id = %d ORDER BY fecha_subida DESC", $user_id
    ));
    if (empty($docs)) {
        echo '<p>El voluntario no ha subido documentos.</p>';
        wp_die();
    }
    echo '<table><thead><tr><th>Documento</th><th>Archivo</th><th>Estado</th><th>Acción</th></tr></thead><tbody>';
    foreach($docs as $doc) {
        $nombre = esc_html($doc->nombre_doc);
        $url = esc_url($doc->url_archivo);
        $estado = esc_html(ucfirst($doc->estado));
        $fecha = esc_html($doc->fecha_subida);
        echo '<tr>';
        echo '<td>'.$nombre.'</td>';
        // Preview de imagen, PDF o link según tipo de archivo
        $ext = strtolower(pathinfo($doc->url_archivo, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            echo '<td><a href="'.esc_url($doc->url_archivo).'" target="_blank">';
            echo '<img src="'.esc_url($doc->url_archivo).'" alt="Documento" style="max-width:120px;max-height:120px;border:1px solid #ccc;" />';
            echo '</a></td>';
        } else {
            echo '<td><a href="'.esc_url($doc->url_archivo).'" target="_blank">Ver archivo</a></td>';
        }
        echo '<td>'.$estado.'</td>';
        echo '<td>';
        if ($doc->estado !== 'aprobado') {
            echo '<button class="button gw-aprobar-doc" data-doc-id="'.$doc->id.'" data-user-id="'.$user_id.'">Aprobar</button>';
        }
        if ($doc->estado !== 'rechazado') {
            echo '<button class="button gw-rechazar-doc" data-doc-id="'.$doc->id.'" data-user-id="'.$user_id.'">Rechazar</button>';
        }
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    wp_die();
});

// AJAX: Aprobar o rechazar documento
add_action('wp_ajax_gw_actualizar_estado_doc_voluntario', function() {
    if (!current_user_can('manage_options')) wp_send_json_error(['msg'=>'Sin permisos']);
    global $wpdb;
    $doc_id = intval($_POST['doc_id'] ?? 0);
    $estado = sanitize_text_field($_POST['estado'] ?? '');
    $user_id = intval($_POST['user_id'] ?? 0);
    if (!$doc_id || !$user_id || !in_array($estado, ['aprobado','rechazado'])) {
        wp_send_json_error(['msg'=>'Datos inválidos']);
    }
    $res = $wpdb->update(
        $wpdb->prefix.'voluntario_docs',
        ['estado'=>$estado],
        ['id'=>$doc_id, 'user_id'=>$user_id]
    );
    if ($res === false) {
        wp_send_json_error(['msg'=>'No se pudo actualizar']);
    }
    wp_send_json_success();
});
// =================== FIN BLOQUE REVISIÓN/ACEPTACIÓN DE DOCUMENTOS ===================