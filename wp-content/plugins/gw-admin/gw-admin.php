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
        
        // Mostrar mensaje de éxito con diseño moderno
        ob_start();
        ?>
<link rel="stylesheet" href="<?php echo plugins_url('gw-manager/css/gw-step1-styles.css?v=' . time()); ?>">
<div class="gw-modern-wrapper">
    <div class="gw-form-wrapper">
        <!-- Panel lateral con pasos -->
        <div class="gw-sidebar">
            <div class="gw-hero-logo2">
                <img src="https://glasswing.org/es/wp-content/uploads/2023/08/Logo-Glasswing-02.png" alt="Logo Glasswing">
            </div> 

            <div class="gw-steps-container">
                <div class="gw-step-item active">
                    <div class="gw-step-number">1</div>
                    <div class="gw-step-content">
                        <h3>Información personal</h3>
                        <p>Cuéntanos quién eres para empezar.</p>
                    </div>
                </div>
                <div class="gw-step-item">
                    <div class="gw-step-number">2</div>
                    <div class="gw-step-content">
                        <h3>Video introductorio</h3>
                        <p>Mira este breve video para conocer Glasswing y tu rol.</p>
                    </div>
                </div>
                <div class="gw-step-item">
                    <div class="gw-step-number">3</div>
                    <div class="gw-step-content">
                        <h3>Registro para inducción</h3>
                        <p>Completa tu información para la inducción.</p>
                    </div>
                </div>
                <div class="gw-step-item">
                    <div class="gw-step-number">4</div>
                    <div class="gw-step-content">
                        <h3>Charlas</h3>
                        <p>Regístrate en la charla asignada y participa.</p>
                    </div>
                </div>
                <div class="gw-step-item">
                    <div class="gw-step-number">5</div>
                    <div class="gw-step-content">
                        <h3>Selección de proyecto</h3>
                        <p>Elige el proyecto en el que participarás.</p>
                    </div>
                </div>
                <div class="gw-step-item">
                    <div class="gw-step-number">6</div>
                    <div class="gw-step-content">
                        <h3>Capacitaciones</h3>
                        <p>Inscríbete y marca tu asistencia para continuar.</p>
                    </div>
                </div>

                      <!-- Paso 7 -->
                    <div class="gw-step-item">
                        <div class="gw-step-number">7</div>
                        <div class="gw-step-content">
                            <h3>Documentos y escuela</h3>
                            <p>Selecciona tu escuela y sube tus documentos.</p>
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
                <div class="gw-welcome-container">
                    <div class="gw-welcome-icon">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M9 12L11 14L15 10"/>
                            <circle cx="12" cy="12" r="10"/>
                        </svg>
                    </div>
                    
                    <h1 class="gw-welcome-title">¡Bienvenido a Glasswing!</h1>
                    <p class="gw-welcome-subtitle">Te has registrado exitosamente como aspirante a voluntario</p>
                    
                    <div class="gw-status-message">
                        <div class="gw-status-icon">
                            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M22 11.08V12A10 10 0 1 1 5.93 7.69"/>
                                <polyline points="22,4 12,14.01 9,11.01"/>
                            </svg>
                        </div>
                        <h2 class="gw-status-title">¡Registro completado!</h2>
                        <p class="gw-status-description">
                            Te hemos registrado como aspirante y configurado tus recordatorios automáticos.<br>
                            Ahora serás redirigido al formulario de datos personales para continuar.
                        </p>
                        <div class="gw-loading-spinner"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<meta http-equiv="refresh" content="3;url=<?php echo esc_url(site_url('/index.php/portal-voluntario/')); ?>">
        <?php
        return ob_get_clean();
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
    $mostrar_exito = false; // Variable para controlar la pantalla de éxito

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
            
            // En lugar de redirigir inmediatamente, mostrar pantalla de éxito
            $mostrar_exito = true;
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
                    <div class="gw-step-number"><?php echo $mostrar_exito ? '✓' : '1'; ?></div>
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

                    <!-- Paso 5 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">4</div>
                    <div class="gw-step-content">
                        <h3>Charlas</h3>
                        <p>Regístrate en la charla asignada y participa.</p>
                    </div>
                    </div>

                    <!-- Paso 6 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">5</div>
                    <div class="gw-step-content">
                        <h3>Selección de proyecto</h3>
                        <p>Elige el proyecto en el que participarás.</p>
                    </div>
                    </div>

                    <!-- Paso 7 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">6</div>
                    <div class="gw-step-content">
                        <h3>Capacitaciones</h3>
                        <p>Inscríbete y marca tu asistencia para continuar.</p>
                    </div>
                    </div>

                      <!-- Paso 8 -->
                    <div class="gw-step-item">
                        <div class="gw-step-number">7</div>
                        <div class="gw-step-content">
                            <h3>Documentos y escuela</h3>
                            <p>Selecciona tu escuela y sube tus documentos.</p>
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

                    <?php if ($mostrar_exito): ?>
                        <!-- PANTALLA DE ÉXITO -->
                        <div class="gw-form-header">
                            <h1>¡Información guardada!</h1>
                        </div>
                        
                        <div class="gw-success-registration">
                            <div class="gw-success-icon">
                                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M9 12l2 2 4-4"/>
                                    <circle cx="12" cy="12" r="10"/>
                                </svg>
                            </div>
                            <h2 class="gw-success-title">¡Gracias por completar tu información personal!</h2>
                            <p class="gw-success-description">
                                Hemos guardado tus datos correctamente: <strong><?php echo esc_html($nombre); ?></strong>.
                                <br>Ahora puedes continuar al siguiente paso del proceso.
                            </p>
                            <div class="gw-loading-spinner"></div>
                        </div>
                        
                        <!-- Redirección automática después de 3 segundos -->
                        <meta http-equiv="refresh" content="3;url=<?php echo esc_url(site_url('/index.php/portal-voluntario/')); ?>">
                        
                    <?php else: ?>
                        <!-- FORMULARIO NORMAL -->
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
                    <?php endif; ?>
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
    $mostrar_exito = false; // Variable para controlar la pantalla de éxito

    // Procesar formulario
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gw_video_nonce']) && wp_verify_nonce($_POST['gw_video_nonce'], 'gw_video_intro')) {
        update_user_meta($user_id, 'gw_step3_completo', 1);
        // Cancela recordatorios
        gw_cancelar_recordatorios_aspirante($user_id);
        
        // En lugar de redirigir inmediatamente, mostrar pantalla de éxito
        $mostrar_exito = true;
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
                    <div class="gw-step-number"><?php echo $mostrar_exito ? '✓' : '2'; ?></div>
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

                    <!-- Paso 5 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">4</div>
                    <div class="gw-step-content">
                        <h3>Charlas</h3>
                        <p>Regístrate en la charla asignada y participa.</p>
                    </div>
                    </div>

                    <!-- Paso 6 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">5</div>
                    <div class="gw-step-content">
                        <h3>Selección de proyecto</h3>
                        <p>Elige el proyecto en el que participarás.</p>
                    </div>
                    </div>

                    <!-- Paso 7 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">6</div>
                    <div class="gw-step-content">
                        <h3>Capacitaciones</h3>
                        <p>Inscríbete y marca tu asistencia para continuar.</p>
                    </div>
                    </div>

                      <!-- Paso 8 -->
                    <div class="gw-step-item">
                        <div class="gw-step-number">7</div>
                        <div class="gw-step-content">
                            <h3>Documentos y escuela</h3>
                            <p>Selecciona tu escuela y sube tus documentos.</p>
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

                    <?php if ($mostrar_exito): ?>
                        <!-- PANTALLA DE ÉXITO -->
                        <div class="gw-form-header">
                            <h1>¡Video completado!</h1>
                        </div>
                        
                        <div class="gw-success-registration">
                            <div class="gw-success-icon">
                                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M9 12l2 2 4-4"/>
                                    <circle cx="12" cy="12" r="10"/>
                                </svg>
                            </div>
                            <h2 class="gw-success-title">¡Gracias por ver el video introductorio!</h2>
                            <p class="gw-success-description">
                                Has completado exitosamente la introducción sobre <strong>Glasswing</strong> y tu rol como voluntario.
                                <br>Ahora estás listo para continuar con la verificación de identidad.
                            </p>
                            <div class="gw-loading-spinner"></div>
                        </div>
                        
                        <!-- Redirección automática después de 3 segundos -->
                        <meta http-equiv="refresh" content="3;url=<?php echo esc_url(site_url('/index.php/portal-voluntario/')); ?>">
                        
                    <?php else: ?>
                        <!-- CONTENIDO NORMAL DEL VIDEO -->
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
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
    
    <?php if (!$mostrar_exito): ?>
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
    <?php endif; ?>
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
    $mostrar_exito = false; // Variable para controlar la pantalla de éxito

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
            
            // En lugar de redirigir inmediatamente, mostrar pantalla de éxito
            $mostrar_exito = true;
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
                    <div class="gw-step-number"><?php echo $mostrar_exito ? '✓' : '3'; ?></div>
                    <div class="gw-step-content">
                        <h3>Registro para inducción</h3>
                        <p>Completa tu información para la inducción.</p>
                    </div>
                    </div>

                    <!-- Paso 5 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">4</div>
                    <div class="gw-step-content">
                        <h3>Charlas</h3>
                        <p>Regístrate en la charla asignada y participa.</p>
                    </div>
                    </div>

                    <!-- Paso 6 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">5</div>
                    <div class="gw-step-content">
                        <h3>Selección de proyecto</h3>
                        <p>Elige el proyecto en el que participarás.</p>
                    </div>
                    </div>

                    <!-- Paso 7 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">6</div>
                    <div class="gw-step-content">
                        <h3>Capacitaciones</h3>
                        <p>Inscríbete y marca tu asistencia para continuar.</p>
                    </div>
                    </div>

                    <!-- Paso 8 -->
                    <div class="gw-step-item">
                        <div class="gw-step-number">7</div>
                        <div class="gw-step-content">
                            <h3>Documentos y escuela</h3>
                            <p>Selecciona tu escuela y sube tus documentos.</p>
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

                    <?php if ($mostrar_exito): ?>
                        <!-- PANTALLA DE ÉXITO -->
                        <div class="gw-form-header">
                            <h1>¡Registro completado!</h1>
                        </div>
                        
                        <div class="gw-success-registration">
                            <div class="gw-success-icon">
                                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M9 12l2 2 4-4"/>
                                    <circle cx="12" cy="12" r="10"/>
                                </svg>
                            </div>
                            <h2 class="gw-success-title">¡Gracias por completar tu registro para la inducción!</h2>
                            <p class="gw-success-description">
                                Hemos guardado tu información correctamente, <strong><?php echo esc_html($nombre); ?></strong>.
                                <br>Ya estás registrado para el proceso de inducción y puedes continuar con las charlas.
                            </p>
                            <div class="gw-loading-spinner"></div>
                        </div>
                        
                        <!-- Redirección automática después de 3 segundos -->
                        <meta http-equiv="refresh" content="3;url=<?php echo esc_url(site_url('/index.php/portal-voluntario/')); ?>">
                        
                    <?php else: ?>
                        <!-- FORMULARIO NORMAL -->
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
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <?php if (!$mostrar_exito): ?>
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
    <?php endif; ?>
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
        wp_safe_redirect(site_url('/index.php/portal-voluntario/'));
        exit;
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

    // Helper para el layout
    $render_layout = function($title, $subtitle, $content) {
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

                    <div class="gw-step-item active">
                    <div class="gw-step-number">4</div>
                    <div class="gw-step-content">
                        <h3>Charlas</h3>
                        <p>Regístrate en la charla asignada y participa.</p>
                    </div>
                    </div>

                    <div class="gw-step-item">
                    <div class="gw-step-number">5</div>
                    <div class="gw-step-content">
                        <h3>Selección de proyecto</h3>
                        <p>Elige el proyecto en el que participarás.</p>
                    </div>
                    </div>

                    <div class="gw-step-item">
                    <div class="gw-step-number">6</div>
                    <div class="gw-step-content">
                        <h3>Capacitaciones</h3>
                        <p>Inscríbete y marca tu asistencia para continuar.</p>
                    </div>
                    </div>

                                          <!-- Paso 8 -->
                                          <div class="gw-step-item">
                        <div class="gw-step-number">7</div>
                        <div class="gw-step-content">
                            <h3>Documentos y escuela</h3>
                            <p>Selecciona tu escuela y sube tus documentos.</p>
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
                        <h1><?php echo esc_html($title); ?></h1>
                        <p><?php echo esc_html($subtitle); ?></p>
                    </div>
                    <?php echo $content; ?>
                </div>
            </div>
        </div>
    </div>
        <?php
        return ob_get_clean();
    };

    // ========== PANTALLA 2: CONFIRMACIÓN DE REGISTRO ==========
    if (isset($_GET['charla_id']) && isset($_GET['charla_idx']) && !isset($_GET['confirm'])) {
        $charla_id = intval($_GET['charla_id']);
        $charla_idx = intval($_GET['charla_idx']);
        
        // Obtener charla y sesión
        $charla_actual = get_post($charla_id);
        if (!$charla_actual) {
            wp_safe_redirect(site_url('/index.php/portal-voluntario/'));
            exit;
        }
        
        $sesiones = get_post_meta($charla_actual->ID, '_gw_fechas_horas', true);
        $sesion = (is_array($sesiones) && isset($sesiones[$charla_idx])) ? $sesiones[$charla_idx] : null;
        
        if (!$sesion) {
            wp_safe_redirect(site_url('/index.php/portal-voluntario/'));
            exit;
        }
        
        $content = '
        <div class="gw-session-confirmation">
            <div class="gw-session-card">
                <div class="gw-session-header">
                    <div class="gw-session-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <polyline points="12,6 12,12 16,14"/>
                        </svg>
                    </div>
                    <h3>' . esc_html($charla_actual->post_title) . ' / Opción ' . ($charla_idx+1) . '</h3>
                </div>
                <div class="gw-session-details">
                    <div class="gw-session-detail"><strong>Modalidad:</strong> ' . ucfirst($sesion['modalidad']) . '</div>
                    <div class="gw-session-detail"><strong>Fecha:</strong> ' . date('d/m/Y', strtotime($sesion['fecha'])) . '</div>
                    <div class="gw-session-detail"><strong>Hora:</strong> ' . esc_html($sesion['hora']) . '</div>';
        
        if ($sesion['modalidad'] == 'presencial') {
            $content .= '<div class="gw-session-detail"><strong>Lugar:</strong> ' . esc_html($sesion['lugar']) . '</div>';
        } else {
            $content .= '<div class="gw-session-detail"><strong>Enlace:</strong> (se habilitará al llegar la hora)</div>';
        }
        
        $content .= '
                </div>
            </div>
        </div>
        
        <div class="gw-confirmation-actions">
            <a href="' . esc_url(site_url('/index.php/portal-voluntario/')) . '" class="gw-btn-secondary">
                Cancelar
            </a>
            <a href="' . esc_url(add_query_arg(['charla_id' => $charla_id, 'charla_idx' => $charla_idx, 'confirm' => '1'], site_url('/index.php/portal-voluntario/'))) . '" class="gw-btn-primary">
                Registrarme
            </a>
        </div>';
        
        return $render_layout('Confirmar registro', '¿Deseas inscribirte en la siguiente sesión?', $content);
    }

    // ========== PANTALLA 3: ÉXITO DE REGISTRO ==========
    if (isset($_GET['charla_id']) && isset($_GET['charla_idx']) && isset($_GET['confirm'])) {
        $charla_id = intval($_GET['charla_id']);
        $charla_idx = intval($_GET['charla_idx']);
        
        // Obtener charla y sesión
        $charla_actual = get_post($charla_id);
        $sesiones = get_post_meta($charla_actual->ID, '_gw_fechas_horas', true);
        $sesion = (is_array($sesiones) && isset($sesiones[$charla_idx])) ? $sesiones[$charla_idx] : null;
        
        if (!$charla_actual || !$sesion) {
            wp_safe_redirect(site_url('/index.php/portal-voluntario/'));
            exit;
        }
        
        // Guardar la charla agendada
        $agendada_data = [
            'charla_id' => $charla_id,
            'charla_title' => $charla_actual->post_title,
            'modalidad' => $sesion['modalidad'],
            'fecha' => $sesion['fecha'],
            'hora' => $sesion['hora'],
            'lugar' => $sesion['lugar'],
            'enlace' => $sesion['enlace'],
            'idx' => $charla_idx,
        ];
        update_user_meta($user_id, 'gw_charla_agendada', $agendada_data);
        
        $content = '
        <div class="gw-success-registration">
            <div class="gw-success-icon">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M9 12l2 2 4-4"/>
                    <circle cx="12" cy="12" r="10"/>
                </svg>
            </div>
            <h2 class="gw-success-title">¡Te has registrado con éxito!</h2>
            <p class="gw-success-description">
                Tu registro en la charla <strong>' . esc_html($charla_actual->post_title) . '</strong> ha sido confirmado.
                <br>Recibirás un recordatorio antes de la sesión.
            </p>
            <div class="gw-loading-spinner"></div>
        </div>';
        
        // Redirección automática después de 3 segundos
        $content .= '<meta http-equiv="refresh" content="3;url=' . esc_url(site_url('/index.php/portal-voluntario/')) . '">';
        
        return $render_layout('¡Registro exitoso!', 'Serás redirigido automáticamente...', $content);
    }

    // ========== PANTALLA 4: CHARLA REGISTRADA (RECORDATORIO) ==========
    if ($charla_agendada && !$forzar_menu_paso5) {
        $ya_ocurrio = strtotime($charla_agendada['fecha'].' '.$charla_agendada['hora']) < time();
        
        $content = '
        <div class="gw-charla-info">
            <div class="gw-charla-title">
                ' . esc_html($charla_agendada['charla_title']) . '
                <span class="gw-charla-modalidad">(' . ucfirst($charla_agendada['modalidad']) . ')</span>
            </div>
            
            <div class="gw-charla-details">
                <div class="gw-detail-item">
                    <strong>Fecha:</strong> ' . date('d/m/Y', strtotime($charla_agendada['fecha'])) . '
                </div>
                <div class="gw-detail-item">
                    <strong>Hora:</strong> ' . esc_html($charla_agendada['hora']) . '
                </div>';
        
        if ($charla_agendada['modalidad']=='presencial') {
            $content .= '
                <div class="gw-detail-item">
                    <strong>Lugar:</strong> ' . esc_html($charla_agendada['lugar']) . '
                </div>';
        } else {
            $content .= '
                <div class="gw-detail-item">
                    <strong>Enlace:</strong> <a href="' . esc_url($charla_agendada['enlace']) . '" target="_blank">' . esc_html($charla_agendada['enlace']) . '</a>
                </div>';
        }
        
        $predicted_completadas = count($charlas_completadas) + 1;
        $has_more = count($charlas_asignadas) > $predicted_completadas;
        $button_label = $has_more ? 'Siguiente charla' : 'Ir a 
        Selección de proyecto';
        
        $content .= '
            </div>
        </div>
        
        <form method="post" class="gw-form">
            ' . wp_nonce_field('gw_charla_asistencia', 'gw_charla_asistencia_nonce', true, false) . '
            <div class="gw-form-actions">
                <button type="submit" class="gw-btn-primary">
                    ' . esc_html($button_label) . '
                </button>
            </div>
        </form>

        <div class="gw-charla-note">
            <p>Recuerda ir al enlace y marcar tu asistencia</p>
        </div>';

        // Procesar volver atrás
        if (
            $_SERVER['REQUEST_METHOD'] === 'POST'
            && isset($_POST['gw_charla_asistencia_nonce'])
            && wp_verify_nonce($_POST['gw_charla_asistencia_nonce'], 'gw_charla_asistencia')
            && isset($_POST['gw_volver_atras'])
        ) {
            delete_user_meta($user_id, 'gw_charla_agendada');
            wp_safe_redirect(site_url('/index.php/portal-voluntario/'));
            exit;
        }

        // Procesar marcar asistencia
        if (
            $_SERVER['REQUEST_METHOD'] === 'POST'
            && isset($_POST['gw_charla_asistencia_nonce'])
            && wp_verify_nonce($_POST['gw_charla_asistencia_nonce'], 'gw_charla_asistencia')
            && !isset($_POST['gw_volver_atras'])
        ) {
            $charlas_completadas[] = (int)$charla_agendada['charla_id'];
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
        
        if ($is_admin) $content .= gw_step_5_charla_admin_form($user_id, $charlas_asignadas, $admin_assign_output);
        return $render_layout('Charla registrada', 'Te recordamos que te registraste en la siguiente charla.', $content);
    }

    // ========== PANTALLA 1: LISTA DE SESIONES ==========
    // Si NO hay charla pendiente, mostrar menú principal de completado
    if (!$charla_pendiente_id || empty($charlas_asignadas)) {
        if ($is_admin || defined('GW_TESTING_MODE')) {
            $content = '
            <div class="gw-success-message">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M9 12l2 2 4-4"></path>
                    <circle cx="12" cy="12" r="10"></circle>
                </svg>
                <span>¡Excelente trabajo! Ahora puedes continuar a las capacitaciones.</span>
            </div>
            
            <div class="gw-form-actions">
                <a href="' . esc_url(site_url('/index.php/portal-voluntario/?paso5_menu=1')) . '" class="gw-btn-primary">
                    Ir a capacitaciones
                </a>
            </div>';

            if ($is_admin || defined('GW_TESTING_MODE')) {
                $content .= '
                <form method="post" class="gw-admin-controls">
                    ' . wp_nonce_field('gw_charla_regresar_admin', 'gw_charla_regresar_admin_nonce', true, false) . '
                    <button type="submit" name="gw_charla_regresar_admin" class="gw-btn-admin">
                        REGRESAR (ADMIN)
                    </button>
                    <small>Solo admin/testing: retrocede a charla anterior.</small>
                </form>';
            }

            // Procesar REGRESAR (ADMIN)
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
                wp_safe_redirect(site_url('/index.php/portal-voluntario/'));
                exit;
            }

            if ($is_admin) $content .= gw_step_5_charla_admin_form($user_id, $charlas_asignadas, $admin_assign_output);
            return $render_layout('¡Charlas completadas!', 'Has completado todas tus charlas asignadas exitosamente.', $content);
        } else {
            wp_safe_redirect(site_url('/index.php/portal-voluntario/'));
            exit;
        }
    }

    // Obtener charla actual
    $charla_actual = get_post($charla_pendiente_id);
    if (!$charla_actual) {
        $content = '<div class="gw-error-message"><span>La charla asignada no existe. Contacta a soporte.</span></div>';
        if ($is_admin) $content .= gw_step_5_charla_admin_form($user_id, $charlas_asignadas, $admin_assign_output);
        return $render_layout('Error', 'Ha ocurrido un problema', $content);
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

    $content = '';
    if (empty($charla_sesiones)) {
        $content = '<div class="gw-error-message"><span>Actualmente no hay sesiones disponibles para registro.</span></div>';
    } else {
        $content = '<div class="gw-charla-sessions">';
        foreach ($charla_sesiones as $idx => $ses) {
            $content .= '
                <div class="gw-session-card">
                    <div class="gw-session-info">
                        <div class="gw-session-title">
                            <strong>OPCIÓN ' . ($idx+1) . ':</strong>
                            ' . esc_html($ses['modalidad']=='virtual' ? "Google Meet" : strtoupper($ses['lugar'] ?: $ses['charla_title'])) . '
                        </div>
                        <div class="gw-session-details">
                            ' . date('d/m/Y', strtotime($ses['fecha'])).' a las '.substr($ses['hora'],0,5) . '
                        </div>
                    </div>
                    <a href="' . esc_url(add_query_arg(['charla_id' => $ses['charla_id'], 'charla_idx' => $ses['idx']], site_url('/index.php/portal-voluntario/'))) . '" class="gw-btn-secondary">Seleccionar</a>
                </div>';
        }
        $content .= '</div>';
    }
    
    if ($is_admin) $content .= gw_step_5_charla_admin_form($user_id, $charlas_asignadas, $admin_assign_output);
    // Cambiar el título y subtítulo para mostrar el nombre específico de la charla y un subtítulo más descriptivo
        return $render_layout($charla_actual->post_title, 'Selecciona una de las sesiones disponibles', $content);
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
    $mostrar_exito = false; // Variable para controlar la pantalla de éxito
    $proyecto_seleccionado = null; // Para almacenar el proyecto seleccionado
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
            
            // En lugar de redirigir inmediatamente, mostrar pantalla de éxito
            $mostrar_exito = true;
            $proyecto_seleccionado = $proy;
        }
        
        // Procesar cancelar
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancelar'])) {
            wp_safe_redirect(site_url('/index.php/portal-voluntario/'));
            exit;
        }
        
        // Si hay que mostrar la pantalla de éxito
        if ($mostrar_exito && $proyecto_seleccionado) {
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
                    <div class="gw-step-number">✓</div>
                    <div class="gw-step-content">
                        <h3>Selección de proyecto</h3>
                        <p>Elige el proyecto en el que participarás.</p>
                    </div>
                    </div>

                    <!-- Paso 7 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">6</div>
                    <div class="gw-step-content">
                        <h3>Capacitaciones</h3>
                        <p>Inscríbete y marca tu asistencia para continuar.</p>
                    </div>
                    </div>

                    <!-- Paso 8 -->
                    <div class="gw-step-item">
                        <div class="gw-step-number">7</div>
                        <div class="gw-step-content">
                            <h3>Documentos y escuela</h3>
                            <p>Selecciona tu escuela y sube tus documentos.</p>
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
                    <!-- PANTALLA DE ÉXITO -->
                    <div class="gw-form-header">
                        <h1>¡Proyecto seleccionado!</h1>
                    </div>
                    
                    <div class="gw-success-registration">
                        <div class="gw-success-icon">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M9 12l2 2 4-4"/>
                                <circle cx="12" cy="12" r="10"/>
                            </svg>
                        </div>
                        <h2 class="gw-success-title">¡Gracias por unirte al proyecto!</h2>
                        <p class="gw-success-description">
                            Te has inscrito exitosamente en <strong><?php echo esc_html($proyecto_seleccionado->post_title); ?></strong>.
                            <br>Ahora puedes continuar con las capacitaciones para completar tu registro.
                        </p>
                        <div class="gw-loading-spinner"></div>
                    </div>
                    
                    <!-- Redirección automática después de 3 segundos -->
                    <meta http-equiv="refresh" content="3;url=<?php echo esc_url(site_url('/index.php/portal-voluntario/')); ?>">
                </div>
            </div>
        </div>
    </div>
            <?php
            return ob_get_clean();
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
                    <div class="gw-step-number">5</div>
                    <div class="gw-step-content">
                        <h3>Selección de proyecto</h3>
                        <p>Elige el proyecto en el que participarás.</p>
                    </div>
                    </div>

                    <!-- Paso 7 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">6</div>
                    <div class="gw-step-content">
                        <h3>Capacitaciones</h3>
                        <p>Inscríbete y marca tu asistencia para continuar.</p>
                    </div>
                    </div>

                                          <!-- Paso 8 -->
                                          <div class="gw-step-item">
                        <div class="gw-step-number">7</div>
                        <div class="gw-step-content">
                            <h3>Documentos y escuela</h3>
                            <p>Selecciona tu escuela y sube tus documentos.</p>
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
                            <button type="submit" name="cancelar" class="gw-btn-secondary">
                                Cancelar
                            </button>
                        </form>
                        <form method="post" style="display: inline;">
                            <input type="hidden" name="confirm_proyecto_id" value="<?php echo esc_attr($proy->ID); ?>">
                            <button type="submit" name="confirmar_inscripcion" class="gw-btn-primary">
                                Sí, inscribirme
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
                    <div class="gw-step-number">5</div>
                    <div class="gw-step-content">
                        <h3>Selección de proyecto</h3>
                        <p>Elige el proyecto en el que participarás.</p>
                    </div>
                    </div>

                    <!-- Paso 7 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">6</div>
                    <div class="gw-step-content">
                        <h3>Capacitaciones</h3>
                        <p>Inscríbete y marca tu asistencia para continuar.</p>
                    </div>
                    </div>

                                          <!-- Paso 8 -->
                                          <div class="gw-step-item">
                        <div class="gw-step-number">7</div>
                        <div class="gw-step-content">
                            <h3>Documentos y escuela</h3>
                            <p>Selecciona tu escuela y sube tus documentos.</p>
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
// === FUNCIÓN HELPER PARA NORMALIZAR FECHA Y HORA ===
function gw_normalize_datetime($fecha, $hora) {
    // Normalizar fecha: convertir dd/mm/yyyy a yyyy-mm-dd
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $fecha, $matches)) {
        $fecha = $matches[3] . '-' . str_pad($matches[2], 2, '0', STR_PAD_LEFT) . '-' . str_pad($matches[1], 2, '0', STR_PAD_LEFT);
    }
    
    // Normalizar hora: convertir formato 12h con p.m./a.m. a 24h
    if (preg_match('/^(\d{1,2}):(\d{2})\s*(p\.?m\.?|a\.?m\.?)$/i', $hora, $matches)) {
        $hour = intval($matches[1]);
        $minute = intval($matches[2]);
        $ampm = strtolower($matches[3]);
        
        // Convertir a formato 24h
        if (strpos($ampm, 'p') === 0 && $hour !== 12) {
            $hour += 12;
        } elseif (strpos($ampm, 'a') === 0 && $hour === 12) {
            $hour = 0;
        }
        
        $hora = str_pad($hour, 2, '0', STR_PAD_LEFT) . ':' . str_pad($minute, 2, '0', STR_PAD_LEFT);
    }
    
    return [$fecha, $hora];
}

