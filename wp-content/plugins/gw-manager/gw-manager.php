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

// Panel personalizado en el admin
add_action('admin_menu', function () {
    add_menu_page('Panel Glasswing', 'Panel Glasswing', 'manage_options', 'gw-panel', 'gw_render_panel', 'dashicons-groups');
    add_submenu_page('gw-panel', 'Emparejar', 'Emparejar', 'manage_options', 'gw-emparejar', 'gw_emparejar_page');
});

function gw_render_panel() {
    // Panel principal
    echo '<div class="wrap"><h1>Panel de Administración Glasswing</h1><p>Aquí irán los accesos a Emparejamientos y Gestión avanzada.</p>';

    // Listado de Capacitaciones con subtabla de sesiones (NUEVA VERSIÓN ÚNICA)
    $capacitaciones = get_posts([
        'post_type' => 'capacitacion',
        'numberposts' => -1,
        'post_status' => 'publish',
        'orderby' => 'date',
        'order' => 'DESC'
    ]);

    if (!empty($capacitaciones)) {
        echo '<h2 style="margin-top:32px;">Capacitaciones</h2>';
        echo '<table class="widefat striped" style="margin-bottom:40px;">';
        echo '<thead>
            <tr>
                <th>Título</th>
                <th>País</th>
                <th>Responsable</th>
                <th>Tipo</th>
            </tr>
        </thead>
        <tbody>';
        foreach ($capacitaciones as $cap) {
            // Obtener metadatos
            $pais_id = get_post_meta($cap->ID, '_gw_pais_relacionado', true);
            $pais_nombre = $pais_id ? get_the_title($pais_id) : '-';
            $coach_id = get_post_meta($cap->ID, '_gw_coach_asignado', true);
            $coach_nombre = $coach_id ? get_userdata($coach_id)->display_name : '-';
            // Tipo: puedes agregar lógica si tienes campo tipo, si no, dejar "-"
            $tipo = get_post_meta($cap->ID, '_gw_tipo_capacitacion', true);
            if (!$tipo) $tipo = '-';
            echo '<tr style="background:#f9f9f9;">';
            echo '<td><strong>' . esc_html($cap->post_title) . '</strong></td>';
            echo '<td>' . esc_html($pais_nombre) . '</td>';
            echo '<td>' . esc_html($coach_nombre) . '</td>';
            echo '<td>' . esc_html($tipo) . '</td>';
            echo '</tr>';

            // Subtabla de sesiones
            $sesiones = get_post_meta($cap->ID, '_gw_sesiones', true);
            if (!is_array($sesiones)) $sesiones = [];
            echo '<tr><td colspan="4" style="padding:0;background:#fff;border-bottom:2px solid #eef3f7;">';
            if (!empty($sesiones)) {
                echo '<div style="padding:10px 0 10px 18px;">';
                echo '<table class="widefat striped" style="margin:0;min-width:600px;background:#f6fbff;">';
                echo '<thead>
                    <tr>
                        <th>ID</th>
                        <th>Modalidad</th>
                        <th>Lugar / Link</th>
                        <th>Fecha</th>
                        <th>Hora</th>
                        <th>Responsable</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>';
                foreach ($sesiones as $idx => $sesion) {
                    // Modalidad: por ahora, si hay 'modalidad', usarla; si no, asumir presencial
                    $modalidad = isset($sesion['modalidad']) ? esc_html($sesion['modalidad']) : 'Presencial';
                    // Lugar o link
                    $lugar = '';
                    if (strtolower($modalidad) === 'virtual') {
                        $link = isset($sesion['link']) ? esc_url($sesion['link']) : '';
                        $lugar = $link ? '<a href="' . $link . '" target="_blank">' . $link . '</a>' : '<span style="color:#888;">Configurar</span>';
                    } else {
                        $lugar = isset($sesion['lugar']) ? esc_html($sesion['lugar']) : '-';
                    }
                    $fecha = isset($sesion['fecha']) ? esc_html($sesion['fecha']) : '-';
                    $hora = isset($sesion['hora']) ? esc_html($sesion['hora']) : '-';
                    // Responsable: por ahora, usa el coach asignado de la capacitación
                    $responsable_id = isset($sesion['responsable']) ? $sesion['responsable'] : $coach_id;
                    $responsable_nombre = $responsable_id ? get_userdata($responsable_id)->display_name : '-';
                    // Acciones: editar (enlace a editar post de capacitación, o a modal futuro)
                    $editar_url = admin_url('post.php?post=' . $cap->ID . '&action=edit');
                    echo '<tr>';
                    echo '<td>' . ($idx+1) . '</td>';
                    echo '<td>' . ucfirst($modalidad) . '</td>';
                    echo '<td>' . $lugar . '</td>';
                    echo '<td>' . $fecha . '</td>';
                    echo '<td>' . $hora . '</td>';
                    echo '<td>' . esc_html($responsable_nombre) . '</td>';
                    echo '<td>
                        <a href="' . esc_url($editar_url) . '" class="button button-small">Editar</a>
                        <!-- <a href="#" class="button button-small" style="color:#b00;">Eliminar</a> -->
                    </td>';
                    echo '</tr>';
                }
                echo '</tbody></table></div>';
            } else {
                echo '<div style="color:#888;padding:12px 0 12px 12px;">Sin sesiones registradas.</div>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p>No hay capacitaciones registradas.</p>';
    }
    // === PROGRESO DEL VOLUNTARIO - DETALLE POR CHARLAS Y CAPACITACIÓN ===
    echo '<h2 style="margin-top:40px;">Progreso del Voluntario</h2>';
    $voluntarios = get_users(['role' => 'voluntario']);
    echo '<table class="widefat striped">';
    echo '<thead><tr>
            <th>Nombre</th><th>Correo</th>
            <th>Charlas</th>
            <th>Capacitación</th><th>Fecha</th><th>Hora</th>
            <th>Acciones</th>
          </tr></thead><tbody>';
    foreach ($voluntarios as $v) {
        $cap_id = get_user_meta($v->ID, 'gw_capacitacion_id', true);
        $cap_title = $cap_id ? get_the_title($cap_id) : '-';
        $fecha = get_user_meta($v->ID, 'gw_fecha', true) ?: '-';
        $hora = get_user_meta($v->ID, 'gw_hora', true) ?: '-';
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
        // Inline editing button and hidden row
        echo '<td><button type="button" class="button button-small button-manage" data-user-id="' . $v->ID . '">Gestionar</button></td>';
        echo '</tr>';
        echo '<tr id="edit-' . $v->ID . '" class="edit-row" style="display:none;"><td colspan="7">' . mostrar_panel_admin_progreso($v->ID) . '</td></tr>';
    }
    echo '</tbody></table>';
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
      document.querySelectorAll('.button-manage').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
          e.preventDefault();
          var id = this.getAttribute('data-user-id');
          var row = document.getElementById('edit-' + id);
          if (row.style.display === 'none') row.style.display = '';
          else row.style.display = 'none';
        });
      });
    });
    </script>
    <?php
    echo '</div>';
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
        $capacitacion_id = intval($_POST['capacitacion_id']);
        $tipo = sanitize_text_field($_POST['tipo']);

        $wpdb->insert($wpdb->prefix . 'gw_emparejamientos', [
            'user_id' => $user_id,
            'proyecto_id' => $capacitacion_id, // sigue usando columna proyecto_id pero ahora almacena ID de capacitacion
            'tipo' => $tipo
        ]);

        $mensaje = '¡Emparejamiento guardado!';
    }

    $capacitaciones = get_posts(['post_type' => 'capacitacion', 'numberposts' => -1]);
    $usuarios = get_users(['role__in' => ['coach', 'coordinador_pais']]);
    $emparejamientos = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}gw_emparejamientos ORDER BY fecha DESC");

    ?>
    <div class="wrap">
        <h1>Emparejar Usuarios con Capacitaciones</h1>
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
                    <th><label for="capacitacion_id">Capacitación</label></th>
                    <td>
                        <select name="capacitacion_id" id="capacitacion_id">
                            <option value="">Seleccionar capacitación</option>
                            <?php foreach ($capacitaciones as $c): ?>
                                <option value="<?= $c->ID ?>"><?= $c->post_title ?></option>
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
                    <th>Capacitación</th>
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
// Metabox dinámico para Capacitaciones
add_action('add_meta_boxes', function () {
    add_meta_box(
        'gw_capacitacion_meta',
        'Información adicional de la Capacitación',
        'gw_capacitacion_meta_callback',
        'capacitacion',
        'side'
    );
});

