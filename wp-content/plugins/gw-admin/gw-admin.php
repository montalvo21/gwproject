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
<link rel="stylesheet" href="<?php echo plugins_url('gw-manager/css/gw-step2-styles.css?v=' . time()); ?>">
    <div class="gw-modern-wrapper">
        <div class="gw-form-wrapper">
            <!-- Panel lateral con pasos -->
            <div class="gw-sidebar">
            <div class="gw-hero-logo2">
                <img src="https://glasswing.org/es/wp-content/uploads/2023/08/Logo-Glasswing-02.png" alt="Logo Glasswing">
            </div> 

                <div class="gw-steps-container">
                    <!-- Paso 1 -->
                    <div class="gw-step-item active">
                    <div class="gw-step-number">1</div>
                    <div class="gw-step-content">
                        <h3>Información personal</h3>
                        <p>Cuéntanos quién eres para empezar.</p>
                    </div>
                    </div>

                    <!-- Paso 2 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">2</div>
                    <div class="gw-step-content">
                        <h3>Video introductorio</h3>
                        <p>Mira este breve video para conocer Glasswing y tu rol.</p>
                    </div>
                    </div>

                    <!-- Paso 3 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">3</div>
                    <div class="gw-step-content">
                        <h3>Verificación de identidad</h3>
                        <p>Confirma tus datos para mantener tu cuenta segura.</p>
                    </div>
                    </div>

                    <!-- Paso 4 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">4</div>
                    <div class="gw-step-content">
                        <h3>Activar cuenta</h3>
                        <p>Confirma y activa tu cuenta para empezar.</p>
                    </div>
                    </div>

                    <!-- Paso 5 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">5</div>
                    <div class="gw-step-content">
                        <h3>Charlas</h3>
                        <p>Regístrate en la charla asignada y participa.</p>
                    </div>
                    </div>

                    <!-- Paso 6 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">6</div>
                    <div class="gw-step-content">
                        <h3>Selección de proyecto</h3>
                        <p>Elige el proyecto en el que participarás.</p>
                    </div>
                    </div>

                    <!-- Paso 7 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">7</div>
                    <div class="gw-step-content">
                        <h3>Capacitaciones</h3>
                        <p>Inscríbete y marca tu asistencia para continuar.</p>
                    </div>
                    </div>

                </div>

                <div class="gw-sidebar-footer">
                    <div class="gw-help-section">
                    <div class="gw-help-text">
                        <h4>Conoce más sobre Glasswing</h4>
                        <p>
                            Visita nuestro sitio oficial  
                            <a href="https://glasswing.org/" target="_blank" rel="noopener noreferrer">
                            Ve a glasswing.org
                            </a>
                        </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Contenido principal -->
            <div class="gw-main-content">
                <div class="gw-form-container">
                    <div class="gw-form-header">
                        <h1>Información Personal</h1>
                    </div>

                    <?php if ($error): ?>
                        <div class="gw-error-message">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/>
                                <line x1="15" y1="9" x2="9" y2="15"/>
                                <line x1="9" y1="9" x2="15" y2="15"/>
                            </svg>
                            <span><?php echo esc_html($error); ?></span>
                        </div>
                    <?php endif; ?>

                    <form method="post" class="gw-form">
                        <?php wp_nonce_field('gw_datos_personales', 'gw_datos_nonce'); ?>
                        
                        <div class="gw-form-grid">
                            <div class="gw-form-group">
                                <label for="nombre">Nombre Completo<span class="gw-required">*</span></label>
                                <input type="text" 
                                       name="nombre" 
                                       id="nombre" 
                                       value="<?php echo esc_attr($nombre); ?>" 
                                       placeholder="Ejem. John Carter" 
                                       required>
                            </div>
                            
                            <div class="gw-form-group">
                                <label for="correo">Email<span class="gw-required">*</span></label>
                                <input type="email" 
                                       name="correo" 
                                       id="correo" 
                                       value="<?php echo esc_attr($user->user_email); ?>" 
                                       placeholder="Ingresa tu email"
                                       readonly>
                            </div>

                            <div class="gw-form-group">
                                <label for="telefono">Numero de Telefonico<span class="gw-required">*</span></label>
                                <input type="text" 
                                       name="telefono" 
                                       id="telefono" 
                                       value="<?php echo esc_attr($telefono); ?>" 
                                       placeholder="(123) 000-0000" 
                                       required>
                            </div>

                            <div class="gw-form-group">
                            <label for="pais">País:</label>
             <select name="pais" id="pais" required>
                 <option value="">Selecciona tu país</option>
                 <?php foreach($paises as $p): ?>
                     <option value="<?php echo esc_attr($p->post_title); ?>" <?php selected($pais, $p->post_title); ?>>
                         <?php echo esc_html($p->post_title); ?>
                     </option>
                 <?php endforeach; ?>
             </select>
                            </div>
                            
                        
                        </div>
                        
                        <div class="gw-form-actions">
                            <button type="submit" class="gw-btn-primary">
                                Continue
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
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
        // Cancela recordatorios
        gw_cancelar_recordatorios_aspirante($user_id);
        wp_safe_redirect(site_url('/index.php/portal-voluntario/'));
        exit;
    }

    ob_start();
    ?>