function gw_get_session_timestamp($sesion) {
    if (empty($sesion['fecha']) || empty($sesion['hora'])) {
        return false;
    }
    
    list($fecha_norm, $hora_norm) = gw_normalize_datetime($sesion['fecha'], $sesion['hora']);
    $ts = strtotime($fecha_norm . ' ' . $hora_norm);
    
    return $ts;
}

// === PASO 7: Capacitaciones ===
{function gw_step_7_capacitacion($user_id) {

    $forzar_menu  = (isset($_GET['paso7_menu']) && $_GET['paso7_menu'] == 1);

    // --- Datos persistentes ---
    $capacitaciones_asignadas = get_user_meta($user_id, 'gw_capacitaciones_asignadas', true);
    if (!is_array($capacitaciones_asignadas)) $capacitaciones_asignadas = [];

    // Autoasignación por proyecto/país si el coach no asignó
    if (empty($capacitaciones_asignadas)) {
        $proyecto_id = get_user_meta($user_id, 'gw_proyecto_id', true);
        $user_pais   = get_user_meta($user_id, 'gw_pais', true);
        $all_caps = get_posts([
            'post_type'   => 'capacitacion',
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby'     => 'menu_order',
            'order'       => 'ASC',
        ]);
        foreach ($all_caps as $cap) {
            $cap_proyecto_id = get_post_meta($cap->ID, '_gw_proyecto_id', true);
            if ($proyecto_id && $cap_proyecto_id && (int)$cap_proyecto_id !== (int)$proyecto_id) continue;
            $pais_id = get_post_meta($cap->ID, '_gw_pais_id', true);
            if ($pais_id && $user_pais) {
                $pais_post = get_post($pais_id);
                if ($pais_post && $pais_post->post_title && strcasecmp(trim($pais_post->post_title), trim($user_pais)) !== 0) continue;
            }
            $capacitaciones_asignadas[] = (int)$cap->ID;
        }
    }

    $capacitaciones_completadas = get_user_meta($user_id, 'gw_capacitaciones_completadas', true);
    if (!is_array($capacitaciones_completadas)) $capacitaciones_completadas = [];
    $capacitacion_agendada = get_user_meta($user_id, 'gw_capacitacion_agendada', true);

    // Siguiente capacitación pendiente (primera no completada)
    $cap_pendiente_id = null;
    foreach ($capacitaciones_asignadas as $cid) {
        if (!in_array((int)$cid, array_map('intval', $capacitaciones_completadas), true)) { $cap_pendiente_id = (int)$cid; break; }
    }

    // === Layout helper: mismo CSS/look del paso 5 ===
    $render_layout = function($title, $subtitle, $content) {
        ob_start(); ?>
<link rel="stylesheet" href="<?php echo plugins_url('gw-manager/css/gw-step5-styles.css?v=' . time()); ?>">
<div class="gw-modern-wrapper">
  <div class="gw-form-wrapper">
    <div class="gw-sidebar">
      <div class="gw-hero-logo2">
        <img src="https://glasswing.org/es/wp-content/uploads/2023/08/Logo-Glasswing-02.png" alt="Logo Glasswing">
      </div>
      <div class="gw-steps-container">
        <div class="gw-step-item"><div class="gw-step-number">✓</div><div class="gw-step-content"><h3>Información personal</h3><p>Cuéntanos quién eres para empezar.</p></div></div>
        <div class="gw-step-item"><div class="gw-step-number">✓</div><div class="gw-step-content"><h3>Video introductorio</h3><p>Mira este breve video para conocer Glasswing y tu rol.</p></div></div>
        <div class="gw-step-item"><div class="gw-step-number">✓</div><div class="gw-step-content"><h3>Registro para inducción</h3><p>Completa tu información para la inducción.</p></div></div>
        <div class="gw-step-item"><div class="gw-step-number">4</div><div class="gw-step-content"><h3>Charlas</h3><p>Regístrate en la charla asignada y participa.</p></div></div>
        <div class="gw-step-item"><div class="gw-step-number">5</div><div class="gw-step-content"><h3>Selección de proyecto</h3><p>Elige el proyecto en el que participarás.</p></div></div>
        <div class="gw-step-item active"><div class="gw-step-number">6</div><div class="gw-step-content"><h3>Capacitaciones</h3><p>Inscríbete y marca tu asistencia para continuar.</p></div></div>
        <div class="gw-step-item"><div class="gw-step-number">7</div><div class="gw-step-content"><h3>Documentos y escuela</h3><p>Selecciona tu escuela y sube tus documentos.</p></div></div>
      </div>
      <div class="gw-sidebar-footer"><div class="gw-help-section"><div class="gw-help-text"><h4>Conoce más sobre Glasswing</h4><p>Visita nuestro sitio oficial <a href="https://glasswing.org/" target="_blank" rel="noopener noreferrer">Ve a glasswing.org</a></p></div></div></div>
    </div>
    <div class="gw-main-content">
      <div class="gw-form-container">
        <div class="gw-form-header"><h1><?php echo esc_html($title); ?></h1><p><?php echo esc_html($subtitle); ?></p></div>
        <?php echo $content; ?>
      </div>
    </div>
  </div>
</div>
<?php return ob_get_clean(); };

    // ===== 2) Confirmación =====
    if (isset($_GET['capacitacion_id']) && isset($_GET['cap_idx']) && !isset($_GET['confirm'])) {
        $cap_id  = intval($_GET['capacitacion_id']);
        $cap_idx = intval($_GET['cap_idx']);
        if ($cap_pendiente_id && $cap_id !== $cap_pendiente_id) { wp_safe_redirect(site_url('/index.php/portal-voluntario/?paso7_menu=1')); exit; }
        $capacitacion = get_post($cap_id);
        if (!$capacitacion) { wp_safe_redirect(site_url('/index.php/portal-voluntario/')); exit; }
        $sesiones = get_post_meta($capacitacion->ID, '_gw_sesiones_cap', true);
        if (!is_array($sesiones) || empty($sesiones)) $sesiones = get_post_meta($capacitacion->ID, '_gw_sesiones', true);
        if (!is_array($sesiones) || empty($sesiones)) $sesiones = get_post_meta($capacitacion->ID, '_gw_fechas_horas', true);
        $sesion = (is_array($sesiones) && isset($sesiones[$cap_idx])) ? $sesiones[$cap_idx] : null;
        if (!$sesion) { wp_safe_redirect(site_url('/index.php/portal-voluntario/')); exit; }

        $modalidad = isset($sesion['modalidad']) ? $sesion['modalidad'] : '';
        $fecha     = isset($sesion['fecha']) ? $sesion['fecha'] : '';
        $hora      = isset($sesion['hora']) ? $sesion['hora'] : '';
        $lugar     = isset($sesion['lugar']) ? $sesion['lugar'] : '';

        $volver_a_sesiones = add_query_arg(['capacitacion_id' => $cap_id, 'capacitacion_menu' => '1'], site_url('/index.php/portal-voluntario/'));

        $content = '<div class="gw-session-confirmation"><div class="gw-session-card"><div class="gw-session-header"><div class="gw-session-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12,6 12,12 16,14"/></svg></div><h3>' . esc_html($capacitacion->post_title) . ' / Opción ' . ($cap_idx+1) . '</h3></div><div class="gw-session-details"><div class="gw-session-detail"><strong>Modalidad:</strong> ' . esc_html(ucfirst($modalidad)) . '</div><div class="gw-session-detail"><strong>Fecha:</strong> ' . date('d/m/Y', strtotime($fecha)) . '</div><div class="gw-session-detail"><strong>Hora:</strong> ' . esc_html($hora) . '</div>' . ($modalidad === 'presencial' ? '<div class="gw-session-detail"><strong>Lugar:</strong> ' . esc_html($lugar) . '</div>' : '<div class="gw-session-detail"><strong>Enlace:</strong> (se habilitará al llegar la hora)</div>') . '</div></div></div><div class="gw-confirmation-actions"><a href="' . esc_url($volver_a_sesiones) . '" class="gw-btn-secondary">Cancelar (volver a sesiones)</a><a href="' . esc_url(add_query_arg(['capacitacion_id'=>$cap_id,'cap_idx'=>$cap_idx,'confirm'=>'1'], site_url('/index.php/portal-voluntario/'))) . '" class="gw-btn-primary">Sí, inscribirme</a></div>';

        return $render_layout('Confirmar registro', 'Revisa y confirma tu inscripción.', $content);
    }

    // ===== 3) Éxito =====
    if (isset($_GET['capacitacion_id']) && isset($_GET['cap_idx']) && isset($_GET['confirm'])) {
        $cap_id  = intval($_GET['capacitacion_id']);
        $cap_idx = intval($_GET['cap_idx']);
        $capacitacion = get_post($cap_id);
        if (!$capacitacion) { wp_safe_redirect(site_url('/index.php/portal-voluntario/')); exit; }
        $sesiones = get_post_meta($cap_id, '_gw_sesiones_cap', true);
        if (!is_array($sesiones) || empty($sesiones)) $sesiones = get_post_meta($cap_id, '_gw_sesiones', true);
        if (!is_array($sesiones) || empty($sesiones)) $sesiones = get_post_meta($cap_id, '_gw_fechas_horas', true);
        $sesion = (is_array($sesiones) && isset($sesiones[$cap_idx])) ? $sesiones[$cap_idx] : null;
        if (!$sesion) { wp_safe_redirect(site_url('/index.php/portal-voluntario/')); exit; }

        $enlace = isset($sesion['enlace']) ? $sesion['enlace'] : (isset($sesion['link']) ? $sesion['link'] : '');
        update_user_meta($user_id, 'gw_capacitacion_agendada', [
            'cap_id'    => $cap_id,
            'cap_title' => $capacitacion->post_title,
            'modalidad' => isset($sesion['modalidad']) ? $sesion['modalidad'] : '',
            'fecha'     => isset($sesion['fecha']) ? $sesion['fecha'] : '',
            'hora'      => isset($sesion['hora']) ? $sesion['hora'] : '',
            'lugar'     => isset($sesion['lugar']) ? $sesion['lugar'] : '',
            'enlace'    => $enlace,
            'idx'       => $cap_idx,
        ]);

        $content = '<div class="gw-success-registration"><div class="gw-success-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="10"/></svg></div><h2 class="gw-success-title">¡Te has registrado con éxito!</h2><p class="gw-success-description">Tu registro en la capacitación <strong>' . esc_html($capacitacion->post_title) . '</strong> ha sido confirmado.</p><div class="gw-loading-spinner"></div></div><meta http-equiv="refresh" content="3;url=' . esc_url(site_url('/index.php/portal-voluntario/')) . '">';

        return $render_layout('¡Registro exitoso!', 'Serás redirigido automáticamente...', $content);
    }

    // ===== 4/5) Recordatorio + \"Siguiente capacitación\" =====
    if ($capacitacion_agendada && !$forzar_menu) {
        $predicted   = count($capacitaciones_completadas) + 1;
        $has_more    = count($capacitaciones_asignadas) > $predicted;
        $button_text = $has_more ? 'Siguiente capacitación' : 'Ir a documentos y escuela';

        if (
            $_SERVER['REQUEST_METHOD'] === 'POST' &&
            isset($_POST['gw_capacitacion_asistencia_nonce']) &&
            wp_verify_nonce($_POST['gw_capacitacion_asistencia_nonce'], 'gw_capacitacion_asistencia')
        ) {
            $capacitaciones_completadas[] = (int)$capacitacion_agendada['cap_id'];
            update_user_meta($user_id, 'gw_capacitaciones_completadas', array_values(array_unique(array_map('intval', $capacitaciones_completadas))));
            delete_user_meta($user_id, 'gw_capacitacion_agendada');

            $quedan = false; $done = array_map('intval', get_user_meta($user_id, 'gw_capacitaciones_completadas', true) ?: []);
            foreach ($capacitaciones_asignadas as $cid) { if (!in_array((int)$cid, $done, true)) { $quedan = true; break; } }
            if (!$quedan) update_user_meta($user_id, 'gw_step7_completo', 1);

            if ($has_more) { wp_safe_redirect(site_url('/index.php/portal-voluntario/')); } else { wp_safe_redirect(site_url('/index.php/portal-voluntario/?paso8_menu=1')); }
            exit;
        }

        $enlace = !empty($capacitacion_agendada['enlace'])
            ? '<a href="' . esc_url($capacitacion_agendada['enlace']) . '" target="_blank" rel="noopener">Unirse a la capacitación</a>'
            : '<span class="gw-text-muted">No hay enlace configurado</span>';

        $content = '<div class="gw-charla-info"><div class="gw-charla-title">' . esc_html($capacitacion_agendada['cap_title']) . ' <span class="gw-charla-modalidad">(' . ucfirst($capacitacion_agendada['modalidad']) . ')</span></div><div class="gw-charla-details"><div class="gw-detail-item"><strong>Fecha:</strong> ' . date('d/m/Y', strtotime($capacitacion_agendada['fecha'])) . '</div><div class="gw-detail-item"><strong>Hora:</strong> ' . esc_html($capacitacion_agendada['hora']) . '</div>' . ($capacitacion_agendada['modalidad'] === 'presencial' ? '<div class="gw-detail-item"><strong>Lugar:</strong> ' . esc_html($capacitacion_agendada['lugar']) . '</div>' : '<div class="gw-detail-item"><strong>Enlace:</strong> ' . $enlace . '</div>') . '</div></div><form method="post" class="gw-form">' . wp_nonce_field('gw_capacitacion_asistencia', 'gw_capacitacion_asistencia_nonce', true, false) . '<div class="gw-form-actions"><button type="submit" class="gw-btn-primary">' . esc_html($button_text) . '</button></div></form><div class="gw-charla-note"><p>Recuerda ir al enlace y marcar tu asistencia.</p></div>';

        return $render_layout('Capacitación registrada', 'Te recordamos que te registraste en la siguiente capacitación.', $content);
    }

    // ===== 1) Lista de sesiones =====
    if (!$cap_pendiente_id || empty($capacitaciones_asignadas)) {
        $content = '<div class="gw-success-message"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="10"/></svg><span>¡Excelente trabajo! Has completado tus capacitaciones.</span></div><div class="gw-form-actions"><a href="' . esc_url(site_url('/index.php/portal-voluntario/?paso8_menu=1')) . '" class="gw-btn-primary">Ir a documentos y escuela</a></div>';
        return $render_layout('¡Capacitaciones completadas!', 'Has completado todas tus capacitaciones asignadas exitosamente.', $content);
    }

    $cap_actual = get_post($cap_pendiente_id);
    if (!$cap_actual) { $content = '<div class="gw-error-message"><span>La capacitación asignada no existe. Contacta a soporte.</span></div>'; return $render_layout('Error','Ha ocurrido un problema',$content); }

    $sesiones = get_post_meta($cap_actual->ID, '_gw_sesiones_cap', true);
    if (!is_array($sesiones) || empty($sesiones)) $sesiones = get_post_meta($cap_actual->ID, '_gw_sesiones', true);
    if (!is_array($sesiones) || empty($sesiones)) $sesiones = get_post_meta($cap_actual->ID, '_gw_fechas_horas', true);

    $cap_sesiones = [];
    if (is_array($sesiones)) {
        foreach ($sesiones as $idx => $ses) {
            $fecha = isset($ses['fecha']) ? $ses['fecha'] : '';
            $hora  = isset($ses['hora']) ? $ses['hora'] : '';
            if (!$fecha || !$hora) continue;
            $ts = strtotime($fecha.' '.$hora);
            if ($ts && $ts > time()) {
                $cap_sesiones[] = [
                    'cap_id'    => $cap_actual->ID,
                    'cap_title' => $cap_actual->post_title,
                    'modalidad' => isset($ses['modalidad']) ? $ses['modalidad'] : '',
                    'fecha'     => $fecha,
                    'hora'      => $hora,
                    'lugar'     => isset($ses['lugar']) ? $ses['lugar'] : '',
                    'enlace'    => isset($ses['enlace']) ? $ses['enlace'] : (isset($ses['link']) ? $ses['link'] : ''),
                    'idx'       => $idx,
                ];
            }
        }
    }

    if (empty($cap_sesiones)) {
        $content = '<div class="gw-error-message"><span>Actualmente no hay sesiones disponibles para registro.</span></div><div class="gw-confirmation-actions"><a class="gw-btn-primary" href="' . esc_url(site_url('/index.php/portal-voluntario/')) . '">MI CUENTA</a></div>';
        return $render_layout('Capacitaciones', 'Selecciona una opción de horario para registrarte en la capacitación.', $content);
    }

    $content = '<div class="gw-charla-sessions">';
    foreach ($cap_sesiones as $i => $ses) {
        $titulo_lugar = ($ses['modalidad'] === 'virtual') ? 'Google Meet' : strtoupper($ses['lugar'] ?: $ses['cap_title']);
        $content .= '<div class="gw-session-card"><div class="gw-session-info"><div class="gw-session-title"><strong>OPCIÓN ' . ($i+1) . ':</strong> ' . esc_html($titulo_lugar) . '</div><div class="gw-session-details">' . date('d/m/Y', strtotime($ses['fecha'])) . ' a las ' . substr($ses['hora'], 0, 5) . '</div></div><a href="' . esc_url(add_query_arg(['capacitacion_id' => $ses['cap_id'], 'cap_idx' => $ses['idx']], site_url('/index.php/portal-voluntario/'))) . '" class="gw-btn-secondary">Seleccionar</a></div>';
    }
    $content .= '</div><div class="gw-confirmation-actions"><a class="gw-btn-primary" href="' . esc_url(site_url('/index.php/portal-voluntario/?paso7_menu=1')) . '">MI CUENTA</a></div>';

    return $render_layout($cap_actual->post_title, 'Selecciona una de las sesiones disponibles', $content);
}
}