function gw_capacitacion_meta_callback($post) {
    $pais_seleccionado = get_post_meta($post->ID, '_gw_pais_relacionado', true);
    $coach_seleccionado = get_post_meta($post->ID, '_gw_coach_asignado', true);
    // Obtener sesiones (array de arrays)
    $sesiones = get_post_meta($post->ID, '_gw_sesiones', true);
    if (!is_array($sesiones)) $sesiones = [];

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
    echo '</select><br><br>';

    // Repeater dinámico de sesiones
    echo '<label>Sesiones (puedes agregar varias):</label>';
    echo '<div id="gw_sesiones_repeater">';
    if (!empty($sesiones)) {
        foreach ($sesiones as $idx => $sesion) {
            $fecha = isset($sesion['fecha']) ? esc_attr($sesion['fecha']) : '';
            $hora = isset($sesion['hora']) ? esc_attr($sesion['hora']) : '';
            echo '<div class="gw-sesion-row" style="margin-bottom:8px;display:flex;gap:4px;align-items:center;">';
            echo '<input type="date" name="gw_sesiones['.$idx.'][fecha]" value="'.$fecha.'" required style="width:120px;">';
            echo '<input type="time" name="gw_sesiones['.$idx.'][hora]" value="'.$hora.'" required style="width:90px;">';
            echo '<button type="button" class="gw-remove-sesion button" style="margin-left:5px;">Eliminar</button>';
            echo '</div>';
        }
    } else {
        // Una fila por defecto vacía
        echo '<div class="gw-sesion-row" style="margin-bottom:8px;display:flex;gap:4px;align-items:center;">';
        echo '<input type="date" name="gw_sesiones[0][fecha]" value="" required style="width:120px;">';
        echo '<input type="time" name="gw_sesiones[0][hora]" value="" required style="width:90px;">';
        echo '<button type="button" class="gw-remove-sesion button" style="margin-left:5px;">Eliminar</button>';
        echo '</div>';
    }
    echo '</div>';
    echo '<button type="button" class="button" id="gw_add_sesion">+ Agregar sesión</button>';
    ?>
    <script>
    (function(){
        // Agregar sesión
        document.addEventListener('DOMContentLoaded', function() {
            var repeater = document.getElementById('gw_sesiones_repeater');
            var addBtn = document.getElementById('gw_add_sesion');
            function updateNames() {
                var rows = repeater.querySelectorAll('.gw-sesion-row');
                rows.forEach(function(row, i) {
                    var inputs = row.querySelectorAll('input');
                    if(inputs[0]) inputs[0].setAttribute('name', 'gw_sesiones['+i+'][fecha]');
                    if(inputs[1]) inputs[1].setAttribute('name', 'gw_sesiones['+i+'][hora]');
                });
            }
            addBtn.addEventListener('click', function() {
                var idx = repeater.querySelectorAll('.gw-sesion-row').length;
                var div = document.createElement('div');
                div.className = 'gw-sesion-row';
                div.style = 'margin-bottom:8px;display:flex;gap:4px;align-items:center;';
                div.innerHTML = '<input type="date" name="gw_sesiones['+idx+'][fecha]" value="" required style="width:120px;">'+
                                '<input type="time" name="gw_sesiones['+idx+'][hora]" value="" required style="width:90px;">'+
                                '<button type="button" class="gw-remove-sesion button" style="margin-left:5px;">Eliminar</button>';
                repeater.appendChild(div);
                updateNames();
            });
            repeater.addEventListener('click', function(e) {
                if(e.target.classList.contains('gw-remove-sesion')) {
                    var rows = repeater.querySelectorAll('.gw-sesion-row');
                    if(rows.length > 1) {
                        e.target.closest('.gw-sesion-row').remove();
                        updateNames();
                    }
                }
            });
        });
    })();
    </script>
    <?php
}