<link rel="stylesheet" href="<?php echo plugins_url('gw-manager/css/gw-step3-styles.css?v=' . time()); ?>">
    <div class="gw-modern-wrapper">
        <div class="gw-form-wrapper">
            <!-- Panel lateral con pasos -->
            <div class="gw-sidebar">
            <div class="gw-hero-logo2">
                <img src="https://glasswing.org/es/wp-content/uploads/2023/08/Logo-Glasswing-02.png" alt="Logo Glasswing">
            </div> 

                <div class="gw-steps-container">
                    <!-- Paso 1 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">✓</div>
                    <div class="gw-step-content">
                        <h3>Información personal</h3>
                        <p>Cuéntanos quién eres para empezar.</p>
                    </div>
                    </div>

                    <!-- Paso 2 -->
                    <div class="gw-step-item active">
                    <div class="gw-step-number">2</div>
                    <div class="gw-step-content">
                        <h3>Video introductorio</h3>
                        <p>Mira este breve video para conocer Glasswing y tu rol.</p>
                    </div>
                    </div>

                    <!-- Paso 3 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">3</div>
                    <div class="gw-step-content">
                        <h3>Verificación de identidad</h3>
                        <p>Confirma tus datos para mantener tu cuenta segura.</p>
                    </div>
                    </div>

                    <!-- Paso 4 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">4</div>
                    <div class="gw-step-content">
                        <h3>Activar cuenta</h3>
                        <p>Confirma y activa tu cuenta para empezar.</p>
                    </div>
                    </div>

                    <!-- Paso 5 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">5</div>
                    <div class="gw-step-content">
                        <h3>Charlas</h3>
                        <p>Regístrate en la charla asignada y participa.</p>
                    </div>
                    </div>

                    <!-- Paso 6 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">6</div>
                    <div class="gw-step-content">
                        <h3>Selección de proyecto</h3>
                        <p>Elige el proyecto en el que participarás.</p>
                    </div>
                    </div>

                    <!-- Paso 7 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">7</div>
                    <div class="gw-step-content">
                        <h3>Capacitaciones</h3>
                        <p>Inscríbete y marca tu asistencia para continuar.</p>
                    </div>
                    </div>

                </div>

                <div class="gw-sidebar-footer">
                    <div class="gw-help-section">
                    <div class="gw-help-text">
                        <h4>Conoce más sobre Glasswing</h4>
                        <p>
                            Visita nuestro sitio oficial  
                            <a href="https://glasswing.org/" target="_blank" rel="noopener noreferrer">
                            Ve a glasswing.org
                            </a>
                        </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Contenido principal -->
            <div class="gw-main-content">
    <div class="gw-form-container">
        <div class="gw-form-header">
            <h1>Video introductorio</h1>
            <p>Mira este breve video para conocer Glasswing y tu rol como voluntario.</p>
        </div>

        <div class="gw-video-help-info">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
                <polygon points="10,8 16,12 10,16 10,8"/>
            </svg>
            <span><strong>Importante:</strong> Debes ver el video completo para continuar al siguiente paso.</span>
        </div>

        <div class="gw-video-container">
            <div id="gw-video-youtube"></div>
        </div>
        
        <form method="post" class="gw-form">
            <?php wp_nonce_field('gw_video_intro', 'gw_video_nonce'); ?>
            
            <div class="gw-form-actions">
                <button type="submit" id="gw-video-btn" class="gw-btn-primary" disabled>
                    He visto el video / Continuar
                </button>
            </div>
        </form>

        <div class="gw-video-info">
            <p>🔒 Tu progreso se guarda automáticamente</p>
        </div>
    </div>