// Función helper para formatear tiempo restante
function gw_format_time_remaining($seconds) {
    if ($seconds <= 0) return '0s';
    
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    
    $result = '';
    if ($hours > 0) $result .= $hours . 'h ';
    if ($minutes > 0) $result .= $minutes . 'm ';
    $result .= $secs . 's';
    
    return $result;
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
// === PASO 8: Subida de documentos y selección de escuela - ACTUALIZADO ===


function gw_step_8_documentos($user_id) {
    // Verificar si ya seleccionó escuela y horario
    $escuela_id = get_user_meta($user_id, 'gw_escuela_seleccionada', true);
    $horario = get_user_meta($user_id, 'gw_escuela_horario', true);

    // Variables para mensajes
    $error = '';
    $success = '';
    $just_submitted = false;

    // Procesar selección nueva
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['escuela_id']) && isset($_POST['horario_idx']) && check_admin_referer('gw_step8_seleccion', 'gw_step8_nonce')) {
        $escuela_id = intval($_POST['escuela_id']);
        $horario_idx = intval($_POST['horario_idx']);

        $escuela = get_post($escuela_id);
        $horarios = get_post_meta($escuela_id, '_gw_escuela_horarios', true);
        if (!is_array($horarios)) $horarios = [];

        if ($escuela && isset($horarios[$horario_idx])) {
            update_user_meta($user_id, 'gw_escuela_seleccionada', $escuela_id);
            update_user_meta($user_id, 'gw_escuela_horario', $horarios[$horario_idx]);
            $success = '¡Escuela y horario seleccionados correctamente!';
        } else {
            $error = 'Error al seleccionar la escuela. Inténtalo de nuevo.';
        }
    }

    // ------ OBTENER ESTADOS INDIVIDUALES DE DOCUMENTOS -------
    $doc1_estado = get_user_meta($user_id, 'gw_doc1_estado', true) ?: 'pendiente';
    $doc2_estado = get_user_meta($user_id, 'gw_doc2_estado', true) ?: 'pendiente';
    $doc3_estado = get_user_meta($user_id, 'gw_doc3_estado', true) ?: 'pendiente';
    $doc4_estado = get_user_meta($user_id, 'gw_doc4_estado', true) ?: 'pendiente';
    
    // Verificar si todos los documentos obligatorios están aceptados
    $todos_aceptados = ($doc1_estado === 'aceptado' && $doc2_estado === 'aceptado');
    
    // Obtener datos de la tabla original para URLs
    global $wpdb;
    $docs = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$wpdb->prefix}voluntario_docs WHERE user_id=%d", $user_id), ARRAY_A );
    
    // PROCESAR SUBIDA DE DOCUMENTOS - MEJORADO
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gw_docs_nonce']) && wp_verify_nonce($_POST['gw_docs_nonce'], 'gw_docs_subida')) {
        $cons1 = isset($_POST['consentimiento1']) ? 1 : 0;
        $cons2 = isset($_POST['consentimiento2']) ? 1 : 0;

        $errors = [];
        $file_names = [null, null, null, null];
        
        // Aumentar límites para subida
        ini_set('memory_limit', '256M');
        ini_set('max_execution_time', 300);
        
        for ($i = 1; $i <= 4; $i++) {
            $doc_estado = get_user_meta($user_id, "gw_doc{$i}_estado", true);
            
            // Solo permitir subida si el documento no está aceptado
            if ($doc_estado !== 'aceptado') {
                if (isset($_FILES["documento_$i"]) && $_FILES["documento_$i"]['size'] > 0) {
                    $file = $_FILES["documento_$i"];
                    
                    // Verificar errores de subida antes de procesar
                    if ($file['error'] !== UPLOAD_ERR_OK) {
                        $error_msg = gw_obtener_mensaje_error_upload($file['error']);
                        $errors[] = "Documento $i: $error_msg";
                        continue;
                    }
                    
                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    if (!in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
                        $errors[] = "Documento $i: Formato inválido ($ext). Solo se permiten: JPG, PNG, GIF, WEBP";
                        continue;
                    }
                    
                    // Verificar tamaño del archivo (máximo 5MB)
                    if ($file['size'] > 5 * 1024 * 1024) {
                        $errors[] = "Documento $i: El archivo es demasiado grande (" . size_format($file['size']) . "). Máximo 5MB.";
                        continue;
                    }
                    
                    // Usar la función mejorada
                    $url = gw_subir_documento_personalizado($file, $user_id, "documento_$i");
                    if (!$url) {
                        $last_error = error_get_last();
                        $error_detail = $last_error ? $last_error['message'] : 'Error desconocido';
                        $errors[] = "Documento $i: Error al subir archivo - $error_detail";
                        continue;
                    }
                    $file_names[$i-1] = $url;
                    
                    // Resetear el estado a pendiente al subir nuevo archivo
                    update_user_meta($user_id, "gw_doc{$i}_estado", 'pendiente');
                } else {
                    // Conservar archivo existente si no se subió uno nuevo
                    if ($docs && isset($docs["documento_{$i}_url"]) && $docs["documento_{$i}_url"]) {
                        $file_names[$i-1] = $docs["documento_{$i}_url"];
                    }
                }
            } else {
                // Documento ya aceptado, conservar
                if ($docs && isset($docs["documento_{$i}_url"]) && $docs["documento_{$i}_url"]) {
                    $file_names[$i-1] = $docs["documento_{$i}_url"];
                }
            }
        }
        
        // Validaciones mejoradas
        if (!$file_names[0] && $doc1_estado !== 'aceptado') {
            $errors[] = "Debes subir el primer documento de identidad (Foto 1).";
        }
        if (!$file_names[1] && $doc2_estado !== 'aceptado') {
            $errors[] = "Debes subir el segundo documento de identidad (Foto 2).";
        }
        if (!$cons1 || !$cons2) {
            $errors[] = "Debes aceptar ambos consentimientos.";
        }

        if (empty($errors)) {
            $table_name = $wpdb->prefix . 'voluntario_docs';
            
            // Verificar si la tabla existe
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
            
            if (!$table_exists) {
                $errors[] = "Error del sistema: Tabla de documentos no encontrada. Contacta al administrador.";
            } else {
                // Actualizar/insertar en la tabla
                if ($docs) {
                    $update_result = $wpdb->update(
                        $table_name, 
                        [
                            'documento_1_url' => $file_names[0],
                            'documento_2_url' => $file_names[1],
                            'documento_3_url' => $file_names[2],
                            'documento_4_url' => $file_names[3],
                            'consent_1'       => $cons1,
                            'consent_2'       => $cons2,
                            'status'          => 'pendiente',
                            'updated_at'      => current_time('mysql', 1)
                        ], 
                        ['user_id' => $user_id]
                    );
                    
                    if ($update_result === false) {
                        $errors[] = "Error al actualizar los documentos en la base de datos.";
                    }
                } else {
                    $insert_result = $wpdb->insert(
                        $table_name,
                        [
                            'user_id'           => $user_id,
                            'escuela_id'        => $escuela_id,
                            'documento_1_url'   => $file_names[0] ? esc_url_raw($file_names[0]) : '',
                            'documento_2_url'   => $file_names[1] ? esc_url_raw($file_names[1]) : '',
                            'documento_3_url'   => $file_names[2] ? esc_url_raw($file_names[2]) : '',
                            'documento_4_url'   => $file_names[3] ? esc_url_raw($file_names[3]) : '',
                            'consent_1'         => $cons1,
                            'consent_2'         => $cons2,
                            'status'            => 'pendiente',
                            'fecha_subida'      => current_time('mysql', 1),
                        ],
                        ['%d','%d','%s','%s','%s','%s','%d','%d','%s','%s']
                    );
                    
                    if (!$insert_result) {
                        $errors[] = "Error al guardar los documentos en la base de datos.";
                    }
                }
                
                if (empty($errors)) {
                    $just_submitted = true;
                    $success = '¡Documentos enviados correctamente! Espera a que sean validados.';
                    
                    // Enviar correo de notificación al admin (opcional)
                    gw_notificar_admin_nuevos_documentos($user_id);
                }
            }
        }
        
        if (!empty($errors)) {
            $error = implode('<br>', $errors);
        }
    }

    // Recargar datos después del procesamiento
    $docs = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$wpdb->prefix}voluntario_docs WHERE user_id=%d", $user_id), ARRAY_A );
    $doc1 = $docs && isset($docs['documento_1_url']) ? $docs['documento_1_url'] : '';
    $doc2 = $docs && isset($docs['documento_2_url']) ? $docs['documento_2_url'] : '';
    $doc3 = $docs && isset($docs['documento_3_url']) ? $docs['documento_3_url'] : '';
    $doc4 = $docs && isset($docs['documento_4_url']) ? $docs['documento_4_url'] : '';
    $cons1 = $docs && isset($docs['consent_1']) ? $docs['consent_1'] : '';
    $cons2 = $docs && isset($docs['consent_2']) ? $docs['consent_2'] : '';

    // Actualizar estados después del procesamiento
    $doc1_estado = get_user_meta($user_id, 'gw_doc1_estado', true) ?: 'pendiente';
    $doc2_estado = get_user_meta($user_id, 'gw_doc2_estado', true) ?: 'pendiente';
    $doc3_estado = get_user_meta($user_id, 'gw_doc3_estado', true) ?: 'pendiente';
    $doc4_estado = get_user_meta($user_id, 'gw_doc4_estado', true) ?: 'pendiente';
    $todos_aceptados = ($doc1_estado === 'aceptado' && $doc2_estado === 'aceptado');

    // Obtener escuelas
    $escuelas = get_posts([
        'post_type' => 'escuela',
        'post_status' => 'publish',
        'numberposts' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
    ]);

    ob_start();
    ?>