// Guardar metadatos de capacitación (IDs y sesiones)
add_action('save_post', function ($post_id) {
    // Solo guardar si es del tipo capacitacion
    $post_type = get_post_type($post_id);
    if ($post_type !== 'capacitacion') return;
    if (isset($_POST['gw_pais_relacionado'])) {
        update_post_meta($post_id, '_gw_pais_relacionado', intval($_POST['gw_pais_relacionado']));
    }
    if (isset($_POST['gw_coach_asignado'])) {
        update_post_meta($post_id, '_gw_coach_asignado', intval($_POST['gw_coach_asignado']));
    }
    // Guardar sesiones como array serializado
    if (isset($_POST['gw_sesiones']) && is_array($_POST['gw_sesiones'])) {
        $limpio = [];
        foreach ($_POST['gw_sesiones'] as $sesion) {
            $fecha = isset($sesion['fecha']) ? sanitize_text_field($sesion['fecha']) : '';
            $hora = isset($sesion['hora']) ? sanitize_text_field($sesion['hora']) : '';
            if ($fecha && $hora) {
                $limpio[] = ['fecha'=>$fecha, 'hora'=>$hora];
            }
        }
        update_post_meta($post_id, '_gw_sesiones', $limpio);
    } else {
        delete_post_meta($post_id, '_gw_sesiones');
    }
    // Eliminar antiguos campos únicos
    delete_post_meta($post_id, '_gw_fecha_sesion');
    // Eliminado: delete_post_meta($post_id, '_gw_horarios');
    delete_post_meta($post_id, '_gw_hora_sesion');
});