</div>

        </div>
    </div>
    
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
            height: '100%',
            width: '100%',
            videoId: '<?php echo esc_js($video_id); ?>',
            playerVars: {
                'modestbranding': 1,
                'rel': 0,
                'showinfo': 0,
                'controls': 1,
                'autoplay': 0
            },
            events: {
                'onStateChange': function(event) {
                    if (event.data == YT.PlayerState.ENDED) {
                        gw_video_done = true;
                        var btn = document.getElementById('gw-video-btn');
                        btn.disabled = false;
                        btn.style.background = 'linear-gradient(135deg, #c4c33f 0%, #a3a332 100%)';
                        btn.style.cursor = 'pointer';
                        btn.style.opacity = '1';
                    }
                }
            }
       });
    }
    </script>
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
            $error = 'Por favor completa todos los campos requeridos.';
        } elseif ($edad < 15 || $edad > 99) {
            $error = 'La edad debe estar entre 15 y 99 años.';
        } elseif (strlen($motivo) < 10) {
            $error = 'El motivo debe tener al menos 10 caracteres.';
        } else {
            update_user_meta($user_id, 'gw_induccion_nombre', $nombre);
            update_user_meta($user_id, 'gw_induccion_motivo', $motivo);
            update_user_meta($user_id, 'gw_induccion_edad', $edad);
            update_user_meta($user_id, 'gw_induccion_pais', $pais);
            update_user_meta($user_id, 'gw_step4_completo', 1);
            // Cancela recordatorios
            gw_cancelar_recordatorios_aspirante($user_id);
            wp_safe_redirect(site_url('/index.php/portal-voluntario/'));
            exit;
        }
    } else {
        // Cargar datos existentes si los hay
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

    // Si no hay países en el CPT, usar lista básica
    if (empty($paises)) {
        $paises_basicos = [
            'Argentina', 'Bolivia', 'Brasil', 'Chile', 'Colombia', 'Costa Rica', 
            'Cuba', 'Ecuador', 'El Salvador', 'España', 'Guatemala', 'Honduras', 
            'México', 'Nicaragua', 'Panamá', 'Paraguay', 'Perú', 'Puerto Rico', 
            'República Dominicana', 'Uruguay', 'Venezuela'
        ];
    }

    ob_start();
    ?>
<link rel="stylesheet" href="<?php echo plugins_url('gw-manager/css/gw-step4-styles.css?v=' . time()); ?>">
    <div class="gw-modern-wrapper">
        <div class="gw-form-wrapper">
            <!-- Panel lateral con pasos -->
            <div class="gw-sidebar">
            <div class="gw-hero-logo2">
                <img src="https://glasswing.org/es/wp-content/uploads/2023/08/Logo-Glasswing-02.png" alt="Logo Glasswing">
            </div> 

                <div class="gw-steps-container">
                    <!-- Paso 1 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">✓</div>
                    <div class="gw-step-content">
                        <h3>Información personal</h3>
                        <p>Cuéntanos quién eres para empezar.</p>
                    </div>
                    </div>

                    <!-- Paso 2 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">✓</div>
                    <div class="gw-step-content">
                        <h3>Video introductorio</h3>
                        <p>Mira este breve video para conocer Glasswing y tu rol.</p>
                    </div>
                    </div>

                    <!-- Paso 3 -->
                    <div class="gw-step-item active">
                    <div class="gw-step-number">3</div>
                    <div class="gw-step-content">
                        <h3>Registro para inducción</h3>
                        <p>Completa tu información para la inducción.</p>
                    </div>
                    </div>

                    <!-- Paso 4 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">4</div>
                    <div class="gw-step-content">
                        <h3>Activar cuenta</h3>
                        <p>Confirma y activa tu cuenta para empezar.</p>
                    </div>
                    </div>

                    <!-- Paso 5 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">5</div>
                    <div class="gw-step-content">
                        <h3>Charlas</h3>
                        <p>Regístrate en la charla asignada y participa.</p>
                    </div>
                    </div>

                    <!-- Paso 6 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">6</div>
                    <div class="gw-step-content">
                        <h3>Selección de proyecto</h3>
                        <p>Elige el proyecto en el que participarás.</p>
                    </div>
                    </div>

                    <!-- Paso 7 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">7</div>
                    <div class="gw-step-content">
                        <h3>Capacitaciones</h3>
                        <p>Inscríbete y marca tu asistencia para continuar.</p>
                    </div>
                    </div>

                </div>

                <div class="gw-sidebar-footer">
                    <div class="gw-help-section">
                    <div class="gw-help-text">
                        <h4>Conoce más sobre Glasswing</h4>
                        <p>
                            Visita nuestro sitio oficial  
                            <a href="https://glasswing.org/" target="_blank" rel="noopener noreferrer">
                            Ve a glasswing.org
                            </a>
                        </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Contenido principal -->
            <div class="gw-main-content">
                <div class="gw-form-container">
                    <div class="gw-form-header">
                        <h1>Registro para inducción</h1>
                        <p>Completa tu información para el proceso de inducción.</p>
                    </div>

                    <?php if ($error): ?>
                        <div class="gw-error-message">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/>
                                <line x1="15" y1="9" x2="9" y2="15"/>
                                <line x1="9" y1="9" x2="15" y2="15"/>
                            </svg>
                            <span><?php echo esc_html($error); ?></span>
                        </div>
                    <?php endif; ?>

                    <form method="post" class="gw-form" id="gw-induction-form" novalidate>
                        <?php wp_nonce_field('gw_form_induccion', 'gw_induccion_nonce'); ?>
                        
                        <div class="gw-form-grid">
                            <div class="gw-form-group gw-full-width">
                                <label for="nombre">Nombre completo<span class="gw-required">*</span></label>
                                <input type="text" 
                                       name="nombre" 
                                       id="nombre" 
                                       value="<?php echo esc_attr($nombre); ?>" 
                                       placeholder="Ingresa tu nombre completo" 
                                       required
                                       minlength="2"
                                       maxlength="100"
                                       autocomplete="name">
                            </div>
                            
                            <div class="gw-form-group">
                                <label for="edad">Edad<span class="gw-required">*</span></label>
                                <input type="number" 
                                       name="edad" 
                                       id="edad" 
                                       min="15" 
                                       max="99" 
                                       value="<?php echo esc_attr($edad); ?>" 
                                       placeholder="Tu edad"
                                       required>
                                <small class="gw-field-help">Debes tener entre 15 y 99 años para participar</small>
                            </div>

                            <div class="gw-form-group">
                                <label for="pais">País de residencia<span class="gw-required">*</span></label>
                                <select name="pais" id="pais" required>
                                    <option value="">Selecciona tu país</option>
                                    <?php if (!empty($paises)): ?>
                                        <?php foreach($paises as $p): ?>
                                            <option value="<?php echo esc_attr($p->post_title); ?>" <?php selected($pais, $p->post_title); ?>>
                                                <?php echo esc_html($p->post_title); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <?php foreach($paises_basicos as $pais_basico): ?>
                                            <option value="<?php echo esc_attr($pais_basico); ?>" <?php selected($pais, $pais_basico); ?>>
                                                <?php echo esc_html($pais_basico); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <div class="gw-form-group gw-full-width">
                                <label for="motivo">Motivo de inscripción<span class="gw-required">*</span></label>
                                <textarea name="motivo" 
                                          id="motivo" 
                                          rows="4" 
                                          required
                                          minlength="10"
                                          maxlength="500"
                                          placeholder="Cuéntanos por qué te interesa participar en este programa..."><?php echo esc_textarea($motivo); ?></textarea>
                                <small class="gw-char-counter">
                                    <span id="motivo-count"><?php echo strlen($motivo); ?></span>/500 caracteres
                                </small>
                            </div>
                        </div>
                        
                        <div class="gw-form-actions">
                            <button type="submit" class="gw-btn-primary" id="gw-submit-btn">
                                <span class="gw-btn-text">Guardar y continuar</span>
                                <span class="gw-btn-loading" style="display: none;">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M12 2V6M12 18V22M4.93 4.93L7.76 7.76M16.24 16.24L19.07 19.07M2 12H6M18 12H22M4.93 19.07L7.76 16.24M16.24 7.76L19.07 4.93" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                    Guardando...
                                </span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script>
    (function() {
        'use strict';
        
        // Elementos del DOM
        const form = document.getElementById('gw-induction-form');
        const submitBtn = document.getElementById('gw-submit-btn');
        const btnText = submitBtn.querySelector('.gw-btn-text');
        const btnLoading = submitBtn.querySelector('.gw-btn-loading');
        const motivoTextarea = document.getElementById('motivo');
        const motivoCounter = document.getElementById('motivo-count');
        
        // Contador de caracteres para el textarea
        if (motivoTextarea && motivoCounter) {
            motivoTextarea.addEventListener('input', function() {
                const count = this.value.length;
                motivoCounter.textContent = count;
                
                // Cambiar color según proximidad al límite
                const counter = motivoCounter.parentElement;
                counter.classList.remove('near-limit', 'at-limit');
                
                if (count >= 450) {
                    counter.classList.add('at-limit');
                } else if (count >= 400) {
                    counter.classList.add('near-limit');
                }
            });
        }
        
        // Validación en tiempo real
        const inputs = form.querySelectorAll('input[required], textarea[required], select[required]');
        inputs.forEach(input => {
            input.addEventListener('blur', validateField);
            input.addEventListener('input', clearErrors);
        });
        
        function validateField(e) {
            const field = e.target;
            const value = field.value.trim();
            
            // Remover clases de error previas
            field.classList.remove('error');
            
            // Validaciones específicas
            if (field.name === 'nombre' && value.length < 2) {
                showFieldError(field, 'El nombre debe tener al menos 2 caracteres');
                return false;
            }
            
            if (field.name === 'motivo' && value.length < 10) {
                showFieldError(field, 'El motivo debe tener al menos 10 caracteres');
                return false;
            }
            
            if (field.name === 'edad') {
                const edad = parseInt(value);
                if (edad < 15 || edad > 99) {
                    showFieldError(field, 'La edad debe estar entre 15 y 99 años');
                    return false;
                }
            }
            
            if (!value && field.hasAttribute('required')) {
                showFieldError(field, 'Este campo es requerido');
                return false;
            }
            
            return true;
        }
        
        function showFieldError(field, message) {
            field.classList.add('error');
            
            // Mostrar mensaje de error
            let errorMsg = field.parentElement.querySelector('.error-message');
            if (!errorMsg) {
                errorMsg = document.createElement('small');
                errorMsg.className = 'error-message';
                field.parentElement.appendChild(errorMsg);
            }
            errorMsg.textContent = message;
        }
        
        function clearErrors(e) {
            const field = e.target;
            field.classList.remove('error');
            const errorMsg = field.parentElement.querySelector('.error-message');
            if (errorMsg) {
                errorMsg.remove();
            }
        }
        
        // Manejo del envío del formulario
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Validar todos los campos
            let isValid = true;
            inputs.forEach(input => {
                if (!validateField({target: input})) {
                    isValid = false;
                }
            });
            
            if (!isValid) {
                // Scroll al primer campo con error
                const firstError = form.querySelector('.error');
                if (firstError) {
                    firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    firstError.focus();
                }
                return;
            }
            
            // Mostrar estado de carga
            submitBtn.disabled = true;
            submitBtn.classList.add('loading');
            btnText.style.display = 'none';
            btnLoading.style.display = 'flex';
            
            // Enviar formulario
            this.submit();
        });
        
        // Prevenir envío doble
        let isSubmitting = false;
        form.addEventListener('submit', function() {
            if (isSubmitting) {
                return false;
            }
            isSubmitting = true;
        });
    })();
    </script>
    <?php
    return ob_get_clean();
}

