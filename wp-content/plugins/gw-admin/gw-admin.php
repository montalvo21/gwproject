<?php
/**
 * Plugin Name: Glasswing Admin Manager
 * Description: Módulos avanzados de administración para el progreso del voluntario de Glasswing.
 * Version: 1.0
 * Author: Carlos Montalvo
 */
function gw_guardar_documentos_voluntario($user_id, $escuela_id, $file_names, $cons1, $cons2) {
    global $wpdb;
    $table = $wpdb->prefix . 'voluntario_docs';

    // Verifica si ya existe un registro para este usuario y escuela
    $docs = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM " . ($wpdb->prefix . 'voluntario_docs') . " WHERE user_id = %d AND escuela_id = %d", $user_id, $escuela_id
    ));

    if ($docs) {
        // Actualiza el registro existente
        $wpdb->update(
            $wpdb->prefix . 'voluntario_docs',
            [
                'documento_1_url' => esc_url_raw($file_names[0]),
                'documento_2_url' => esc_url_raw($file_names[1]),
                'consent_1' => $cons1,
                'consent_2' => $cons2,
                'status' => 'pendiente',
                'fecha_revision' => current_time('mysql', 1)
            ],
            [
                'user_id' => $user_id,
                'escuela_id' => $escuela_id
            ],
            ['%s','%s','%d','%d','%s','%s'],
            ['%d','%d']
        );
    } else {
        // Inserta un nuevo registro
        $wpdb->insert(
            $wpdb->prefix . 'voluntario_docs',
            [
                'user_id' => $user_id,
                'escuela_id' => $escuela_id,
                'documento_1_url' => esc_url_raw($file_names[0]),
                'documento_2_url' => esc_url_raw($file_names[1]),
                'consent_1' => $cons1,
                'consent_2' => $cons2,
                'status' => 'pendiente',
                'fecha_subida' => current_time('mysql', 1),
                'fecha_revision' => current_time('mysql', 1)
            ],
            ['%d','%d','%s','%s','%d','%d','%s','%s','%s']
        );
    }
}

 if (!defined('ABSPATH')) exit;

 // --- SHORTCODE PRINCIPAL ---
add_shortcode('gw_portal_voluntario', 'gw_portal_voluntario_shortcode');

function gw_portal_voluntario_shortcode() {
    if (!is_user_logged_in()) {
        return '<p>Debes iniciar sesión con tu cuenta para continuar.</p>';
    }

    $user_id = get_current_user_id();
    // Si viene de "Regresar a capacitaciones", resetear paso 7
    if (isset($_GET['paso7_menu']) && $_GET['paso7_menu'] == 1) {
        delete_user_meta($user_id, 'gw_step7_completo');
    }
    // DEBUG: Mostrar paso y meta de paso5 en pantalla
    echo '<div style="background:#ffc;padding:10px 18px;margin:12px 0 24px 0;border:2px solid #e2ad00;border-radius:8px;color:#333;">'.
        '<b>DEBUG:</b> Paso actual: <b>' . gw_get_voluntario_step($user_id) . '</b> | '.
        'Meta step5_completo: <b>' . get_user_meta($user_id, 'gw_step5_completo', true) . '</b>'.
        '</div>';
    $current_step = gw_get_voluntario_step($user_id);

    ob_start();
    echo '<div class="gw-voluntario-onboarding">';

    // ===== PASO 1: REGISTRO EN "ASPIRANTES" + AGENDAR RECORDATORIOS =====
    if ($current_step == 1) {
        echo gw_step_1_registro($user_id);
    }
    // ===== PASO 2: FORMULARIO DE DATOS =====
    elseif ($current_step == 2) {
        echo gw_step_2_form_datos($user_id);
    }
    // ===== PASO 3: VIDEO INTRODUCTORIO =====
    elseif ($current_step == 3) {
        echo gw_step_3_video_intro($user_id);
    }
    // ===== PASO 4: FORMULARIO DE INDUCCIÓN =====
    elseif ($current_step == 4) {
        echo gw_step_4_form_induccion($user_id);
    }
    // ===== PASO 5: CHARLA/PRIMERA SESIÓN EN VIVO =====
    elseif ($current_step == 5) {
        echo gw_step_5_charla($user_id);
    }
    // ===== PASO 6: SELECCIÓN DE PROYECTO =====
    elseif ($current_step == 6) {
        echo gw_step_6_proyecto($user_id);
    }
    // ===== PASO 7: CAPACITACIONES =====
    elseif ($current_step == 7) {
        echo gw_step_7_capacitacion($user_id);
    }
    // ===== PASO 8: SUBIDA DE DOCUMENTOS Y SELECCIÓN DE ESCUELA =====
    elseif ($current_step == 8) {
        echo gw_step_8_documentos($user_id);
    }
    // ===== FLUJO COMPLETADO =====
    else {
        echo '<div class="notice notice-success"><p>¡Bienvenido/a! Has completado tu onboarding. Ya puedes participar en todas las actividades.</p></div>';
    }
    echo '</div>';
    return ob_get_clean();
}
 
// --- Lógica para saber en qué paso va el usuario ---
function gw_get_voluntario_step($user_id) {
    // Flujo de pasos:
    // 1: Registro
    // 2: Formulario de datos
    // 3: Video introductorio
    // 4: Formulario de inducción
    // 5: Charlas
    // 6: Selección de proyecto
    // 7: Capacitaciones
    // 8: Finalizado
    if (!get_user_meta($user_id, 'gw_step1_completo', true)) return 1;
    if (!get_user_meta($user_id, 'gw_step2_completo', true)) return 2;
    if (!get_user_meta($user_id, 'gw_step3_completo', true)) return 3;
    if (!get_user_meta($user_id, 'gw_step4_completo', true)) return 4;
    if (!get_user_meta($user_id, 'gw_step5_completo', true)) return 5;
    if (!get_user_meta($user_id, 'gw_proyecto_id', true)) return 6;
    if (!get_user_meta($user_id, 'gw_step7_completo', true)) return 7; // opcional: marcar como completo al final de capacitaciones
    return 8;
}
 
 
 // --- Aquí van las funciones gw_step_1_registro, gw_step_2_form_datos, etc. ---
 function gw_step_1_registro($user_id) {
     // Marcar como aspirante (solo si no existe)
     if (!get_user_meta($user_id, 'gw_es_aspirante', true)) {
         update_user_meta($user_id, 'gw_es_aspirante', 1);
         // Agendar 6 recordatorios automáticos
         gw_agendar_recordatorios_aspirante($user_id);
     }
 
     // Marcar paso 1 como completo
     if (!get_user_meta($user_id, 'gw_step1_completo', true)) {
         update_user_meta($user_id, 'gw_step1_completo', 1);
         return '<div class="notice notice-success"><p>¡Te hemos registrado como aspirante!<br>Redirigiendo al formulario de datos personales...</p></div><meta http-equiv="refresh" content="1">';
     }
     // Si vuelve aquí, redirigir inmediatamente
     return '<meta http-equiv="refresh" content="0">';
 }
 
 // --- Helpers para agendar y cancelar recordatorios ---
 function gw_agendar_recordatorios_aspirante($user_id) {
     // Elimina recordatorios previos si los hubiera
     gw_cancelar_recordatorios_aspirante($user_id);
 
     $intervalos = [10*60, 24*60*60, 2*24*60*60, 4*24*60*60, 7*24*60*60, 14*24*60*60]; // 10min, 1d, 2d, 4d, 7d, 14d
     $base_time = time();
     foreach ($intervalos as $i => $segundos) {
         $hook = 'gw_enviar_recordatorio_aspirante_' . $user_id . '_' . $i;
         if (!wp_next_scheduled($hook, [$user_id])) {
             wp_schedule_single_event($base_time + $segundos, $hook, [$user_id]);
             add_action($hook, 'gw_enviar_recordatorio_aspirante', 10, 1);
         }
     }
 }
 function gw_cancelar_recordatorios_aspirante($user_id) {
     for ($i = 0; $i < 6; $i++) {
         $hook = 'gw_enviar_recordatorio_aspirante_' . $user_id . '_' . $i;
         $timestamp = wp_next_scheduled($hook, [$user_id]);
         if ($timestamp) {
             wp_unschedule_event($timestamp, $hook, [$user_id]);
         }
         remove_action($hook, 'gw_enviar_recordatorio_aspirante', 10);
     }
 }
 
 // --- Función que envía el correo real ---
 function gw_enviar_recordatorio_aspirante($user_id) {
     // Si el usuario ya completó el formulario, no enviar
     if (get_user_meta($user_id, 'gw_step2_completo', true)) return;
 
     $user = get_userdata($user_id);
     if (!$user || !$user->user_email) return;
 
     $to = $user->user_email;
     $subject = 'Recordatorio: Completa tu registro como voluntario Glasswing';
     $message = '<h2>¡Bienvenido a Glasswing!</h2>
         <p>Te recordamos que para finalizar tu proceso como voluntario, debes completar el formulario de datos personales en tu portal:</p>
         <p><a href="' . site_url('/index.php/portal-voluntario/') . '">Completar registro aquí</a></p>
         <p>Si ya lo completaste, ignora este mensaje. ¡Gracias!</p>';
     $headers = array('Content-Type: text/html; charset=UTF-8');
 
     wp_mail($to, $subject, $message, $headers);
 }
 function gw_step_2_form_datos($user_id) {
     $user = get_userdata($user_id);
 
     // Si ya está completo, redirige
     if (get_user_meta($user_id, 'gw_step2_completo', true)) {
         return '<meta http-equiv="refresh" content="0">';
     }
 
     $error = '';
     $nombre = $telefono = $pais = '';
 
     if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gw_datos_nonce']) && wp_verify_nonce($_POST['gw_datos_nonce'], 'gw_datos_personales')) {
         $nombre = sanitize_text_field($_POST['nombre']);
         $pais = sanitize_text_field($_POST['pais']);
         $telefono = sanitize_text_field($_POST['telefono']);
 
        if (!$nombre || !$pais || !$telefono) {
            $error = 'Por favor completa todos los campos.';
        } else {
            update_user_meta($user_id, 'gw_nombre', $nombre);
            update_user_meta($user_id, 'gw_pais', $pais);
            update_user_meta($user_id, 'gw_telefono', $telefono);
            update_user_meta($user_id, 'gw_step2_completo', 1);
            // Cancela recordatorios
            gw_cancelar_recordatorios_aspirante($user_id);
            wp_safe_redirect(site_url('/index.php/portal-voluntario/'));
            exit;
        }
     } else {
         // Precargar si existen
         $nombre = get_user_meta($user_id, 'gw_nombre', true);
         $pais = get_user_meta($user_id, 'gw_pais', true);
         $telefono = get_user_meta($user_id, 'gw_telefono', true);
     }
 
     // Obtener lista de países (CPT 'pais')
     $paises = get_posts([
         'post_type' => 'pais',
         'post_status' => 'publish',
         'numberposts' => -1,
         'orderby' => 'title',
         'order' => 'ASC',
     ]);
 
     ob_start();
     ?>
     <h3>Paso 2: Completa tus datos personales</h3>
     <?php if ($error): ?>
         <div class="notice notice-error"><p><?php echo $error; ?></p></div>
     <?php endif; ?>
     <form method="post">
         <?php wp_nonce_field('gw_datos_personales', 'gw_datos_nonce'); ?>
         <p>
             <label for="nombre">Nombre completo:</label><br>
             <input type="text" name="nombre" id="nombre" value="<?php echo esc_attr($nombre); ?>" required>
         </p>
         <p>
             <label for="pais">País:</label><br>
             <select name="pais" id="pais" required>
                 <option value="">Selecciona tu país</option>
                 <?php foreach($paises as $p): ?>
                     <option value="<?php echo esc_attr($p->post_title); ?>" <?php selected($pais, $p->post_title); ?>>
                         <?php echo esc_html($p->post_title); ?>
                     </option>
                 <?php endforeach; ?>
             </select>
         </p>
         <p>
             <label for="correo">Correo electrónico:</label><br>
             <input type="email" name="correo" id="correo" value="<?php echo esc_attr($user->user_email); ?>" readonly>
         </p>
         <p>
             <label for="telefono">Número telefónico:</label><br>
             <input type="text" name="telefono" id="telefono" value="<?php echo esc_attr($telefono); ?>" required>
         </p>
         <p>
             <button type="submit" class="button button-primary">Guardar y continuar</button>
         </p>
     </form>
     <?php
     return ob_get_clean();
 }
 function gw_step_3_video_intro($user_id) {
     // Si ya está completo, redirige
     if (get_user_meta($user_id, 'gw_step3_completo', true)) {
         return '<meta http-equiv="refresh" content="0">';
     }
 
     // YouTube Video ID (Cambiar esto por el ID del video que se necesita mostrar)
     $video_id = '9zCLT0GJKfk';
 
     // Procesar formulario
     if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gw_video_nonce']) && wp_verify_nonce($_POST['gw_video_nonce'], 'gw_video_intro')) {
         update_user_meta($user_id, 'gw_step3_completo', 1);
         return '<div class="notice notice-success"><p>¡Paso completado! Redirigiendo…</p></div><meta http-equiv="refresh" content="1">';
     }
 
     ob_start();
     ?>
     <h3>Paso 3: Video introductorio</h3>
     <div style="max-width:520px;margin:auto;">
         <div id="gw-video-youtube"></div>
     </div>
     <form method="post" style="margin-top:24px;text-align:center;">
         <?php wp_nonce_field('gw_video_intro', 'gw_video_nonce'); ?>
         <button type="submit" id="gw-video-btn" class="button button-primary" disabled>He visto el video / Continuar</button>
     </form>
     <script>
     // Cargar API de YouTube
     if (!window.GW_YTLoaded) {
         var tag = document.createElement('script');
         tag.src = "https://www.youtube.com/iframe_api";
         var firstScriptTag = document.getElementsByTagName('script')[0];
         firstScriptTag.parentNode.insertBefore(tag, firstScriptTag);
         window.GW_YTLoaded = true;
     }
     var gw_player, gw_video_done = false;
     function onYouTubeIframeAPIReady() {
         if (gw_player) return;
         gw_player = new YT.Player('gw-video-youtube', {
             height: '290',
             width: '100%',
             videoId: '<?php echo esc_js($video_id); ?>',
             events: {
                 'onStateChange': function(event) {
                     if (event.data == YT.PlayerState.ENDED) {
                         gw_video_done = true;
                         document.getElementById('gw-video-btn').disabled = false;
                     }
                 }
             }
        });
     }
     </script>
     <style>
     #gw-video-btn[disabled] { background: #999 !important; border-color:#999 !important; cursor: not-allowed !important; opacity:0.7; }
     </style>
     <?php
     return ob_get_clean();
 }
 function gw_step_4_form_induccion($user_id) {
     // Si ya está completo, redirige
     if (get_user_meta($user_id, 'gw_step4_completo', true)) {
         return '<meta http-equiv="refresh" content="0">';
     }
 
     $error = '';
     $nombre = $motivo = $edad = $pais = '';
 
     if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gw_induccion_nonce']) && wp_verify_nonce($_POST['gw_induccion_nonce'], 'gw_form_induccion')) {
         $nombre = sanitize_text_field($_POST['nombre']);
         $motivo = sanitize_textarea_field($_POST['motivo']);
         $edad = intval($_POST['edad']);
         $pais = sanitize_text_field($_POST['pais']);
 
         if (!$nombre || !$motivo || !$edad || !$pais) {
             $error = 'Por favor completa todos los campos.';
         } else {
             update_user_meta($user_id, 'gw_induccion_nombre', $nombre);
             update_user_meta($user_id, 'gw_induccion_motivo', $motivo);
             update_user_meta($user_id, 'gw_induccion_edad', $edad);
             update_user_meta($user_id, 'gw_induccion_pais', $pais);
             update_user_meta($user_id, 'gw_step4_completo', 1);
 
             return '<div class="notice notice-success"><p>¡Registro de inducción guardado! Redirigiendo…</p></div><meta http-equiv="refresh" content="1">';
         }
     } else {
         $nombre = get_user_meta($user_id, 'gw_induccion_nombre', true);
         $motivo = get_user_meta($user_id, 'gw_induccion_motivo', true);
         $edad = get_user_meta($user_id, 'gw_induccion_edad', true);
         $pais = get_user_meta($user_id, 'gw_induccion_pais', true);
     }
 
     // Obtener lista de países (CPT 'pais')
     $paises = get_posts([
         'post_type' => 'pais',
         'post_status' => 'publish',
         'numberposts' => -1,
         'orderby' => 'title',
         'order' => 'ASC',
     ]);
 
     ob_start();
     ?>
     <h3>Paso 4: Registro para inducción</h3>
     <?php if ($error): ?>
         <div class="notice notice-error"><p><?php echo $error; ?></p></div>
     <?php endif; ?>
     <form method="post">
         <?php wp_nonce_field('gw_form_induccion', 'gw_induccion_nonce'); ?>
         <p>
             <label for="nombre">Nombre completo:</label><br>
             <input type="text" name="nombre" id="nombre" value="<?php echo esc_attr($nombre); ?>" required>
         </p>
         <p>
             <label for="motivo">Motivo de inscripción:</label><br>
             <textarea name="motivo" id="motivo" rows="3" style="width:100%;" required><?php echo esc_textarea($motivo); ?></textarea>
         </p>
         <p>
             <label for="edad">Edad:</label><br>
             <input type="number" name="edad" id="edad" min="15" max="99" value="<?php echo esc_attr($edad); ?>" required>
         </p>
         <p>
             <label for="pais">País:</label><br>
             <select name="pais" id="pais" required>
                 <option value="">Selecciona tu país</option>
                 <?php foreach($paises as $p): ?>
                     <option value="<?php echo esc_attr($p->post_title); ?>" <?php selected($pais, $p->post_title); ?>>
                         <?php echo esc_html($p->post_title); ?>
                     </option>
                 <?php endforeach; ?>
             </select>
         </p>
         <p>
             <button type="submit" class="button button-primary">Guardar y continuar</button>
         </p>
     </form>
     <?php
     return ob_get_clean();
 }