// Shortcode para formulario de inscripción a capacitaciones
add_shortcode('gw_academia_form', 'gw_academia_form_shortcode');

function gw_academia_form_shortcode() {
    if (!is_user_logged_in()) {
        return '<p>Por favor inicia sesión para ver tus capacitaciones.</p>';
    }

    $user = wp_get_current_user();
    $mensaje = '';

    // Guardar inscripción
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['gw_academia_nonce']) && wp_verify_nonce($_POST['gw_academia_nonce'], 'gw_academia')) {
        $capacitacion_id = intval($_POST['capacitacion']);
        $fecha = sanitize_text_field($_POST['fecha']);
        $hora = sanitize_text_field($_POST['hora']);

        update_user_meta($user->ID, 'gw_capacitacion_id', $capacitacion_id);
        update_user_meta($user->ID, 'gw_fecha', $fecha);
        update_user_meta($user->ID, 'gw_hora', $hora);

        // Obtener info de la capacitación
        $capacitacion_title = get_the_title($capacitacion_id);

        // === INTEGRACIÓN GOOGLE CALENDAR & MEET ===
        require_once plugin_dir_path(__FILE__) . 'google-calendar.php';

        $summary = "Capacitación: $capacitacion_title";
        $description = "Sesión en vivo de capacitación Glasswing";
        $start = date('c', strtotime("$fecha $hora"));
        $end = date('c', strtotime("$fecha $hora +1 hour"));

        $meet_link = create_google_meet_event($summary, $description, $start, $end, $user->user_email);

        // === MENSAJE DE CONFIRMACIÓN EN PANTALLA ===
        $mensaje = "Te has inscrito a <strong>{$capacitacion_title}</strong> el día <strong>{$fecha}</strong> a las <strong>{$hora}</strong>.";

        // === ENVÍO AUTOMÁTICO DE CORREO AL VOLUNTARIO ===
        $to = $user->user_email;
        $subject = 'Confirmación de inscripción a capacitación';
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        $mensaje_email = "
            <h2>Hola {$user->display_name},</h2>
            <p>Has sido inscrito a la capacitación <strong>$capacitacion_title</strong>.</p>
            <p><strong>Fecha:</strong> $fecha<br>
            <strong>Hora:</strong> $hora</p>
            <p>Enlace de la sesión por Google Meet:<br>
            <a href=\"$meet_link\">$meet_link</a></p>
            <p>¡Gracias por participar!</p>
        ";

        wp_mail($to, $subject, $mensaje_email, $headers);
    }

    // Obtener capacitaciones del CPT "capacitacion"
    $capacitaciones = get_posts([
        'post_type' => 'capacitacion',
        'numberposts' => -1,
        'post_status' => 'publish',
        'orderby' => 'date',
        'order' => 'DESC'
    ]);

    // Prepara array de sesiones por capacitación (id => sesiones)
    $cap_sesiones = [];
    foreach($capacitaciones as $cap) {
        $sesiones = get_post_meta($cap->ID, '_gw_sesiones', true);
        if (!is_array($sesiones)) $sesiones = [];
        $cap_sesiones[$cap->ID] = $sesiones;
    }

    ob_start();
    ?>