// FLUJO SECUENCIAL: voluntario solo ve y completa UNA charla a la vez.
// Solo admin puede usar atajos o regresar.
function gw_step_5_charla($user_id) {
    // Lógica de menú forzado
    $forzar_menu_paso5 = isset($_GET['paso5_menu']) && $_GET['paso5_menu'] == 1;

    // --- Bloque para asignar charlas manualmente por el admin ---
    $admin_assign_output = '';
    $current_user = wp_get_current_user();
    $is_admin = in_array('administrator', $current_user->roles);
    if ($is_admin && isset($_POST['gw_asignar_charlas_nonce']) && wp_verify_nonce($_POST['gw_asignar_charlas_nonce'], 'gw_asignar_charlas')) {
        $ids = sanitize_text_field($_POST['gw_charlas_ids']);
        $ids_arr = array_filter(array_map('intval', explode(',', $ids)));
        update_user_meta($user_id, 'gw_charlas_asignadas', $ids_arr);
        $admin_assign_output = '<div class="notice notice-success" style="margin-bottom:10px;">Charlas asignadas: ' . esc_html(implode(', ', $ids_arr)) . '</div>';
    }

    // Si ya está completo, redirige
    if (get_user_meta($user_id, 'gw_step5_completo', true)) {
        return '<meta http-equiv="refresh" content="0">';
    }

    // --- Cargar arrays de charlas asignadas y completadas ---
    $charlas_asignadas = get_user_meta($user_id, 'gw_charlas_asignadas', true);
    if (!is_array($charlas_asignadas)) {
        $charlas_asignadas = [];
    }
    if (empty($charlas_asignadas) && !in_array('administrator', $current_user->roles)) {
        $all_charlas = get_posts([
            'post_type'   => 'charla',
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby'     => 'menu_order',
            'order'       => 'ASC',
        ]);
        $charlas_asignadas = wp_list_pluck($all_charlas, 'ID');
    }

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

    // Si NO hay charla pendiente, mostrar menú principal
    if (!$charla_pendiente_id || empty($charlas_asignadas)) {
        if ($is_admin || defined('GW_TESTING_MODE')) {
            ob_start();
            ?>
<link rel="stylesheet" href="<?php echo plugins_url('gw-manager/css/gw-step5-styles.css?v=' . time()); ?>">
    <div class="gw-modern-wrapper">
        <div class="gw-form-wrapper">
            <!-- Panel lateral con pasos -->
            <div class="gw-sidebar">
            <div class="gw-hero-logo2">
                <img src="https://glasswing.org/es/wp-content/uploads/2023/08/Logo-Glasswing-02.png" alt="Logo Glasswing">
            </div> 

                <div class="gw-steps-container">
                    <!-- Paso 1 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">✓</div>
                    <div class="gw-step-content">
                        <h3>Información personal</h3>
                        <p>Cuéntanos quién eres para empezar.</p>
                    </div>
                    </div>

                    <!-- Paso 2 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">✓</div>
                    <div class="gw-step-content">
                        <h3>Video introductorio</h3>
                        <p>Mira este breve video para conocer Glasswing y tu rol.</p>
                    </div>
                    </div>

                    <!-- Paso 3 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">✓</div>
                    <div class="gw-step-content">
                        <h3>Registro para inducción</h3>
                        <p>Completa tu información para la inducción.</p>
                    </div>
                    </div>

                    <!-- Paso 4 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">✓</div>
                    <div class="gw-step-content">
                        <h3>Activar cuenta</h3>
                        <p>Confirma y activa tu cuenta para empezar.</p>
                    </div>
                    </div>

                    <!-- Paso 5 -->
                    <div class="gw-step-item active">
                    <div class="gw-step-number">5</div>
                    <div class="gw-step-content">
                        <h3>Charlas</h3>
                        <p>Regístrate en la charla asignada y participa.</p>
                    </div>
                    </div>

                    <!-- Paso 6 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">6</div>
                    <div class="gw-step-content">
                        <h3>Selección de proyecto</h3>
                        <p>Elige el proyecto en el que participarás.</p>
                    </div>
                    </div>

                    <!-- Paso 7 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">7</div>
                    <div class="gw-step-content">
                        <h3>Capacitaciones</h3>
                        <p>Inscríbete y marca tu asistencia para continuar.</p>
                    </div>
                    </div>

                </div>

                <div class="gw-sidebar-footer">
                    <div class="gw-help-section">
                    <div class="gw-help-text">
                        <h4>Conoce más sobre Glasswing</h4>
                        <p>
                            Visita nuestro sitio oficial  
                            <a href="https://glasswing.org/" target="_blank" rel="noopener noreferrer">
                            Ve a glasswing.org
                            </a>
                        </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Contenido principal -->
            <div class="gw-main-content">
                <div class="gw-form-container">
                    <div class="gw-form-header">
                        <h1>¡Charlas completadas!</h1>
                        <p>Has completado todas tus charlas asignadas exitosamente.</p>
                    </div>

                    <div class="gw-success-message">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M9 12l2 2 4-4"></path>
                            <circle cx="12" cy="12" r="10"></circle>
                        </svg>
                        <span>¡Excelente trabajo! Ahora puedes continuar a las capacitaciones.</span>
                    </div>
                    
                    <div class="gw-form-actions">
                        <a href="<?php echo esc_url(site_url('/index.php/portal-voluntario/?paso5_menu=1')); ?>" class="gw-btn-primary">
                            Ir a capacitaciones
                        </a>
                    </div>

                    <?php if ($is_admin || defined('GW_TESTING_MODE')): ?>
                        <form method="post" class="gw-admin-controls">
                            <?php wp_nonce_field('gw_charla_regresar_admin', 'gw_charla_regresar_admin_nonce'); ?>
                            <button type="submit" name="gw_charla_regresar_admin" class="gw-btn-admin">
                                REGRESAR (ADMIN)
                            </button>
                            <small>Solo admin/testing: retrocede a charla anterior.</small>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
            <?php
            // Procesar REGRESAR (ADMIN) en menú principal
            if (
                $_SERVER['REQUEST_METHOD'] === 'POST'
                && isset($_POST['gw_charla_regresar_admin_nonce'])
                && wp_verify_nonce($_POST['gw_charla_regresar_admin_nonce'], 'gw_charla_regresar_admin')
                && ($is_admin || defined('GW_TESTING_MODE'))
            ) {
                $charlas_completadas = get_user_meta($user_id, 'gw_charlas_completadas', true);
                if (!is_array($charlas_completadas)) $charlas_completadas = [];
                if (!empty($charlas_completadas)) {
                    array_pop($charlas_completadas);
                    update_user_meta($user_id, 'gw_charlas_completadas', $charlas_completadas);
                }
                delete_user_meta($user_id, 'gw_charla_agendada');
                delete_user_meta($user_id, 'gw_step5_completo');
                echo '<meta http-equiv="refresh" content="1">';
                return ob_get_clean();
            }
            if ($is_admin) {
                echo gw_step_5_charla_admin_form($user_id, $charlas_asignadas, $admin_assign_output);
            }
            return ob_get_clean();
        } else {
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
    
    $charla_actual = null;
    foreach ($charlas as $ch) {
        if ($ch->ID == $charla_pendiente_id) {
            $charla_actual = $ch;
            break;
        }
    }
    
    if (!$charla_actual) {
        ob_start();
        ?>
<link rel="stylesheet" href="<?php echo plugins_url('gw-manager/css/gw-step5-styles.css?v=' . time()); ?>">
    <div class="gw-modern-wrapper">
        <div class="gw-form-wrapper">
            <div class="gw-sidebar">
                <!-- Sidebar content aquí -->
            </div>
            <div class="gw-main-content">
                <div class="gw-form-container">
                    <div class="gw-error-message">
                        <span>La charla asignada (ID: <?php echo esc_html($charla_pendiente_id); ?>) no existe. Contacta a soporte.</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
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

    // Obtener sesión agendada (solo para esta charla)
    $agendada = get_user_meta($user_id, 'gw_charla_agendada', true);
    if ($agendada && (int)$agendada['charla_id'] !== (int)$charla_actual->ID) {
        $agendada = null;
        delete_user_meta($user_id, 'gw_charla_agendada');
    }

    if ($forzar_menu_paso5) {
        $agendada = null;
    }

    // Mostrar charla agendada
    if ($agendada && !isset($_GET['charla_id']) && !isset($_GET['charla_idx'])) {
        $ya_ocurrio = strtotime($agendada['fecha'].' '.$agendada['hora']) < time();
        ob_start();
        ?>
<link rel="stylesheet" href="<?php echo plugins_url('gw-manager/css/gw-step5-styles.css?v=' . time()); ?>">
    <div class="gw-modern-wrapper">
        <div class="gw-form-wrapper">
            <!-- Panel lateral con pasos -->
            <div class="gw-sidebar">
            <div class="gw-hero-logo2">
                <img src="https://glasswing.org/es/wp-content/uploads/2023/08/Logo-Glasswing-02.png" alt="Logo Glasswing">
            </div> 

                <div class="gw-steps-container">
                    <!-- Paso 1 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">✓</div>
                    <div class="gw-step-content">
                        <h3>Información personal</h3>
                        <p>Cuéntanos quién eres para empezar.</p>
                    </div>
                    </div>

                    <!-- Paso 2 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">✓</div>
                    <div class="gw-step-content">
                        <h3>Video introductorio</h3>
                        <p>Mira este breve video para conocer Glasswing y tu rol.</p>
                    </div>
                    </div>

                    <!-- Paso 3 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">✓</div>
                    <div class="gw-step-content">
                        <h3>Registro para inducción</h3>
                        <p>Completa tu información para la inducción.</p>
                    </div>
                    </div>

                    <!-- Paso 4 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">✓</div>
                    <div class="gw-step-content">
                        <h3>Activar cuenta</h3>
                        <p>Confirma y activa tu cuenta para empezar.</p>
                    </div>
                    </div>

                    <!-- Paso 5 -->
                    <div class="gw-step-item active">
                    <div class="gw-step-number">5</div>
                    <div class="gw-step-content">
                        <h3>Charlas</h3>
                        <p>Regístrate en la charla asignada y participa.</p>
                    </div>
                    </div>

                    <!-- Paso 6 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">6</div>
                    <div class="gw-step-content">
                        <h3>Selección de proyecto</h3>
                        <p>Elige el proyecto en el que participarás.</p>
                    </div>
                    </div>

                    <!-- Paso 7 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">7</div>
                    <div class="gw-step-content">
                        <h3>Capacitaciones</h3>
                        <p>Inscríbete y marca tu asistencia para continuar.</p>
                    </div>
                    </div>

                </div>

                <div class="gw-sidebar-footer">
                    <div class="gw-help-section">
                    <div class="gw-help-text">
                        <h4>Conoce más sobre Glasswing</h4>
                        <p>
                            Visita nuestro sitio oficial  
                            <a href="https://glasswing.org/" target="_blank" rel="noopener noreferrer">
                            Ve a glasswing.org
                            </a>
                        </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Contenido principal -->
            <div class="gw-main-content">
                <div class="gw-form-container">
                    <div class="gw-form-header">
                        <h1>Charla registrada</h1>
                        <p>Te recordamos que te registraste en la siguiente charla.</p>
                    </div>

                    <div class="gw-charla-info">
                        <div class="gw-charla-title">
                            <?php echo esc_html($agendada['charla_title']); ?>
                            <span class="gw-charla-modalidad">(<?php echo ucfirst($agendada['modalidad']); ?>)</span>
                        </div>
                        
                        <div class="gw-charla-details">
                            <div class="gw-detail-item">
                                <strong>Fecha:</strong> <?php echo date('d/m/Y', strtotime($agendada['fecha'])); ?>
                            </div>
                            <div class="gw-detail-item">
                                <strong>Hora:</strong> <?php echo esc_html($agendada['hora']); ?>
                            </div>
                            <?php if ($agendada['modalidad']=='presencial'): ?>
                                <div class="gw-detail-item">
                                    <strong>Lugar:</strong> <?php echo esc_html($agendada['lugar']); ?>
                                </div>
                            <?php else: ?>
                                <div class="gw-detail-item">
                                    <strong>Enlace:</strong> <a href="<?php echo esc_url($agendada['enlace']); ?>" target="_blank"><?php echo esc_html($agendada['enlace']); ?></a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php
                    $predicted_completadas = count($charlas_completadas) + 1;
                    $has_more = count($charlas_asignadas) > $predicted_completadas;
                    $button_label = $has_more ? 'Siguiente charla' : 'Ir a capacitación';
                    ?>
                    
                    <form method="post" class="gw-form">
                        <?php wp_nonce_field('gw_charla_asistencia', 'gw_charla_asistencia_nonce'); ?>
                        <div class="gw-form-actions">
                            <button type="submit" class="gw-btn-primary">
                                <?php echo esc_html($button_label); ?>
                            </button>
                        </div>
                    </form>

                    <div class="gw-charla-note">
                        <p>Recuerda ir al enlace y marcar tu asistencia</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
        <?php
        
        // Procesar marcar asistencia
        if (
            $_SERVER['REQUEST_METHOD'] === 'POST'
            && isset($_POST['gw_charla_asistencia_nonce'])
            && wp_verify_nonce($_POST['gw_charla_asistencia_nonce'], 'gw_charla_asistencia')
        ) {
            $charlas_completadas[] = (int)$charla_actual->ID;
            update_user_meta($user_id, 'gw_charlas_completadas', array_unique($charlas_completadas));
            delete_user_meta($user_id, 'gw_charla_agendada');
            
            $quedan = false;
            foreach ($charlas_asignadas as $cid) {
                if (!in_array($cid, $charlas_completadas)) { $quedan = true; break; }
            }
            if (!$quedan) update_user_meta($user_id, 'gw_step5_completo', 1);
            
            if (!empty($charlas_completadas) && count($charlas_asignadas) > count($charlas_completadas)) {
                wp_safe_redirect(site_url('/index.php/portal-voluntario/'));
            } else {
                wp_safe_redirect(site_url('/index.php/portal-voluntario/?paso5_menu=1'));
            }
            exit;
        }

        if ($is_admin) echo gw_step_5_charla_admin_form($user_id, $charlas_asignadas, $admin_assign_output);
        return ob_get_clean();
    }

    // Continúa con el resto de la lógica original...
    // [El resto del código se mantiene igual pero adaptado al nuevo diseño]
    
    ob_start();
    ?>
<link rel="stylesheet" href="<?php echo plugins_url('gw-manager/css/gw-step5-styles.css?v=' . time()); ?>">
    <div class="gw-modern-wrapper">
        <div class="gw-form-wrapper">
            <!-- Panel lateral con pasos -->
            <div class="gw-sidebar">
            <div class="gw-hero-logo2">
                <img src="https://glasswing.org/es/wp-content/uploads/2023/08/Logo-Glasswing-02.png" alt="Logo Glasswing">
            </div> 

                <div class="gw-steps-container">
                    <!-- Pasos del 1 al 7 aquí -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">✓</div>
                    <div class="gw-step-content">
                        <h3>Información personal</h3>
                        <p>Cuéntanos quién eres para empezar.</p>
                    </div>
                    </div>

                    <div class="gw-step-item">
                    <div class="gw-step-number">✓</div>
                    <div class="gw-step-content">
                        <h3>Video introductorio</h3>
                        <p>Mira este breve video para conocer Glasswing y tu rol.</p>
                    </div>
                    </div>

                    <div class="gw-step-item">
                    <div class="gw-step-number">✓</div>
                    <div class="gw-step-content">
                        <h3>Registro para inducción</h3>
                        <p>Completa tu información para la inducción.</p>
                    </div>
                    </div>

                    <div class="gw-step-item">
                    <div class="gw-step-number">✓</div>
                    <div class="gw-step-content">
                        <h3>Activar cuenta</h3>
                        <p>Confirma y activa tu cuenta para empezar.</p>
                    </div>
                    </div>

                    <div class="gw-step-item active">
                    <div class="gw-step-number">5</div>
                    <div class="gw-step-content">
                        <h3>Charlas</h3>
                        <p>Regístrate en la charla asignada y participa.</p>
                    </div>
                    </div>

                    <div class="gw-step-item">
                    <div class="gw-step-number">6</div>
                    <div class="gw-step-content">
                        <h3>Selección de proyecto</h3>
                        <p>Elige el proyecto en el que participarás.</p>
                    </div>
                    </div>

                    <div class="gw-step-item">
                    <div class="gw-step-number">7</div>
                    <div class="gw-step-content">
                        <h3>Capacitaciones</h3>
                        <p>Inscríbete y marca tu asistencia para continuar.</p>
                    </div>
                    </div>

                </div>

                <div class="gw-sidebar-footer">
                    <div class="gw-help-section">
                    <div class="gw-help-text">
                        <h4>Conoce más sobre Glasswing</h4>
                        <p>
                            Visita nuestro sitio oficial  
                            <a href="https://glasswing.org/" target="_blank" rel="noopener noreferrer">
                            Ve a glasswing.org
                            </a>
                        </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Contenido principal -->
            <div class="gw-main-content">
                <div class="gw-form-container">
                    <div class="gw-form-header">
                        <h1>Charlas</h1>
                        <p>Selecciona una opción de horario para registrarte en la charla.</p>
                    </div>

                    <?php if (empty($charla_sesiones)): ?>
                        <div class="gw-error-message">
                            <span>Actualmente no hay sesiones disponibles para registro.</span>
                        </div>
                    <?php else: ?>
                        <div class="gw-charla-sessions">
                            <?php foreach ($charla_sesiones as $idx => $ses): ?>
                                <div class="gw-session-card">
                                    <div class="gw-session-info">
                                        <div class="gw-session-title">
                                            <strong>OPCIÓN <?php echo ($idx+1); ?>:</strong>
                                            <?php echo esc_html($ses['modalidad']=='virtual' ? "Google Meet" : strtoupper($ses['lugar'] ?: $ses['charla_title'])); ?>
                                        </div>
                                        <div class="gw-session-details">
                                            <?php echo date('d/m/Y', strtotime($ses['fecha'])).' a las '.substr($ses['hora'],0,5); ?>
                                        </div>
                                    </div>
                                    <form method="get" action="">
                                        <input type="hidden" name="charla_id" value="<?php echo esc_attr($ses['charla_id']); ?>">
                                        <input type="hidden" name="charla_idx" value="<?php echo esc_attr($ses['idx']); ?>">
                                        <button type="submit" class="gw-btn-secondary">Seleccionar</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
    
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
<link rel="stylesheet" href="<?php echo plugins_url('gw-manager/css/gw-step5-admin-styles.css?v=' . time()); ?>">
    <div class="gw-admin-panel">
        <div class="gw-admin-header">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 1L21.5 7V17L12 23L2.5 17V7L12 1Z"/>
                <path d="M12 8C13.66 8 15 6.66 15 5S13.66 2 12 2S9 3.34 9 5S10.34 8 12 8Z"/>
                <path d="M12 14C10.34 14 9 15.34 9 17V19C9 20.66 10.34 22 12 22S15 20.66 15 19V17C15 15.34 13.66 14 12 14Z"/>
            </svg>
            <h3>Panel de Administrador</h3>
        </div>
        
        <div class="gw-admin-description">
            <p>Asignar charlas manualmente a este usuario</p>
            <small>Ingresa los IDs de las charlas separados por comas</small>
        </div>

        <?php if ($admin_output): ?>
            <div class="gw-admin-message">
                <?php echo $admin_output; ?>
            </div>
        <?php endif; ?>

        <form method="post" class="gw-admin-form">
            <?php wp_nonce_field('gw_asignar_charlas', 'gw_asignar_charlas_nonce'); ?>
            
            <div class="gw-admin-field">
                <label for="gw_charlas_ids">IDs de Charlas:</label>
                <div class="gw-admin-input-group">
                    <input type="text" 
                           name="gw_charlas_ids" 
                           id="gw_charlas_ids"
                           value="<?php echo esc_attr(implode(',', $charlas_asignadas)); ?>" 
                           placeholder="Ej: 123,456,789"
                           class="gw-admin-input">
                    <button type="submit" class="gw-btn-admin">Asignar</button>
                </div>
            </div>

            <div class="gw-admin-charlas-list">
                <strong>Charlas disponibles:</strong>
                <div class="gw-charlas-grid">
                    <?php foreach ($all_charlas as $c): ?>
                        <div class="gw-charla-item" title="<?php echo esc_attr($c->post_title); ?>">
                            <span class="gw-charla-id">#<?php echo $c->ID; ?></span>
                            <span class="gw-charla-title"><?php echo esc_html($c->post_title); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
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
            ob_start();
            ?>
<link rel="stylesheet" href="<?php echo plugins_url('gw-manager/css/gw-step6-styles.css?v=' . time()); ?>">
    <div class="gw-modern-wrapper">
        <div class="gw-form-wrapper">
            <div class="gw-sidebar">
                <!-- Sidebar content -->
            </div>
            <div class="gw-main-content">
                <div class="gw-form-container">
                    <div class="gw-error-message">
                        <span>El proyecto seleccionado no existe.</span>
                    </div>
                    <div class="gw-form-actions">
                        <a href="<?php echo esc_url(site_url('/index.php/portal-voluntario/')); ?>" class="gw-btn-primary">
                            Volver a mi cuenta
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
            <?php
            return ob_get_clean();
        }
        
        // Procesar confirmación
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmar_inscripcion']) && isset($_POST['confirm_proyecto_id']) && intval($_POST['confirm_proyecto_id']) == $confirm_proyecto_id) {
            update_user_meta($user_id, 'gw_proyecto_id', $confirm_proyecto_id);
            // Cancela recordatorios
            gw_cancelar_recordatorios_aspirante($user_id);
            wp_safe_redirect(site_url('/index.php/portal-voluntario/'));
            exit;
        }
        
        // Procesar cancelar
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancelar'])) {
            wp_safe_redirect(site_url('/index.php/portal-voluntario/'));
            exit;
        }
        
        // Mostrar página de confirmación
        ob_start();
        ?>
<link rel="stylesheet" href="<?php echo plugins_url('gw-manager/css/gw-step6-styles.css?v=' . time()); ?>">
    <div class="gw-modern-wrapper">
        <div class="gw-form-wrapper">
            <!-- Panel lateral con pasos -->
            <div class="gw-sidebar">
            <div class="gw-hero-logo2">
                <img src="https://glasswing.org/es/wp-content/uploads/2023/08/Logo-Glasswing-02.png" alt="Logo Glasswing">
            </div> 

                <div class="gw-steps-container">
                    <!-- Paso 1 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">✓</div>
                    <div class="gw-step-content">
                        <h3>Información personal</h3>
                        <p>Cuéntanos quién eres para empezar.</p>
                    </div>
                    </div>

                    <!-- Paso 2 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">✓</div>
                    <div class="gw-step-content">
                        <h3>Video introductorio</h3>
                        <p>Mira este breve video para conocer Glasswing y tu rol.</p>
                    </div>
                    </div>

                    <!-- Paso 3 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">✓</div>
                    <div class="gw-step-content">
                        <h3>Registro para inducción</h3>
                        <p>Completa tu información para la inducción.</p>
                    </div>
                    </div>

                    <!-- Paso 4 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">✓</div>
                    <div class="gw-step-content">
                        <h3>Activar cuenta</h3>
                        <p>Confirma y activa tu cuenta para empezar.</p>
                    </div>
                    </div>

                    <!-- Paso 5 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">✓</div>
                    <div class="gw-step-content">
                        <h3>Charlas</h3>
                        <p>Regístrate en la charla asignada y participa.</p>
                    </div>
                    </div>

                    <!-- Paso 6 -->
                    <div class="gw-step-item active">
                    <div class="gw-step-number">6</div>
                    <div class="gw-step-content">
                        <h3>Selección de proyecto</h3>
                        <p>Elige el proyecto en el que participarás.</p>
                    </div>
                    </div>

                    <!-- Paso 7 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">7</div>
                    <div class="gw-step-content">
                        <h3>Capacitaciones</h3>
                        <p>Inscríbete y marca tu asistencia para continuar.</p>
                    </div>
                    </div>

                </div>

                <div class="gw-sidebar-footer">
                    <div class="gw-help-section">
                    <div class="gw-help-text">
                        <h4>Conoce más sobre Glasswing</h4>
                        <p>
                            Visita nuestro sitio oficial  
                            <a href="https://glasswing.org/" target="_blank" rel="noopener noreferrer">
                            Ve a glasswing.org
                            </a>
                        </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Contenido principal -->
            <div class="gw-main-content">
                <div class="gw-form-container">
                    <div class="gw-form-header">
                        <h1>Confirmar proyecto</h1>
                        <p>¿Deseas inscribirte en el siguiente proyecto?</p>
                    </div>

                    <div class="gw-project-confirmation">
                        <div class="gw-project-card">
                            <div class="gw-project-icon">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M12 2L2 7V17L12 22L22 17V7L12 2Z"/>
                                    <circle cx="12" cy="12" r="4"/>
                                </svg>
                            </div>
                            <div class="gw-project-info">
                                <h3><?php echo esc_html($proy->post_title); ?></h3>
                                <p>Al confirmar tu inscripción formarás parte de este proyecto como voluntario.</p>
                            </div>
                        </div>
                    </div>

                    <div class="gw-confirmation-actions">
                        <form method="post" style="display: inline;">
                            <input type="hidden" name="confirm_proyecto_id" value="<?php echo esc_attr($proy->ID); ?>">
                            <button type="submit" name="confirmar_inscripcion" class="gw-btn-primary">
                                Sí, inscribirme
                            </button>
                        </form>
                        
                        <form method="post" style="display: inline;">
                            <button type="submit" name="cancelar" class="gw-btn-secondary">
                                Cancelar
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
        <?php
        return ob_get_clean();
    }

    // --- Menú de selección de proyectos ---
    ob_start();
    ?>