<link rel="stylesheet" href="<?php echo plugins_url('gw-manager/css/gw-step8-styles.css?v=' . time()); ?>">
    <div class="gw-modern-wrapper">
        <div class="gw-form-wrapper">
            <!-- Panel lateral con pasos -->
            <div class="gw-sidebar">
                <div class="gw-hero-logo2">
                    <img src="https://glasswing.org/es/wp-content/uploads/2023/08/Logo-Glasswing-02.png" alt="Logo Glasswing">
                </div> 

                <div class="gw-steps-container">
                    <!-- Pasos 1-7 (iguales) -->
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
                            <p>Mira este breve video para conocer Glasswing.</p>
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
                            <h3>Charlas</h3>
                            <p>Regístrate en la charla asignada y participa.</p>
                        </div>
                    </div>
                    <div class="gw-step-item">
                        <div class="gw-step-number">✓</div>
                        <div class="gw-step-content">
                            <h3>Selección de proyecto</h3>
                            <p>Elige el proyecto en el que participarás.</p>
                        </div>
                    </div>
                    <div class="gw-step-item">
                        <div class="gw-step-number">✓</div>
                        <div class="gw-step-content">
                            <h3>Capacitaciones</h3>
                            <p>Inscríbete y marca tu asistencia para continuar.</p>
                        </div>
                    </div>
                    <div class="gw-step-item active">
                        <div class="gw-step-number">8</div>
                        <div class="gw-step-content">
                            <h3>Documentos y escuela</h3>
                            <p>Selecciona tu escuela y sube tus documentos.</p>
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
                    
                    <?php if ($just_submitted): ?>
                        <!-- Pantalla de confirmación -->
                        <div class="gw-submission-success">
                            <div class="gw-success-animation">
                                <svg width="80" height="80" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                                    <polyline points="22,4 12,14.01 9,11.01"/>
                                </svg>
                            </div>
                            <h1>¡Documentos enviados exitosamente!</h1>
                            <p class="gw-thank-you-message">
                                Gracias por completar todos los pasos del proceso de registro como voluntario. 
                                Tus documentos han sido enviados y están siendo revisados por nuestro equipo.
                            </p>
                            <div class="gw-info-box">
                                <h3>¿Qué sigue ahora?</h3>
                                <ul>
                                    <li>📋 Nuestro equipo revisará tus documentos individualmente</li>
                                    <li>📧 Recibirás notificaciones específicas por cada documento</li>
                                    <li>🔄 Podrás volver a subir solo los documentos rechazados</li>
                                    <li>🎉 Comenzarás tu voluntariado una vez todos sean aprobados</li>
                                </ul>
                            </div>
                            <div class="gw-countdown-section">
                                <p>Cerrando sesión automáticamente en:</p>
                                <div class="gw-countdown" id="gw-countdown">10</div>
                                <p class="gw-countdown-text">segundos</p>
                            </div>
                        </div>
                    
                    <?php elseif ($todos_aceptados): ?>
                        <!-- Todos los documentos aceptados -->
                        <div class="gw-approved-status">
                            <div class="gw-success-icon">
                                <svg width="80" height="80" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                                    <polyline points="22,4 12,14.01 9,11.01"/>
                                </svg>
                            </div>
                            <h1>¡Documentos aprobados!</h1>
                            <p>Tus documentos han sido validados exitosamente. Tu voluntariado está confirmado y puedes comenzar a participar en las actividades programadas.</p>
                            <div class="gw-countdown-section">
                                <p>Cerrando sesión automáticamente en:</p>
                                <div class="gw-countdown" id="gw-countdown">15</div>
                                <p class="gw-countdown-text">segundos</p>
                            </div>
                        </div>
                    
                    <?php else: ?>
                        <!-- Formulario principal -->
                        <div class="gw-form-header">
                            <h1>Documentos y escuela</h1>
                            <p>Selecciona tu escuela, horario y sube los documentos requeridos.</p>
                        </div>

                        <?php if ($error): ?>
                            <div class="gw-error-message">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="10"/>
                                    <line x1="15" y1="9" x2="9" y2="15"/>
                                    <line x1="9" y1="9" x2="15" y2="15"/>
                                </svg>
                                <span><?php echo wp_kses_post($error); ?></span>
                            </div>
                        <?php endif; ?>

                        <?php if ($success && !$just_submitted): ?>
                            <div class="gw-success-message">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                                    <polyline points="22,4 12,14.01 9,11.01"/>
                                </svg>
                                <span><?php echo esc_html($success); ?></span>
                            </div>
                        <?php endif; ?>

                        <!-- Estado individual de documentos rechazados -->
                        <?php 
                        $hay_rechazados = ($doc1_estado === 'rechazado' || $doc2_estado === 'rechazado' || $doc3_estado === 'rechazado' || $doc4_estado === 'rechazado');
                        if ($hay_rechazados): ?>
                            <div class="gw-rejected-docs-notice">
                                <div class="gw-notice-header">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="12" cy="12" r="10"/>
                                        <line x1="15" y1="9" x2="9" y2="15"/>
                                        <line x1="9" y1="9" x2="15" y2="15"/>
                                    </svg>
                                    <h3>Documentos que necesitan corrección</h3>
                                </div>
                                <p>Los siguientes documentos fueron rechazados y necesitas subir nuevas versiones:</p>
                                <ul>
                                    <?php if ($doc1_estado === 'rechazado'): ?>
                                        <li>❌ Documento de identidad (Foto 1)</li>
                                    <?php endif; ?>
                                    <?php if ($doc2_estado === 'rechazado'): ?>
                                        <li>❌ Documento de identidad (Foto 2)</li>
                                    <?php endif; ?>
                                    <?php if ($doc3_estado === 'rechazado'): ?>
                                        <li>❌ Documento adicional (Foto 3)</li>
                                    <?php endif; ?>
                                    <?php if ($doc4_estado === 'rechazado'): ?>
                                        <li>❌ Documento adicional (Foto 4)</li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <!-- Selección de escuela -->
                        <?php if ($escuela_id && $horario): ?>
                            <div class="gw-selection-summary">
                                <div class="gw-summary-header">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                                        <polyline points="22,4 12,14.01 9,11.01"/>
                                    </svg>
                                    <h3>¡Escuela seleccionada!</h3>
                                </div>
                                <div class="gw-summary-content">
                                    <?php 
                                    $escuela = get_post($escuela_id);
                                    echo '<p><strong>Escuela:</strong> ' . esc_html($escuela ? $escuela->post_title : 'Escuela eliminada') . '</p>';
                                    echo '<p><strong>Día:</strong> ' . esc_html($horario['dia']) . ' | <strong>Hora:</strong> ' . esc_html($horario['hora']) . '</p>';
                                    ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <!-- Formulario de selección de escuela -->
                            <div class="gw-section">
                                <div class="gw-section-header">
                                    <h2>Selecciona tu escuela y horario</h2>
                                    <p>Elige la escuela y el horario donde vas a realizar tu voluntariado.</p>
                                </div>
                                <?php if (!empty($escuelas)): ?>
                                    <form method="post" class="gw-school-form">
                                        <?php wp_nonce_field('gw_step8_seleccion', 'gw_step8_nonce'); ?>
                                        <div class="gw-schools-grid">
                                            <?php foreach ($escuelas as $esc): 
                                                $horarios = get_post_meta($esc->ID, '_gw_escuela_horarios', true);
                                                if (!is_array($horarios) || empty($horarios)) continue;
                                            ?>
                                                <div class="gw-school-card">
                                                    <div class="gw-school-header">
                                                        <h3><?php echo esc_html($esc->post_title); ?></h3>
                                                    </div>
                                                    <div class="gw-school-schedules">
                                                        <?php foreach ($horarios as $idx => $h): 
                                                            if (!$h['dia'] && !$h['hora']) continue;
                                                        ?>
                                                            <div class="gw-schedule-option">
                                                                <input type="radio" 
                                                                       name="escuela_id" 
                                                                       value="<?php echo esc_attr($esc->ID); ?>" 
                                                                       id="schedule_<?php echo esc_attr($esc->ID . '_' . $idx); ?>"
                                                                       data-horario="<?php echo esc_attr($idx); ?>"
                                                                       required>
                                                                <label for="schedule_<?php echo esc_attr($esc->ID . '_' . $idx); ?>" class="gw-schedule-label">
                                                                    <div class="gw-schedule-info">
                                                                        <span class="gw-day"><?php echo esc_html($h['dia']); ?></span>
                                                                        <span class="gw-time"><?php echo esc_html($h['hora']); ?></span>
                                                                    </div>
                                                                    <button type="submit" 
                                                                            name="horario_idx" 
                                                                            value="<?php echo esc_attr($idx); ?>" 
                                                                            class="gw-select-btn">
                                                                        Seleccionar
                                                                    </button>
                                                                </label>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Formulario de documentos con estados individuales -->
                        <?php if ($escuela_id && $horario): ?>
                            <div class="gw-section">
                                <div class="gw-section-header">
                                    <h2>Subir documentos</h2>
                                    <p>Sube tu DUI o documento de identidad para completar tu registro.</p>
                                </div>

                                <form method="post" enctype="multipart/form-data" class="gw-documents-form" id="gw-documents-form">
                                    <?php wp_nonce_field('gw_docs_subida', 'gw_docs_nonce'); ?>
                                    
                                    <div class="gw-documents-grid" id="documents-container">
                                        <!-- Documento 1 -->
                                        <div class="gw-document-upload" data-doc="1">
                                            <label class="gw-upload-label">
                                                <span class="gw-label-text">Documento de identidad (Foto 1)</span>
                                                <span class="gw-required">*</span>
                                                <?php 
                                                $color1 = '';
                                                $text1 = '';
                                                switch ($doc1_estado) {
                                                    case 'aceptado': $color1 = '#46b450'; $text1 = 'APROBADO'; break;
                                                    case 'rechazado': $color1 = '#dc3232'; $text1 = 'RECHAZADO - SUBIR NUEVO'; break;
                                                    default: $color1 = '#ffb900'; $text1 = 'PENDIENTE'; break;
                                                }
                                                ?>
                                                <span style="color: <?php echo $color1; ?>; font-size: 10px; font-weight: bold; margin-left: 10px;"><?php echo $text1; ?></span>
                                            </label>
                                            
                                            <?php if ($doc1 && $doc1_estado !== 'rechazado'): ?>
                                                <div class="gw-document-preview">
                                                    <img src="<?php echo esc_url($doc1); ?>" alt="Documento 1">
                                                    <div class="gw-document-status" style="background: <?php echo $color1; ?>;">
                                                        <?php echo $doc1_estado === 'aceptado' ? '✓ Aprobado' : '⏳ En revisión'; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($doc1_estado !== 'aceptado'): ?>
                                                <div class="gw-file-upload">
                                                    <input type="file" 
                                                           name="documento_1" 
                                                           id="documento_1"
                                                           accept="image/*"
                                                           class="gw-file-input">
                                                    <label for="documento_1" class="gw-file-label">
                                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                                            <polyline points="7,10 12,15 17,10"/>
                                                            <line x1="12" y1="15" x2="12" y2="3"/>
                                                        </svg>
                                                        <span><?php echo ($doc1_estado === 'rechazado') ? 'Subir nuevo archivo' : ($doc1 ? 'Cambiar archivo' : 'Seleccionar archivo'); ?></span>
                                                    </label>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Documento 2 -->
                                        <div class="gw-document-upload" data-doc="2">
                                            <label class="gw-upload-label">
                                                <span class="gw-label-text">Documento de identidad (Foto 2)</span>
                                                <span class="gw-required">*</span>
                                                <?php 
                                                $color2 = '';
                                                $text2 = '';
                                                switch ($doc2_estado) {
                                                    case 'aceptado': $color2 = '#46b450'; $text2 = 'APROBADO'; break;
                                                    case 'rechazado': $color2 = '#dc3232'; $text2 = 'RECHAZADO - SUBIR NUEVO'; break;
                                                    default: $color2 = '#ffb900'; $text2 = 'PENDIENTE'; break;
                                                }
                                                ?>
                                                <span style="color: <?php echo $color2; ?>; font-size: 10px; font-weight: bold; margin-left: 10px;"><?php echo $text2; ?></span>
                                            </label>
                                            
                                            <?php if ($doc2 && $doc2_estado !== 'rechazado'): ?>
                                                <div class="gw-document-preview">
                                                    <img src="<?php echo esc_url($doc2); ?>" alt="Documento 2">
                                                    <div class="gw-document-status" style="background: <?php echo $color2; ?>;">
                                                        <?php echo $doc2_estado === 'aceptado' ? '✓ Aprobado' : '⏳ En revisión'; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($doc2_estado !== 'aceptado'): ?>
                                                <div class="gw-file-upload">
                                                    <input type="file" 
                                                           name="documento_2" 
                                                           id="documento_2"
                                                           accept="image/*"
                                                           class="gw-file-input">
                                                    <label for="documento_2" class="gw-file-label">
                                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                                            <polyline points="7,10 12,15 17,10"/>
                                                            <line x1="12" y1="15" x2="12" y2="3"/>
                                                        </svg>
                                                        <span><?php echo ($doc2_estado === 'rechazado') ? 'Subir nuevo archivo' : ($doc2 ? 'Cambiar archivo' : 'Seleccionar archivo'); ?></span>
                                                    </label>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Documento 3 - Solo si existe o fue rechazado -->
                                        <?php if ($doc3 || $doc3_estado === 'rechazado'): ?>
                                        <div class="gw-document-upload optional-doc" data-doc="3" style="display: flex;">
                                            <label class="gw-upload-label">
                                                <span class="gw-label-text">Documento adicional (Foto 3)</span>
                                                <?php 
                                                $color3 = '';
                                                $text3 = '';
                                                switch ($doc3_estado) {
                                                    case 'aceptado': $color3 = '#46b450'; $text3 = 'APROBADO'; break;
                                                    case 'rechazado': $color3 = '#dc3232'; $text3 = 'RECHAZADO - SUBIR NUEVO'; break;
                                                    default: $color3 = '#ffb900'; $text3 = 'PENDIENTE'; break;
                                                }
                                                ?>
                                                <span style="color: <?php echo $color3; ?>; font-size: 10px; font-weight: bold; margin-left: 10px;"><?php echo $text3; ?></span>
                                            </label>
                                            
                                            <?php if ($doc3 && $doc3_estado !== 'rechazado'): ?>
                                                <div class="gw-document-preview">
                                                    <img src="<?php echo esc_url($doc3); ?>" alt="Documento 3">
                                                    <div class="gw-document-status" style="background: <?php echo $color3; ?>;">
                                                        <?php echo $doc3_estado === 'aceptado' ? '✓ Aprobado' : '⏳ En revisión'; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($doc3_estado !== 'aceptado'): ?>
                                                <div class="gw-file-upload">
                                                    <input type="file" 
                                                           name="documento_3" 
                                                           id="documento_3"
                                                           accept="image/*"
                                                           class="gw-file-input">
                                                    <label for="documento_3" class="gw-file-label">
                                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                                            <polyline points="7,10 12,15 17,10"/>
                                                            <line x1="12" y1="15" x2="12" y2="3"/>
                                                        </svg>
                                                        <span><?php echo ($doc3_estado === 'rechazado') ? 'Subir nuevo archivo' : 'Seleccionar archivo'; ?></span>
                                                    </label>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <?php endif; ?>

                                        <!-- Documento 4 - Solo si existe o fue rechazado -->
                                        <?php if ($doc4 || $doc4_estado === 'rechazado'): ?>
                                        <div class="gw-document-upload optional-doc" data-doc="4" style="display: flex;">
                                            <label class="gw-upload-label">
                                                <span class="gw-label-text">Documento adicional (Foto 4)</span>
                                                <?php 
                                                $color4 = '';
                                                $text4 = '';
                                                switch ($doc4_estado) {
                                                    case 'aceptado': $color4 = '#46b450'; $text4 = 'APROBADO'; break;
                                                    case 'rechazado': $color4 = '#dc3232'; $text4 = 'RECHAZADO - SUBIR NUEVO'; break;
                                                    default: $color4 = '#ffb900'; $text4 = 'PENDIENTE'; break;
                                                }
                                                ?>
                                                <span style="color: <?php echo $color4; ?>; font-size: 10px; font-weight: bold; margin-left: 10px;"><?php echo $text4; ?></span>
                                            </label>
                                            
                                            <?php if ($doc4 && $doc4_estado !== 'rechazado'): ?>
                                                <div class="gw-document-preview">
                                                    <img src="<?php echo esc_url($doc4); ?>" alt="Documento 4">
                                                    <div class="gw-document-status" style="background: <?php echo $color4; ?>;">
                                                        <?php echo $doc4_estado === 'aceptado' ? '✓ Aprobado' : '⏳ En revisión'; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($doc4_estado !== 'aceptado'): ?>
                                                <div class="gw-file-upload">
                                                    <input type="file" 
                                                           name="documento_4" 
                                                           id="documento_4"
                                                           accept="image/*"
                                                           class="gw-file-input">
                                                    <label for="documento_4" class="gw-file-label">
                                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                                            <polyline points="7,10 12,15 17,10"/>
                                                            <line x1="12" y1="15" x2="12" y2="3"/>
                                                        </svg>
                                                        <span><?php echo ($doc4_estado === 'rechazado') ? 'Subir nuevo archivo' : 'Seleccionar archivo'; ?></span>
                                                    </label>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Botón para agregar más fotos (solo si no están todos) -->
                                    <?php if (!$doc3 && !$doc4 && $doc3_estado !== 'rechazado' && $doc4_estado !== 'rechazado'): ?>
                                        <div class="gw-add-photos-section">
                                            <button type="button" id="add-photo-btn" class="gw-add-photo-btn">
                                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <line x1="12" y1="5" x2="12" y2="19"/>
                                                    <line x1="5" y1="12" x2="19" y2="12"/>
                                                </svg>
                                                Agregar otra foto
                                            </button>
                                            <p class="gw-photo-help">Puedes subir hasta 4 fotos de tus documentos</p>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Consentimientos -->
                                    <div class="gw-consent-section">
                                        <div class="gw-consent-item">
                                            <input type="checkbox" 
                                                   name="consentimiento1" 
                                                   id="consentimiento1"
                                                   value="1" 
                                                   <?php checked($cons1, 1); ?>>
                                            <label for="consentimiento1">
                                                <span class="gw-checkbox-custom"></span>
                                                Acepto el consentimiento #1 y autorizo el uso de mi información personal
                                            </label>
                                        </div>
                                        <div class="gw-consent-item">
                                            <input type="checkbox" 
                                                   name="consentimiento2" 
                                                   id="consentimiento2"
                                                   value="1" 
                                                   <?php checked($cons2, 1); ?>>
                                            <label for="consentimiento2">
                                                <span class="gw-checkbox-custom"></span>
                                                Acepto el consentimiento #2 y las políticas de privacidad
                                            </label>
                                        </div>
                                    </div>

                                    <!-- Resumen de estado de documentos -->
                                    <div class="gw-documents-summary">
                                        <h4>Estado de tus documentos:</h4>
                                        <div class="gw-doc-status-grid">
                                            <div class="gw-doc-status-item">
                                                <span class="gw-doc-name">Documento 1:</span>
                                                <span class="gw-doc-state" style="color: <?php echo $color1; ?>;"><?php echo $text1; ?></span>
                                            </div>
                                            <div class="gw-doc-status-item">
                                                <span class="gw-doc-name">Documento 2:</span>
                                                <span class="gw-doc-state" style="color: <?php echo $color2; ?>;"><?php echo $text2; ?></span>
                                            </div>
                                            <?php if ($doc3 || $doc3_estado === 'rechazado'): ?>
                                            <div class="gw-doc-status-item">
                                                <span class="gw-doc-name">Documento 3:</span>
                                                <span class="gw-doc-state" style="color: <?php echo $color3; ?>;"><?php echo $text3; ?></span>
                                            </div>
                                            <?php endif; ?>
                                            <?php if ($doc4 || $doc4_estado === 'rechazado'): ?>
                                            <div class="gw-doc-status-item">
                                                <span class="gw-doc-name">Documento 4:</span>
                                                <span class="gw-doc-state" style="color: <?php echo $color4; ?>;"><?php echo $text4; ?></span>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="gw-form-actions">
                                        <button type="submit" class="gw-btn-primary" id="gw-submit-docs">
                                            <span class="gw-btn-text">
                                                <?php if ($hay_rechazados): ?>
                                                    Subir documentos corregidos
                                                <?php else: ?>
                                                    Enviar documentos
                                                <?php endif; ?>
                                            </span>
                                            <span class="gw-btn-loading" style="display: none;">
                                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                    <path d="M12 2V6M12 18V22M4.93 4.93L7.76 7.76M16.24 16.24L19.07 19.07M2 12H6M18 12H22M4.93 19.07L7.76 16.24M16.24 7.76L19.07 4.93" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                                </svg>
                                                Enviando...
                                            </span>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <script>
    (function() {
        'use strict';
        
        function logoutAndRedirect() {
            window.location.href = '<?php echo home_url(); ?>?gw_logout=1';
        }
        
        function startCountdown(seconds, callback) {
            const countdownElement = document.getElementById('gw-countdown');
            if (!countdownElement) return;
            
            let count = seconds;
            countdownElement.textContent = count;
            
            const interval = setInterval(() => {
                count--;
                countdownElement.textContent = count;
                
                if (count <= 3) {
                    countdownElement.style.color = '#ef4444';
                    countdownElement.style.transform = 'scale(1.1)';
                } else if (count <= 5) {
                    countdownElement.style.color = '#f59e0b';
                }
                
                if (count <= 0) {
                    clearInterval(interval);
                    if (callback) callback();
                }
            }, 1000);
        }
        
        <?php if ($just_submitted): ?>
            startCountdown(10, logoutAndRedirect);
        <?php elseif ($todos_aceptados): ?>
            startCountdown(15, logoutAndRedirect);
        <?php endif; ?>
        
        <?php if (!$just_submitted && !$todos_aceptados): ?>
        
        const form = document.getElementById('gw-documents-form');
        if (!form) return;
        
        const submitBtn = document.getElementById('gw-submit-docs');
        const btnText = submitBtn?.querySelector('.gw-btn-text');
        const btnLoading = submitBtn?.querySelector('.gw-btn-loading');
        const fileInputs = form.querySelectorAll('.gw-file-input');
        const checkboxes = form.querySelectorAll('input[type="checkbox"]');
        
        // Validación mejorada de archivos
        fileInputs.forEach(input => {
            input.addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (!file) return;
                
                // Limpiar mensajes de error previos
                const existingError = this.parentNode.querySelector('.gw-file-error');
                if (existingError) existingError.remove();
                
                const validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
                if (!validTypes.includes(file.type)) {
                    this.showError('Por favor selecciona un archivo de imagen válido (JPG, PNG, GIF, WEBP)');
                    this.value = '';
                    return;
                }
                
                const maxSize = 5 * 1024 * 1024; // 5MB
                if (file.size > maxSize) {
                    this.showError('El archivo es demasiado grande (' + (file.size / (1024*1024)).toFixed(1) + 'MB). Máximo 5MB.');
                    this.value = '';
                    return;
                }
                
                // Mostrar nombre del archivo seleccionado
                const label = this.nextElementSibling;
                if (label) {
                    const span = label.querySelector('span');
                    if (span) span.textContent = file.name + ' (' + (file.size / 1024).toFixed(1) + 'KB)';
                    label.classList.add('file-selected');
                }
            });
            
            // Método para mostrar errores
            input.showError = function(message) {
                const errorDiv = document.createElement('div');
                errorDiv.className = 'gw-file-error';
                errorDiv.style.cssText = 'color: #dc3232; font-size: 12px; margin-top: 5px; padding: 8px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 4px;';
                errorDiv.textContent = message;
                this.parentNode.appendChild(errorDiv);
            };
        });
        
        function validateForm() {
            const consent1 = document.getElementById('consentimiento1');
            const consent2 = document.getElementById('consentimiento2');
            
            let isValid = true;
            let errors = [];
            
            // Verificar documentos obligatorios
            const doc1Input = document.getElementById('documento_1');
            const doc2Input = document.getElementById('documento_2');
            
            const hasDoc1 = doc1Input?.files.length > 0 || <?php echo $doc1 && $doc1_estado !== 'rechazado' ? 'true' : 'false'; ?>;
            const hasDoc2 = doc2Input?.files.length > 0 || <?php echo $doc2 && $doc2_estado !== 'rechazado' ? 'true' : 'false'; ?>;
            
            if (!hasDoc1) {
                errors.push('Debes subir el primer documento (Foto 1)');
                isValid = false;
            }
            
            if (!hasDoc2) {
                errors.push('Debes subir el segundo documento (Foto 2)');
                isValid = false;
            }
            
            if (!consent1?.checked) {
                errors.push('Debes aceptar el consentimiento #1');
                isValid = false;
            }
            
            if (!consent2?.checked) {
                errors.push('Debes aceptar el consentimiento #2');
                isValid = false;
            }
            
            if (!isValid) {
                alert('Por favor corrige los siguientes errores:\n• ' + errors.join('\n• '));
            }
            
            return isValid;
        }
        
        if (submitBtn) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                
                if (!validateForm()) {
                    return;
                }
                
                submitBtn.disabled = true;
                submitBtn.classList.add('loading');
                if (btnText) btnText.style.display = 'none';
                if (btnLoading) btnLoading.style.display = 'flex';
                
                this.submit();
            });
        }
        
        // Manejo de checkboxes personalizados
        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const customBox = this.nextElementSibling.querySelector('.gw-checkbox-custom');
                if (customBox) {
                    if (this.checked) {
                        customBox.classList.add('checked');
                    } else {
                        customBox.classList.remove('checked');
                    }
                }
            });
            
            if (checkbox.checked) {
                const customBox = checkbox.nextElementSibling.querySelector('.gw-checkbox-custom');
                if (customBox) {
                    customBox.classList.add('checked');
                }
            }
        });
        
        <?php endif; ?>
    })();
    </script>
    
    <style>
    .gw-rejected-docs-notice {
        background: #fff3cd;
        border: 1px solid #ffeaa7;
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 20px;
    }
    .gw-rejected-docs-notice .gw-notice-header {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 10px;
    }
    .gw-rejected-docs-notice .gw-notice-header svg {
        color: #dc3232;
    }
    .gw-rejected-docs-notice h3 {
        margin: 0;
        color: #dc3232;
    }
    .gw-rejected-docs-notice ul {
        margin: 10px 0 0 20px;
    }
    .gw-documents-summary {
        background: #f8f9fa;
        border: 1px solid #e9ecef;
        border-radius: 8px;
        padding: 15px;
        margin: 20px 0;
    }
    .gw-documents-summary h4 {
        margin: 0 0 10px 0;
        color: #495057;
    }
    .gw-doc-status-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
    }
    .gw-doc-status-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 5px 0;
    }
    .gw-doc-name {
        font-weight: 500;
    }
    .gw-doc-state {
        font-weight: bold;
        font-size: 12px;
    }
    .gw-file-error {
        animation: fadeIn 0.3s ease;
    }
    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }
    .file-selected {
        background: #e8f5e8 !important;
        border-color: #46b450 !important;
    }
    </style>
    <?php
    return ob_get_clean();
}

// Función adicional para notificar al admin
function gw_notificar_admin_nuevos_documentos($user_id) {
    $user = get_userdata($user_id);
    if (!$user) return;
    
    $admin_email = get_option('admin_email');
    $subject = 'Nuevos documentos subidos - ' . $user->display_name;
    
    $message = "
    <html>
    <body>
        <p>El voluntario <strong>{$user->display_name}</strong> ({$user->user_email}) ha subido nuevos documentos.</p>
        <p><a href='" . admin_url('admin.php?page=panel-administrativo') . "'>Ir al panel administrativo para revisar</a></p>
    </body>
    </html>";
    
    $headers = ['Content-Type: text/html; charset=UTF-8'];
    wp_mail($admin_email, $subject, $message, $headers);
}