<div class="gw-academia-form">
    <h2>Selecciona tu capacitación</h2>
    <?php if ($mensaje): ?>
        <div class="notice notice-success"><p><?php echo $mensaje; ?></p></div>
    <?php endif; ?>
    <form method="post" id="gw-academia-form">
        <?php wp_nonce_field('gw_academia', 'gw_academia_nonce'); ?>

        <p>
            <label for="capacitacion">Capacitación:</label><br>
            <select name="capacitacion" id="gw_capacitacion" required>
                <option value="">Selecciona una</option>
                <?php foreach($capacitaciones as $cap): ?>
                    <option value="<?php echo $cap->ID; ?>">
                        <?php echo esc_html($cap->post_title); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
        <div id="gw-session-info" style="display:none;">
            <p>
                <label>Fecha:</label><br>
                <input type="text" name="fecha" id="gw_fecha_val" required placeholder="Seleccione una capacitación" autocomplete="off">
            </p>
            <p id="gw-hora-block" style="display:none;">
                <label>Hora:</label><br>
                <select name="hora" id="gw_hora_val_select" style="display:none;" required></select>
                <input type="text" name="hora" id="gw_hora_val_text" style="display:none;" readonly>
            </p>
        </div>

        <p><input type="submit" value="Confirmar inscripción" class="button button-primary"></p>
    </form>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
    var gwSesiones = <?php echo json_encode($cap_sesiones); ?>;
    var fechaInput = document.getElementById('gw_fecha_val');
    var capSelect = document.getElementById('gw_capacitacion');
    var sessionInfo = document.getElementById('gw-session-info');
    var horaBlock = document.getElementById('gw-hora-block');
    var horaSelect = document.getElementById('gw_hora_val_select');
    var horaText = document.getElementById('gw_hora_val_text');
    var flatpickrInstance = null;

    function resetSessionFields() {
        if (flatpickrInstance) flatpickrInstance.clear();
        fechaInput.value = '';
        horaSelect.style.display = 'none';
        horaText.style.display = 'none';
        horaSelect.innerHTML = '';
        horaText.value = '';
        horaBlock.style.display = 'none';
    }

    capSelect.addEventListener('change', function() {
        var capId = this.value;
        resetSessionFields();
        if (capId && gwSesiones[capId] && gwSesiones[capId].length > 0) {
            sessionInfo.style.display = 'block';
            var sesiones = gwSesiones[capId];
            var fechasDisponibles = [...new Set(sesiones.map(function(s){return s.fecha;}))];

            if (flatpickrInstance) flatpickrInstance.destroy();
            flatpickrInstance = flatpickr(fechaInput, {
                dateFormat: "Y-m-d",
                enable: fechasDisponibles,
                disableMobile: true,
                onChange: function(selectedDates, dateStr, instance) {
                    var horas = sesiones.filter(function(s){return s.fecha === dateStr;}).map(function(s){return s.hora;});
                    horaSelect.innerHTML = '';
                    if (horas.length > 1) {
                        horaBlock.style.display = 'block';
                        horaSelect.style.display = 'inline-block';
                        horaText.style.display = 'none';
                        horas.forEach(function(h){
                            var opt = document.createElement('option');
                            opt.value = h;
                            opt.text = h;
                            horaSelect.appendChild(opt);
                        });
                        horaSelect.required = true;
                        horaText.required = false;
                    } else if (horas.length === 1) {
                        horaBlock.style.display = 'block';
                        horaSelect.style.display = 'none';
                        horaText.style.display = 'inline-block';
                        horaText.value = horas[0];
                        horaText.required = true;
                        horaSelect.required = false;
                    } else {
                        horaBlock.style.display = 'none';
                    }
                }
            });
            fechaInput.disabled = false;
        } else {
            sessionInfo.style.display = 'none';
            if (flatpickrInstance) { flatpickrInstance.destroy(); flatpickrInstance = null; }
        }
    });

    window.addEventListener('DOMContentLoaded', function() {
        sessionInfo.style.display = 'none';
        resetSessionFields();
    });
    </script>
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
        <select name="gw_reg_pais" required>
            <option value="">Selecciona tu país</option>
            <?php
            // Obtener países desde el CPT 'pais'
            $paises = get_posts(['post_type' => 'pais', 'numberposts' => -1, 'orderby'=>'title','order'=>'ASC']);
            foreach ($paises as $pais) {
                echo '<option value="'.$pais->ID.'">'.esc_html($pais->post_title).'</option>';
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
        $pais_id = intval($_POST['gw_reg_pais']);
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

    // Botones para paso 5 (Charlas)
    if ($show_step5) {
        ?>
        <div id="gw-admin-testing-controls-step5" style="position:fixed;bottom:24px;left:24px;z-index:9999;background:rgba(255,255,255,0.97);border:2px solid #2c3e50;padding:18px 25px;border-radius:12px;box-shadow:0 2px 16px #b4c7e7;">
            <div style="font-weight:bold;margin-bottom:8px;color:#2c3e50;">[Charlas] Modo Admin/Testing</div>
            <button onclick="gwStep5AdminBack()" class="button button-secondary" style="margin-right:8px;">Regresar al menú de Charlas (Paso 5)</button>
            <button onclick="gwStep5TestingContinue()" class="button button-primary">Continuar Test</button>
        </div>
        <script>
        function gwStep5AdminBack() {
            // Lógica: borra metas de paso 5 y charla actual y recarga al menú principal de charlas/paso 5
            var ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
            var data = new FormData();
            data.append('action', 'gw_admin_reset_charlas');
            fetch(ajaxurl, {method:'POST', credentials:'same-origin', body:data})
            .then(function(){ window.location.href = '<?php echo site_url('/index.php/portal-voluntario'); ?>'; });
        }
        function gwStep5TestingContinue() {
            // Solo recarga la página, puedes mejorar para avanzar si hay lógica
            window.location.reload();
        }
        </script>
        <?php
    }
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
        echo '<td><button type="button" class="button button-small button-manage" data-user-id="' . $v->ID . '">Gestionar</button></td>';
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
    <?php
    ?>
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
    <?php
    echo '</div>';
    return ob_get_clean();
}

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