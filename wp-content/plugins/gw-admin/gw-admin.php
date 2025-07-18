<?php
/**
 * Plugin Name: Glasswing Admin Manager
 * Description: Módulos avanzados de administración y gestión para ONG Glasswing.
 * Version: 1.0
 * Author: Carlos Montalvo
 */

 if (!defined('ABSPATH')) exit;

 // --- SHORTCODE PRINCIPAL ---
 add_shortcode('gw_portal_voluntario', 'gw_portal_voluntario_shortcode');
 
 function gw_portal_voluntario_shortcode() {
     if (!is_user_logged_in()) {
         return '<p>Debes iniciar sesión con tu cuenta para continuar.</p>';
     }
 
    $user_id = get_current_user_id();
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
         // Aquí ejecutas lo de guardar en la tabla de aspirantes y agendar correos.
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
    // ===== PASO 6: FLUJO DE CAPACITACIONES =====
     elseif ($current_step == 6) {
         // Aquí puedes llamar el shortcode o función del paso de capacitaciones.
         echo gw_step_6_capacitacion($user_id);
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
     // Cambia esta lógica según el meta/user fields que vayas guardando por paso completado
     if (!get_user_meta($user_id, 'gw_step1_completo', true)) return 1;
     if (!get_user_meta($user_id, 'gw_step2_completo', true)) return 2;
     if (!get_user_meta($user_id, 'gw_step3_completo', true)) return 3;
     if (!get_user_meta($user_id, 'gw_step4_completo', true)) return 4;
     if (!get_user_meta($user_id, 'gw_step5_completo', true)) return 5;
     // Paso 6 = Formulario de capacitaciones (ya existente)
     return 6;
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
    if (!is_array($charlas_asignadas)) $charlas_asignadas = [];
    $charlas_completadas = get_user_meta($user_id, 'gw_charlas_completadas', true);
    if (!is_array($charlas_completadas)) $charlas_completadas = [];

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
            // Usuario normal sin charlas pendientes: avanzar a paso 6
            wp_safe_redirect(site_url('/index.php/portal-voluntario/?paso5_menu=1'));
            exit;
        }
    }

    // --- Listar todas las charlas y sesiones ---
    $charlas = get_posts([
        'post_type' => 'charla',
        'post_status' => 'publish',
        'numberposts' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
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
                    <?php echo esc_html($agendada['charla_title']) . ' / OPCIÓN ' . ($agendada['idx']+1); ?>
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

function gw_step_6_capacitacion($user_id) {
    $forzar_menu = isset($_GET['paso6_menu']) && $_GET['paso6_menu'] == 1;
    // Cargar arrays de capacitaciones agendadas y completadas
    $capacitaciones_agendadas = get_user_meta($user_id, 'gw_capacitaciones_agendadas', true);
    if (!is_array($capacitaciones_agendadas)) $capacitaciones_agendadas = [];
    $capacitaciones_completadas = get_user_meta($user_id, 'gw_capacitaciones_completadas', true);
    if (!is_array($capacitaciones_completadas)) $capacitaciones_completadas = [];

    // ADMIN/TEST
    $current_user = wp_get_current_user();
    $is_admin = in_array('administrator', $current_user->roles);

    // --- Procesar botón CONTINUAR (ADMIN) ---
    if (
        $_SERVER['REQUEST_METHOD'] === 'POST' &&
        isset($_POST['admin_skip_step6']) &&
        ($is_admin || defined('GW_TESTING_MODE'))
    ) {
        // Buscar la primera capacitación agendada (próxima)
        if (!empty($capacitaciones_agendadas)) {
            $next_cap = $capacitaciones_agendadas[0];
            $admin_flag = 'gw_admin_cap_ready_'.$next_cap['cap_id'].'_'.$next_cap['idx'];
            update_user_meta($user_id, $admin_flag, 1);
        }
        // No marcar como completado, solo simular disponibilidad
        wp_safe_redirect(site_url('/index.php/portal-voluntario/'));
        exit;
    }

    // Botón ADMIN/TEST para regresar
    if (
        $_SERVER['REQUEST_METHOD'] === 'POST' &&
        isset($_POST['gw_capacitacion_regresar_admin_nonce']) &&
        wp_verify_nonce($_POST['gw_capacitacion_regresar_admin_nonce'], 'gw_capacitacion_regresar_admin') &&
        ($is_admin || defined('GW_TESTING_MODE'))
    ) {
        delete_user_meta($user_id, 'gw_step6_completo');
        delete_user_meta($user_id, 'gw_capacitaciones_agendadas');
        delete_user_meta($user_id, 'gw_capacitaciones_completadas');
        delete_user_meta($user_id, 'gw_capacitacion_agendada');
        delete_user_meta($user_id, 'gw_step5_completo');
        delete_user_meta($user_id, 'gw_charla_agendada');
        wp_safe_redirect(add_query_arg('paso6_menu',1,site_url('/index.php/portal-voluntario/')));
        exit;
    }

    // Si ya completó todas las capacitaciones (todas las sesiones disponibles), marcar como completo
    // Obtener todas las capacitaciones disponibles para el país del usuario
    $user_pais = get_user_meta($user_id, 'gw_pais', true);
    $capacitaciones = get_posts([
        'post_type' => 'capacitacion',
        'post_status' => 'publish',
        'numberposts' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
    ]);
    // Filtrar sesiones por país (si el CPT tiene país asignado)
    $cap_sesiones = [];
    foreach ($capacitaciones as $cap) {
        // Si el país está asignado, filtrar
        $pais_id = get_post_meta($cap->ID, '_gw_pais_id', true);
        if ($pais_id && $user_pais) {
            $pais_post = get_post($pais_id);
            if ($pais_post && $pais_post->post_title && strtolower(trim($pais_post->post_title)) !== strtolower(trim($user_pais))) {
                continue;
            }
        }
        $sesiones = get_post_meta($cap->ID, '_gw_sesiones_cap', true);
        if (!is_array($sesiones)) continue;
        foreach ($sesiones as $idx => $ses) {
            $ts = strtotime($ses['fecha'].' '.$ses['hora']);
            if (!$ses['fecha'] || !$ses['hora'] || $ts <= 0) continue;
            $cap_sesiones[] = [
                'cap_id' => $cap->ID,
                'cap_title' => $cap->post_title,
                'modalidad' => $ses['modalidad'],
                'fecha' => $ses['fecha'],
                'hora' => $ses['hora'],
                'lugar' => $ses['lugar'],
                'enlace' => $ses['enlace'],
                'idx' => $idx,
            ];
        }
    }

    // Determinar IDs únicos de sesiones disponibles
    $all_sesion_keys = [];
    foreach ($cap_sesiones as $ses) {
        $all_sesion_keys[] = $ses['cap_id'].'_'.$ses['idx'];
    }
    // Cuántas debe completar
    $total_capacitaciones = count($cap_sesiones);
    // Cuántas completadas
    $completadas_keys = [];
    foreach ($capacitaciones_completadas as $cc) {
        $completadas_keys[] = $cc['cap_id'].'_'.$cc['idx'];
    }
    // Si ya completó todas, marcar paso 6 como completo
    if ($total_capacitaciones > 0 && count($completadas_keys) >= $total_capacitaciones && !get_user_meta($user_id, 'gw_step6_completo', true)) {
        update_user_meta($user_id, 'gw_step6_completo', 1);
    }
    // Si ya está completo, mostrar mensaje de éxito y botón siguiente
    if (get_user_meta($user_id, 'gw_step6_completo', true)) {
        ob_start();
        ?>
        <div class="notice notice-success"><p>¡Has finalizado todas tus capacitaciones!</p></div>
        <a href="<?php echo site_url('/siguiente-flujo/'); ?>" class="button button-primary">Siguiente</a>
        <?php if ($is_admin || defined('GW_TESTING_MODE')): ?>
            <form method="post" style="margin-top:20px;">
                <?php wp_nonce_field('gw_capacitacion_regresar_admin', 'gw_capacitacion_regresar_admin_nonce'); ?>
                <button type="submit" name="gw_capacitacion_regresar_admin" class="gw-charla-admin-btn">REGRESAR (ADMIN)</button>
                <div style="font-size:12px;color:#1976d2;margin-top:6px;">Solo admin/testing: retrocede al menú de selección.</div>
            </form>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }

    // ---- PROCESAMIENTO DE FORMULARIOS ----
    // 1. Registrar nueva capacitación agendada
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar_capacitacion'])) {
        $cap_id = intval($_POST['cap_id']);
        $cap_idx = intval($_POST['cap_idx']);
        // Buscar la sesión exacta
        $sesion = null;
        foreach ($cap_sesiones as $ses) {
            if ($ses['cap_id'] == $cap_id && $ses['idx'] == $cap_idx) {
                $sesion = $ses;
                break;
            }
        }
        // No permitir duplicados
        $already = false;
        foreach ($capacitaciones_agendadas as $ag) {
            if ($ag['cap_id'] == $cap_id && $ag['idx'] == $cap_idx) { $already = true; break; }
        }
        foreach ($capacitaciones_completadas as $comp) {
            if ($comp['cap_id'] == $cap_id && $comp['idx'] == $cap_idx) { $already = true; break; }
        }
        if ($sesion && !$already) {
            $capacitaciones_agendadas[] = $sesion;
            update_user_meta($user_id, 'gw_capacitaciones_agendadas', $capacitaciones_agendadas);
            echo '<div class="notice notice-success"><p>¡Te has registrado con éxito en la capacitación!</p></div><meta http-equiv="refresh" content="1">';
            return;
        }
    }
    // 2. Cancelar una capacitación agendada (solo si no ha ocurrido)
    if (isset($_GET['cancelar_capacitacion'])) {
        $key = sanitize_text_field($_GET['cancelar_capacitacion']);
        $new_agendadas = [];
        foreach ($capacitaciones_agendadas as $ag) {
            if (($ag['cap_id'].'_'.$ag['idx']) !== $key) {
                $new_agendadas[] = $ag;
            }
        }
        update_user_meta($user_id, 'gw_capacitaciones_agendadas', $new_agendadas);
        echo '<div class="notice notice-warning"><p>Capacitación cancelada.</p></div><meta http-equiv="refresh" content="1">';
        return;
    }
    // 3. Marcar como completada (al hacer clic en "Ir a capacitación")
    if (
        $_SERVER['REQUEST_METHOD'] === 'POST' &&
        isset($_POST['completar_capacitacion']) &&
        isset($_POST['cap_id']) && isset($_POST['cap_idx'])
    ) {
        $cap_id = intval($_POST['cap_id']);
        $cap_idx = intval($_POST['cap_idx']);
        $key = $cap_id . '_' . $cap_idx;
        // Buscar en agendadas
        $found = null;
        foreach ($capacitaciones_agendadas as $k => $ag) {
            if ($ag['cap_id'] == $cap_id && $ag['idx'] == $cap_idx) {
                $found = $ag;
                unset($capacitaciones_agendadas[$k]);
                break;
            }
        }
        if ($found) {
            // Limpiar flag de simulación
            $admin_flag = 'gw_admin_cap_ready_' . $cap_id . '_' . $cap_idx;
            delete_user_meta($user_id, $admin_flag);
            $capacitaciones_completadas[] = $found;
            update_user_meta($user_id, 'gw_capacitaciones_agendadas', array_values($capacitaciones_agendadas));
            update_user_meta($user_id, 'gw_capacitaciones_completadas', $capacitaciones_completadas);
            // Si ya completó todas, marcar paso 6 como completo
            $completadas_keys = [];
            foreach ($capacitaciones_completadas as $cc) $completadas_keys[] = $cc['cap_id'].'_'.$cc['idx'];
            if (count($completadas_keys) >= $total_capacitaciones) {
                update_user_meta($user_id, 'gw_step6_completo', 1);
                echo '<div class="notice notice-success"><p>¡Capacitación marcada como completada! Redirigiendo…</p></div><meta http-equiv="refresh" content="1">';
                return;
            }
            echo '<div class="notice notice-success"><p>¡Capacitación marcada como completada!</p></div><meta http-equiv="refresh" content="1">';
            return;
        }
    }
    // 4. Test/ADMIN: completar capacitación directamente
    if (
        $_SERVER['REQUEST_METHOD'] === 'POST' &&
        isset($_POST['test_completar_capacitacion']) &&
        isset($_POST['cap_id']) && isset($_POST['cap_idx']) &&
        ($is_admin || defined('GW_TESTING_MODE'))
    ) {
        $cap_id = intval($_POST['cap_id']);
        $cap_idx = intval($_POST['cap_idx']);
        $key = $cap_id . '_' . $cap_idx;
        // Buscar en agendadas
        $found = null;
        foreach ($capacitaciones_agendadas as $k => $ag) {
            if ($ag['cap_id'] == $cap_id && $ag['idx'] == $cap_idx) {
                $found = $ag;
                unset($capacitaciones_agendadas[$k]);
                break;
            }
        }
        if ($found) {
            // Limpiar flag de simulación
            $admin_flag = 'gw_admin_cap_ready_' . $cap_id . '_' . $cap_idx;
            delete_user_meta($user_id, $admin_flag);
            $capacitaciones_completadas[] = $found;
            update_user_meta($user_id, 'gw_capacitaciones_agendadas', array_values($capacitaciones_agendadas));
            update_user_meta($user_id, 'gw_capacitaciones_completadas', $capacitaciones_completadas);
            $completadas_keys = [];
            foreach ($capacitaciones_completadas as $cc) $completadas_keys[] = $cc['cap_id'].'_'.$cc['idx'];
            if (count($completadas_keys) >= $total_capacitaciones) {
                update_user_meta($user_id, 'gw_step6_completo', 1);
                echo '<div class="notice notice-success"><p>¡Capacitación marcada como completada! Redirigiendo…</p></div><meta http-equiv="refresh" content="1">';
                return;
            }
            echo '<div class="notice notice-success"><p>¡Capacitación marcada como completada!</p></div><meta http-equiv="refresh" content="1">';
            return;
        }
    }

    // ---- UI PRINCIPAL ----
    $charla_css = '
    <style>
    .gw-charla-menu-box {max-width:620px;margin:30px auto;background:#fff;border-radius:18px;padding:36px 32px;box-shadow:0 4px 22px #dde8f8;}
    .gw-charla-header-flex {display: flex;justify-content: space-between;align-items: center;margin-bottom: 18px;}
    .gw-charla-title { color: #ff9800; font-size: 2.2rem; font-weight: bold; }
    .gw-charla-my-btn {padding: 13px 40px;background: #27b84c;border: none;border-radius: 13px;color: #fff;font-weight: bold;font-size: 1.13rem;margin-left: 20px;box-shadow: 0 2px 8px #e3f0e9;cursor: pointer;transition: background .18s;}
    .gw-charla-my-btn:hover {background: #21903a;}
    .gw-charla-btn {padding:10px 32px;background:#fff;border:2px solid #1976d2;border-radius:9px;color:#1976d2;font-weight:bold;font-size:1.02rem;transition:.18s;cursor:pointer;}
    .gw-charla-btn:hover {background:#e3f0fe;}
    .gw-charla-sesion-row {display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;padding-bottom:12px;border-bottom:1px solid #efefef;}
    .gw-charla-sesion-row:last-child {border-bottom:none;}
    .gw-capacitacion-agendada-block {border:1px solid #e2e2e2;padding:18px 22px;margin-bottom:16px;border-radius:12px;background:#f7f8fb;}
    .gw-capacitacion-agendada-block .estado {font-weight:bold;}
    .gw-capacitacion-agendada-block .estado.pendiente {color:#888;}
    .gw-capacitacion-agendada-block .estado.disponible {color:#1976d2;}
    .gw-capacitacion-agendada-block .estado.completada {color:#27b84c;}
    </style>
    ';
    ob_start();
    echo $charla_css;
    ?>
    <div class="gw-charla-menu-box">
        <div class="gw-charla-header-flex">
            <div class="gw-charla-title">CAPACITACIONES</div>
            <a href="<?php echo esc_url(add_query_arg('paso6_menu',1,site_url('/index.php/portal-voluntario/'))); ?>" class="gw-charla-my-btn">MI CUENTA</a>
        </div>
        <div style="margin-bottom:26px;">
            <strong>Capacitaciones agendadas:</strong>
            <?php if (empty($capacitaciones_agendadas)): ?>
                <div style="color:#888;">No tienes capacitaciones agendadas.</div>
            <?php else: ?>
                <?php foreach ($capacitaciones_agendadas as $ag): 
                    $key = $ag['cap_id'].'_'.$ag['idx'];
                    $admin_flag = 'gw_admin_cap_ready_'.$ag['cap_id'].'_'.$ag['idx'];
                    $simular_disponible = ($is_admin && get_user_meta($user_id, $admin_flag, true));
                    $ya_ocurrio = $ts <= $now || $simular_disponible;
                    ?>
                    <div class="gw-capacitacion-agendada-block">
                        <div>
                            <b><?php echo esc_html($ag['cap_title']); ?></b>
                            <?php if ($ag['modalidad']=='presencial'): ?>
                                <span style="margin-left:8px;color:#1976d2;">(Presencial)</span>
                            <?php else: ?>
                                <span style="margin-left:8px;color:#1976d2;">(Virtual/Google Meet)</span>
                            <?php endif; ?>
                        </div>
                        <div>Fecha: <b><?php echo date('d/m/Y', strtotime($ag['fecha'])); ?></b> &nbsp; Hora: <b><?php echo substr($ag['hora'],0,5); ?></b></div>
                        <?php if ($ag['modalidad']=='presencial'): ?>
                            <div>Lugar: <b><?php echo esc_html($ag['lugar']); ?></b></div>
                        <?php else: ?>
                            <div>Enlace: <?php if ($ya_ocurrio && $ag['enlace']): ?><a href="<?php echo esc_url($ag['enlace']); ?>" target="_blank"><?php echo esc_html($ag['enlace']); ?></a><?php else: ?><span style="color:#888;">(Se habilitará al llegar la hora)</span><?php endif; ?></div>
                        <?php endif; ?>
                        <div>
                            Estado: 
                            <?php if ($ya_ocurrio): ?>
                                <span class="estado disponible">Disponible para asistir</span>
                            <?php else: ?>
                                <span class="estado pendiente">Pendiente</span>
                            <?php endif; ?>
                        </div>
                        <div style="margin-top:8px;">
                            <?php if ($ya_ocurrio): ?>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="cap_id" value="<?php echo esc_attr($ag['cap_id']); ?>">
                                    <input type="hidden" name="cap_idx" value="<?php echo esc_attr($ag['idx']); ?>">
                                    <?php wp_nonce_field('gw_capacitacion_completar_'.$key, 'gw_capacitacion_completar_nonce_'.$key); ?>
                                    <?php if ($ag['modalidad']=='virtual' && $ag['enlace']): ?>
                                        <a href="<?php echo esc_url($ag['enlace']); ?>" target="_blank" class="gw-charla-btn" style="margin-right:10px;">Ir a capacitación</a>
                                    <?php endif; ?>
                                    <button type="submit" name="completar_capacitacion" class="gw-charla-my-btn">Marcar como completada</button>
                                </form>
                                <?php if ($is_admin || defined('GW_TESTING_MODE')): ?>
                                    <form method="post" style="display:inline;margin-left:10px;">
                                        <input type="hidden" name="cap_id" value="<?php echo esc_attr($ag['cap_id']); ?>">
                                        <input type="hidden" name="cap_idx" value="<?php echo esc_attr($ag['idx']); ?>">
                                        <button type="submit" name="test_completar_capacitacion" class="gw-charla-btn">CONTINUAR (TEST)</button>
                                    </form>
                                <?php endif; ?>
                            <?php else: ?>
                                <a href="<?php echo esc_url(add_query_arg('cancelar_capacitacion', $key, site_url('/index.php/portal-voluntario/'))); ?>" class="gw-charla-btn" onclick="return confirm('¿Seguro que quieres cancelar esta capacitación?');">Cancelar capacitación</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div style="margin-bottom:26px;">
            <strong>Capacitaciones completadas:</strong>
            <?php if (empty($capacitaciones_completadas)): ?>
                <div style="color:#888;">Aún no has completado ninguna capacitación.</div>
            <?php else: ?>
                <?php foreach ($capacitaciones_completadas as $comp): ?>
                    <div class="gw-capacitacion-agendada-block">
                        <div>
                            <b><?php echo esc_html($comp['cap_title']); ?></b>
                            <?php if ($comp['modalidad']=='presencial'): ?>
                                <span style="margin-left:8px;color:#1976d2;">(Presencial)</span>
                            <?php else: ?>
                                <span style="margin-left:8px;color:#1976d2;">(Virtual/Google Meet)</span>
                            <?php endif; ?>
                        </div>
                        <div>Fecha: <b><?php echo date('d/m/Y', strtotime($comp['fecha'])); ?></b> &nbsp; Hora: <b><?php echo substr($comp['hora'],0,5); ?></b></div>
                        <div class="estado completada">Completada</div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div>
            <strong>Capacitaciones disponibles para registrar:</strong>
            <?php
            // Mostrar solo las sesiones que NO estén ni agendadas ni completadas
            $agendadas_keys = [];
            foreach ($capacitaciones_agendadas as $ag) $agendadas_keys[] = $ag['cap_id'].'_'.$ag['idx'];
            $completadas_keys = [];
            foreach ($capacitaciones_completadas as $cc) $completadas_keys[] = $cc['cap_id'].'_'.$cc['idx'];
            $disponibles = [];
            foreach ($cap_sesiones as $ses) {
                $key = $ses['cap_id'].'_'.$ses['idx'];
                $ts = strtotime($ses['fecha'].' '.$ses['hora']);
                if (in_array($key, $agendadas_keys) || in_array($key, $completadas_keys)) continue;
                if ($ts <= time()) continue; // Solo mostrar futuras
                $disponibles[] = $ses;
            }
            if (empty($disponibles)): ?>
                <div style="color:#888;">No hay más capacitaciones disponibles para registrar.</div>
            <?php else: ?>
                <?php foreach ($disponibles as $idx => $ses): ?>
                    <div class="gw-charla-sesion-row">
                        <span>
                            <b>OPCIÓN <?php echo ($idx+1); ?>:</b>
                            <?php echo esc_html($ses['cap_title']); ?>
                            <?php if ($ses['modalidad']=='presencial'): ?>
                                <span style="margin-left:8px;color:#1976d2;">(Presencial)</span>
                            <?php else: ?>
                                <span style="margin-left:8px;color:#1976d2;">(Virtual/Google Meet)</span>
                            <?php endif; ?>
                            <span style="margin-left:16px;font-weight:normal;color:#888;">(<?php echo date('d/m/Y', strtotime($ses['fecha'])).' '.substr($ses['hora'],0,5); ?>)</span>
                        </span>
                        <form method="post" style="margin:0;">
                            <input type="hidden" name="cap_id" value="<?php echo esc_attr($ses['cap_id']); ?>">
                            <input type="hidden" name="cap_idx" value="<?php echo esc_attr($ses['idx']); ?>">
                            <button type="submit" name="registrar_capacitacion" class="gw-charla-btn">Seleccionar</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php if ($is_admin || defined('GW_TESTING_MODE')): ?>
            <form method="post" style="margin-top:10px;">
                <?php wp_nonce_field('gw_capacitacion_regresar_admin', 'gw_capacitacion_regresar_admin_nonce'); ?>
                <button type="submit" name="gw_capacitacion_regresar_admin" class="gw-charla-admin-btn">REGRESAR (ADMIN)</button>
                <div style="font-size:12px;color:#1976d2;margin-top:6px;">Solo admin/testing: retrocede al menú de selección.</div>
            </form>
        <?php endif; ?>
        <!-- Bloque CONTINUAR (ADMIN) siempre visible para admin/testing -->
        <?php if ($is_admin || defined('GW_TESTING_MODE')): ?>
            <form method="post" style="margin-top:24px;">
                <button type="submit" name="admin_skip_step6" class="gw-charla-my-btn" style="background:#1976d2;">CONTINUAR (ADMIN)</button>
                <div style="font-size:12px;color:#1976d2;margin-top:6px;">(Solo admin/testing: forzar paso siguiente.)</div>
            </form>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

if (!defined('ABSPATH')) exit; // Seguridad: salir si se accede directamente

// Registrar Custom Post Type "País"
add_action('init', function () {
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
        'show_in_menu' => true,
        'show_in_rest' => true // para Gutenberg y REST API
    ]);
});

// Registrar Custom Post Type "Capacitación"
add_action('init', function () {
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
        'show_in_menu' => true, // Mostrar en el menú de administración
        'show_in_rest' => true
    ]);
});

// Metabox para Detalles de Capacitación (nuevo: coach, país y repeater de sesiones)
add_action('add_meta_boxes', function() {
    add_meta_box(
        'gw_capacitacion_detalles',
        'Detalles de Capacitación',
        function($post) {
            // Coach responsable
            $coach_id = get_post_meta($post->ID, '_gw_coach', true);
            $coaches = get_users(['role' => 'coach']);
            // País
            $pais_id = get_post_meta($post->ID, '_gw_pais_id', true);
            $paises = get_posts([
                'post_type' => 'pais',
                'post_status' => 'publish',
                'numberposts' => -1,
                'orderby' => 'title',
                'order' => 'ASC',
            ]);
            // Sesiones (repeater)
            $sesiones = get_post_meta($post->ID, '_gw_sesiones_cap', true);
            if (!is_array($sesiones)) $sesiones = [];
            if (empty($sesiones)) $sesiones = [ [ 'modalidad'=>'virtual', 'fecha'=>'', 'hora'=>'', 'lugar'=>'', 'enlace'=>'' ] ];
            ?>
            <p>
                <label for="gw_coach">Coach responsable:</label><br>
                <select name="gw_coach" id="gw_coach" style="width:100%;">
                    <option value="">-- Selecciona coach --</option>
                    <?php foreach($coaches as $c): ?>
                        <option value="<?php echo esc_attr($c->ID); ?>" <?php selected($coach_id, $c->ID); ?>>
                            <?php echo esc_html($c->display_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p>
                <label for="gw_pais_id">País:</label><br>
                <select name="gw_pais_id" id="gw_pais_id" style="width:100%;">
                    <option value="">-- Selecciona país --</option>
                    <?php foreach($paises as $p): ?>
                        <option value="<?php echo esc_attr($p->ID); ?>" <?php selected($pais_id, $p->ID); ?>>
                            <?php echo esc_html($p->post_title); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>
            <hr>
            <strong>Sesiones de capacitación</strong>
            <div id="gw-cap-sesiones-list">
            <?php foreach ($sesiones as $idx => $s): ?>
                <div class="gw-cap-sesion-block" style="border:1px solid #e2e2e2;padding:10px 12px;margin-bottom:10px;border-radius:6px;position:relative;">
                    <strong>Sesión <?php echo ($idx+1); ?></strong>
                    <button type="button" class="gw-remove-cap-sesion-btn" style="float:right;color:#b71c1c;background:none;border:none;font-size:1.1em;" onclick="gwRemoveCapSesion(this)">✖</button>
                    <div style="margin-top:7px;">
                        <label>
                            Modalidad:
                            <select name="gw_cap_sesion_modalidad[]"
                                    class="gw-cap-sesion-modalidad"
                                    onchange="gwCapSesionModalidadChange(this)">
                                <option value="virtual" <?php selected($s['modalidad'],'virtual'); ?>>Virtual</option>
                                <option value="presencial" <?php selected($s['modalidad'],'presencial'); ?>>Presencial</option>
                            </select>
                        </label>
                        <label style="margin-left:15px;">
                            Fecha:
                            <input type="date" name="gw_cap_sesion_fecha[]" value="<?php echo esc_attr($s['fecha']); ?>">
                        </label>
                        <label style="margin-left:15px;">
                            Hora:
                            <input type="time" name="gw_cap_sesion_hora[]" value="<?php echo esc_attr($s['hora']); ?>">
                        </label>
                    </div>
                    <div style="margin-top:7px;">
                        <span class="gw-cap-sesion-lugar-field" style="<?php if($s['modalidad']!='presencial')echo'display:none;'; ?>">
                            <label>
                                Lugar:
                                <input type="text" name="gw_cap_sesion_lugar[]" value="<?php echo esc_attr($s['lugar']); ?>" style="width:70%;">
                            </label>
                        </span>
                        <span class="gw-cap-sesion-enlace-field" style="<?php if($s['modalidad']!='virtual')echo'display:none;'; ?>">
                            <label>
                                Enlace:
                                <input type="text" name="gw_cap_sesion_enlace[]" value="<?php echo esc_attr($s['enlace']); ?>" style="width:70%;" placeholder="(Se configurará luego con Google Meet API)">
                            </label>
                            <span style="color:#888;font-size:12px;">(Se configurará luego con Google Meet API)</span>
                        </span>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
            <button type="button" id="gw-add-cap-sesion-btn" style="margin-top:8px;background:#1976d2;color:#fff;padding:6px 18px;border:none;border-radius:4px;">Agregar sesión</button>
            <script>
            function gwRemoveCapSesion(btn) {
                var block = btn.closest('.gw-cap-sesion-block');
                if(document.querySelectorAll('.gw-cap-sesion-block').length > 1) {
                    block.parentNode.removeChild(block);
                }
            }
            function gwCapSesionModalidadChange(sel) {
                var block = sel.closest('.gw-cap-sesion-block');
                var modalidad = sel.value;
                block.querySelector('.gw-cap-sesion-lugar-field').style.display = (modalidad=='presencial')?'inline':'none';
                block.querySelector('.gw-cap-sesion-enlace-field').style.display = (modalidad=='virtual')?'inline':'none';
            }
            document.addEventListener('DOMContentLoaded',function(){
                document.getElementById('gw-add-cap-sesion-btn').addEventListener('click',function(){
                    var list = document.getElementById('gw-cap-sesiones-list');
                    var idx = list.children.length + 1;
                    var div = document.createElement('div');
                    div.className = 'gw-cap-sesion-block';
                    div.style = "border:1px solid #e2e2e2;padding:10px 12px;margin-bottom:10px;border-radius:6px;position:relative;";
                    div.innerHTML = `
                        <strong>Sesión `+idx+`</strong>
                        <button type="button" class="gw-remove-cap-sesion-btn" style="float:right;color:#b71c1c;background:none;border:none;font-size:1.1em;" onclick="gwRemoveCapSesion(this)">✖</button>
                        <div style="margin-top:7px;">
                            <label>
                                Modalidad:
                                <select name="gw_cap_sesion_modalidad[]" class="gw-cap-sesion-modalidad" onchange="gwCapSesionModalidadChange(this)">
                                    <option value="virtual" selected>Virtual</option>
                                    <option value="presencial">Presencial</option>
                                </select>
                            </label>
                            <label style="margin-left:15px;">
                                Fecha:
                                <input type="date" name="gw_cap_sesion_fecha[]" value="">
                            </label>
                            <label style="margin-left:15px;">
                                Hora:
                                <input type="time" name="gw_cap_sesion_hora[]" value="">
                            </label>
                        </div>
                        <div style="margin-top:7px;">
                            <span class="gw-cap-sesion-lugar-field" style="display:none;">
                                <label>
                                    Lugar:
                                    <input type="text" name="gw_cap_sesion_lugar[]" value="" style="width:70%;">
                                </label>
                            </span>
                            <span class="gw-cap-sesion-enlace-field" style="display:inline;">
                                <label>
                                    Enlace:
                                    <input type="text" name="gw_cap_sesion_enlace[]" value="" style="width:70%;" placeholder="(Se configurará luego con Google Meet API)">
                                </label>
                                <span style="color:#888;font-size:12px;">(Se configurará luego con Google Meet API)</span>
                            </span>
                        </div>
                    `;
                    list.appendChild(div);
                });
                // Modalidad change listeners para los existentes
                document.querySelectorAll('.gw-cap-sesion-modalidad').forEach(function(sel){
                    sel.addEventListener('change',function(){gwCapSesionModalidadChange(sel);});
                });
            });
            </script>
            <?php
        },
        'capacitacion',
        'normal'
    );
});

// --- BLOQUE OBSOLETO: Información adicional de la capacitación ---
/*
// Metabox Información adicional de la capacitación (OBSOLETO: ahora se usa repeater de sesiones)
add_action('add_meta_boxes', function() {
    add_meta_box(
        'gw_capacitacion_info',
        'Información adicional de la capacitación',
        function($post) {
            // Aquí iba la lógica antigua de campos simples (fecha, hora, lugar, enlace)
            // echo 'Este bloque está obsoleto y ha sido reemplazado por el repeater de sesiones.';
        },
        'capacitacion',
        'normal'
    );
});
// Guardado de metabox obsoleto
add_action('save_post_capacitacion', function($post_id) {
    // Aquí iba la lógica antigua de guardado de los campos simples.
}, 20, 1);
*/

// Guardar metadatos de Capacitación NUEVO
add_action('save_post_capacitacion', function($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    // Coach responsable
    if (isset($_POST['gw_coach'])) {
        update_post_meta($post_id, '_gw_coach', intval($_POST['gw_coach']));
    }
    // País
    if (isset($_POST['gw_pais_id'])) {
        update_post_meta($post_id, '_gw_pais_id', intval($_POST['gw_pais_id']));
    }
    // Repeater de sesiones
    if (isset($_POST['gw_cap_sesion_modalidad'])) {
        $modalidads = $_POST['gw_cap_sesion_modalidad'];
        $fechas = $_POST['gw_cap_sesion_fecha'];
        $horas = $_POST['gw_cap_sesion_hora'];
        $lugares = $_POST['gw_cap_sesion_lugar'];
        $enlaces = $_POST['gw_cap_sesion_enlace'];
        $sesiones = [];
        for($i=0;$i<count($modalidads);$i++) {
            $mod = sanitize_text_field($modalidads[$i]);
            $fecha = sanitize_text_field($fechas[$i]);
            $hora = sanitize_text_field($horas[$i]);
            $lugar = sanitize_text_field($lugares[$i]);
            $enlace = sanitize_text_field($enlaces[$i]);
            $sesiones[] = [
                'modalidad' => $mod,
                'fecha' => $fecha,
                'hora' => $hora,
                'lugar' => $lugar,
                'enlace' => $enlace,
            ];
        }
        update_post_meta($post_id, '_gw_sesiones_cap', $sesiones);
    }
    // Eliminar antiguos campos simples (si existen)
    delete_post_meta($post_id, '_gw_fecha');
    delete_post_meta($post_id, '_gw_hora');
    delete_post_meta($post_id, '_gw_lugar');
    delete_post_meta($post_id, '_gw_link');
}, 10, 1);

// Registrar Custom Post Type "Charla"
add_action('init', function () {
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
        'menu_icon' => 'dashicons-welcome-learn-more',
        'supports' => ['title'],
        'show_in_menu' => true,
        'show_in_rest' => true // para Gutenberg y REST API
    ]);
});

// Metabox personalizado para Charlas: gestión de múltiples sesiones (modalidad, fecha, hora, lugar/enlace)
add_action('add_meta_boxes', function() {
    add_meta_box('gw_charla_sesiones', 'Sesiones de la charla', function($post){
        // Cargar sesiones guardadas
        $sesiones = get_post_meta($post->ID, '_gw_fechas_horas', true);
        if (!is_array($sesiones)) $sesiones = [];
        // Si no hay, agregar una vacía para mostrar
        if (empty($sesiones)) $sesiones = [ [ 'modalidad'=>'virtual', 'fecha'=>'', 'hora'=>'', 'lugar'=>'', 'enlace'=>'' ] ];
        ?>
        <div id="gw-sesiones-list">
            <?php foreach ($sesiones as $idx => $s): ?>
                <div class="gw-sesion-block" style="border:1px solid #e2e2e2;padding:10px 12px;margin-bottom:10px;border-radius:6px;position:relative;">
                    <strong>Sesión <?php echo ($idx+1); ?></strong>
                    <button type="button" class="gw-remove-sesion-btn" style="float:right;color:#b71c1c;background:none;border:none;font-size:1.1em;" onclick="gwRemoveSesion(this)">✖</button>
                    <div style="margin-top:7px;">
                        <label>
                            Modalidad:
                            <select name="gw_sesion_modalidad[]"
                                    class="gw-sesion-modalidad"
                                    onchange="gwSesionModalidadChange(this)">
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
                        <span class="gw-sesion-lugar-field" style="<?php if($s['modalidad']!='presencial')echo'display:none;'; ?>">
                            <label>
                                Lugar:
                                <input type="text" name="gw_sesion_lugar[]" value="<?php echo esc_attr($s['lugar']); ?>" style="width:70%;">
                            </label>
                        </span>
                        <span class="gw-sesion-enlace-field" style="<?php if($s['modalidad']!='virtual')echo'display:none;'; ?>">
                            <label>
                                Enlace:
                                <input type="text" name="gw_sesion_enlace[]" value="<?php echo esc_attr($s['enlace']); ?>" style="width:70%;">
                            </label>
                        </span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <button type="button" id="gw-add-sesion-btn" style="margin-top:8px;background:#1976d2;color:#fff;padding:6px 18px;border:none;border-radius:4px;">Agregar sesión</button>
        <script>
        function gwRemoveSesion(btn) {
            var block = btn.closest('.gw-sesion-block');
            if(document.querySelectorAll('.gw-sesion-block').length > 1) {
                block.parentNode.removeChild(block);
            }
        }
        function gwSesionModalidadChange(sel) {
            var block = sel.closest('.gw-sesion-block');
            var modalidad = sel.value;
            block.querySelector('.gw-sesion-lugar-field').style.display = (modalidad=='presencial')?'inline':'none';
            block.querySelector('.gw-sesion-enlace-field').style.display = (modalidad=='virtual')?'inline':'none';
        }
        document.addEventListener('DOMContentLoaded',function(){
            document.getElementById('gw-add-sesion-btn').addEventListener('click',function(){
                var list = document.getElementById('gw-sesiones-list');
                var idx = list.children.length + 1;
                var div = document.createElement('div');
                div.className = 'gw-sesion-block';
                div.style = "border:1px solid #e2e2e2;padding:10px 12px;margin-bottom:10px;border-radius:6px;position:relative;";
                div.innerHTML = `
                    <strong>Sesión `+idx+`</strong>
                    <button type="button" class="gw-remove-sesion-btn" style="float:right;color:#b71c1c;background:none;border:none;font-size:1.1em;" onclick="gwRemoveSesion(this)">✖</button>
                    <div style="margin-top:7px;">
                        <label>
                            Modalidad:
                            <select name="gw_sesion_modalidad[]" class="gw-sesion-modalidad" onchange="gwSesionModalidadChange(this)">
                                <option value="virtual" selected>Virtual</option>
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
                        <span class="gw-sesion-lugar-field" style="display:none;">
                            <label>
                                Lugar:
                                <input type="text" name="gw_sesion_lugar[]" value="" style="width:70%;">
                            </label>
                        </span>
                        <span class="gw-sesion-enlace-field" style="display:inline;">
                            <label>
                                Enlace:
                                <input type="text" name="gw_sesion_enlace[]" value="" style="width:70%;">
                            </label>
                        </span>
                    </div>
                `;
                list.appendChild(div);
            });
            // Modalidad change listeners para los existentes
            document.querySelectorAll('.gw-sesion-modalidad').forEach(function(sel){
                sel.addEventListener('change',function(){gwSesionModalidadChange(sel);});
            });
        });
        </script>
        <?php
    }, 'charla', 'normal');
});

// Guardar las sesiones como array serializado en _gw_fechas_horas
add_action('save_post_charla', function($post_id){
    // Solo si es guardado desde el admin
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!isset($_POST['gw_sesion_modalidad'])) return;
    $modalidads = $_POST['gw_sesion_modalidad'];
    $fechas = $_POST['gw_sesion_fecha'];
    $horas = $_POST['gw_sesion_hora'];
    $lugares = $_POST['gw_sesion_lugar'];
    $enlaces = $_POST['gw_sesion_enlace'];
    $sesiones = [];
    for($i=0;$i<count($modalidads);$i++) {
        $mod = sanitize_text_field($modalidads[$i]);
        $fecha = sanitize_text_field($fechas[$i]);
        $hora = sanitize_text_field($horas[$i]);
        $lugar = sanitize_text_field($lugares[$i]);
        $enlace = sanitize_text_field($enlaces[$i]);
        $sesiones[] = [
            'modalidad' => $mod,
            'fecha' => $fecha,
            'hora' => $hora,
            'lugar' => $lugar,
            'enlace' => $enlace,
        ];
    }
    update_post_meta($post_id, '_gw_fechas_horas', $sesiones);
});


// Shortcode: [gw_panel_admin]
add_shortcode('gw_panel_admin', 'gw_panel_admin_shortcode');
function gw_panel_admin_shortcode() {
    if (!is_user_logged_in() || !current_user_can('manage_options')) {
        return '<p>No tienes permisos para ver este panel.</p>';
    }

    // ¿Qué sección mostrar?
    $section = isset($_GET['gw_section']) ? sanitize_text_field($_GET['gw_section']) : 'paises';

    ob_start();
    ?>
    <style>
/* Mejoras visuales y responsivas para Capacitaciones */
.gw-caps-block {
    max-width: 820px;
    width: 100%;
    margin: 0 auto 38px auto;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 0 12px #e6eaf5;
    padding: 36px 40px 40px 40px;
    display: flex;
    flex-direction: column;
    align-items: stretch;
}
@media (max-width: 700px) {
    .gw-caps-block { padding: 16px 4vw 28px 4vw; }
}
.gw-caps-block h2 { text-align: center; margin-bottom: 28px; }
.gw-caps-form { margin-bottom: 38px; }
.gw-caps-list { overflow-x: auto; }
.gw-caps-list table { min-width: 650px; font-size: 1.01rem; }
.gw-caps-list th, .gw-caps-list td { padding: 10px 8px; text-align: left; }
.gw-caps-list tr:nth-child(even) { background: #f7f8fa; }
.gw-dashboard-container {
    display: flex;
    min-height: 100vh;
    font-family: sans-serif;
    background: #f4f6fa;
}
.gw-dashboard-sidebar {
    width: 260px;
    background: #1c2331;
    color: #fff;
    padding: 0;
    display: flex;
    flex-direction: column;
    min-height: 100vh;
    position: fixed;
    left: 0; top: 0; bottom: 0;
    z-index: 100;
    transition: transform .25s cubic-bezier(.65,.05,.36,1);
    box-shadow: 1px 0 7px 0 rgba(30,30,50,0.13);
}
.gw-sidebar-hidden {
    transform: translateX(-100%);
}
.gw-sidebar-logo {
    text-align: center;
    padding: 36px 0 18px 0;
    border-bottom: 1px solid #232b3e;
}
.gw-sidebar-logo img {
    max-width: 120px;
    max-height: 46px;
    filter: grayscale(0.7);
}
.gw-sidebar-toggle {
    position: absolute;
    top: 18px;
    right: -35px;
    background: #fff;
    color: #1c2331;
    border-radius: 50%;
    border: none;
    width: 32px;
    height: 32px;
    font-size: 1.2rem;
    box-shadow: 1px 2px 7px 0 rgba(30,30,50,0.16);
    cursor: pointer;
    z-index: 300;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background .2s;
}
.gw-dashboard-sidebar a {
    display: block; 
    color: #fff; 
    padding: 22px 36px; 
    text-decoration: none; 
    border-bottom: 1px solid #232b3e;
    transition: background .2s;
    font-size: 1.1rem;
}
.gw-dashboard-sidebar a.active, .gw-dashboard-sidebar a:hover {
    background: #2d3748;
    font-weight: bold;
}
.gw-dashboard-main {
    flex: 1;
    margin-left: 260px;
    padding: 48px 8vw;
    min-width: 0;
    width: 100%;
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
    align-items: center;
    transition: margin-left .25s cubic-bezier(.65,.05,.36,1);
}
.gw-main-wide {
    margin-left: 0 !important;
}
@media (max-width: 900px) {
    .gw-dashboard-main { padding: 24px 2vw; }
    .gw-dashboard-sidebar { width: 200px; }
    .gw-dashboard-main { margin-left: 200px; }
}
@media (max-width: 650px) {
    .gw-dashboard-container { flex-direction: column; }
    .gw-dashboard-sidebar {
        position: fixed;
        width: 75vw;
        min-width: 150px;
        max-width: 90vw;
        min-height: 100vh;
        border-bottom: none;
    }
    .gw-dashboard-main { 
        margin-left: 0; 
        padding: 18px 3vw; 
        align-items: stretch;
    }
    .gw-sidebar-toggle {
        top: 15px;
        right: -36px;
    }
}
</style>
<script>
document.addEventListener('DOMContentLoaded', function(){
    var toggle = document.getElementById('gw-sidebar-toggle');
    var sidebar = document.getElementById('gw-dashboard-sidebar');
    var main = document.getElementById('gw-dashboard-main');
    if(toggle && sidebar && main) {
        toggle.addEventListener('click', function() {
            sidebar.classList.toggle('gw-sidebar-hidden');
            main.classList.toggle('gw-main-wide');
        });
    }
});
</script>
    <div class="gw-dashboard-container">
        <div id="gw-dashboard-sidebar" class="gw-dashboard-sidebar">
            <div class="gw-sidebar-logo">
                <img src="<?php echo plugin_dir_url(__FILE__); ?>glasswing-logo.png" alt="Glasswing International">
            </div>
            <button class="gw-sidebar-toggle" id="gw-sidebar-toggle" title="Ocultar/mostrar menú">&#9776;</button>
            <a href="?gw_section=paises" class="<?php if($section=='paises') echo 'active'; ?>">Gestión de países</a>
            <a href="?gw_section=usuarios" class="<?php if($section=='usuarios') echo 'active'; ?>">Gestión de usuarios</a>
            <a href="?gw_section=capacitaciones" class="<?php if($section=='capacitaciones') echo 'active'; ?>">Capacitaciones</a>
            <a href="?gw_section=progreso" class="<?php if($section=='progreso') echo 'active'; ?>">Progreso del voluntario</a>
            <a href="?gw_section=ausencias" class="<?php if($section=='ausencias') echo 'active'; ?>">Seguimiento de ausencias</a>
            <a href="?gw_section=reportes" class="<?php if($section=='reportes') echo 'active'; ?>">Reportes y listados</a>
        </div>
        <div id="gw-dashboard-main" class="gw-dashboard-main">
            <?php
            // Aquí cargaremos cada módulo según $section
            switch ($section) {
                case 'paises':
                    // Guardar país nuevo
                    if (isset($_POST['gw_pais_nombre']) && !empty($_POST['gw_pais_nombre'])) {
                        $nuevo_pais = sanitize_text_field($_POST['gw_pais_nombre']);
                        wp_insert_post([
                            'post_title' => $nuevo_pais,
                            'post_type' => 'pais',
                            'post_status' => 'publish'
                        ]);
                        echo '<div style="color:green;margin-bottom:12px;">País creado correctamente.</div>';
                    }
                
                    // Eliminar país
                    if (isset($_GET['delete_pais']) && is_numeric($_GET['delete_pais'])) {
                        wp_delete_post(intval($_GET['delete_pais']), true);
                        echo '<div style="color:red;margin-bottom:12px;">País eliminado.</div>';
                    }
                
                    // Guardar asociaciones (coordinadores y charlas)
                    if (isset($_POST['gw_save_asoc']) && is_numeric($_POST['pais_id'])) {
                        $pid = intval($_POST['pais_id']);
                        $coordinadores = array_map('intval', $_POST['gw_coordinadores'] ?? []);
                        // El array de charlas ya viene ordenado según el orden en el campo (gracias al drag & drop)
                        $charlas = array_map('sanitize_text_field', $_POST['gw_charlas'] ?? []);
                        update_post_meta($pid, '_gw_coordinadores', $coordinadores);
                        update_post_meta($pid, '_gw_charlas', $charlas);
                        echo '<div style="color:blue;margin-bottom:12px;">Asociaciones guardadas para el país.</div>';
                    }
                
                    // Formulario para crear país
                    echo '
                    <h2>Gestión de Países</h2>
                    <form method="post" style="margin-bottom:24px;">
                        <input type="text" name="gw_pais_nombre" placeholder="Nuevo país" required style="padding:8px;">
                        <button type="submit" style="background:#1976d2;color:#fff;padding:8px 20px;border:none;border-radius:5px;">Agregar país</button>
                    </form>
                    ';
                
                    // Listar países existentes
                    $paises = get_posts(['post_type' => 'pais', 'numberposts' => -1, 'orderby'=>'title','order'=>'ASC']);
                    if ($paises) {
                        echo '<table style="width:100%;background:#fff;border-radius:8px;box-shadow:0 0 8px #e3e3e3;"><tr><th style="padding:8px;">Nombre</th><th>Coordinadores</th><th>Charlas</th><th>Acciones</th></tr>';
                        foreach ($paises as $pais) {
                            $coors = get_post_meta($pais->ID, '_gw_coordinadores', true) ?: [];
                            $chrls = get_post_meta($pais->ID, '_gw_charlas', true) ?: [];
                            $editando = (isset($_GET['edit_pais']) && intval($_GET['edit_pais']) === $pais->ID);
                
                            // Si se está editando este país, mostrar formulario
                            if ($editando) {
                                // Usuarios con rol coordinador_pais
                                $usuarios = get_users(['role' => 'coordinador_pais']);
                                // Lista de charlas
                                $charlas = get_posts(['post_type'=>'charla','numberposts'=>-1]);
                                echo '<tr><form method="post"><td style="padding:8px;">'.esc_html($pais->post_title).'</td>';
                                // Coordinadores
                                echo '<td><select name="gw_coordinadores[]" multiple style="min-width:130px">';
                                foreach($usuarios as $u) {
                                    $sel = in_array($u->ID, $coors) ? 'selected' : '';
                                    echo '<option value="'.$u->ID.'" '.$sel.'>'.$u->display_name.'</option>';
                                }
                                echo '</select></td>';
                                // Charlas - ORDENABLE drag & drop
                                echo '<td>';
                                ?>
                                <style>
                                .gw-charlas-sortable-list { list-style:none; margin:0; padding:0; }
                                .gw-charlas-sortable-list li {
                                    padding: 7px 12px;
                                    margin-bottom: 4px;
                                    background: #f5f7fd;
                                    border: 1px solid #dbe2f6;
                                    border-radius: 4px;
                                    cursor: move;
                                    display: flex;
                                    align-items: center;
                                }
                                .gw-charlas-sortable-list li:last-child { margin-bottom:0; }
                                .gw-charlas-sortable-list .gw-charla-title {
                                    flex: 1;
                                }
                                </style>
                                <ul class="gw-charlas-sortable-list" id="gw-charlas-sortable-<?php echo $pais->ID; ?>">
                                <?php
                                // Mostrar primero las charlas ya asignadas, en su orden
                                $chrls_ids = array_map('intval', $chrls);
                                $charlas_map = [];
                                foreach($charlas as $c) $charlas_map[$c->ID] = $c;
                                foreach($chrls_ids as $cid) {
                                    if (!isset($charlas_map[$cid])) continue;
                                    $c = $charlas_map[$cid];
                                    echo '<li data-id="'.$c->ID.'"><span class="dashicons dashicons-move"></span> <span class="gw-charla-title">'.esc_html($c->post_title).'</span>
                                    <input type="hidden" name="gw_charlas[]" value="'.$c->ID.'"></li>';
                                }
                                // Mostrar las charlas no seleccionadas aún
                                foreach($charlas as $c) {
                                    if (in_array($c->ID, $chrls_ids)) continue;
                                    echo '<li data-id="'.$c->ID.'" style="opacity:.5;"><span class="dashicons dashicons-move"></span> <span class="gw-charla-title">'.esc_html($c->post_title).'</span>
                                    <input type="hidden" name="gw_charlas[]" value="'.$c->ID.'"></li>';
                                }
                                ?>
                                </ul>
                                <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
                                <script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
                                <script>
                                jQuery(function($){
                                    var ul = $('#gw-charlas-sortable-<?php echo $pais->ID; ?>');
                                    ul.sortable({
                                        items: 'li',
                                        placeholder: 'gw-charla-sort-placeholder',
                                        update: function(ev, ui) {
                                            // Solo dejar seleccionadas en el orden actual
                                            ul.find('li').each(function(i, el){
                                                // Si el li está marcado como no seleccionado (opacity) y estaba antes seleccionado, lo quitamos del submit
                                                // Pero para UX, solo se ordenan las seleccionadas arriba, las otras quedan abajo y no se envían.
                                            });
                                        }
                                    });
                                    // Al enviar el formulario, solo enviar los inputs de los li que NO tienen opacity (es decir, los seleccionados)
                                    ul.closest('form').on('submit', function(){
                                        ul.find('li').each(function(){
                                            var $li = $(this);
                                            if ($li.css('opacity') < 1) {
                                                $li.find('input[name="gw_charlas[]"]').prop('disabled', true);
                                            } else {
                                                $li.find('input[name="gw_charlas[]"]').prop('disabled', false);
                                            }
                                        });
                                    });
                                    // Click para seleccionar/deseleccionar charla
                                    ul.on('click', 'li', function(e){
                                        // Solo si clic en el li, no en el input
                                        if (e.target.tagName.toLowerCase() === 'input') return;
                                        var $li = $(this);
                                        if ($li.css('opacity') < 1) {
                                            $li.css('opacity', 1);
                                        } else {
                                            $li.css('opacity', 0.5);
                                        }
                                    });
                                });
                                </script>
                                <?php
                                echo '</td>';
                                echo '<td>
                                    <input type="hidden" name="pais_id" value="'.$pais->ID.'">
                                    <button type="submit" name="gw_save_asoc" style="background:#388e3c;color:#fff;padding:6px 18px;border:none;border-radius:4px;">Guardar</button>
                                    <a href="?gw_section=paises" style="margin-left:8px;">Cancelar</a>
                                    </td></form></tr>';
                            } else {
                                // Mostrar valores actuales
                                $usuarios = array_map(function($id){ $u=get_userdata($id); return $u?$u->display_name:''; }, $coors);
                                $charlas = array_map(function($id){ $c=get_post($id); return $c?$c->post_title:''; }, $chrls);
                                echo '<tr>
                                    <td style="padding:8px;">'.esc_html($pais->post_title).'</td>
                                    <td>'.implode(', ', array_filter($usuarios)).'</td>
                                    <td>'.implode(', ', array_filter($charlas)).'</td>
                                    <td>
                                        <a href="?gw_section=paises&edit_pais='.$pais->ID.'" style="color:#1976d2;">Editar</a>
                                        &nbsp;|&nbsp;
                                        <a href="?gw_section=paises&delete_pais='.$pais->ID.'" style="color:#b71c1c;" onclick="return confirm(\'¿Seguro que quieres eliminar este país?\')">Eliminar</a>
                                    </td>
                                </tr>';
                            }
                        }
                        echo '</table>';
                    } else {
                        echo '<p>No hay países registrados todavía.</p>';
                    }
                    break;
                    case 'usuarios':
                        echo '<h2>Gestión de Usuarios</h2>';
                        // Mostrar formulario de alta si es admin
                        if (current_user_can('administrator')) {
                            // Guardar usuario nuevo
                            if (isset($_POST['gw_nuevo_nombre'])) {
                                $nombre = sanitize_text_field($_POST['gw_nuevo_nombre']);
                                $rol = sanitize_text_field($_POST['gw_nuevo_rol']);
                                $activo = isset($_POST['gw_nuevo_activo']) ? 1 : 0;
                                // Generar correo temporal único
                                $timestamp = time();
                                $correo = 'nuevo-usuario-' . $timestamp . '@example.com';
                                // Genera una contraseña aleatoria
                                $pass = wp_generate_password(10, true, true);

                                // Crea el usuario con correo temporal y contraseña generada
                                if (!email_exists($correo)) {
                                    $uid = wp_create_user($correo, $pass, $correo);
                                    wp_update_user(['ID' => $uid, 'display_name' => $nombre]);
                                    $user = get_user_by('id', $uid);
                                    $user->set_role($rol);
                                    update_user_meta($uid, 'gw_activo', $activo);
                                    // NOTA: No se asigna país ni se solicita email/contraseña reales aquí
                                    echo '<div style="color:green;margin-bottom:12px;">¡Usuario creado! El correo y contraseña reales deberán ser editados posteriormente.</div>';
                                } else {
                                    echo '<div style="color:#b71c1c;margin-bottom:12px;">El correo temporal generado ya existe (esto es muy raro, intenta de nuevo).</div>';
                                }
                            }

                            // Botón para mostrar formulario (usamos un poco de JS para toggle)
                            echo '<button onclick="document.getElementById(\'gw-user-nuevo-form\').style.display = (document.getElementById(\'gw-user-nuevo-form\').style.display==\'none\'?\'block\':\'none\');" style="margin-bottom:15px;background:#1976d2;color:#fff;padding:7px 17px;border:none;border-radius:5px;font-size:1rem;">Agregar usuario</button>';

                            // Formulario oculto por defecto: solo nombre, rol y activo
                            echo '<form method="post" id="gw-user-nuevo-form" style="background:#f7f9fc;padding:18px 24px;margin-bottom:20px;border-radius:7px;display:none;max-width:540px;">
                                <strong>Nuevo usuario</strong><br><br>
                                <input type="text" name="gw_nuevo_nombre" placeholder="Nombre completo" required style="width:60%;margin-bottom:10px;">
                                <br>
                                <select name="gw_nuevo_rol" required style="width:60%;margin-bottom:10px;">
                                    <option value="">Rol</option>
                                    <option value="administrator">Administrador</option>
                                    <option value="coordinador_pais">Coordinador de país</option>
                                    <option value="coach">Coach</option>
                                    <option value="voluntario">Voluntario</option>
                                </select>
                                <br>
                                <label style="margin-left:2px;"><input type="checkbox" name="gw_nuevo_activo" value="1" checked> Activo</label>
                                <br>
                                <button type="submit" style="background:#388e3c;color:#fff;padding:7px 24px;border:none;border-radius:5px;margin-top:12px;">Guardar usuario</button>
                                </form>';
                        }
                    
                        // Guardar cambios del usuario
                        if (isset($_POST['gw_guardar_usuario']) && is_numeric($_POST['usuario_id'])) {
                            $uid = intval($_POST['usuario_id']);
                            $nuevo_nombre = sanitize_text_field($_POST['gw_user_nombre']);
                            $nuevo_rol = sanitize_text_field($_POST['gw_user_role']);
                            $nuevo_pais = intval($_POST['gw_user_pais']);
                            $nuevo_email = sanitize_email($_POST['gw_user_email']);
                            $activo = isset($_POST['gw_user_activo']) ? 1 : 0;
                            $user = get_userdata($uid);
                            if ($user) {
                                // Cambiar nombre
                                wp_update_user(['ID'=>$uid, 'display_name'=>$nuevo_nombre, 'nickname'=>$nuevo_nombre]);
                                // Cambiar correo si corresponde y está libre
                                if ($user->user_email != $nuevo_email && !email_exists($nuevo_email)) {
                                    wp_update_user(['ID'=>$uid, 'user_email'=>$nuevo_email]);
                                }
                                // Cambiar rol correctamente (borra roles previos primero)
                                foreach($user->roles as $role) {
                                    $user->remove_role($role);
                                }
                                $user->add_role($nuevo_rol);
                                update_user_meta($uid, 'gw_pais_id', $nuevo_pais);
                                update_user_meta($uid, 'gw_activo', $activo);
                                echo '<div style="color:green;margin-bottom:12px;">Usuario actualizado.</div>';
                            }
                        }
                    
                        // Listar usuarios
                        $usuarios = get_users(['orderby'=>'display_name','order'=>'ASC']);
                        $paises = get_posts(['post_type' => 'pais', 'numberposts' => -1, 'orderby'=>'title','order'=>'ASC']);
                        $roles = [
                            'administrator' => 'Administrador',
                            'coordinador_pais' => 'Coordinador de país',
                            'coach' => 'Coach',
                            'voluntario' => 'Voluntario'
                        ];
                    
                        if ($usuarios) {
                            echo '<table style="width:100%;background:#fff;border-radius:8px;box-shadow:0 0 8px #e3e3e3;font-size:0.97rem;"><tr>
                                    <th style="padding:8px;">Nombre</th>
                                    <th>Correo</th>
                                    <th>Rol</th>
                                    <th>País</th>
                                    <th>Activo</th>
                                    <th>Acciones</th>
                                </tr>';
                            foreach ($usuarios as $u) {
                                $user_roles = $u->roles;
                                $rol_actual = $user_roles ? $user_roles[0] : '';
                                $pais_id = get_user_meta($u->ID, 'gw_pais_id', true);
                                $activo = get_user_meta($u->ID, 'gw_activo', true);

                                $editando = (isset($_GET['edit_user']) && intval($_GET['edit_user']) === $u->ID);

                                if ($editando) {
                                    // Formulario de edición
                                    echo '<tr><form method="post">';
                                    echo '<td style="padding:8px;">
                                        <input type="text" name="gw_user_nombre" value="'.esc_attr($u->display_name).'" required style="width:98%;">
                                    </td>';
                                    echo '<td>
                                        <input type="email" name="gw_user_email" value="'.esc_attr($u->user_email).'" required style="width:98%;">
                                    </td>';
                                    echo '<td>
                                        <select name="gw_user_role">';
                                    foreach($roles as $k=>$v) {
                                        $sel = ($k==$rol_actual)?'selected':'';
                                        echo '<option value="'.$k.'" '.$sel.'>'.$v.'</option>';
                                    }
                                    echo '</select>
                                    </td>';
                                    echo '<td>
                                        <select name="gw_user_pais">
                                            <option value="">Ninguno</option>';
                                    foreach($paises as $p) {
                                        $sel = ($p->ID==$pais_id)?'selected':'';
                                        echo '<option value="'.$p->ID.'" '.$sel.'>'.$p->post_title.'</option>';
                                    }
                                    echo '</select>
                                    </td>';
                                    echo '<td><input type="checkbox" name="gw_user_activo" value="1" '.($activo?'checked':'').'></td>';
                                    echo '<td>
                                        <input type="hidden" name="usuario_id" value="'.$u->ID.'">
                                        <button type="submit" name="gw_guardar_usuario" style="background:#388e3c;color:#fff;padding:5px 16px;border:none;border-radius:4px;">Guardar</button>
                                        <a href="?gw_section=usuarios" style="margin-left:7px;">Cancelar</a>
                                    </td>';
                                    echo '</form></tr>';
                                } else {
                                    // Modo lectura
                                    $pais_nombre = $pais_id ? get_the_title($pais_id) : '';
                                    echo '<tr>
                                        <td style="padding:8px;">'.esc_html($u->display_name).'</td>
                                        <td>'.esc_html($u->user_email).'</td>
                                        <td>'.($roles[$rol_actual]??$rol_actual).'</td>
                                        <td>'.$pais_nombre.'</td>
                                        <td>'.($activo?'Sí':'No').'</td>
                                        <td>
                                            <a href="?gw_section=usuarios&edit_user='.$u->ID.'" style="color:#1976d2;">Editar</a>
                                        </td>
                                    </tr>';
                                }
                            }
                            echo '</table>';
                        } else {
                            echo '<p>No hay usuarios registrados todavía.</p>';
                        }
                        break;
                case 'capacitaciones':
    echo '<div class="gw-caps-block">';
    echo '<h2>Capacitaciones</h2>';

    // Solo Coordinador de país o Coach pueden agregar/editar
    if (!current_user_can('coordinador_pais') && !current_user_can('coach') && !current_user_can('administrator')) {
        echo '<p>Solo coordinadores de país, coachs o administradores pueden gestionar capacitaciones.</p>';
        echo '</div>';
        break;
    }

    // Guardar/editar capacitación
    if (isset($_POST['gw_save_capacitacion'])) {
        $titulo = sanitize_text_field($_POST['gw_titulo']);
        $tipo = sanitize_text_field($_POST['gw_tipo']);
        $pais = intval($_POST['gw_pais']);
        $charla = intval($_POST['gw_programa']); // Nota: la variable sigue llamándose $programa para mantener la lógica de IDs, pero es una charla
        $responsable = intval($_POST['gw_responsable']);
        $fecha = sanitize_text_field($_POST['gw_fecha']);
        // Normalizar hora a formato 24 horas H:i
        $hora = date('H:i', strtotime(sanitize_text_field($_POST['gw_hora'])));
        $lugar = sanitize_text_field($_POST['gw_lugar']);
        $link = sanitize_text_field($_POST['gw_link']);

        $cap_id = isset($_POST['capacitacion_id']) ? intval($_POST['capacitacion_id']) : 0;

        $args = [
            'post_title' => $titulo,
            'post_type' => 'capacitacion',
            'post_status' => 'publish',
        ];
        if ($cap_id) { $args['ID'] = $cap_id; }

        $cap_id = wp_insert_post($args);

        // Guardar metadatos
        update_post_meta($cap_id, '_gw_tipo', $tipo);
        update_post_meta($cap_id, '_gw_pais', $pais);
        update_post_meta($cap_id, '_gw_programa', $charla);
        update_post_meta($cap_id, '_gw_responsable', $responsable);
        update_post_meta($cap_id, '_gw_fecha', $fecha);
        update_post_meta($cap_id, '_gw_hora', $hora);
        update_post_meta($cap_id, '_gw_lugar', $lugar);
        update_post_meta($cap_id, '_gw_link', $link);

        echo '<div style="color:green;margin-bottom:12px;">Capacitación guardada.</div>';
    }

    // Eliminar capacitación
    if (isset($_GET['delete_capacitacion']) && is_numeric($_GET['delete_capacitacion'])) {
        wp_delete_post(intval($_GET['delete_capacitacion']), true);
        echo '<div style="color:red;margin-bottom:12px;">Capacitación eliminada.</div>';
    }

    // Mostrar formulario para agregar/editar
    $editando = isset($_GET['edit_capacitacion']) ? intval($_GET['edit_capacitacion']) : 0;
    $cap_data = [
        'titulo' => '',
        'tipo' => '',
        'pais' => '',
        'programa' => '',
        'responsable' => '',
        'fecha' => '',
        'hora' => '',
        'lugar' => '',
        'link' => ''
    ];
    if ($editando) {
        $post = get_post($editando);
        $cap_data['titulo'] = $post->post_title;
        $cap_data['tipo'] = get_post_meta($post->ID, '_gw_tipo', true);
        $cap_data['pais'] = get_post_meta($post->ID, '_gw_pais', true);
        $cap_data['programa'] = get_post_meta($post->ID, '_gw_programa', true);
        $cap_data['responsable'] = get_post_meta($post->ID, '_gw_responsable', true);
        $cap_data['fecha'] = get_post_meta($post->ID, '_gw_fecha', true);
        $cap_data['hora'] = get_post_meta($post->ID, '_gw_hora', true);
        $cap_data['lugar'] = get_post_meta($post->ID, '_gw_lugar', true);
        $cap_data['link'] = get_post_meta($post->ID, '_gw_link', true);
    }

    // Datos para selects
    $paises = get_posts(['post_type' => 'pais', 'numberposts' => -1, 'orderby'=>'title','order'=>'ASC']);
    $charlas = get_posts(['post_type'=>'charla','numberposts'=>-1]);
    $usuarios = get_users(['role__in'=>['coordinador_pais','coach']]);

    // Formulario con clase visual
    echo '<form method="post" class="gw-caps-form">';
    echo '<strong>'.($editando ? 'Editar' : 'Nueva').' capacitación</strong><br><br>';
    echo '<input type="text" name="gw_titulo" placeholder="Título de la capacitación" required value="'.esc_attr($cap_data['titulo']).'" style="margin-bottom:10px;width:60%;"> ';
    echo '<select name="gw_tipo" required>
            <option value="">Tipo</option>
            <option value="virtual"'.($cap_data['tipo']=='virtual'?' selected':'').'>Virtual</option>
            <option value="presencial"'.($cap_data['tipo']=='presencial'?' selected':'').'>Presencial</option>
        </select> ';
    echo '<select name="gw_pais" required><option value="">País</option>';
    foreach($paises as $p) {
        $sel = ($cap_data['pais']==$p->ID)?'selected':'';
        echo '<option value="'.$p->ID.'" '.$sel.'>'.$p->post_title.'</option>';
    }
    echo '</select> ';
    // Select para asociar charla
    echo '<select name="gw_programa" required><option value="">Charla</option>';
    foreach($charlas as $ch) {
        $sel = ($cap_data['programa']==$ch->ID)?'selected':'';
        echo '<option value="'.$ch->ID.'" '.$sel.'>'.$ch->post_title.'</option>';
    }
    echo '</select> ';
    echo '<select name="gw_responsable" required><option value="">Responsable</option>';
    foreach($usuarios as $u) {
        $sel = ($cap_data['responsable']==$u->ID)?'selected':'';
        echo '<option value="'.$u->ID.'" '.$sel.'>'.$u->display_name.' ('.$u->user_email.')</option>';
    }
    echo '</select><br><br>';
    echo 'Fecha: <input type="date" name="gw_fecha" required value="'.esc_attr($cap_data['fecha']).'"> ';
    echo 'Hora: <input type="time" name="gw_hora" required step="60" value="'.esc_attr($cap_data['hora']).'"><br><br>';
    echo 'Lugar/Link: <input type="text" name="gw_lugar" placeholder="Lugar físico" value="'.esc_attr($cap_data['lugar']).'"> ';
    echo '<input type="text" name="gw_link" placeholder="Link (si es virtual)" value="'.esc_attr($cap_data['link']).'"> ';
    if ($editando) echo '<input type="hidden" name="capacitacion_id" value="'.$editando.'">';
    echo '<button type="submit" name="gw_save_capacitacion" style="background:#1976d2;color:#fff;padding:8px 24px;border:none;border-radius:6px;margin-left:12px;">Guardar</button>';
    if ($editando) echo ' <a href="?gw_section=capacitaciones" style="margin-left:7px;">Cancelar</a>';
    echo '</form>';

    // Listado de capacitaciones
    $caps = get_posts(['post_type'=>'capacitacion','numberposts'=>-1,'orderby'=>'meta_value','meta_key'=>'_gw_fecha','order'=>'DESC']);
    if ($caps) {
        echo '<div class="gw-caps-list">';
        echo '<table>';
        echo '<tr>
                <th>Título</th>
                <th>Tipo</th>
                <th>País</th>
                <th>Charla</th>
                <th>Responsable</th>
                <th>Fecha</th>
                <th>Hora</th>
                <th>Lugar/Link</th>
                <th>Asistentes</th>
                <th>Acciones</th>
            </tr>';
        foreach ($caps as $c) {
            $tipo = get_post_meta($c->ID, '_gw_tipo', true);
            $pais = get_post_meta($c->ID, '_gw_pais', true);
            $charla = get_post_meta($c->ID, '_gw_programa', true); // sigue siendo _gw_programa pero es charla
            $responsable = get_post_meta($c->ID, '_gw_responsable', true);
            $fecha = get_post_meta($c->ID, '_gw_fecha', true);
            $hora = get_post_meta($c->ID, '_gw_hora', true);
            $lugar = get_post_meta($c->ID, '_gw_lugar', true);
            $link = get_post_meta($c->ID, '_gw_link', true);
            // Asistencias para capacitaciones virtuales
            $asistencias = get_post_meta($c->ID, '_gw_asistencias', true);
            if (!is_array($asistencias)) $asistencias = [];
            $asistentes_nombres = [];
            foreach (array_keys($asistencias) as $uid) {
                $user = get_userdata($uid);
                if ($user) $asistentes_nombres[] = $user->display_name;
            }
            echo '<tr>
                <td>'.esc_html($c->post_title).'</td>
                <td>'.ucfirst($tipo).'</td>
                <td>'.($pais?get_the_title($pais):'').'</td>
                <td>'.($charla?get_the_title($charla):'').'</td>
                <td>'.($responsable?get_userdata($responsable)->display_name:'').'</td>
                <td>'.$fecha.'</td>
                <td>'.$hora.'</td>';
            if ($tipo=='virtual') {
                echo '<td><a href="' . site_url('/?gw_asistir=' . $c->ID) . '" target="_blank">Entrar y marcar asistencia</a></td>';
                echo '<td>' . (!empty($asistentes_nombres) ? esc_html(implode(', ', $asistentes_nombres)) : '—') . '</td>';
            } else {
                echo '<td>'.$lugar.'</td>';
                echo '<td>—</td>';
            }
            echo '<td>
                    <a href="?gw_section=capacitaciones&edit_capacitacion='.$c->ID.'" style="color:#1976d2;">Editar</a>
                    &nbsp;|&nbsp;
                    <a href="?gw_section=capacitaciones&delete_capacitacion='.$c->ID.'" style="color:#b71c1c;" onclick="return confirm(\'¿Seguro que quieres eliminar esta capacitación?\')">Eliminar</a>
                </td>
            </tr>';
        }
        echo '</table>';
        echo '</div>';
    } else {
        echo '<div class="gw-caps-list"><p>No hay capacitaciones registradas todavía.</p></div>';
    }
    echo '</div>';
    break;
                // Eliminado el handler de 'progreso' (Mi Progreso) según instrucciones
                case 'progreso':
                    echo '<h2>Progreso del Voluntario</h2>';
                    echo do_shortcode('[gw_progreso_voluntario]');
                    break;
                case 'ausencias': echo '<h2>Seguimiento de Ausencias</h2><p>Próximamente podrás gestionar ausencias aquí.</p>'; break;
                case 'reportes': echo '<h2>Reportes y Listados</h2><p>Próximamente podrás ver reportes aquí.</p>'; break;
                default: echo '<h2>Bienvenido/a al Panel Administrativo</h2>'; break;
            }
            ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
// Redirigir por rol automáticamente al iniciar sesión
add_action('wp_login', 'gw_redireccion_por_rol', 10, 2);
function gw_redireccion_por_rol($user_login, $user) {
    $roles = $user->roles;
    $rol = isset($roles[0]) ? $roles[0] : '';

    if ($rol === 'voluntario') {
        wp_redirect(site_url('/index.php/portal-voluntario/'));
        exit;
    } elseif (in_array($rol, ['administrator', 'coordinador_pais', 'coach'])) {
        wp_redirect(site_url('/panel-administrativo/'));
        exit;
    }
}

// Bloquear acceso al admin a los voluntarios
add_action('admin_init', function () {
    if (current_user_can('voluntario') && !current_user_can('manage_options')) {
        wp_redirect(site_url('/index.php/portal-voluntario/'));
        exit;
    }
});