<link rel="stylesheet" href="<?php echo plugins_url('gw-manager/css/gw-step6-styles.css?v=' . time()); ?>">
    <div class="gw-modern-wrapper">
        <div class="gw-form-wrapper">
            <!-- Panel lateral con pasos -->
            <div class="gw-sidebar">
            <div class="gw-hero-logo2">
                <img src="https://glasswing.org/es/wp-content/uploads/2023/08/Logo-Glasswing-02.png" alt="Logo Glasswing">
            </div> 

                <div class="gw-steps-container">
                    <!-- Paso 1 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">✓</div>
                    <div class="gw-step-content">
                        <h3>Información personal</h3>
                        <p>Cuéntanos quién eres para empezar.</p>
                    </div>
                    </div>

                    <!-- Paso 2 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">✓</div>
                    <div class="gw-step-content">
                        <h3>Video introductorio</h3>
                        <p>Mira este breve video para conocer Glasswing y tu rol.</p>
                    </div>
                    </div>

                    <!-- Paso 3 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">✓</div>
                    <div class="gw-step-content">
                        <h3>Registro para inducción</h3>
                        <p>Completa tu información para la inducción.</p>
                    </div>
                    </div>

                    <!-- Paso 4 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">✓</div>
                    <div class="gw-step-content">
                        <h3>Activar cuenta</h3>
                        <p>Confirma y activa tu cuenta para empezar.</p>
                    </div>
                    </div>

                    <!-- Paso 5 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">✓</div>
                    <div class="gw-step-content">
                        <h3>Charlas</h3>
                        <p>Regístrate en la charla asignada y participa.</p>
                    </div>
                    </div>

                    <!-- Paso 6 -->
                    <div class="gw-step-item active">
                    <div class="gw-step-number">6</div>
                    <div class="gw-step-content">
                        <h3>Selección de proyecto</h3>
                        <p>Elige el proyecto en el que participarás.</p>
                    </div>
                    </div>

                    <!-- Paso 7 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">7</div>
                    <div class="gw-step-content">
                        <h3>Capacitaciones</h3>
                        <p>Inscríbete y marca tu asistencia para continuar.</p>
                    </div>
                    </div>

                </div>

                <div class="gw-sidebar-footer">
                    <div class="gw-help-section">
                    <div class="gw-help-text">
                        <h4>Conoce más sobre Glasswing</h4>
                        <p>
                            Visita nuestro sitio oficial  
                            <a href="https://glasswing.org/" target="_blank" rel="noopener noreferrer">
                            Ve a glasswing.org
                            </a>
                        </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Contenido principal -->
            <div class="gw-main-content">
                <div class="gw-form-container">
                    <div class="gw-form-header">
                        <h1>Selección de proyecto</h1>
                        <p>Elige el proyecto en el que te gustaría participar como voluntario.</p>
                    </div>

                    <?php if ($error): ?>
                        <div class="gw-error-message">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/>
                                <line x1="15" y1="9" x2="9" y2="15"/>
                                <line x1="9" y1="9" x2="15" y2="15"/>
                            </svg>
                            <span><?php echo esc_html($error); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (empty($proyectos)): ?>
                        <div class="gw-no-projects">
                            <div class="gw-no-projects-icon">
                                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="11" cy="11" r="8"/>
                                    <path d="M21 21L16.65 16.65"/>
                                </svg>
                            </div>
                            <h3>No hay proyectos disponibles</h3>
                            <p>Actualmente no hay proyectos disponibles para tu país o región.</p>
                        </div>
                    <?php else: ?>
                        <div class="gw-projects-grid">
                            <?php foreach ($proyectos as $idx => $proy): ?>
                                <div class="gw-project-card">
                                    <div class="gw-project-header">
                                        <div class="gw-project-icon">
                                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M12 2L2 7V17L12 22L22 17V7L12 2Z"/>
                                                <circle cx="12" cy="12" r="4"/>
                                            </svg>
                                        </div>
                                        <h3><?php echo esc_html($proy->post_title); ?></h3>
                                    </div>
                                    
                                    <div class="gw-project-description">
                                        <?php 
                                        $description = get_post_meta($proy->ID, '_gw_descripcion', true);
                                        if ($description) {
                                            echo '<p>' . esc_html(wp_trim_words($description, 20)) . '</p>';
                                        } else {
                                            echo '<p>Únete a este importante proyecto de voluntariado.</p>';
                                        }
                                        ?>
                                    </div>
                                    
                                    <form method="get" action="">
                                        <input type="hidden" name="proyecto_id" value="<?php echo esc_attr($proy->ID); ?>">
                                        <button type="submit" class="gw-btn-secondary">
                                            Seleccionar proyecto
                                        </button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
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