// FLUJO SECUENCIAL: voluntario solo ve y completa UNA charla a la vez.
// Solo admin puede usar atajos o regresar.
function gw_step_5_charla($user_id) {
    // Lógica de menú forzado
    $forzar_menu_paso5 = isset($_GET['paso5_menu']) && $_GET['paso5_menu'] == 1;

    // [ELIMINADO: shortcut para voluntarios paso5_skip]

    // --- Bloque para asignar charlas manualmente por el admin ---
    $admin_assign_output = '';
    $current_user = wp_get_current_user();
    $is_admin = in_array('administrator', $current_user->roles);
    if ($is_admin && isset($_POST['gw_asignar_charlas_nonce']) && wp_verify_nonce($_POST['gw_asignar_charlas_nonce'], 'gw_asignar_charlas')) {
        $ids = sanitize_text_field($_POST['gw_charlas_ids']);
        $ids_arr = array_filter(array_map('intval', explode(',', $ids)));
        update_user_meta($user_id, 'gw_charlas_asignadas', $ids_arr);
        // Limpiar completadas si se desea (opcional)
        // update_user_meta($user_id, 'gw_charlas_completadas', []);
        $admin_assign_output = '<div class="notice notice-success" style="margin-bottom:10px;">Charlas asignadas: ' . esc_html(implode(', ', $ids_arr)) . '</div>';
    }

    // [ELIMINADO: shortcut para cambiar_charla]

    // Si ya está completo, redirige
    if (get_user_meta($user_id, 'gw_step5_completo', true)) {
        return '<meta http-equiv="refresh" content="0">';
    }

    // --- Cargar arrays de charlas asignadas y completadas ---
    $charlas_asignadas = get_user_meta($user_id, 'gw_charlas_asignadas', true);
    // Si no es array, inicializar
    if (!is_array($charlas_asignadas)) {
        $charlas_asignadas = [];
    }
    // Si no hay charlas asignadas y no es admin, asignar todas las charlas publicadas
    if (empty($charlas_asignadas) && !in_array('administrator', $current_user->roles)) {
        $all_charlas = get_posts([
            'post_type'   => 'charla',
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby'     => 'menu_order',  // o 'title' según prefieras
            'order'       => 'ASC',
        ]);
        $charlas_asignadas = wp_list_pluck($all_charlas, 'ID');
    }

    // --- Obtener charlas completadas y agendada ---
    $charlas_completadas = get_user_meta($user_id, 'gw_charlas_completadas', true);
    if (!is_array($charlas_completadas)) $charlas_completadas = [];
    $charla_agendada = get_user_meta($user_id, 'gw_charla_agendada', true);

    // Determinar la siguiente charla pendiente
    $charla_pendiente_id = null;
    foreach ($charlas_asignadas as $cid) {
        if (!in_array($cid, $charlas_completadas)) {
            $charla_pendiente_id = $cid;
            break;
        }
    }

    // Si NO hay charla pendiente, mostrar menú principal solo para admin, voluntario avanza automáticamente
    if (!$charla_pendiente_id || empty($charlas_asignadas)) {
        if ($is_admin || defined('GW_TESTING_MODE')) {
            ob_start();
            ?>
            <div class="gw-charla-menu-box" style="max-width:620px;margin:30px auto;background:#fff;border-radius:18px;padding:36px 32px;box-shadow:0 4px 22px #dde8f8;">
                <div class="gw-charla-header-flex" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;">
                    <div class="gw-charla-title" style="color:#ff9800;font-size:2.2rem;font-weight:bold;">Charlas</div>
                </div>
                <div style="margin-bottom:22px;">
                    <strong>¡Has completado todas tus charlas asignadas!</strong>
                </div>
                <div style="text-align:center;margin-top:20px;">
                    <a href="<?php echo esc_url(site_url('/index.php/portal-voluntario/?paso5_menu=1')); ?>" class="gw-charla-my-btn" style="padding:13px 40px;background:#27b84c;border:none;border-radius:13px;color:#fff;font-weight:bold;font-size:1.13rem;margin-left:20px;box-shadow:0 2px 8px #e3f0e9;cursor:pointer;transition:background .18s;">Ir a capacitaciones</a>
                </div>
                <?php
                // BOTÓN ADMIN: REGRESAR (ADMIN) en menú principal cuando ya completó todas las charlas
                if ($is_admin || defined('GW_TESTING_MODE')): ?>
                    <form method="post" style="margin-top:10px;">
                        <?php wp_nonce_field('gw_charla_regresar_admin', 'gw_charla_regresar_admin_nonce'); ?>
                        <button type="submit" name="gw_charla_regresar_admin" class="gw-charla-admin-btn">REGRESAR (ADMIN)</button>
                        <div style="font-size:12px;color:#1976d2;margin-top:6px;">Solo admin/testing: retrocede a charla anterior.</div>
                    </form>
                <?php endif; ?>
            </div>
            <?php
            // Procesar REGRESAR (ADMIN) en menú principal
            if (
                $_SERVER['REQUEST_METHOD'] === 'POST'
                && isset($_POST['gw_charla_regresar_admin_nonce'])
                && wp_verify_nonce($_POST['gw_charla_regresar_admin_nonce'], 'gw_charla_regresar_admin')
                && ($is_admin || defined('GW_TESTING_MODE'))
            ) {
                // Retroceder charla completada (elimina la última charla completada y la charla agendada actual)
                $charlas_completadas = get_user_meta($user_id, 'gw_charlas_completadas', true);
                if (!is_array($charlas_completadas)) $charlas_completadas = [];
                // Quitar la última charla completada si existe (último del array)
                if (!empty($charlas_completadas)) {
                    array_pop($charlas_completadas);
                    update_user_meta($user_id, 'gw_charlas_completadas', $charlas_completadas);
                }
                // Eliminar la charla agendada actual
                delete_user_meta($user_id, 'gw_charla_agendada');
                // Eliminar step5_completo si estaba marcado
                delete_user_meta($user_id, 'gw_step5_completo');
                // Recargar para mostrar la charla anterior
                echo '<div class="notice notice-warning"><p>Regresaste al paso anterior (charla previa). Recargando…</p></div><meta http-equiv="refresh" content="1">';
                return ob_get_clean();
            }
            // Mini formulario admin para asignar charlas
            if ($is_admin) {
                echo gw_step_5_charla_admin_form($user_id, $charlas_asignadas, $admin_assign_output);
            }
            return ob_get_clean();
        } else {
            // Usuario normal sin charlas pendientes: avanzar a selección de proyecto si no tiene proyecto seleccionado
            // El flujo de selección de proyecto ahora es un paso independiente (6), así que aquí solo avanzar.
            wp_safe_redirect(site_url('/index.php/portal-voluntario/'));
            exit;
        }
    }

    // Obtener charlas en el orden asignado
    $charlas = get_posts([
        'post_type'   => 'charla',
        'post_status' => 'publish',
        'post__in'    => $charlas_asignadas,
        'orderby'     => 'post__in',
        'numberposts' => -1,
    ]);
    // Buscar la charla pendiente
    $charla_actual = null;
    foreach ($charlas as $ch) {
        if ($ch->ID == $charla_pendiente_id) {
            $charla_actual = $ch;
            break;
        }
    }
    if (!$charla_actual) {
        // Error: asignada pero no existe
        ob_start();
        ?>
        <div class="notice notice-error">La charla asignada (ID: <?php echo esc_html($charla_pendiente_id); ?>) no existe. Contacta a soporte.</div>
        <?php
        if ($is_admin) echo gw_step_5_charla_admin_form($user_id, $charlas_asignadas, $admin_assign_output);
        return ob_get_clean();
    }

    // Sesiones de la charla pendiente
    $charla_sesiones = [];
    $sesiones = get_post_meta($charla_actual->ID, '_gw_fechas_horas', true);
    // DEBUG SOLO ADMIN: mostrar $sesiones
    if ($is_admin) {
        echo '<pre>'; var_dump($sesiones); echo '</pre>';
    }
    if (is_array($sesiones)) {
        foreach ($sesiones as $idx => $ses) {
            $ts = strtotime($ses['fecha'].' '.$ses['hora']);
            if (!$ses['fecha'] || !$ses['hora'] || $ts <= time()) continue;
            $charla_sesiones[] = [
                'charla_id' => $charla_actual->ID,
                'charla_title' => $charla_actual->post_title,
                'modalidad' => $ses['modalidad'],
                'fecha' => $ses['fecha'],
                'hora' => $ses['hora'],
                'lugar' => $ses['lugar'],
                'enlace' => $ses['enlace'],
                'idx' => $idx,
            ];
        }
    }

    // --- Obtener sesión agendada (solo para esta charla) ---
    $agendada = get_user_meta($user_id, 'gw_charla_agendada', true);
    // Solo considerar agendada si corresponde a la charla pendiente
    if ($agendada && (int)$agendada['charla_id'] !== (int)$charla_actual->ID) {
        $agendada = null;
        delete_user_meta($user_id, 'gw_charla_agendada');
    }

    // Si se está forzando el menú principal, mostrar listado de sesiones para la charla pendiente
    if ($forzar_menu_paso5) {
        $agendada = null;
    }

    // --- Mostrar charla agendada (solo para charla pendiente) ---
    if ($agendada && !isset($_GET['charla_id']) && !isset($_GET['charla_idx'])) {
        $ya_ocurrio = strtotime($agendada['fecha'].' '.$agendada['hora']) < time();
        ob_start();
        ?>
        <?php if ($ya_ocurrio): ?>
            <?php // El bloque original del "ya_ocurrio" se mantiene sin cambios ?>
        <?php else: ?>
            <div style="text-align:center; margin:30px auto; max-width:620px; background:#fff; border-radius:18px; padding:36px 32px; box-shadow:0 4px 22px #dde8f8;">
                <div style="font-size:0.9rem;color:#888;margin-bottom:6px;">
                    <?php echo '(' . ucfirst($agendada['modalidad']) . ')'; ?>
                </div>
                <div style="font-size:1rem;color:#333;margin-bottom:6px;">TE RECORDAMOS QUE TE REGISTRASTE A</div>
                <div style="color:#ff9800;font-size:2.2rem;font-weight:bold;margin-bottom:12px;">
                <?php echo esc_html($agendada['charla_title']) . ' / OPCIÓN ' . (isset($agendada['idx']) ? ($agendada['idx']+1) : ''); ?>
                </div>
                <div style="font-size:1rem;color:#333;margin-bottom:22px; line-height:1.4;">
                    Hora: <?php echo esc_html($agendada['hora']); ?><br>
                    <?php if ($agendada['modalidad']=='presencial'): ?>
                        Lugar: <?php echo esc_html($agendada['lugar']); ?>
                    <?php else: ?>
                        Enlace: <a href="<?php echo esc_url($agendada['enlace']); ?>" target="_blank"><?php echo esc_html($agendada['enlace']); ?></a>
                    <?php endif; ?>
                </div>
                <?php
                // Calcular si quedan charlas tras completar esta
                $predicted_completadas = count($charlas_completadas) + 1;
                $has_more = count($charlas_asignadas) > $predicted_completadas;
                $button_label = $has_more ? 'Siguiente charla' : 'Ir a capacitación';
                ?>
                <form method="post" style="margin-bottom:8px;">
                    <?php wp_nonce_field('gw_charla_asistencia', 'gw_charla_asistencia_nonce'); ?>
                    <button type="submit" class="gw-charla-my-btn"><?php echo esc_html($button_label); ?></button>
                </form>
                <div style="font-size:12px;color:#888;">(ir al enlace y marcar asistencia)</div>
            </div>
        <?php endif; ?>
        <?php
        // Procesar marcar charla como completada
        if (
            ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gw_charla_completar_nonce']) && wp_verify_nonce($_POST['gw_charla_completar_nonce'], 'gw_charla_completar') && $agendada && strtotime($agendada['fecha'].' '.$agendada['hora']) < time())
            ||
            ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gw_charla_completar_test_nonce']) && wp_verify_nonce($_POST['gw_charla_completar_test_nonce'], 'gw_charla_completar_test') && ($is_admin || defined('GW_TESTING_MODE')))
        ) {
            // Marcar como completada en el array
            $charlas_completadas[] = (int)$charla_actual->ID;
            update_user_meta($user_id, 'gw_charlas_completadas', array_unique($charlas_completadas));
            // Limpiar agendada para siguiente charla
            delete_user_meta($user_id, 'gw_charla_agendada');
            // Si ya no hay más pendientes, marcar paso 5 como completo
            $quedan_pendientes = false;
            foreach ($charlas_asignadas as $cid) {
                if (!in_array($cid, $charlas_completadas)) {
                    $quedan_pendientes = true;
                    break;
                }
            }
            if (!$quedan_pendientes) {
                update_user_meta($user_id, 'gw_step5_completo', 1);
            }
            // Asegurar recarga a siguiente charla (redirigir siempre)
            wp_safe_redirect(site_url('/index.php/portal-voluntario/'));
            exit;
        }
        // Procesar marcar asistencia al hacer clic en "Ir a capacitación"
        if (
            $_SERVER['REQUEST_METHOD'] === 'POST'
            && isset($_POST['gw_charla_asistencia_nonce'])
            && wp_verify_nonce($_POST['gw_charla_asistencia_nonce'], 'gw_charla_asistencia')
        ) {
            // Marcar como completada en el array
            $charlas_completadas[] = (int)$charla_actual->ID;
            update_user_meta($user_id, 'gw_charlas_completadas', array_unique($charlas_completadas));
            delete_user_meta($user_id, 'gw_charla_agendada');
            // Si ya no hay pendientes, marcar paso 5 como completo
            $quedan = false;
            foreach ($charlas_asignadas as $cid) {
                if (!in_array($cid, $charlas_completadas)) { $quedan = true; break; }
            }
            if (!$quedan) update_user_meta($user_id, 'gw_step5_completo', 1);
            // Decidir redirección según charlas restantes
            if (!empty($charlas_completadas) && count($charlas_asignadas) > count($charlas_completadas)) {
                // Quedan charlas: recargar para mostrar la siguiente
                wp_safe_redirect(site_url('/index.php/portal-voluntario/'));
            } else {
                // Última charla completada: avanzar a capacitaciones
                wp_safe_redirect(site_url('/index.php/portal-voluntario/?paso5_menu=1'));
            }
            exit;
        }
        // Procesar REGRESAR (ADMIN)
        if (
            $_SERVER['REQUEST_METHOD'] === 'POST'
            && isset($_POST['gw_charla_regresar_admin_nonce'])
            && wp_verify_nonce($_POST['gw_charla_regresar_admin_nonce'], 'gw_charla_regresar_admin')
            && ($is_admin || defined('GW_TESTING_MODE'))
        ) {
            // Retroceder charla completada (elimina la última charla completada y la charla agendada actual)
            $charlas_completadas = get_user_meta($user_id, 'gw_charlas_completadas', true);
            if (!is_array($charlas_completadas)) $charlas_completadas = [];
            // Quitar la última charla completada si existe (último del array)
            if (!empty($charlas_completadas)) {
                array_pop($charlas_completadas);
                update_user_meta($user_id, 'gw_charlas_completadas', $charlas_completadas);
            }
            // Eliminar la charla agendada actual
            delete_user_meta($user_id, 'gw_charla_agendada');
            // Eliminar step5_completo si estaba marcado
            delete_user_meta($user_id, 'gw_step5_completo');
            // Recargar para mostrar la charla anterior
            return '<div class="notice notice-warning"><p>Regresaste al paso anterior (charla previa). Recargando…</p></div><meta http-equiv="refresh" content="1">';
        }
        if ($is_admin) echo gw_step_5_charla_admin_form($user_id, $charlas_asignadas, $admin_assign_output);
        return ob_get_clean();
    }

    // Si llega con charla_id y charla_idx (pantalla de confirmación)
    if (
        isset($_GET['charla_id']) && isset($_GET['charla_idx']) &&
        $_GET['charla_id'] !== '' && $_GET['charla_idx'] !== ''
    ) {
        $charla_id = intval($_GET['charla_id']);
        $charla_idx = intval($_GET['charla_idx']);
        // Solo permitir seleccionar de la charla pendiente
        if ($charla_id != $charla_actual->ID) {
            return '<div class="notice notice-error">Solo puedes seleccionar sesiones de tu charla pendiente.<br><a href="'.esc_url(site_url('/index.php/portal-voluntario/')).'" class="gw-charla-my-btn">MI CUENTA</a></div>';
        }
        // Buscar la sesión exacta
        $sesion = null;
        foreach ($charla_sesiones as $ses) {
            if ($ses['charla_id'] == $charla_id && $ses['idx'] == $charla_idx) {
                $sesion = $ses;
                break;
            }
        }
        if (!$sesion) {
            return '<div class="notice notice-error">La sesión seleccionada ya no está disponible.<br><a href="'.esc_url(site_url('/index.php/portal-voluntario/')).'" class="gw-charla-my-btn">MI CUENTA</a></div>';
        }
        $error = '';
        $success = false;
        // Procesar registro (botón "Registrarme")
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gw_charla_registrarme_nonce']) && wp_verify_nonce($_POST['gw_charla_registrarme_nonce'], 'gw_charla_registrarme')) {
            // Validar que la sesión sigue disponible
            $encontrada = false;
            foreach ($charla_sesiones as $s) {
                if (
                    $s['charla_id'] == $sesion['charla_id'] &&
                    $s['modalidad'] == $sesion['modalidad'] &&
                    $s['fecha'] == $sesion['fecha'] &&
                    $s['hora'] == $sesion['hora']
                ) {
                    $encontrada = true; break;
                }
            }
            if ($encontrada) {
                $agendada = [
                    'charla_id' => $sesion['charla_id'],
                    'charla_title' => $sesion['charla_title'],
                    'modalidad' => $sesion['modalidad'],
                    'fecha' => $sesion['fecha'],
                    'hora' => $sesion['hora'],
                    'lugar' => $sesion['lugar'],
                    'enlace' => $sesion['enlace'],
                ];
                update_user_meta($user_id, 'gw_charla_agendada', $agendada);
                $success = true;
            } else {
                $error = "La sesión seleccionada ya no está disponible.";
            }
        }
        ob_start();
        ?>
        <style>
        .gw-charla-menu-box {max-width:620px;margin:30px auto;background:#fff;border-radius:18px;padding:36px 32px;box-shadow:0 4px 22px #dde8f8;}
        .gw-charla-header-flex {display: flex;justify-content: space-between;align-items: center;margin-bottom: 18px;}
        .gw-charla-title { color: #ff9800; font-size: 2.2rem; font-weight: bold; }
        .gw-charla-my-btn {padding: 13px 40px;background: #27b84c;border: none;border-radius: 13px;color: #fff;font-weight: bold;font-size: 1.13rem;margin-left: 20px;box-shadow: 0 2px 8px #e3f0e9;cursor: pointer;transition: background .18s;}
        .gw-charla-my-btn:hover {background: #21903a;}
        .gw-charla-btn {padding:10px 32px;background:#fff;border:2px solid #1976d2;border-radius:9px;color:#1976d2;font-weight:bold;font-size:1.02rem;transition:.18s;cursor:pointer;}
        .gw-charla-btn:hover {background:#e3f0fe;}
        </style>
        <div class="gw-charla-menu-box">
            <div class="gw-charla-header-flex">
                <div class="gw-charla-title"><?php echo esc_html($charla_actual->post_title); ?></div>
                <a href="<?php echo esc_url(site_url('/index.php/portal-voluntario/')); ?>" class="gw-charla-my-btn">MI CUENTA</a>
            </div>
            <?php if ($error): ?><div class="notice notice-error" style="margin-bottom:20px;"><?php echo esc_html($error); ?></div><?php endif; ?>
            <?php if ($success): ?>
                <div class="notice notice-success" style="margin-bottom:20px;">¡Te has registrado con éxito en la charla!</div>
            <?php else: ?>
            <div style="margin-bottom:22px;">
                <strong>Confirmar registro a la siguiente sesión:</strong><br>
                <b><?php echo esc_html($sesion['charla_title']); ?></b>
                <?php if ($sesion['modalidad']=='presencial'): ?>
                    <span style="margin-left:8px;color:#1976d2;">(Presencial)</span>
                <?php else: ?>
                    <span style="margin-left:8px;color:#1976d2;">(Virtual/Google Meet)</span>
                <?php endif; ?><br>
                Fecha: <b><?php echo date('d/m/Y', strtotime($sesion['fecha'])); ?></b> <br>
                Hora: <b><?php echo substr($sesion['hora'],0,5); ?></b> <br>
                <?php if ($sesion['modalidad']=='presencial'): ?>
                    Lugar: <b><?php echo esc_html($sesion['lugar']); ?></b><br>
                <?php else: ?>
                    Enlace: <span style="color:#888;">(Se habilitará después del registro)</span><br>
                <?php endif; ?>
            </div>
            <form method="post" style="display:inline;">
                <?php wp_nonce_field('gw_charla_registrarme', 'gw_charla_registrarme_nonce'); ?>
                <button type="submit" class="gw-charla-my-btn">Registrarme</button>
            </form>
            <?php endif; ?>
        </div>
        <?php
        if ($is_admin) echo gw_step_5_charla_admin_form($user_id, $charlas_asignadas, $admin_assign_output);
        return ob_get_clean();
    }

    // Menú principal: listar todas las sesiones futuras de la charla pendiente
    ob_start();
    ?>
    <style>
    .gw-charla-header-flex {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 18px;
    }
    .gw-charla-title { color: #ff9800; font-size: 2.2rem; font-weight: bold; }
    .gw-charla-my-btn {
        padding: 13px 40px;
        background: #27b84c;
        border: none;
        border-radius: 13px;
        color: #fff;
        font-weight: bold;
        font-size: 1.13rem;
        margin-left: 20px;
        box-shadow: 0 2px 8px #e3f0e9;
        cursor: pointer;
        transition: background .18s;
    }
    .gw-charla-my-btn:hover {
        background: #21903a;
    }
    .gw-charla-menu-box {max-width:620px;margin:30px auto;background:#fff;border-radius:18px;padding:36px 32px;box-shadow:0 4px 22px #dde8f8;}
    .gw-charla-sesion-row {display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;padding-bottom:12px;border-bottom:1px solid #efefef;}
    .gw-charla-sesion-row:last-child {border-bottom:none;}
    .gw-charla-btn {padding:10px 32px;background:#fff;border:2px solid #1976d2;border-radius:9px;color:#1976d2;font-weight:bold;font-size:1.02rem;transition:.18s;cursor:pointer;}
    .gw-charla-btn:hover {background:#e3f0fe;}
    </style>
    <div class="gw-charla-menu-box">
        <div class="gw-charla-header-flex">
            <div class="gw-charla-title"><?php echo esc_html($charla_actual->post_title); ?></div>
            <a href="<?php echo esc_url(add_query_arg('paso5_menu',1,site_url('/index.php/portal-voluntario/'))); ?>" class="gw-charla-my-btn">MI CUENTA</a>
        </div>
        <?php if (empty($charla_sesiones)): ?>
            <div class="notice notice-error">Actualmente no hay sesiones disponibles para registro.</div>
        <?php else: ?>
            <?php foreach ($charla_sesiones as $idx => $ses): ?>
                <div class="gw-charla-sesion-row">
                    <span>
                        <b>OPCIÓN <?php echo ($idx+1); ?>:</b>
                        <?php echo esc_html($ses['modalidad']=='virtual' ? "Google Meet" : strtoupper($ses['lugar'] ?: $ses['charla_title'])); ?>
                        <span style="margin-left:16px;font-weight:normal;color:#888;">(<?php echo date('d/m/Y', strtotime($ses['fecha'])).' '.substr($ses['hora'],0,5); ?>)</span>
                    </span>
                    <form method="get" action="" style="margin:0;">
                        <input type="hidden" name="charla_id" value="<?php echo esc_attr($ses['charla_id']); ?>">
                        <input type="hidden" name="charla_idx" value="<?php echo esc_attr($ses['idx']); ?>">
                        <button type="submit" class="gw-charla-btn">Seleccionar</button>
                    </form>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php
    // Mini formulario admin para asignar charlas
    if ($is_admin) echo gw_step_5_charla_admin_form($user_id, $charlas_asignadas, $admin_assign_output);
    return ob_get_clean();
}

// Mini formulario para asignar IDs de charla manualmente (solo admin)
function gw_step_5_charla_admin_form($user_id, $charlas_asignadas, $admin_output = '') {
    $all_charlas = get_posts([
        'post_type' => 'charla',
        'post_status' => 'publish',
        'numberposts' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
    ]);
    ob_start();
    ?>
    <div style="margin:30px auto 0 auto;max-width:620px;padding:20px 28px;background:#f8f8f8;border-radius:12px;border:1px solid #e2e2e2;">
        <div style="font-weight:bold;margin-bottom:6px;color:#1976d2;">[ADMIN] Asignar charlas manualmente a este usuario (IDs separados por coma):</div>
        <?php if ($admin_output) echo $admin_output; ?>
        <form method="post" style="margin-bottom:0;">
            <?php wp_nonce_field('gw_asignar_charlas', 'gw_asignar_charlas_nonce'); ?>
            <input type="text" name="gw_charlas_ids" value="<?php echo esc_attr(implode(',', $charlas_asignadas)); ?>" style="width:70%;padding:4px 7px;" placeholder="Ej: 123,456">
            <button type="submit" class="button">Asignar</button>
            <span style="font-size:12px;color:#888;margin-left:8px;">Charlas disponibles:
            <?php foreach ($all_charlas as $c): ?>
                <span title="<?php echo esc_attr($c->post_title); ?>">#<?php echo $c->ID; ?></span>
            <?php endforeach; ?>
            </span>
        </form>
    </div>
    <?php
    return ob_get_clean();
}

// === NUEVO PASO 6: Selección de proyecto ===
function gw_step_6_proyecto($user_id) {
    if (get_user_meta($user_id, 'gw_proyecto_id', true)) {
        return '<meta http-equiv="refresh" content="0">';
    }
    $error = '';
    $success = false;
    $user_pais = get_user_meta($user_id, 'gw_pais', true);

    // Obtener lista de proyectos (CPT 'proyecto'), filtrar por país si existe
    $args = [
        'post_type' => 'proyecto',
        'post_status' => 'publish',
        'numberposts' => -1,
        'orderby' => 'title',
        'order' => 'ASC'
    ];
    $proyectos = get_posts($args);
    // Filtrar por país si corresponde
    if ($user_pais) {
        $proyectos = array_filter($proyectos, function($p) use ($user_pais) {
            $pais = get_post_meta($p->ID, '_gw_pais', true);
            return !$pais || strtolower(trim($pais)) === strtolower(trim($user_pais));
        });
    }

    // --- Confirmación por GET ---
    $confirm_proyecto_id = isset($_GET['proyecto_id']) ? intval($_GET['proyecto_id']) : 0;
    // Si estamos en la página de confirmación
    if ($confirm_proyecto_id) {
        // Buscar el proyecto
        $proy = null;
        foreach ($proyectos as $p) {
            if ($p->ID == $confirm_proyecto_id) {
                $proy = $p;
                break;
            }
        }
        // Si no existe, mostrar error y regresar
        if (!$proy) {
            return '<div class="notice notice-error">El proyecto seleccionado no existe.<br><a href="'.esc_url(site_url('/index.php/portal-voluntario/')).'" class="gw-charla-my-btn">MI CUENTA</a></div>';
        }
        // Procesar confirmación
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmar_inscripcion']) && isset($_POST['confirm_proyecto_id']) && intval($_POST['confirm_proyecto_id']) == $confirm_proyecto_id) {
            update_user_meta($user_id, 'gw_proyecto_id', $confirm_proyecto_id);
            // Redirigir al paso 7
            return '<div class="notice notice-success"><p>¡Proyecto seleccionado correctamente! Redirigiendo…</p></div><meta http-equiv="refresh" content="1">';
        }
        // Procesar cancelar
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancelar'])) {
            // Redirigir al menú de selección de proyectos
            wp_safe_redirect(site_url('/index.php/portal-voluntario/'));
            exit;
        }
        // Mostrar página de confirmación
        ob_start();
        ?>
        <style>
        .gw-charla-header-flex {display: flex;justify-content: space-between;align-items: center;margin-bottom: 18px;}
        .gw-charla-title { color: #1976d2; font-size: 2.2rem; font-weight: bold; }
        .gw-charla-my-btn {padding: 13px 40px;background: #27b84c;border: none;border-radius: 13px;color: #fff;font-weight: bold;font-size: 1.13rem;margin-left: 20px;box-shadow: 0 2px 8px #e3f0e9;cursor: pointer;transition: background .18s;}
        .gw-charla-my-btn:hover {background: #21903a;}
        </style>
        <div class="gw-charla-header-flex" style="margin-bottom:32px;">
            <a href="<?php echo esc_url(site_url('/index.php/portal-voluntario/')); ?>" class="gw-charla-my-btn" style="margin-left:0;">MI CUENTA</a>
        </div>
        <div style="max-width:620px;margin:30px auto;background:#fff;border-radius:18px;padding:36px 32px;box-shadow:0 4px 22px #dde8f8;text-align:center;">
            <div class="gw-charla-title" style="color:#1976d2;margin-bottom:18px;"><?php echo esc_html($proy->post_title); ?></div>
            <div style="background:#f8f7e7;border:1px solid #ffe566;border-radius:7px;padding:18px 14px;margin-bottom:12px;text-align:center;">
                <b>¿Deseas inscribirte al proyecto "<?php echo esc_html($proy->post_title); ?>"?</b>
                <form method="post" style="display:inline;">
                    <input type="hidden" name="confirm_proyecto_id" value="<?php echo esc_attr($proy->ID); ?>">
                    <button type="submit" name="confirmar_inscripcion" class="button button-primary" style="margin:10px 12px;">Sí, inscribirme</button>
                </form>
                <form method="post" style="display:inline;">
                    <button type="submit" name="cancelar" class="button">Cancelar</button>
                </form>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // --- Menú de selección de proyectos ---
    ob_start();
    ?>
    <style>
    .gw-charla-header-flex {display: flex;justify-content: space-between;align-items: center;margin-bottom: 18px;}
    .gw-charla-title { color: #1976d2; font-size: 2.2rem; font-weight: bold; }
    .gw-charla-my-btn {padding: 13px 40px;background: #27b84c;border: none;border-radius: 13px;color: #fff;font-weight: bold;font-size: 1.13rem;margin-left: 20px;box-shadow: 0 2px 8px #e3f0e9;cursor: pointer;transition: background .18s;}
    .gw-charla-my-btn:hover {background: #21903a;}
    .gw-proyecto-btn {padding:10px 26px;background:#218838;color:#fff;border:none;border-radius:8px;font-weight:bold;font-size:1rem;box-shadow:0 2px 6px #e3f0e9;cursor:pointer;}
    .gw-proyecto-btn:hover { background:#17672b !important; }
    </style>
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;max-width:600px;margin-left:auto;margin-right:auto;">
        <h3 style="margin:0;">Paso 6: Selección de proyecto</h3>
        <a href="<?php echo esc_url(site_url('/index.php/portal-voluntario/')); ?>" class="gw-charla-my-btn" style="margin-left:18px;">MI CUENTA</a>
    </div>
    <?php if ($error): ?>
        <div class="notice notice-error"><p><?php echo $error; ?></p></div>
    <?php endif; ?>
    <div style="max-width:600px;margin:0 auto;">
        <?php foreach ($proyectos as $idx => $proy): ?>
            <div class="gw-proyecto-row" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;padding:22px 20px;background:#fff;border-radius:13px;box-shadow:0 3px 10px #f0f3fa;">
                <div>
                    <div style="font-size:1.18rem;font-weight:bold;color:#1976d2;"><?php echo esc_html($proy->post_title); ?></div>
                    <!-- Puedes agregar más info aquí si tu CPT proyecto tiene -->
                </div>
                <form method="get" action="" style="margin:0;">
                    <input type="hidden" name="proyecto_id" value="<?php echo esc_attr($proy->ID); ?>">
                    <button type="submit" class="gw-proyecto-btn">
                        Seleccionar
                    </button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}

// === PASO 7: Capacitaciones ===
function gw_step_7_capacitacion($user_id) {
    // === FLUJO IGUAL AL DE CHARLAS, PERO PARA CAPACITACIONES ===
    $current_user = wp_get_current_user();
    $is_admin = in_array('administrator', $current_user->roles);
    $forzar_menu = isset($_GET['paso7_menu']) && $_GET['paso7_menu'] == 1;

    // Si ya está completo, redirige
    if (get_user_meta($user_id, 'gw_step7_completo', true)) {
        return '<meta http-equiv="refresh" content="0">';
    }

    // Obtener el proyecto del usuario (para filtrar capacitaciones)
    $user_pais = get_user_meta($user_id, 'gw_pais', true);
    $proyecto_id = get_user_meta($user_id, 'gw_proyecto_id', true);

    // Obtener todas las capacitaciones disponibles (filtrar por proyecto y país)
    $capacitaciones = get_posts([
        'post_type' => 'capacitacion',
        'post_status' => 'publish',
        'numberposts' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
    ]);
    // Filtrar por proyecto y país
    $capacitaciones_filtradas = [];
    foreach ($capacitaciones as $cap) {
        $cap_proyecto_id = get_post_meta($cap->ID, '_gw_proyecto_id', true);
        if ($proyecto_id && $cap_proyecto_id && intval($cap_proyecto_id) !== intval($proyecto_id)) continue;
        $pais_id = get_post_meta($cap->ID, '_gw_pais_id', true);
        if ($pais_id && $user_pais) {
            $pais_post = get_post($pais_id);
            if ($pais_post && $pais_post->post_title && strtolower(trim($pais_post->post_title)) !== strtolower(trim($user_pais))) {
                continue;
            }
        }
        $capacitaciones_filtradas[] = $cap;
    }

    // Estado del usuario
    $capacitaciones_completadas = get_user_meta($user_id, 'gw_capacitaciones_completadas', true);
    if (!is_array($capacitaciones_completadas)) $capacitaciones_completadas = [];
    $capacitacion_agendada = get_user_meta($user_id, 'gw_capacitacion_agendada', true);
    if (!is_array($capacitacion_agendada)) $capacitacion_agendada = null;

    // --- FLUJO DE REGISTRO A SESIÓN DE CAPACITACIÓN (PRIORITARIO) ---
    if (
        isset($_GET['capacitacion_id']) &&
        isset($_GET['cap_idx']) &&
        $_GET['capacitacion_id'] !== '' &&
        $_GET['cap_idx'] !== ''
    ) {
        // Página de confirmación y registro a la sesión
        $capacitacion_id = intval($_GET['capacitacion_id']);
        $cap_idx = intval($_GET['cap_idx']);
        $capacitacion = null;
        foreach ($capacitaciones_filtradas as $cap) {
            if ($cap->ID == $capacitacion_id) {
                $capacitacion = $cap;
                break;
            }
        }
        if (!$capacitacion) {
            return '<div class="notice notice-error">La capacitación seleccionada no existe.<br><a href="'.esc_url(site_url('/index.php/portal-voluntario/?paso7_menu=1')).'" class="gw-charla-my-btn">MI CUENTA</a></div>';
        }
        // Buscar la sesión exacta
        $sesiones = get_post_meta($capacitacion->ID, '_gw_sesiones', true);
        $sesion = null;
        if (is_array($sesiones)) {
            foreach ($sesiones as $idx => $ses) {
                if ($idx == $cap_idx) {
                    $sesion = $ses;
                    break;
                }
            }
        }
        if (!$sesion) {
            return '<div class="notice notice-error">La sesión seleccionada ya no está disponible.<br><a href="'.esc_url(site_url('/index.php/portal-voluntario/?paso7_menu=1')).'" class="gw-charla-my-btn">MI CUENTA</a></div>';
        }
        $error = '';
        $success = false;
        // Procesar registro (botón "Registrarme")
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gw_capacitacion_registrarme_nonce']) && wp_verify_nonce($_POST['gw_capacitacion_registrarme_nonce'], 'gw_capacitacion_registrarme')) {
            // Validar que la sesión sigue disponible
            $ts = strtotime($sesion['fecha'].' '.$sesion['hora']);
            if ($ts > time()) {
                $capacitacion_agendada = [
                    'cap_id' => $capacitacion->ID,
                    'cap_title' => $capacitacion->post_title,
                    'modalidad' => $sesion['modalidad'],
                    'fecha' => $sesion['fecha'],
                    'hora' => $sesion['hora'],
                    'lugar' => $sesion['lugar'],
                    'enlace' => $sesion['enlace'],
                    'idx' => $cap_idx,
                ];
                update_user_meta($user_id, 'gw_capacitacion_agendada', $capacitacion_agendada);
                $success = true;
            } else {
                $error = "La sesión seleccionada ya no está disponible.";
            }
        }
        ob_start();
        ?>
        <style>
        .gw-charla-menu-box {max-width:620px;margin:30px auto;background:#fff;border-radius:18px;padding:36px 32px;box-shadow:0 4px 22px #dde8f8;}
        .gw-charla-header-flex {display: flex;justify-content: space-between;align-items: center;margin-bottom: 18px;}
        .gw-charla-title { color: #ff9800; font-size: 2.2rem; font-weight: bold; }
        .gw-charla-my-btn {padding: 13px 40px;background: #27b84c;border: none;border-radius: 13px;color: #fff;font-weight: bold;font-size: 1.13rem;margin-left: 20px;box-shadow: 0 2px 8px #e3f0e9;cursor: pointer;transition: background .18s;}
        .gw-charla-my-btn:hover {background: #21903a;}
        .gw-charla-btn {padding:10px 32px;background:#fff;border:2px solid #1976d2;border-radius:9px;color:#1976d2;font-weight:bold;font-size:1.02rem;transition:.18s;cursor:pointer;}
        .gw-charla-btn:hover {background:#e3f0fe;}
        </style>
        <div class="gw-charla-menu-box">
            <div class="gw-charla-header-flex">
                <div class="gw-charla-title"><?php echo esc_html($capacitacion->post_title) . ' / OPCIÓN ' . ($cap_idx+1); ?></div>
                <a href="<?php echo esc_url(site_url('/index.php/portal-voluntario/?paso7_menu=1')); ?>" class="gw-charla-my-btn">MI CUENTA</a>
            </div>
            <?php if ($error): ?><div class="notice notice-error" style="margin-bottom:20px;"><?php echo esc_html($error); ?></div><?php endif; ?>
            <?php if ($success): ?>
                <div class="notice notice-success" style="margin-bottom:20px;">TE HAS REGISTRADO CON ÉXITO</div>
                <meta http-equiv="refresh" content="2;url=<?php echo esc_url(site_url('/index.php/portal-voluntario/?paso7_menu=1')); ?>">
            <?php else: ?>
            <div style="margin-bottom:22px;">
                <b>LUGAR:</b> <?php echo esc_html($sesion['lugar']); ?><br>
                <b>HORA:</b> <?php echo esc_html($sesion['hora']); ?><br>
                <?php if ($sesion['modalidad']=='presencial'): ?>
                    <span style="color:#1976d2;">(Presencial)</span>
                <?php else: ?>
                    <span style="color:#1976d2;">(Virtual / Google Meet)</span>
                <?php endif; ?>
            </div>
            <form method="post" style="display:inline;">
                <?php wp_nonce_field('gw_capacitacion_registrarme', 'gw_capacitacion_registrarme_nonce'); ?>
                <button type="submit" class="gw-charla-my-btn">Registrarme</button>
            </form>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    // Menú principal: mostrar todas las capacitaciones como tarjetas/fila
    if (
        !$capacitacion_agendada ||
        $forzar_menu ||
        (isset($_GET['capacitacion_menu']) && $_GET['capacitacion_menu'] == 1)
    ) {
        // Si se seleccionó una capacitación, mostrar sus sesiones
        if (isset($_GET['capacitacion_id']) && $_GET['capacitacion_id'] !== '' && !isset($_GET['cap_idx'])) {
            $capacitacion_id = intval($_GET['capacitacion_id']);
            $capacitacion = null;
            foreach ($capacitaciones_filtradas as $cap) {
                if ($cap->ID == $capacitacion_id) {
                    $capacitacion = $cap;
                    break;
                }
            }
            if (!$capacitacion) {
                return '<div class="notice notice-error">La capacitación seleccionada no existe.<br><a href="'.esc_url(site_url('/index.php/portal-voluntario/?paso7_menu=1')).'" class="gw-charla-my-btn">MI CUENTA</a></div>';
            }
            // Obtener sesiones
            $sesiones = get_post_meta($capacitacion->ID, '_gw_sesiones', true);
            $cap_sesiones = [];
            if (is_array($sesiones)) {
                foreach ($sesiones as $idx => $ses) {
                    $ts = strtotime($ses['fecha'].' '.$ses['hora']);
                    // Mostrar solo si la sesión es presente (hoy) o futura
                    if (!$ses['fecha'] || !$ses['hora'] || $ts < strtotime('today')) continue;
                    $cap_sesiones[] = [
                        'cap_id' => $capacitacion->ID,
                        'cap_title' => $capacitacion->post_title,
                        'modalidad' => $ses['modalidad'],
                        'fecha' => $ses['fecha'],
                        'hora' => $ses['hora'],
                        'lugar' => $ses['lugar'],
                        'enlace' => $ses['enlace'],
                        'idx' => $idx,
                    ];
                }
            }
            ob_start();
            ?>
            <style>
            .gw-charla-header-flex {display: flex;justify-content: space-between;align-items: center;margin-bottom: 18px;}
            .gw-charla-title { color: #ff9800; font-size: 2.2rem; font-weight: bold; }
            .gw-charla-my-btn {padding: 13px 40px;background: #27b84c;border: none;border-radius: 13px;color: #fff;font-weight: bold;font-size: 1.13rem;margin-left: 20px;box-shadow: 0 2px 8px #e3f0e9;cursor: pointer;transition: background .18s;}
            .gw-charla-my-btn:hover {background: #21903a;}
            .gw-charla-btn {padding:10px 32px;background:#fff;border:2px solid #1976d2;border-radius:9px;color:#1976d2;font-weight:bold;font-size:1.02rem;transition:.18s;cursor:pointer;}
            .gw-charla-btn:hover {background:#e3f0fe;}
            .gw-charla-sesion-row {display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;padding-bottom:12px;border-bottom:1px solid #efefef;}
            .gw-charla-sesion-row:last-child {border-bottom:none;}
            </style>
            <div class="gw-charla-header-flex">
                <div class="gw-charla-title"><?php echo esc_html($capacitacion->post_title); ?></div>
                <a href="<?php echo esc_url(site_url('/index.php/portal-voluntario/?paso7_menu=1')); ?>" class="gw-charla-my-btn">MI CUENTA</a>
            </div>
            <?php if (empty($cap_sesiones)): ?>
                <div class="notice notice-error">Actualmente no hay sesiones disponibles para registro.</div>
            <?php else: ?>
                <?php foreach ($cap_sesiones as $idx => $ses): ?>
                    <div class="gw-charla-sesion-row">
                        <span>
                            <b>OPCIÓN <?php echo ($idx+1); ?>:</b>
                            <?php echo esc_html($ses['lugar']); ?>
                            <span style="color:#1976d2;font-weight:normal;">
                                (<?php echo $ses['modalidad']=='virtual' ? "Virtual / Google Meet" : "Presencial"; ?>)
                            </span>
                            <span style="margin-left:16px;font-weight:normal;color:#888;">(<?php echo date('d/m/Y', strtotime($ses['fecha'])).' '.substr($ses['hora'],0,5); ?>)</span>
                        </span>
                        <form method="get" action="" style="margin:0;">
                            <input type="hidden" name="capacitacion_id" value="<?php echo esc_attr($ses['cap_id']); ?>">
                            <input type="hidden" name="cap_idx" value="<?php echo esc_attr($ses['idx']); ?>">
                            <button type="submit" class="gw-charla-btn">Seleccionar</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php
            return ob_get_clean();
        }
        // Menú principal de capacitaciones (si no hay capacitacion_id)
        ob_start();
        ?>
        <style>
        .gw-charla-header-flex {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
        }
        .gw-charla-title { color: #ff9800; font-size: 2.2rem; font-weight: bold; }
        .gw-charla-my-btn {
            padding: 13px 40px;
            background: #27b84c;
            border: none;
            border-radius: 13px;
            color: #fff;
            font-weight: bold;
            font-size: 1.13rem;
            margin-left: 20px;
            box-shadow: 0 2px 8px #e3f0e9;
            cursor: pointer;
            transition: background .18s;
        }
        .gw-charla-my-btn:hover { background: #21903a; }
        .gw-capacitacion-row {display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;padding:22px 20px;background:#fff;border-radius:13px;box-shadow:0 3px 10px #f0f3fa;}
        .gw-charla-btn {padding:10px 32px;background:#fff;border:2px solid #1976d2;border-radius:9px;color:#1976d2;font-weight:bold;font-size:1.02rem;transition:.18s;cursor:pointer;}
        .gw-charla-btn:hover {background:#e3f0fe;}
        /* Tooltip styles for disabled button */
        .gw-btn-tooltip[disabled][title] {
            position: relative;
        }
        .gw-btn-tooltip[disabled][title]:hover::after {
            content: attr(title);
            position: absolute;
            left: 50%;
            bottom: 115%;
            transform: translateX(-50%);
            background: #222;
            color: #fff;
            padding: 7px 13px;
            border-radius: 7px;
            font-size: 0.97rem;
            white-space: nowrap;
            box-shadow: 0 2px 8px #0002;
            z-index: 99;
            opacity: 1;
            pointer-events: none;
        }
        .gw-btn-tooltip[disabled][title]::after {
            content: "";
            display: none;
        }
        .gw-btn-tooltip[disabled][title]:hover::after {
            display: block;
        }
        </style>
        <div class="gw-charla-header-flex" style="margin-bottom:32px;">
            <div class="gw-charla-title">Capacitaciones</div>
            <a href="<?php echo esc_url(site_url('/index.php/portal-voluntario/?paso7_menu=1')); ?>" class="gw-charla-my-btn" style="margin-left:0;">MI CUENTA</a>
        </div>
        <?php if (empty($capacitaciones_filtradas)): ?>
            <div class="notice notice-error">No hay capacitaciones disponibles para tu proyecto o país.</div>
        <?php else: ?>
            <div style="max-width:700px;margin:0 auto;">
            <?php foreach ($capacitaciones_filtradas as $cap): ?>
                <div class="gw-capacitacion-row">
                    <div>
                        <div style="font-size:1.18rem;font-weight:bold;color:#1976d2;"><?php echo esc_html($cap->post_title); ?></div>
                    </div>
                    <?php if ($capacitacion_agendada && $capacitacion_agendada['cap_id'] != $cap->ID): ?>
                        <button class="gw-charla-btn gw-btn-tooltip" disabled style="background:#eee;color:#aaa;border-color:#ccc;cursor:not-allowed;" title="Debes finalizar tu capacitación actual para seleccionar otra">Seleccionar</button>
                    <?php else: ?>
                        <form method="get" action="" style="margin:0;">
                            <input type="hidden" name="capacitacion_id" value="<?php echo esc_attr($cap->ID); ?>">
                            <button type="submit" class="gw-charla-btn">Seleccionar</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }

    // SI YA TIENE CAPACITACION AGENDADA, MOSTRAR RECORDATORIO Y BOTÓN DE ASISTENCIA
    if ($capacitacion_agendada) {
        $ts = strtotime($capacitacion_agendada['fecha'].' '.$capacitacion_agendada['hora']);
        $ya_ocurrio = $ts <= time();
        $completada = false;
        foreach ($capacitaciones_completadas as $comp) {
            if ($comp['cap_id'] == $capacitacion_agendada['cap_id'] && $comp['idx'] == $capacitacion_agendada['idx']) {
                $completada = true;
                break;
            }
        }
        $current_user = wp_get_current_user();
        $is_admin = in_array('administrator', $current_user->roles);
        $testing_mode = defined('GW_TESTING_MODE');
        ob_start();
        ?>
        <style>
        .gw-charla-menu-box {max-width:620px;margin:30px auto;background:#fff;border-radius:18px;padding:36px 32px;box-shadow:0 4px 22px #dde8f8;position:relative;}
        .gw-charla-header-flex {display: flex;justify-content: space-between;align-items: center;margin-bottom: 18px;}
        .gw-charla-title { color: #ff9800; font-size: 2.2rem; font-weight: bold; }
        .gw-charla-my-btn {padding: 13px 40px;background: #27b84c;border: none;border-radius: 13px;color: #fff;font-weight: bold;font-size: 1.13rem;margin-left: 20px;box-shadow: 0 2px 8px #e3f0e9;cursor: pointer;transition: background .18s;}
        .gw-charla-my-btn:hover {background: #21903a;}
        .gw-charla-btn {padding:10px 32px;background:#fff;border:2px solid #1976d2;border-radius:9px;color:#1976d2;font-weight:bold;font-size:1.02rem;transition:.18s;cursor:pointer;}
        .gw-charla-btn:hover {background:#e3f0fe;}
        .gw-cap-cancel-btn {padding: 13px 30px;background:#e53935;color:#fff;border:none;border-radius:10px;font-weight:bold;font-size:1.01rem;box-shadow:0 2px 8px #f8d8d8;cursor:pointer;transition:background .15s;margin-left:12px;}
        .gw-cap-cancel-btn:hover { background:#b71c1c;}
        .gw-cap-float-btn {
            display: flex;
            align-items: center;
            position: absolute;
            bottom: 16px;
            right: 18px;
            background: #1976d2;
            color: #fff;
            border: none;
            border-radius: 18px;
            padding: 8px 18px;
            font-size: 0.98rem;
            font-weight: bold;
            box-shadow: 0 2px 10px #bcd4ff;
            cursor: pointer;
            z-index: 10;
            opacity: 0.90;
        }
        .gw-cap-float-btn:hover { background: #1251a2;}
        </style>
        <div class="gw-charla-menu-box">
            <div class="gw-charla-header-flex">
                <div class="gw-charla-title"><?php echo esc_html($capacitacion_agendada['cap_title']); ?></div>
                <a href="<?php echo esc_url(site_url('/index.php/portal-voluntario/?paso7_menu=1')); ?>" class="gw-charla-my-btn">MI CUENTA</a>
            </div>
            <?php if ($completada): ?>
                <div class="notice notice-success" style="margin-bottom:20px;">¡Has completado esta capacitación!</div>
                <form method="post" style="display:inline;">
                    <button type="submit" name="gw_capacitacion_volver_menu" class="gw-charla-btn">Volver al menú de capacitaciones</button>
                </form>
                <form method="post" style="display:inline;">
                    <button type="submit" name="gw_capacitacion_cancelar" class="gw-cap-cancel-btn">Cancelar capacitación</button>
                </form>
            <?php else: ?>
                <div style="font-size:1rem;color:#333;margin-bottom:6px;">TE RECORDAMOS QUE TE REGISTRASTE A</div>
                <div style="color:#ff9800;font-size:2.2rem;font-weight:bold;margin-bottom:12px;">
                <?php echo esc_html($capacitacion_agendada['cap_title']) . ' / OPCIÓN ' . (isset($capacitacion_agendada['idx']) ? ($capacitacion_agendada['idx']+1) : ''); ?>
                </div>
                <div style="font-size:1rem;color:#333;margin-bottom:22px; line-height:1.4;">
                    Hora: <?php echo esc_html($capacitacion_agendada['hora']); ?><br>
                    <?php if ($capacitacion_agendada['modalidad']=='presencial'): ?>
                        Lugar: <?php echo esc_html($capacitacion_agendada['lugar']); ?>
                    <?php else: ?>
                        Enlace: <?php if ($ya_ocurrio && $capacitacion_agendada['enlace']): ?><a href="<?php echo esc_url($capacitacion_agendada['enlace']); ?>" target="_blank"><?php echo esc_html($capacitacion_agendada['enlace']); ?></a><?php else: ?><span style="color:#888;">(Se habilitará al llegar la hora)</span><?php endif; ?>
                    <?php endif; ?>
                </div>
                <form method="post" style="display:inline;margin-bottom:8px;">
                    <?php if ($ya_ocurrio): ?>
                        <?php wp_nonce_field('gw_capacitacion_asistencia', 'gw_capacitacion_asistencia_nonce'); ?>
                        <?php if ($capacitacion_agendada['modalidad']=='virtual' && $capacitacion_agendada['enlace']): ?>
                            <a href="<?php echo esc_url($capacitacion_agendada['enlace']); ?>" target="_blank" class="gw-charla-btn" style="margin-right:10px;">Ir a capacitación</a>
                        <?php endif; ?>
                        <button type="submit" class="gw-charla-my-btn">Marcar como completada</button>
                    <?php else: ?>
                        <div style="color:#888;">La capacitación estará disponible cuando llegue la hora.</div>
                    <?php endif; ?>
                </form>
                <form method="post" style="display:inline;">
                    <button type="submit" name="gw_capacitacion_volver_menu" class="gw-charla-btn">Volver al menú de capacitaciones</button>
                </form>
                <form method="post" style="display:inline;">
                    <button type="submit" name="gw_capacitacion_cancelar" class="gw-cap-cancel-btn">Cancelar capacitación</button>
                </form>
            <?php endif; ?>
            <?php
            // SOLO TEST/ADMIN: BOTÓN FLOTANTE PARA FORZAR COMPLETAR
            if ($is_admin || $testing_mode):
            ?>
            <!--
            ================== GRANDE: ELIMINAR ESTE BOTÓN ANTES DE PRODUCCIÓN ==================
            -->
            <form method="post" style="margin:0;padding:0;">
                <button type="submit" name="gw_capacitacion_forzar_completar" class="gw-cap-float-btn">Forzar completar capacitación</button>
            </form>
            <!--
            ================== FIN BOTÓN TEST/ADMIN ==================
            -->
            <?php endif; ?>
        </div>
        <?php
        // Procesar marcar como completada
        if (
            $_SERVER['REQUEST_METHOD'] === 'POST'
            && isset($_POST['gw_capacitacion_asistencia_nonce'])
            && wp_verify_nonce($_POST['gw_capacitacion_asistencia_nonce'], 'gw_capacitacion_asistencia')
            && $ya_ocurrio
        ) {
            // Marcar como completada
            $capacitaciones_completadas[] = $capacitacion_agendada;
            update_user_meta($user_id, 'gw_capacitaciones_completadas', $capacitaciones_completadas);
            delete_user_meta($user_id, 'gw_capacitacion_agendada');
            // Si ya no hay más pendientes, marcar paso 7 como completo (opcional)
            $total = 0;
            foreach ($capacitaciones_filtradas as $cap) {
                $sesiones = get_post_meta($cap->ID, '_gw_sesiones', true);
                if (is_array($sesiones)) {
                    foreach ($sesiones as $idx => $ses) {
                        $total++;
                    }
                }
            }
            if (count($capacitaciones_completadas) >= $total && $total > 0) {
                update_user_meta($user_id, 'gw_step7_completo', 1);
            }
            wp_safe_redirect(site_url('/index.php/portal-voluntario/?paso7_menu=1'));
            exit;
        }
        // Procesar volver al menú
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gw_capacitacion_volver_menu'])) {
            delete_user_meta($user_id, 'gw_capacitacion_agendada');
            wp_safe_redirect(site_url('/index.php/portal-voluntario/?paso7_menu=1'));
            exit;
        }
        // Procesar cancelar capacitación
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gw_capacitacion_cancelar'])) {
            delete_user_meta($user_id, 'gw_capacitacion_agendada');
            wp_safe_redirect(site_url('/index.php/portal-voluntario/?paso7_menu=1'));
            exit;
        }
        // Procesar botón flotante "Forzar completar capacitación"
        /*
        ================== GRANDE: ELIMINAR ESTE BLOQUE ANTES DE PRODUCCIÓN ==================
        */
        if (
            $_SERVER['REQUEST_METHOD'] === 'POST'
            && isset($_POST['gw_capacitacion_forzar_completar'])
            && ($is_admin || $testing_mode)
        ) {
            // Simula la finalización de la capacitación:
            // Agrega la capacitación agendada al array de completadas, elimina meta de agendada y fuerza el paso 8
            $capacitaciones_completadas[] = $capacitacion_agendada;
            update_user_meta($user_id, 'gw_capacitaciones_completadas', $capacitaciones_completadas);
            delete_user_meta($user_id, 'gw_capacitacion_agendada');
            update_user_meta($user_id, 'gw_step7_completo', 1);
            // Redirige al paso 8
            wp_safe_redirect(site_url('/index.php/portal-voluntario/'));
            exit;
        }
        /*
        ================== FIN BLOQUE TEST/ADMIN ==================
        */
        return ob_get_clean();
    }
    // Si llega aquí, fallback: mostrar menú principal
    wp_safe_redirect(site_url('/index.php/portal-voluntario/?paso7_menu=1'));
    exit;
}

if (!defined('ABSPATH')) exit; // Seguridad: salir si se accede directamente

// Registrar Custom Post Types
add_action('init', function () {
    // CPT País
    register_post_type('pais', [
        'labels' => [
            'name' => 'Países',
            'singular_name' => 'País',
            'add_new' => 'Agregar Nuevo País',
            'add_new_item' => 'Agregar Nuevo País',
            'edit_item' => 'Editar País',
            'new_item' => 'Nuevo País',
            'view_item' => 'Ver País',
            'search_items' => 'Buscar País',
            'not_found' => 'No se encontraron países',
            'not_found_in_trash' => 'No se encontraron países en la papelera',
            'all_items' => 'Todos los Países',
            'menu_name' => 'Países',
        ],
        'public' => true,
        'has_archive' => true,
        'menu_icon' => 'dashicons-admin-site',
        'supports' => ['title'],
        'show_ui' => true,
        'show_in_menu' => true,
        'show_in_rest' => true // para Gutenberg y REST API
    ]);

    // CPT Capacitación
    register_post_type('capacitacion', [
        'labels' => [
            'name' => 'Capacitaciones',
            'singular_name' => 'Capacitación',
            'add_new' => 'Agregar Nueva Capacitación',
            'add_new_item' => 'Agregar Nueva Capacitación',
            'edit_item' => 'Editar Capacitación',
            'new_item' => 'Nueva Capacitación',
            'view_item' => 'Ver Capacitación',
            'search_items' => 'Buscar Capacitación',
            'not_found' => 'No se encontraron capacitaciones',
            'not_found_in_trash' => 'No se encontraron capacitaciones en la papelera',
            'all_items' => 'Todas las Capacitaciones',
            'menu_name' => 'Capacitaciones',
        ],
        'public' => true,
        'has_archive' => true,
        'menu_icon' => 'dashicons-welcome-learn-more',
        'supports' => ['title'],
        'show_ui' => true,
        'show_in_menu' => true, // Mostrar en el menú de administración
        'show_in_rest'   => true
    ]);

    // CPT Charla 
    register_post_type('charla', [
        'labels' => [
            'name' => 'Charlas',
            'singular_name' => 'Charla',
            'add_new' => 'Agregar Nueva Charla',
            'add_new_item' => 'Agregar Nueva Charla',
            'edit_item' => 'Editar Charla',
            'new_item' => 'Nueva Charla',
            'view_item' => 'Ver Charla',
            'search_items' => 'Buscar Charla',
            'not_found' => 'No se encontraron charlas',
            'not_found_in_trash' => 'No se encontraron charlas en la papelera',
            'all_items' => 'Todas las Charlas',
            'menu_name' => 'Charlas',
        ],
        'public' => true,
        'has_archive' => true,
        'menu_icon' => 'dashicons-microphone',
        'supports' => ['title'],
        'show_ui' => true,
        'show_in_menu' => true,
        'show_in_rest' => true
    ]);

    // CPT Escuela
    register_post_type('escuela', [
        'labels' => [
            'name' => 'Escuelas',
            'singular_name' => 'Escuela',
            'add_new' => 'Agregar Nueva Escuela',
            'add_new_item' => 'Agregar Nueva Escuela',
            'edit_item' => 'Editar Escuela',
            'new_item' => 'Nueva Escuela',
            'view_item' => 'Ver Escuela',
            'search_items' => 'Buscar Escuela',
            'not_found' => 'No se encontraron escuelas',
            'not_found_in_trash' => 'No se encontraron escuelas en la papelera',
            'all_items' => 'Todas las Escuelas',
            'menu_name' => 'Escuelas',
        ],
        'public' => true,
        'has_archive' => true,
        'menu_icon' => 'dashicons-welcome-learn-more',
        'supports' => ['title'],
        'show_ui' => true,
        'show_in_menu' => true,
        'show_in_rest' => true // Para Gutenberg
    ]);
});

// Metabox para horarios de la escuela
add_action('add_meta_boxes', function() {
    add_meta_box(
        'gw_escuela_horarios',
        'Horarios disponibles',
        function($post) {
            $horarios = get_post_meta($post->ID, '_gw_escuela_horarios', true);
            if (!is_array($horarios)) $horarios = [];
            if (empty($horarios)) $horarios = [ [ 'dia'=>'', 'hora'=>'' ] ];
            ?>
            <div id="gw-escuela-horarios-list">
                <?php foreach ($horarios as $idx => $h): ?>
                    <div class="gw-escuela-horario-block" style="margin-bottom:12px;padding:8px;border:1px solid #eee;">
                        <strong>Horario <?php echo ($idx+1); ?></strong>
                        <label style="margin-left:12px;">
                            Día:
                            <input type="text" name="gw_escuela_dia[]" value="<?php echo esc_attr($h['dia']); ?>" style="width:120px;">
                        </label>
                        <label style="margin-left:12px;">
                            Hora:
                            <input type="time" name="gw_escuela_hora[]" value="<?php echo esc_attr($h['hora']); ?>">
                        </label>
                        <button type="button" class="gw-remove-escuela-horario-btn" onclick="gwRemoveEscuelaHorario(this)" style="color:red;margin-left:12px;">Eliminar</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" id="gw-add-escuela-horario-btn" style="margin-top:10px;">Agregar horario</button>
            <script>
                function gwRemoveEscuelaHorario(btn) {
                    var block = btn.closest('.gw-escuela-horario-block');
                    if(document.querySelectorAll('.gw-escuela-horario-block').length > 1) {
                        block.parentNode.removeChild(block);
                    }
                }
                document.addEventListener('DOMContentLoaded',function(){
                    document.getElementById('gw-add-escuela-horario-btn').addEventListener('click',function(){
                        var list = document.getElementById('gw-escuela-horarios-list');
                        var idx = list.children.length + 1;
                        var div = document.createElement('div');
                        div.className = 'gw-escuela-horario-block';
                        div.style = "margin-bottom:12px;padding:8px;border:1px solid #eee;";
                        div.innerHTML = `
                            <strong>Horario `+idx+`</strong>
                            <label style="margin-left:12px;">
                                Día:
                                <input type="text" name="gw_escuela_dia[]" value="" style="width:120px;">
                            </label>
                            <label style="margin-left:12px;">
                                Hora:
                                <input type="time" name="gw_escuela_hora[]" value="">
                            </label>
                            <button type="button" class="gw-remove-escuela-horario-btn" onclick="gwRemoveEscuelaHorario(this)" style="color:red;margin-left:12px;">Eliminar</button>
                        `;
                        list.appendChild(div);
                    });
                });
            </script>
            <?php
        },
        'escuela',
        'normal'
    );
});

// Guardar horarios al guardar la escuela
add_action('save_post_escuela', function($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;
    $dias = isset($_POST['gw_escuela_dia']) ? $_POST['gw_escuela_dia'] : [];
    $horas = isset($_POST['gw_escuela_hora']) ? $_POST['gw_escuela_hora'] : [];
    $horarios = [];
    $count = max(count($dias), count($horas));
    for ($i = 0; $i < $count; $i++) {
        // Limpiar horarios vacíos
        $dia = isset($dias[$i]) ? sanitize_text_field($dias[$i]) : '';
        $hora = isset($horas[$i]) ? sanitize_text_field($horas[$i]) : '';
        if ($dia === '' && $hora === '') continue;
        $horarios[] = [
            'dia' => $dia,
            'hora' => $hora,
        ];
    }
    update_post_meta($post_id, '_gw_escuela_horarios', $horarios);
}, 10, 1);
// Guardar sesiones de la charla al guardar el CPT charla
add_action('save_post_charla', function($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    // Validar que los campos existen
    $modalidades = isset($_POST['gw_sesion_modalidad']) ? $_POST['gw_sesion_modalidad'] : [];
    $fechas      = isset($_POST['gw_sesion_fecha']) ? $_POST['gw_sesion_fecha'] : [];
    $horas       = isset($_POST['gw_sesion_hora']) ? $_POST['gw_sesion_hora'] : [];
    $lugares     = isset($_POST['gw_sesion_lugar']) ? $_POST['gw_sesion_lugar'] : [];
    $enlaces     = isset($_POST['gw_sesion_enlace']) ? $_POST['gw_sesion_enlace'] : [];

    // Asegurar que todos son arrays y del mismo tamaño
    $total = max(
        is_array($modalidades) ? count($modalidades) : 0,
        is_array($fechas) ? count($fechas) : 0,
        is_array($horas) ? count($horas) : 0,
        is_array($lugares) ? count($lugares) : 0,
        is_array($enlaces) ? count($enlaces) : 0
    );

    $sesiones = [];
    for ($i = 0; $i < $total; $i++) {
        // Leer y limpiar cada campo
        $modalidad = isset($modalidades[$i]) ? sanitize_text_field($modalidades[$i]) : '';
        $fecha = isset($fechas[$i]) ? sanitize_text_field($fechas[$i]) : '';
        $hora  = isset($horas[$i]) ? sanitize_text_field($horas[$i]) : '';
        $lugar = isset($lugares[$i]) ? sanitize_text_field($lugares[$i]) : '';
        $enlace = isset($enlaces[$i]) ? sanitize_text_field($enlaces[$i]) : '';

        // Limpiar sesiones vacías (sin fecha u hora)
        if (empty($fecha) || empty($hora)) continue;
        // Modalidad debe ser válida
        if ($modalidad !== 'virtual' && $modalidad !== 'presencial') $modalidad = 'virtual';
        // Si es presencial, limpiar enlace
        if ($modalidad === 'presencial') $enlace = '';
        // Si es virtual, limpiar lugar
        if ($modalidad === 'virtual') $lugar = '';

        $sesiones[] = [
            'modalidad' => $modalidad,
            'fecha'     => $fecha,
            'hora'      => $hora,
            'lugar'     => $lugar,
            'enlace'    => $enlace,
        ];
    }
    // Guardar o limpiar meta
    if (!empty($sesiones)) {
        update_post_meta($post_id, '_gw_fechas_horas', $sesiones);
    } else {
        delete_post_meta($post_id, '_gw_fechas_horas');
    }
}, 10, 1);

// Metabox para Detalles de Charla (fecha, hora, modalidad, sesiones)
add_action('add_meta_boxes', function() {
    add_meta_box(
        'gw_charla_detalles',
        'Detalles de la Charla',
        'gw_charla_detalles_metabox_callback',
        'charla',
        'normal'
    );
});

function gw_charla_detalles_metabox_callback($post) {
    if (!is_admin()) return;
    // Sesiones (repeater: fecha, hora, modalidad, lugar, enlace)
    $fechas_horas = get_post_meta($post->ID, '_gw_fechas_horas', true);
    if (!is_array($fechas_horas)) $fechas_horas = [];
    if (empty($fechas_horas)) {
        $fechas_horas = [ [ 'modalidad'=>'virtual', 'fecha'=>'', 'hora'=>'', 'lugar'=>'', 'enlace'=>'' ] ];
    }
    ?>
    <strong>Sesiones de la charla</strong>
    <div id="gw-charla-sesiones-list">
    <?php foreach ($fechas_horas as $idx => $s): ?>
        <div class="gw-charla-sesion-block" style="border:1px solid #e2e2e2;padding:10px 12px;margin-bottom:10px;border-radius:6px;position:relative;">
            <strong>Sesión <?php echo ($idx+1); ?></strong>
            <button type="button" class="gw-remove-charla-sesion-btn" style="float:right;color:#b71c1c;background:none;border:none;font-size:1.1em;" onclick="gwRemoveCharlaSesion(this)">✖</button>
            <div style="margin-top:7px;">
                <label>
                    Modalidad:
                    <select name="gw_sesion_modalidad[]" class="gw-charla-sesion-modalidad" onchange="gwCharlaSesionModalidadChange(this)">
                        <option value="virtual" <?php selected($s['modalidad'],'virtual'); ?>>Virtual</option>
                        <option value="presencial" <?php selected($s['modalidad'],'presencial'); ?>>Presencial</option>
                    </select>
                </label>
                <label style="margin-left:15px;">
                    Fecha:
                    <input type="date" name="gw_sesion_fecha[]" value="<?php echo esc_attr($s['fecha']); ?>">
                </label>
                <label style="margin-left:15px;">
                    Hora:
                    <input type="time" name="gw_sesion_hora[]" value="<?php echo esc_attr($s['hora']); ?>">
                </label>
            </div>
            <div style="margin-top:7px;">
                <span class="gw-charla-sesion-lugar-field" style="<?php if($s['modalidad']!='presencial')echo'display:none;'; ?>">
                    <label>
                        Lugar:
                        <input type="text" name="gw_sesion_lugar[]" value="<?php echo esc_attr($s['lugar']); ?>" style="width:70%;">
                    </label>
                </span>
                <span class="gw-charla-sesion-enlace-field" style="<?php if($s['modalidad']!='virtual')echo'display:none;'; ?>">
                    <label>
                        Enlace:
                        <input type="text" name="gw_sesion_enlace[]" value="<?php echo esc_attr($s['enlace']); ?>" style="width:70%;" placeholder="Enlace Meet/Zoom">
                    </label>
                </span>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
    <button type="button" id="gw-add-charla-sesion-btn" style="margin-top:8px;background:#1976d2;color:#fff;padding:6px 18px;border:none;border-radius:4px;">Agregar sesión</button>
    <script>
    function gwRemoveCharlaSesion(btn) {
        var block = btn.closest('.gw-charla-sesion-block');
        if(document.querySelectorAll('.gw-charla-sesion-block').length > 1) {
            block.parentNode.removeChild(block);
        }
    }
    function gwCharlaSesionModalidadChange(sel) {
        var block = sel.closest('.gw-charla-sesion-block');
        var modalidad = sel.value;
        block.querySelector('.gw-charla-sesion-lugar-field').style.display = (modalidad=='presencial')?'inline':'none';
        block.querySelector('.gw-charla-sesion-enlace-field').style.display = (modalidad=='virtual')?'inline':'none';
    }
    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('gw-add-charla-sesion-btn').addEventListener('click', function() {
            var list = document.getElementById('gw-charla-sesiones-list');
            var idx = list.children.length + 1;
            var div = document.createElement('div');
            div.className = 'gw-charla-sesion-block';
            div.style = "border:1px solid #e2e2e2;padding:10px 12px;margin-bottom:10px;border-radius:6px;position:relative;";
            div.innerHTML = `
                <strong>Sesión `+idx+`</strong>
                <button type="button" class="gw-remove-charla-sesion-btn" style="float:right;color:#b71c1c;background:none;border:none;font-size:1.1em;" onclick="gwRemoveCharlaSesion(this)">✖</button>
                <div style="margin-top:7px;">
                    <label>
                        Modalidad:
                        <select name="gw_sesion_modalidad[]" class="gw-charla-sesion-modalidad" onchange="gwCharlaSesionModalidadChange(this)">
                            <option value="virtual">Virtual</option>
                            <option value="presencial">Presencial</option>
                        </select>
                    </label>
                    <label style="margin-left:15px;">
                        Fecha:
                        <input type="date" name="gw_sesion_fecha[]" value="">
                    </label>
                    <label style="margin-left:15px;">
                        Hora:
                        <input type="time" name="gw_sesion_hora[]" value="">
                    </label>
                </div>
                <div style="margin-top:7px;">
                    <span class="gw-charla-sesion-lugar-field" style="display:none;">
                        <label>
                            Lugar:
                            <input type="text" name="gw_sesion_lugar[]" value="" style="width:70%;">
                        </label>
                    </span>
                    <span class="gw-charla-sesion-enlace-field" style="display:inline;">
                        <label>
                            Enlace:
                            <input type="text" name="gw_sesion_enlace[]" value="" style="width:70%;" placeholder="Enlace Meet/Zoom">
                        </label>
                    </span>
                </div>
            `;
            list.appendChild(div);
        });
    });
    </script>
    <?php
}
// === PASO 8: Subida de documentos y selección de escuela ===
function gw_step_8_documentos($user_id) {
    // Verificar si ya seleccionó escuela y horario
    $escuela_id = get_user_meta($user_id, 'gw_escuela_seleccionada', true);
    $horario = get_user_meta($user_id, 'gw_escuela_horario', true);

    // Procesar selección nueva
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['escuela_id']) && isset($_POST['horario_idx']) && check_admin_referer('gw_step8_seleccion', 'gw_step8_nonce')) {
        $escuela_id = intval($_POST['escuela_id']);
        $horario_idx = intval($_POST['horario_idx']);

        // Obtener la escuela y horario seleccionados
        $escuela = get_post($escuela_id);
        $horarios = get_post_meta($escuela_id, '_gw_escuela_horarios', true);
        if (!is_array($horarios)) $horarios = [];

        if ($escuela && isset($horarios[$horario_idx])) {
            update_user_meta($user_id, 'gw_escuela_seleccionada', $escuela_id);
            update_user_meta($user_id, 'gw_escuela_horario', $horarios[$horario_idx]);
            // Refrescar para evitar repost
            wp_safe_redirect(site_url('/index.php/portal-voluntario/'));
            exit;
        }
    }

    ob_start();
    ?>
    <div style="margin-bottom:24px;">
        <a href="<?php echo esc_url( site_url('/index.php/portal-voluntario/?paso7_menu=1') ); ?>"
           class="gw-charla-my-btn" style="background:#1976d2;">
            &larr; Regresar a capacitaciones
        </a>
    </div>
    <?php
    if ($escuela_id && $horario) {
        // Mostrar resumen de selección
        $escuela = get_post($escuela_id);
        echo '<div class="notice notice-success" style="margin-bottom:24px;"><b>¡Escuela seleccionada!</b><br>';
        echo 'Escuela: <b>' . esc_html($escuela ? $escuela->post_title : 'Escuela eliminada') . '</b><br>';
        echo 'Día: <b>' . esc_html($horario['dia']) . '</b> | Hora: <b>' . esc_html($horario['hora']) . '</b>';
        echo '</div>';
        // Botón para cambiar de escuela/horario si lo deseas (desactívalo si no lo necesitas)
        /*
        echo '<form method="post"><button type="submit" name="cambiar_escuela" class="button">Cambiar escuela/horario</button></form>';
        if (isset($_POST['cambiar_escuela'])) {
            delete_user_meta($user_id, 'gw_escuela_seleccionada');
            delete_user_meta($user_id, 'gw_escuela_horario');
            wp_safe_redirect(site_url('/index.php/portal-voluntario/'));
            exit;
        }
        */
    } else {
        // Listar todas las escuelas y horarios
        $escuelas = get_posts([
            'post_type' => 'escuela',
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);
        if (empty($escuelas)) {
            echo '<div class="notice notice-error">No hay escuelas disponibles por el momento.</div>';
        } else {
            echo '<h3>Selecciona la escuela y horario donde vas a servir</h3>'
        .'<form method="post">';
            wp_nonce_field('gw_step8_seleccion', 'gw_step8_nonce');
            foreach ($escuelas as $esc) {
                $horarios = get_post_meta($esc->ID, '_gw_escuela_horarios', true);
                if (!is_array($horarios) || empty($horarios)) continue;
                echo '<div style="border:1px solid #e2e2e2;border-radius:10px;padding:14px 20px;margin-bottom:18px;background:#f9f9ff;">';
                echo '<strong>' . esc_html($esc->post_title) . '</strong><br><br>';
                foreach ($horarios as $idx => $h) {
                    if (!$h['dia'] && !$h['hora']) continue;
                    echo '<label style="display:block;margin-bottom:8px;">';
                    echo '<input type="radio" name="escuela_id" value="'.esc_attr($esc->ID).'" required>';
                    echo ' Día: <b>'.esc_html($h['dia']).'</b> | Hora: <b>'.esc_html($h['hora']).'</b>';
                    echo ' <button type="submit" name="horario_idx" value="'.esc_attr($idx).'" class="button button-primary" style="margin-left:15px;">Seleccionar</button>';
                    echo '</label>';
                }
                echo '</div>';
            }
            echo '</form>';
        }
    }

    // ------ SUBIDA DE DOCUMENTOS (DUI/ID) -------
global $wpdb;
$docs = $wpdb->get_row( $wpdb->prepare("SELECT * FROM wp_voluntario_docs WHERE user_id=%d", $user_id), ARRAY_A );
$status = $docs ? $docs['status'] : '';

if ($escuela_id && $horario && $status !== 'validado') {
    $msg = '';
    // Procesar subida
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gw_docs_nonce']) && wp_verify_nonce($_POST['gw_docs_nonce'], 'gw_docs_subida')) {
        $cons1 = isset($_POST['consentimiento1']) ? 1 : 0;
        $cons2 = isset($_POST['consentimiento2']) ? 1 : 0;

        // Archivos
        $errors = [];
           $file_names = [null, null];
                for ($i = 1; $i <= 2; $i++) {
                    if (isset($_FILES["documento_$i"]) && $_FILES["documento_$i"]['size'] > 0) {
                        $file = $_FILES["documento_$i"];
                        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    if (!in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
                        $errors[] = "Documento $i: Formato inválido ($ext)";
                continue;
            }
        // Subir a uploads
            require_once(ABSPATH . 'wp-admin/includes/file.php');
                $upload = wp_handle_upload($file, ['test_form' => false]);
            if (!empty($upload['error'])) {
                $errors[] = "Documento $i: " . $upload['error'];
            continue;
        }
        $file_names[$i-1] = $upload['url'];
    } else {
        // Si ya tenía antes, conservar (usa la columna *_url)
        if ($docs && $docs["documento_{$i}_url"]) {
            $file_names[$i-1] = $docs["documento_{$i}_url"];
        }
    }
}
        if (!$file_names[0] || !$file_names[1]) $errors[] = "Debes subir ambos documentos.";
        if (!$cons1 || !$cons2) $errors[] = "Debes aceptar ambos consentimientos.";

        if (empty($errors)) {
            // Insertar/actualizar en la tabla personalizada
                if ($docs) {
                    $wpdb->update($wpdb->prefix . 'voluntario_docs', [
                        'documento_1_url' => $file_names[0],
                        'documento_2_url' => $file_names[1],
                        'consent_1'       => $cons1,
                        'consent_2'       => $cons2,
                        'status'          => 'pendiente',
                        'updated_at'      => current_time('mysql', 1)
                    ], [ 'user_id' => $user_id ]);
                } else {
                    $wpdb->insert(
                        $wpdb->prefix . 'voluntario_docs',
                        [
                            'user_id'           => $user_id,
                            'escuela_id'        => $escuela_id,
                            'documento_1_url'   => esc_url_raw($file_names[0]),
                            'documento_2_url'   => esc_url_raw($file_names[1]),
                            'consent_1'         => $cons1,
                            'consent_2'         => $cons2,
                            'status'            => 'pendiente',
                            'fecha_subida'      => current_time('mysql', 1),
                            'fecha_revision'    => current_time('mysql', 1),
                        ],
                        [ '%d','%d','%s','%s','%d','%d','%s','%s','%s' ]
            );
    }
            $msg = '<div class="notice notice-success"><b>¡Documentos enviados! Espera a que sean validados por el coach/admin.</b></div>';
        } else {
            $msg = '<div class="notice notice-error"><b>Corrige los siguientes errores:</b><ul><li>' . implode('</li><li>', $errors) . '</li></ul></div>';
        }
    }

    // Cargar de nuevo el estado
    $docs = $wpdb->get_row( $wpdb->prepare("SELECT * FROM wp_voluntario_docs WHERE user_id=%d", $user_id), ARRAY_A );
    $status = $docs && isset($docs['status']) ? $docs['status'] : '';
    $doc1 = $docs && isset($docs['documento_1_url']) ? $docs['documento_1_url'] : '';
    $doc2 = $docs && isset($docs['documento_2_url']) ? $docs['documento_2_url'] : '';
    $cons1 = $docs && isset($docs['consent_1']) ? $docs['consent_1'] : '';
    $cons2 = $docs && isset($docs['consent_2']) ? $docs['consent_2'] : '';
    

    echo $msg;

    if ($status === 'pendiente') {
        echo '<div class="notice notice-info"><b>Documentos enviados. Espera validación del coach/admin.</b></div>';
    }

    // Si ya están validados, mostrar mensaje final
    if ($status === 'validado') {
        echo '<div class="notice notice-success"><b>¡Tus documentos han sido validados! Tu voluntariado está confirmado.</b></div>';
    } else {
        ?>
        <form method="post" enctype="multipart/form-data" style="margin-top:26px;background:#f9f9ff;padding:18px 32px;border-radius:14px;max-width:520px;">
            <?php wp_nonce_field('gw_docs_subida', 'gw_docs_nonce'); ?>
            <div style="margin-bottom:18px;">
                <label><b>Subir documento 1 (DUI/ID):</b></label><br>
                <?php if ($doc1): ?>
                    <img src="<?php echo esc_url($doc1); ?>" alt="Documento 1" style="max-width:120px;max-height:120px;display:block;margin:8px 0;">
                <?php endif; ?>
                <input type="file" name="documento_1" accept="image/*" <?php if($doc1) echo ''; ?>><br>
            </div>
            <div style="margin-bottom:18px;">
                <label><b>Subir documento 2 (DUI/ID):</b></label><br>
                <?php if ($doc2): ?>
                    <img src="<?php echo esc_url($doc2); ?>" alt="Documento 2" style="max-width:120px;max-height:120px;display:block;margin:8px 0;">
                <?php endif; ?>
                <input type="file" name="documento_2" accept="image/*" <?php if($doc2) echo ''; ?>><br>
            </div>
            <div style="margin-bottom:18px;">
                <input type="checkbox" name="consentimiento1" value="1" <?php checked($cons1,1); ?>> Acepto el consentimiento #1<br>
                <input type="checkbox" name="consentimiento2" value="1" <?php checked($cons2,1); ?>> Acepto el consentimiento #2
            </div>
            <button type="submit" class="button button-primary" <?php if ($status === 'pendiente') echo 'disabled'; ?>>Enviar</button>
        </form>
        <?php
    }
}
    return ob_get_clean();
}