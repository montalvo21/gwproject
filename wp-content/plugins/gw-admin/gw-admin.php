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

    // Normalizar arreglo de archivos a 4 posiciones
    $f1 = isset($file_names[0]) ? esc_url_raw($file_names[0]) : '';
    $f2 = isset($file_names[1]) ? esc_url_raw($file_names[1]) : '';
    $f3 = isset($file_names[2]) ? esc_url_raw($file_names[2]) : '';
    $f4 = isset($file_names[3]) ? esc_url_raw($file_names[3]) : '';

    // Detectar si la tabla tiene columnas 3/4
    $has_doc3 = (bool) $wpdb->get_var( $wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'documento_3_url') );
    $has_doc4 = (bool) $wpdb->get_var( $wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'documento_4_url') );

    // Construir data y formatos dinámicamente (UPDATE/INSERT comparten lógica)
    $base_data = [
        'documento_1_url' => $f1,
        'documento_2_url' => $f2,
        'consent_1'       => (int) $cons1,
        'consent_2'       => (int) $cons2,
        'status'          => 'pendiente',
    ];
    $base_fmt  = ['%s','%s','%d','%d','%s'];

    if ($has_doc3) { $base_data['documento_3_url'] = $f3; $base_fmt[] = '%s'; }
    if ($has_doc4) { $base_data['documento_4_url'] = $f4; $base_fmt[] = '%s'; }

    // Guardar fallback en user_meta si la tabla no tiene esas columnas
    if ($f3 && !$has_doc3) { update_user_meta($user_id, 'gw_doc3_url', $f3); }
    if ($f4 && !$has_doc4) { update_user_meta($user_id, 'gw_doc4_url', $f4); }

    // ¿Existe registro?
    $docs = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE user_id = %d AND escuela_id = %d",
        $user_id, $escuela_id
    ));

    if ($docs) {
        // UPDATE
        $data = $base_data;
        $data['fecha_revision'] = current_time('mysql', 1);
        $fmt  = array_merge($base_fmt, ['%s']);

        $wpdb->update(
            $table,
            $data,
            [ 'user_id' => $user_id, 'escuela_id' => $escuela_id ],
            $fmt,
            ['%d','%d']
        );
    } else {
        // INSERT
        $data = array_merge($base_data, [
            'user_id'       => $user_id,
            'escuela_id'    => $escuela_id,
            'fecha_subida'  => current_time('mysql', 1),
            'fecha_revision'=> current_time('mysql', 1),
        ]);
        $fmt  = array_merge($base_fmt, ['%d','%d','%s','%s']);

        $wpdb->insert($table, $data, $fmt);
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

    $current_step = gw_get_voluntario_step($user_id);

    ob_start();
    echo '<div class="gw-voluntario-onboarding">';
    // Botón global de Cerrar sesión (visible en TODO el flujo)
    $logout_url = wp_logout_url( site_url('/')); 
    echo '<a class="gw-logout-btn" href="' . esc_url($logout_url) . '" aria-label="Cerrar sesión">'
        . '<span class="gw-logout-text">Cerrar sesión</span>'
        . '<svg class="gw-logout-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">'
        . '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>'
        . '<polyline points="16 17 21 12 16 7"/>'
        . '<line x1="21" y1="12" x2="9" y2="12"/>'
        . '</svg>'
        . '</a>';
    
    echo '<style>
    .gw-logout-btn {
        position: fixed !important;
        top: 20px !important;
        right: 20px !important;
        z-index: 2147483647 !important;
        display: flex !important;
        align-items: center !important;
        gap: 8px !important;
        padding: 12px 16px !important;
        border-radius: 12px !important;
        font-weight: 600 !important;
        font-size: 14px !important;
        text-decoration: none !important;
        letter-spacing: 0.2px !important;
        background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%) !important;
        color: white !important;
        border: 1px solid rgba(255, 255, 255, 0.2) !important;
        box-shadow: 0 8px 25px rgba(239, 68, 68, 0.3), 0 2px 8px rgba(0, 0, 0, 0.1) !important;
        backdrop-filter: blur(10px) !important;
        opacity: 0.95 !important;
        visibility: visible !important;
        pointer-events: auto !important;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
    }
    
    .gw-logout-btn:hover {
        opacity: 1 !important;
        transform: translateY(-2px) scale(1.02) !important;
        box-shadow: 0 12px 35px rgba(239, 68, 68, 0.4), 0 4px 12px rgba(0, 0, 0, 0.15) !important;
        background: linear-gradient(135deg, #f87171 0%, #ef4444 100%) !important;
    }
    
    .gw-logout-btn:active {
        transform: translateY(-1px) scale(1.01) !important;
        transition: all 0.1s cubic-bezier(0.4, 0, 0.2, 1) !important;
    }
    
    .gw-logout-icon {
        display: inline-block !important;
        width: 18px !important;
        height: 18px !important;
        stroke-width: 2.5 !important;
        opacity: 0.9 !important;
    }
    
    .gw-logout-text {
        white-space: nowrap !important;
        font-weight: 600 !important;
    }
    
    /* Adaptación para admin bar de WordPress */
    body.admin-bar .gw-logout-btn {
        top: 52px !important;
    }
    
    @media (min-width: 783px) {
        body.admin-bar .gw-logout-btn {
            top: 52px !important;
        }
    }
    
    /* Responsive design */
    @media (max-width: 768px) {
        .gw-logout-btn {
            top: 16px !important;
            right: 16px !important;
            padding: 10px 14px !important;
            font-size: 13px !important;
        }
    }
    
    @media (max-width: 640px) {
        .gw-logout-text {
            display: none !important;
        }
        .gw-logout-btn {
            top: 12px !important;
            right: 12px !important;
            padding: 10px !important;
            border-radius: 50% !important;
            min-width: 44px !important;
            min-height: 44px !important;
            justify-content: center !important;
        }
        .gw-logout-icon {
            width: 20px !important;
            height: 20px !important;
        }
        body.admin-bar .gw-logout-btn {
            top: 48px !important;
        }
    }
    
    /* Adaptación específica para tu diseño con sidebar */
    @media (min-width: 1025px) {
        .gw-logout-btn {
            right: 55px !important;
            top: 32px !important;
        }
    }
    
    /* Animación de entrada suave */
    @keyframes fadeInScale {
        0% {
            opacity: 0;
            transform: translateY(-10px) scale(0.9);
        }
        100% {
            opacity: 0.95;
            transform: translateY(0) scale(1);
        }
    }
    
    .gw-logout-btn {
        animation: fadeInScale 0.3s cubic-bezier(0.4, 0, 0.2, 1) forwards !important;
    }
    </style>';
    
    echo '<script>
    (function(){
        try {
            var btn = document.querySelector(".gw-logout-btn");
            if (!btn) return;
            
            // Mover al final del body para evitar stacking contexts
            if (btn.parentNode !== document.body) {
                document.body.appendChild(btn);
            }
            
            // Forzar estilos críticos
            btn.style.position = "fixed";
            btn.style.zIndex = "2147483647";
            btn.style.visibility = "visible";
            btn.style.pointerEvents = "auto";
            
            // Confirmación mejorada al hacer click
            if (!btn.dataset.bound) {
                btn.addEventListener("click", function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    // Crear modal de confirmación personalizado
                    var modal = document.createElement("div");
                    modal.style.cssText = `
                        position: fixed !important;
                        top: 0 !important;
                        left: 0 !important;
                        width: 100vw !important;
                        height: 100vh !important;
                        background: rgba(0, 0, 0, 0.6) !important;
                        z-index: 2147483648 !important;
                        display: flex !important;
                        align-items: center !important;
                        justify-content: center !important;
                        backdrop-filter: blur(4px) !important;
                        animation: fadeIn 0.2s ease-out !important;
                    `;
                    
                    modal.innerHTML = `
                        <div style="
                            background: white !important;
                            padding: 32px !important;
                            border-radius: 16px !important;
                            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.3) !important;
                            text-align: center !important;
                            max-width: 400px !important;
                            margin: 20px !important;
                            animation: scaleIn 0.2s ease-out !important;
                        ">
                            <h3 style="margin: 0 0 16px 0 !important; color: #1f2937 !important; font-size: 20px !important;">
                                ¿Cerrar sesión?
                            </h3>
                            <p style="margin: 0 0 24px 0 !important; color: #6b7280 !important; line-height: 1.5 !important;">
                                Tu progreso se guardará automáticamente.
                            </p>
                            <div style="display: flex !important; gap: 12px !important; justify-content: center !important;">
                                <button id="cancel-logout" style="
                                    padding: 10px 20px !important;
                                    border: 2px solid #e5e7eb !important;
                                    background: white !important;
                                    color: #6b7280 !important;
                                    border-radius: 8px !important;
                                    font-weight: 600 !important;
                                    cursor: pointer !important;
                                    transition: all 0.2s !important;
                                ">
                                    Cancelar
                                </button>
                                <button id="confirm-logout" style="
                                    padding: 10px 20px !important;
                                    background: linear-gradient(135deg, #ef4444, #dc2626) !important;
                                    color: white !important;
                                    border: none !important;
                                    border-radius: 8px !important;
                                    font-weight: 600 !important;
                                    cursor: pointer !important;
                                    transition: all 0.2s !important;
                                ">
                                    Cerrar sesión
                                </button>
                            </div>
                        </div>
                    `;
                    
                    // Agregar estilos de animación
                    var styleSheet = document.createElement("style");
                    styleSheet.textContent = `
                        @keyframes fadeIn {
                            from { opacity: 0; }
                            to { opacity: 1; }
                        }
                        @keyframes scaleIn {
                            from { transform: scale(0.9); opacity: 0; }
                            to { transform: scale(1); opacity: 1; }
                        }
                    `;
                    document.head.appendChild(styleSheet);
                    
                    document.body.appendChild(modal);
                    
                    // Eventos de los botones
                    document.getElementById("cancel-logout").onclick = function() {
                        modal.remove();
                        styleSheet.remove();
                    };
                    
                    document.getElementById("confirm-logout").onclick = function() {
                        window.location.href = btn.href;
                    };
                    
                    // Cerrar con escape o click fuera
                    modal.onclick = function(e) {
                        if (e.target === modal) {
                            modal.remove();
                            styleSheet.remove();
                        }
                    };
                    
                    document.addEventListener("keydown", function(e) {
                        if (e.key === "Escape") {
                            modal.remove();
                            styleSheet.remove();
                        }
                    });
                });
                
                btn.dataset.bound = "1";
            }
        } catch(e) {
            console.error("Error en logout button:", e);
        }
    })();
    </script>';

    // === VOLUNTARIO: Campanita de notificaciones (UI + JS) ===
    $gwv_ajax  = admin_url('admin-ajax.php');
    $gwv_nonce = wp_create_nonce('gwv_notif');

    echo '
    <style>
      /* SOLO CAMBIOS DE POSICIÓN - DESKTOP */
      @media (min-width: 1025px) {
        #gwv-notif-root{position:fixed;right:200px;top:32px;z-index:2147483647;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif}
        body.admin-bar #gwv-notif-root{top:64px}
        #gwv-notif-btn{width:64px;height:40px;border-radius:50%;justify-content:center;padding:0;         position: relative;
            left: -1.3rem;
        top: -0.6rem;
        height: 2.8rem;}
        #gwv-notif-btn .ico{font-size:24px;line-height:1}
        #gwv-notif-btn span:not(.ico){display:none}
        #gwv-notif-badge{position:absolute;top:-6px;right:-6px;min-width:20px;height:20px;font-size:11px;line-height:20px}
        #gwv-notif-panel{right:32px;top:120px;width:420px}
        body.admin-bar #gwv-notif-panel{top: 7rem !important; right: 3.5rem;}
      }
      
      /* TABLET */
      @media (min-width: 768px) and (max-width: 1024px) {
        #gwv-notif-root{position:fixed;right:140px;top:24px;z-index:2147483647;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif}
        body.admin-bar #gwv-notif-root{top:56px}
        #gwv-notif-btn{width:56px;height:40px;border-radius:50%;justify-content:center;padding:0;         position: relative;
            left: -2rem;
            top: -0.2rem;}
        #gwv-notif-btn .ico{font-size:22px;line-height:1}
        #gwv-notif-btn span:not(.ico){display:none}
        #gwv-notif-badge{position:absolute;top:-5px;right:-5px;min-width:18px;height:18px;font-size:10px;line-height:18px}
        #gwv-notif-panel{right:24px;top:100px;width:380px;max-width:calc(100vw - 48px)}
        body.admin-bar #gwv-notif-panel{top:7rem !important;}
      }
      
      /* MÓVIL */
      @media (max-width: 767px) {
        #gwv-notif-root{position:fixed;right:80px;top:16px;z-index:2147483647;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif}
        body.admin-bar #gwv-notif-root{top:48px}
        #gwv-notif-btn{width:48px;height:48px;border-radius:50%;justify-content:center;padding:0; top: -0.1rem;
            position: relative;
            left: 1.2rem;}
        #gwv-notif-btn .ico{font-size:20px;line-height:1}
        #gwv-notif-btn span:not(.ico){display:none}
        #gwv-notif-badge{position:absolute;top:-4px;right:-4px;min-width:16px;height:16px;font-size:9px;line-height:16px}
        #gwv-notif-panel{left:16px;right:16px;top:80px;width:auto;border-radius:16px}
        body.admin-bar #gwv-notif-panel{top:112px}
      }
      
      /* ESTILOS ORIGINALES MANTENIDOS */
      #gwv-notif-btn{display:flex;align-items:center;gap:8px;padding:10px 12px;border-radius:12px;background:#fff;border:1px solid #E5E7EB;box-shadow:0 6px 20px rgba(0,0,0,.08);cursor:pointer;font-weight:700}
      #gwv-notif-btn .ico{font-size:16px;line-height:1}
      #gwv-notif-badge{display:none;min-width:18px;height:18px;padding:0 6px;border-radius:999px;background:#d5172f;color:#fff;font-size:12px;line-height:18px;text-align:center}
      #gwv-notif-panel{position:fixed;right:20px;top:130px;width:360px;max-width:92vw;background:#fff;border:1px solid #E5E7EB;border-radius:14px;box-shadow:0 24px 60px rgba(0,0,0,.18);display:none}
      #gwv-notif-panel .hd{display:flex;align-items:center;justify-content:space-between;padding:12px 14px;border-bottom:1px solid #eceff4}
      #gwv-notif-panel .hd .ttl{font-weight:800}
      #gwv-notif-panel .hd .act{display:flex;gap:8px}
      #gwv-notif-panel .hd button{border:none;background:#F3F4F6;padding:8px 10px;border-radius:8px;font-weight:600;cursor:pointer}
      #gwv-notif-list{max-height:58vh;overflow:auto}
      #gwv-notif-panel .item{padding:12px 14px;border-bottom:1px solid #F1F5F9}
      #gwv-notif-panel .item:last-child{border-bottom:none}
      #gwv-notif-panel .item .content{display:flex;gap:10px;align-items:flex-start}
      #gwv-notif-panel .item .icon{font-size:15px}
      #gwv-notif-panel .item .title{font-size:14px;font-weight:700;margin-bottom:2px}
      #gwv-notif-panel .item.unread .title{font-weight:800}
      #gwv-notif-panel .item .meta{color:#607285;font-size:12px}
      #gwv-notif-panel .empty{padding:24px;text-align:center;color:#607285}
    </style>

    <div id="gwv-notif-root" aria-live="polite">
      <button id="gwv-notif-btn" type="button" aria-haspopup="true" aria-expanded="false">
        <span class="ico">🔔</span>
        <span>Notificaciones</span>
        <span id="gwv-notif-badge">0</span>
      </button>
      <div id="gwv-notif-panel" role="dialog" aria-label="Notificaciones">
        <div class="hd">
          <div class="ttl">Notificaciones</div>
          <div class="act">
            <button id="gwv-notif-mark">Marcar leídas</button>
            <button id="gwv-notif-close">Cerrar</button>
          </div>
        </div>
        <div id="gwv-notif-list">
          <div class="empty">
            <div style="font-size:20px;margin-bottom:6px">🔔</div>
            <div class="title" style="font-weight:700">No hay notificaciones</div>
            <div class="meta">Aquí aparecerán tus avisos</div>
          </div>
        </div>
      </div>
    </div>

    <!-- === VOLUNTARIO: BOTÓN DE TICKET (UI) === -->
    <style>
      /* POSICIONAMIENTO HORIZONTAL PARA TODOS LOS DISPOSITIVOS - TICKETS */
      
      /* DESKTOP (1025px+) - Tickets en línea perfecta con los otros */
      @media (min-width: 1025px) {
        #gwv-ticket-root{position:fixed;right:192px;top:32px;z-index:2147483647;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif}
        body.admin-bar #gwv-ticket-root{top:64px}
        #gwv-ticket-btn{width:64px;height:64px;border-radius:50%;justify-content:center;padding:0;        left: -17.5rem;
            top: -6.9rem;
            height: 2.8rem;
            position: relative;}
        #gwv-ticket-btn .ico{font-size:24px;line-height:1}
        #gwv-ticket-btn span:not(.ico):not(.badge){display:none}
        #gwv-ticket-btn .badge{position:absolute;top:-6px;right:-6px;min-width:20px;height:20px;font-size:11px;line-height:20px}
        #gwv-tk-panel{right:32px;top:120px;width:420px}
        body.admin-bar #gwv-tk-panel{        top: 7rem !important;
            right: 3.5rem;}
      }
      
      /* TABLET (768px - 1024px) - Tickets en línea perfecta con los otros */
      @media (min-width: 768px) and (max-width: 1024px) {
        #gwv-ticket-root{position:fixed;right:168px;top:24px;z-index:2147483647;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif}
        body.admin-bar #gwv-ticket-root{top:56px}
        #gwv-ticket-btn{width:56px;height:40px;border-radius:50%;justify-content:center;padding:0;         position: relative;
            left: -14rem;
            top: -7rem;}
        #gwv-ticket-btn .ico{font-size:22px;line-height:1}
        #gwv-ticket-btn span:not(.ico):not(.badge){display:none}
        #gwv-ticket-btn .badge{position:absolute;top:-5px;right:-5px;min-width:18px;height:18px;font-size:10px;line-height:18px}
        #gwv-tk-panel{right:24px;top:100px;width:380px;max-width:calc(100vw - 48px)}
        body.admin-bar #gwv-tk-panel{top:7rem!important}
      }
      
      /* MÓVIL (hasta 767px) - Tickets en línea perfecta con los otros */
      @media (max-width: 767px) {
        #gwv-ticket-root{position:fixed;right:128px;top:16px;z-index:2147483647;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif}
        body.admin-bar #gwv-ticket-root{top:48px}
        #gwv-ticket-btn{width:48px;height:48px;border-radius:50%;justify-content:center;padding:0; top: -7.5rem;
            position: relative;
            left: -6rem;}
        #gwv-ticket-btn .ico{font-size:20px;line-height:1}
        #gwv-ticket-btn span:not(.ico):not(.badge){display:none}
        #gwv-ticket-btn .badge{position:absolute;top:-4px;right:-4px;min-width:16px;height:16px;font-size:9px;line-height:16px}
        #gwv-tk-panel{left:16px;right:16px;top:80px;width:auto;border-radius:16px}
        body.admin-bar #gwv-tk-panel{top: unset!important;}
      }
      
      /* ESTILOS ORIGINALES MANTENIDOS */
      #gwv-ticket-root{position:fixed;right:20px;top:134px;z-index:2147483647;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif}
      body.admin-bar #gwv-ticket-root{top:166px}
      #gwv-ticket-btn{display:flex;align-items:center;gap:8px;padding:10px 12px;border-radius:12px;background:#fff;border:1px solid #E5E7EB;box-shadow:0 6px 20px rgba(0,0,0,.08);cursor:pointer;font-weight:700}
      #gwv-ticket-btn .ico{font-size:16px;line-height:1}
      #gwv-ticket-btn .badge{
        display:none;min-width:18px;height:18px;padding:0 6px;border-radius:999px;background:#10b981;color:#fff;font-size:12px;line-height:18px;font-weight:700;
      }
      /* Mini panel de tickets (inbox) para voluntario */
      #gwv-tk-panel{
        position:fixed;right:20px;top:176px;width:380px;max-width:92vw;background:#fff;border:1px solid #E5E7EB;border-radius:14px;box-shadow:0 24px 60px rgba(0,0,0,.18);display:none;z-index:2147483647;
      }
      body.admin-bar #gwv-tk-panel{ top:208px; }
      #gwv-tk-panel .hd{display:flex;align-items:center;justify-content:space-between;padding:12px 14px;border-bottom:1px solid #eceff4}
      #gwv-tk-panel .hd .ttl{font-weight:800}
      #gwv-tk-panel .hd .act{display:flex;gap:8px}
      #gwv-tk-panel .hd button{border:none;background:#F3F4F6;padding:8px 10px;border-radius:8px;font-weight:600;cursor:pointer}
      #gwv-tk-list{max-height:55vh;overflow:auto}
      #gwv-tk-panel .item{padding:12px 14px;border-bottom:1px solid #F1F5F9}
      #gwv-tk-panel .item:last-child{border-bottom:none}
      #gwv-tk-panel .item .title{font-size:14px;font-weight:700;margin-bottom:2px}
      #gwv-tk-panel .item.unread .title{font-weight:800}
      #gwv-tk-panel .item .meta{color:#607285;font-size:12px}
      #gwv-tk-panel .empty{padding:24px;text-align:center;color:#607285}
      @media(max-width:480px){
        #gwv-tk-panel{ right:16px; top:160px; width:calc(100vw - 32px); }
      }

      /* Modal sencillo */
      #gwv-ticket-modal{position:fixed;inset:0;display:none;align-items:center;justify-content:center;background:rgba(0,0,0,.4);z-index:2147483647}
      #gwv-ticket-card{background:#fff;border:1px solid #E5E7EB;border-radius:14px;box-shadow:0 24px 60px rgba(0,0,0,.18);width:520px;max-width:92vw}
      #gwv-ticket-card .hd{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid #F1F5F9}
      #gwv-ticket-card .hd h3{margin:0;font-size:18px}
      #gwv-ticket-card .bd{padding:14px 16px}
      #gwv-ticket-card label{display:block;font-weight:700;margin-bottom:6px}
      #gwv-ticket-card select,#gwv-ticket-card textarea{width:100%;border:1px solid #D1D5DB;border-radius:10px;padding:10px}
      #gwv-ticket-card textarea{min-height:120px;resize:vertical}
      #gwv-ticket-card .ft{display:flex;gap:10px;justify-content:flex-end;padding:12px 16px;border-top:1px solid #F1F5F9}
      #gwv-ticket-card .btn{border:none;border-radius:10px;padding:10px 14px;font-weight:700;cursor:pointer}
      #gwv-ticket-cancel{background:#F3F4F6}
      #gwv-ticket-send{background:#c4c33f;color:#fff}
      #gwv-ticket-ok{display:none;color:#0f766e;background:#ecfdf5;border:1px solid #99f6e4;padding:10px;border-radius:10px;margin-top:10px}
    </style>

    <div id="gwv-ticket-root">
      <button id="gwv-ticket-btn" type="button" aria-haspopup="dialog">
        <span class="ico">🎫</span><span>Ticket</span><span class="badge" id="gwv-ticket-badge">0</span>
      </button>
    </div>

    <div id="gwv-tk-panel" role="dialog" aria-label="Tickets">
      <div class="hd">
        <div class="ttl">Tickets</div>
        <div class="act">
          <button id="gwv-tk-new" type="button">Crear</button>
          <button id="gwv-tk-mark" type="button">Marcar leídas</button>
          <button id="gwv-tk-close" type="button">Cerrar</button>
        </div>
      </div>
      <div id="gwv-tk-list">
        <div class="empty">
          <div style="font-size:20px;margin-bottom:6px">🎫</div>
          <div class="title" style="font-weight:700">Sin novedades</div>
          <div class="meta">Aquí verás respuestas y estado de tus tickets</div>
        </div>
      </div>
    </div>

    <div id="gwv-ticket-modal" role="dialog" aria-modal="true" aria-labelledby="gwv-ticket-title">
      <div id="gwv-ticket-card">
        <div class="hd">
          <h3 id="gwv-ticket-title">Crear ticket</h3>
          <button id="gwv-ticket-x" class="btn" aria-label="Cerrar">✕</button>
        </div>
        <div class="bd">
          <div style="margin-bottom:10px">
            <label for="gwv-tk-topic">¿Sobre qué quieres crear este ticket?</label>
            <select id="gwv-tk-topic">
              <option value="Datos personales">Datos personales</option>
              <option value="Documentos">Documentos</option>
              <option value="Charlas y capacitaciones">Charlas y capacitaciones</option>
              <option value="Otro">Otro</option>
            </select>
          </div>
          <div>
            <label for="gwv-tk-msg">Cuéntanos el problema</label>
            <textarea id="gwv-tk-msg" placeholder="Ejemplo: Me equivoqué al escribir mi edad. Debería decir 24 y puse 42."></textarea>
          </div>
          <div id="gwv-ticket-ok">✔️ Su solicitud ha sido enviada. Pronto el equipo se comunicará contigo.</div>
        </div>
        <div class="ft">
          <button id="gwv-ticket-cancel" class="btn" type="button">Cancelar</button>
          <button id="gwv-ticket-send" class="btn" type="button">Enviar</button>
        </div>
      </div>
    </div>
    ';
    ?>
    <script>
    (function(){
      var AJAX  = <?php echo json_encode($gwv_ajax); ?>;
      var NONCE = <?php echo json_encode($gwv_nonce); ?>;

      var btn    = document.getElementById('gwv-notif-btn');
      var panel  = document.getElementById('gwv-notif-panel');
      var list   = document.getElementById('gwv-notif-list');
      var badge  = document.getElementById('gwv-notif-badge');
      var closeBt= document.getElementById('gwv-notif-close');
      var markBt = document.getElementById('gwv-notif-mark');
      var poller = null;

      function esc(t){
        if (t == null) return '';
        return String(t).replace(/[&<>"']/g, function(m){
          return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]);
        });
      }
      function setBadge(n){
        n = parseInt(n,10) || 0;
        if (n > 0) { badge.style.display = 'inline-flex'; badge.textContent = n; }
        else { badge.style.display = 'none'; badge.textContent = '0'; }
      }

      function render(items){
        list.innerHTML = '';
        if (!items || !items.length){
          list.innerHTML = '<div class="empty"><div style="font-size:20px;margin-bottom:6px">🔔</div><div class="title" style="font-weight:700">No hay notificaciones</div><div class="meta">Aquí aparecerán tus avisos</div></div>';
          return;
        }
        items.forEach(function(it){
          var t = (it.type || '').toUpperCase();
          var ico = (t === 'CHARLA') ? '🗣️' : (t === 'CAPACITACION' ? '🎓' : '📎');
          var div = document.createElement('div');
          div.className = 'item' + (String(it.status).toUpperCase() === 'UNREAD' ? ' unread' : '');
          div.innerHTML =
            '<div class="content">' +
              '<div class="icon">' + ico + '</div>' +
              '<div class="text">' +
                '<div class="title">' + esc(it.title || 'Notificación') + '</div>' +
                '<div class="meta">' + esc(it.body || '') +
                  (it.time_h ? ' · <span class="time">' + esc(it.time_h) + '</span>' : '') +
                '</div>' +
              '</div>' +
            '</div>';
          list.appendChild(div);
        });
      }

      function fetchList(){
        var fd = new FormData();
        fd.append('action','gwv_notif_fetch');
        fd.append('nonce', NONCE);
        return fetch(AJAX, {method:'POST', credentials:'same-origin', body: fd})
          .then(function(r){ return r.json(); })
          .then(function(res){
            if (!res || !res.success) return;
            var d = res.data || {};
            render(d.items || []);
            setBadge(d.unread || 0);
          })
          .catch(function(){});
      }

      function markRead(){
        var fd = new FormData();
        fd.append('action','gwv_notif_mark_read');
        fd.append('nonce', NONCE);
        fetch(AJAX, {method:'POST', credentials:'same-origin', body: fd})
          .then(function(r){ return r.json(); })
          .then(function(){
            list.querySelectorAll('.item.unread').forEach(function(n){ n.classList.remove('unread'); });
            setBadge(0);
          })
          .catch(function(){});
      }

      function openPanel(){
        // Cerrar el panel de TICKETS si está abierto
        if (window.gwvCloseTkPanel) { try { window.gwvCloseTkPanel(); } catch(e){} }
        panel.style.display = 'block';
        fetchList();
        if (!poller) { poller = setInterval(fetchList, 25000); }
      }
      function closePanel(){
        panel.style.display = 'none';
        if (poller){ clearInterval(poller); poller = null; }
      }
      // Disponer cierre global para que otros módulos nos cierren correctamente
      window.gwvCloseNotifPanel = closePanel;

      btn && btn.addEventListener('click', function(e){
        e.stopPropagation();
        var visible = (panel.style.display === 'block');
        if (visible) { closePanel(); }
        else { openPanel(); }
      });
      closeBt&& closeBt.addEventListener('click', closePanel);
      markBt && markBt.addEventListener('click', markRead);
      document.addEventListener('click', function(e){ if (!panel.contains(e.target) && !btn.contains(e.target)) closePanel(); });

      // Primera carga para pintar badge
      fetchList();
    })();
    </script>
    <script>
    (function(){
      var AJAX  = <?php echo json_encode($gwv_ajax); ?>;
      var NONCE = <?php echo json_encode( wp_create_nonce('gwv_ticket') ); ?>;

      var root   = document.getElementById('gwv-ticket-root');
      var btn    = document.getElementById('gwv-ticket-btn');
      var modal  = document.getElementById('gwv-ticket-modal');
      var card   = document.getElementById('gwv-ticket-card');
      var closeX = document.getElementById('gwv-ticket-x');
      var cancel = document.getElementById('gwv-ticket-cancel');
      var send   = document.getElementById('gwv-ticket-send');
      var topic  = document.getElementById('gwv-tk-topic');
      var msg    = document.getElementById('gwv-tk-msg');
      var okBox  = document.getElementById('gwv-ticket-ok');

      // Panel de inbox
      var tkPanel = document.getElementById('gwv-tk-panel');
      var tkList  = document.getElementById('gwv-tk-list');
      var tkBadge = document.getElementById('gwv-ticket-badge');
      var tkNew   = document.getElementById('gwv-tk-new');
      var tkClose = document.getElementById('gwv-tk-close');
      var tkMark  = document.getElementById('gwv-tk-mark');
      var tkPoll  = null;

      // Explicit helpers to open/cerrar el panel de tickets y exponer global closer
      function openTkPanel(){
        // Cerrar el panel de NOTIFICACIONES si estuviera abierto
        if (window.gwvCloseNotifPanel) { try { window.gwvCloseNotifPanel(); } catch(e){} }
        tkPanel.style.display='block';
        fetchInbox();
        if (!tkPoll){ tkPoll = setInterval(fetchInbox, 30000); }
      }
      function closeTkPanel(){
        tkPanel.style.display='none';
        if (tkPoll){ clearInterval(tkPoll); tkPoll=null; }
      }
      // Exponer para que el módulo de notificaciones pueda cerrarnos
      window.gwvCloseTkPanel = closeTkPanel;

      function openM(){ modal.style.display='flex'; msg.focus(); }
      function closeM(){ modal.style.display='none'; okBox.style.display='none'; msg.value=''; }

      function esc(t){ return (t==null?'':String(t)).trim(); }

      function setTkBadge(n){
        n = parseInt(n,10)||0;
        if (n>0){ tkBadge.style.display='inline-flex'; tkBadge.textContent = n; }
        else { tkBadge.style.display='none'; tkBadge.textContent='0'; }
      }
      function renderInbox(items){
        tkList.innerHTML='';
        if (!items || !items.length){
          tkList.innerHTML = '<div class="empty"><div style="font-size:20px;margin-bottom:6px">🎫</div><div class="title" style="font-weight:700">Sin novedades</div><div class="meta">Aquí verás respuestas y estado de tus tickets</div></div>';
          return;
        }
        items.forEach(function(it){
          var div = document.createElement('div');
          div.className = 'item'+(String(it.status).toUpperCase()==='UNREAD'?' unread':'');
          div.innerHTML = '<div class="title">'+ (it.title||'Ticket') +'</div>'+
                          '<div class="meta">'+ (it.body||'') + (it.time_h?(' · <span class="time">'+it.time_h+'</span>'):'') +'</div>';
          tkList.appendChild(div);
        });
      }
      function fetchInbox(){
        var fd = new FormData();
        fd.append('action','gwv_ticket_inbox');
        fd.append('nonce', NONCE);
        return fetch(AJAX,{method:'POST',credentials:'same-origin',body:fd})
          .then(function(r){ return r.json(); })
          .then(function(res){
            if (!res || !res.success) return;
            renderInbox(res.data.items||[]);
            setTkBadge(res.data.unread||0);
          }).catch(function(){});
      }
      function markInboxRead(){
        var fd = new FormData();
        fd.append('action','gwv_ticket_mark_read');
        fd.append('nonce', NONCE);
        fetch(AJAX,{method:'POST',credentials:'same-origin',body:fd})
          .then(function(r){ return r.json(); })
          .then(function(){
            tkList.querySelectorAll('.item.unread').forEach(function(n){ n.classList.remove('unread'); });
            setTkBadge(0);
          }).catch(function(){});
      }
      // Acciones del panel
      tkNew   && tkNew.addEventListener('click', function(e){ e.preventDefault(); openM(); });
      tkClose && tkClose.addEventListener('click', function(){ closeTkPanel(); });
      tkMark  && tkMark.addEventListener('click', function(){ markInboxRead(); });
      document.addEventListener('click', function(e){
        if (tkPanel.style.display==='block' && !tkPanel.contains(e.target) && !btn.contains(e.target)){
          closeTkPanel();
        }
      });
      // Ticket panel open/close logic using new helpers
      btn && btn.addEventListener('click', function(e){
        e.stopPropagation();
        var vis = (tkPanel.style.display==='block');
        if (vis){
          closeTkPanel();
        } else {
          openTkPanel();
        }
      });
      // Precarga del badge al entrar
      fetchInbox();

      closeX && closeX.addEventListener('click', closeM);
      cancel && cancel.addEventListener('click', closeM);
      modal && modal.addEventListener('click', function(e){ if (e.target === modal) closeM(); });
      document.addEventListener('keydown', function(e){ if (e.key==='Escape') closeM(); });

      send && send.addEventListener('click', function(){
        var t = esc(topic.value);
        var m = esc(msg.value);
        if (!m){ msg.focus(); msg.reportValidity && msg.reportValidity(); return; }

        send.disabled = true; send.textContent = 'Enviando…';

        var fd = new FormData();
        fd.append('action','gwv_ticket_create');
        fd.append('nonce', NONCE);
        fd.append('topic', t);
        fd.append('message', m);

        fetch(AJAX, {method:'POST', credentials:'same-origin', body: fd})
          .then(function(r){ return r.json(); })
          .then(function(res){
            if (res && res.success){
              okBox.style.display='block';
              // pequeño feedback y cierre automático
              setTimeout(closeM, 1600);
            } else {
              alert((res && res.data && res.data.msg) ? res.data.msg : 'No se pudo enviar el ticket.');
            }
          })
          .catch(function(){ alert('Error de red'); })
          .finally(function(){ send.disabled = false; send.textContent = 'Enviar'; });
      });
    })();
    </script>
    <style>
      /* Miniatura estándar para previews de documentos */
      .gw-thumb{margin-top:8px;border:1px dashed #d1d5db;border-radius:10px;overflow:hidden;max-height:150px}
      .gw-thumb img{display:block;width:100%;height:auto;object-fit:cover}
    </style>
    <script>
(function(){
  // Evitar doble inyección
  if (window.__gwDocsPreviewInjectedV2) return;
  window.__gwDocsPreviewInjectedV2 = true;

  function isLikelyDocInput(el){
    if (!el || el.tagName !== 'INPUT' || el.type !== 'file') return false;
    // Menos restrictivo: cualquier input file entra, pero priorizamos los que parezcan de documentos
    var n = (el.getAttribute('name')||'').toLowerCase();
    return !!el.matches('input[type="file"]');
  }

  function ensureThumb(input){
    var wrap = input.closest('.gw-doc-slot, .gw-doc-card, .gw-upload-card, .gw-file-box, .gw-doc-uploader, .gw-field, .gw-documento, .gw-form-group') || input.parentNode;
    var preview = wrap.querySelector('.gw-thumb');
    if (!preview){
      preview = document.createElement('div');
      preview.className = 'gw-thumb';
      var img = document.createElement('img');
      preview.appendChild(img);
      wrap.appendChild(preview);
    }
    return preview.querySelector('img');
  }

  // 1) PREVIEW inmediato — aplicado a CUALQUIER input file (delegado)
  document.addEventListener('change', function(e){
    var el = e.target;
    if (!isLikelyDocInput(el) || !el.files || !el.files[0]) return;
    var f = el.files[0];
    if (!/^image\//.test(f.type || '')) return;
    var img = ensureThumb(el);
    try { img.src = URL.createObjectURL(f); } catch(_){/* noop */}
  }, true);

  // 2) Crear slots extra (3 y 4) de forma defensiva
  function norm(s){ return (s||'').toLowerCase().trim().normalize('NFD').replace(/[\u0300-\u036f]/g,''); }
  function getDocsContainer(anchor){
    // Intenta anclar la inserción cerca del botón pulsado
    if (anchor) {
      var near = anchor.closest('.gw-docs-extra, .gw-docs, .gw-documentos, .gw-upload-area, .gw-upload-card, .gw-file-box, .gw-form-group, .gw-section, .gw-card, form');
      if (near) return near;
    }
    // Fallbacks globales
    return document.querySelector('[data-gw-docs-container]')
        || document.getElementById('gw-docs-form')
        || document.querySelector('.gw-docs')
        || document.querySelector('.gw-form-container')
        || document.querySelector('form');
  }
  function createSlot(n, container, anchor){
    var c = container || getDocsContainer(anchor); if (!c) return null;
    // Evitar duplicados si ya existe el input gw_docN en todo el documento
    if (document.querySelector('input[name="gw_doc'+n+'"]')) return null;

    var slot = document.createElement('div');
    slot.className = 'gw-doc-slot';
    slot.setAttribute('data-slot', String(n));
    slot.innerHTML = '<label style="display:block;font-weight:600;margin:8px 0">Documento de identidad (Foto '+n+')</label>'+
                     '<input type="file" accept="image/*" name="gw_doc'+n+'" data-gw-doc="'+n+'">';

    // Si tenemos el botón, insertar DENTRO del contenedor del botón para que quede visible en el mismo recuadro (debajo del texto)
    if (anchor) {
      var host = anchor.closest('.gw-docs-extra, .gw-upload-area, .gw-file-box, .gw-form-group, .gw-section, .gw-card, .gw-dashed, .gw-dotted, .gw-docs-box');
      if (host) {
        host.appendChild(slot); // al final del recuadro, después del texto indicativo
      } else if (anchor.parentNode) {
        anchor.parentNode.appendChild(slot);
      } else {
        c.appendChild(slot);
      }
    } else {
      c.appendChild(slot);
    }

    try { slot.scrollIntoView({behavior:'smooth', block:'nearest'}); } catch(_){}
    return slot;
  }
  function ensureExtraSlots(anchor){
    var c = getDocsContainer(anchor);
    createSlot(3, c, anchor);
    createSlot(4, c, anchor);
  }

  // 3) Hook del botón “+ Agregar otra foto” (delegado y tolerante a variaciones)
  document.addEventListener('click', function(e){
    var t = e.target;
    var label = norm(t.textContent || t.value || '');
    if (
      t.id === 'gw-add-photo' ||
      t.getAttribute('data-gw-add-doc') === '1' ||
      /(^|\s)[+]?\s*agregar\s+otra\s+foto(\s|$)/.test(label) ||
      /(^|\s)agregar\s+foto(s)?(\s|$)/.test(label)
    ){
      e.preventDefault();
      ensureExtraSlots(t);
    }
  }, true);
})();
</script>
    <?php
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
// ===== PASO 4: CHARLA/PRIMERA SESIÓN EN VIVO (anteriormente paso 5) =====
elseif ($current_step == 4) {
    echo gw_step_5_charla($user_id);
}
// ===== PASO 5: SELECCIÓN DE PROYECTO (anteriormente paso 6) =====
elseif ($current_step == 5) {
    echo gw_step_6_proyecto($user_id);
}
// ===== PASO 6: CAPACITACIONES (anteriormente paso 7) =====
elseif ($current_step == 6) {
    echo gw_step_7_capacitacion($user_id);
}
// ===== PASO 7: CONTROLADOR (anteriormente paso 8) =====
elseif ($current_step == 7) {
    echo gw_step_8_controller($user_id);
}
    // ===== FLUJO COMPLETADO =====
    else {
        echo '<div class="notice notice-success"><p>¡Bienvenido/a! Has completado tu onboarding. Ya puedes participar en todas las actividades.</p></div>';
    }
    echo '</div>';
    return ob_get_clean();
}

// === VOLUNTARIO: AJAX backend de notificaciones Campanita ===
add_action('wp_ajax_gwv_notif_fetch', 'gwv_notif_fetch');
add_action('wp_ajax_gwv_notif_mark_read', 'gwv_notif_mark_read');

// === VOLUNTARIO: AJAX crear ticket (se almacena en wp_notificaciones) ===
add_action('wp_ajax_gwv_ticket_create', 'gwv_ticket_create');
function gwv_ticket_create(){
    if ( !is_user_logged_in() ) wp_send_json_error(['msg'=>'No logueado']);
    $uid = get_current_user_id();
    $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
    if ( !wp_verify_nonce($nonce, 'gwv_ticket') ) wp_send_json_error(['msg'=>'Nonce inválido']);

    $topic = sanitize_text_field( $_POST['topic'] ?? '' );
    $msg   = wp_kses_post( $_POST['message'] ?? '' );

    if ( strlen(trim($msg)) < 5 ){
        wp_send_json_error(['msg'=>'Describe brevemente tu solicitud.']);
    }

    global $wpdb;
    $t = $wpdb->prefix . 'notificaciones';

    // Título y cuerpo amigables
    $user   = wp_get_current_user();
    $title  = 'Ticket: ' . ($topic ? $topic : 'Solicitud de ayuda');
    $body   = $msg;
    $now    = current_time('mysql');

    // Guardamos como notificación para el staff (user_id = 0 => visible a administración; el panel admin filtrará TICKET)
    $wpdb->insert($t, [
        'user_id'    => 0,              // destinatario: administración (se manejará en el panel)
        'type'       => 'TICKET',
        'entity_id'  => intval($uid),   // quién lo creó
        'title'      => $title,
        'body'       => $body,
        'status'     => 'UNREAD',
        'created_at' => $now,
        'read_at'    => null,
    ], ['%d','%s','%d','%s','%s','%s','%s','%s']);

    wp_send_json_success(['ok'=>1]);
}

// === VOLUNTARIO: Inbox de tickets (solo TICKET) ===
add_action('wp_ajax_gwv_ticket_inbox', 'gwv_ticket_inbox');
add_action('wp_ajax_gwv_ticket_mark_read', 'gwv_ticket_mark_read');

function gwv_ticket_inbox(){
    if ( !is_user_logged_in() ) wp_send_json_error(['msg'=>'No logueado']);
    $uid = get_current_user_id();
    $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
    if ( !wp_verify_nonce($nonce, 'gwv_ticket') ) wp_send_json_error(['msg'=>'Nonce inválido']);
    global $wpdb;
    $t = $wpdb->prefix . 'notificaciones';
    $rows = $wpdb->get_results(
        $wpdb->prepare("SELECT id,type,title,body,status,created_at FROM {$t} WHERE user_id=%d AND type='TICKET' ORDER BY id DESC LIMIT 25", $uid),
        ARRAY_A
    );
    $items=[]; 
    if (is_array($rows)){
        foreach($rows as $r){
            $items[] = [
                'id'     => intval($r['id']),
                'type'   => strtoupper((string)$r['type']),
                'title'  => (string)($r['title'] ?: 'Ticket'),
                'body'   => (string)($r['body'] ?: ''),
                'status' => strtoupper((string)$r['status']),
                'time_h' => date_i18n('Y-m-d H:i', strtotime($r['created_at'] ?: 'now')),
            ];
        }
    }
    $unread = intval( $wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE user_id=%d AND type='TICKET' AND status='UNREAD'", $uid) ) );
    wp_send_json_success(['items'=>$items,'unread'=>$unread]);
}

function gwv_ticket_mark_read(){
    if ( !is_user_logged_in() ) wp_send_json_error(['msg'=>'No logueado']);
    $uid = get_current_user_id();
    $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
    if ( !wp_verify_nonce($nonce, 'gwv_ticket') ) wp_send_json_error(['msg'=>'Nonce inválido']);
    global $wpdb;
    $t = $wpdb->prefix . 'notificaciones';
    $wpdb->query( $wpdb->prepare("UPDATE {$t} SET status='read', read_at=NOW() WHERE user_id=%d AND type='TICKET' AND status='UNREAD'", $uid) );
    wp_send_json_success(['ok'=>1]);
}

function gwv_notif_fetch(){
    if ( !is_user_logged_in() ) wp_send_json_error(['msg'=>'No logueado']);
    $uid = get_current_user_id();
    global $wpdb;
    $t = $wpdb->prefix . 'notificaciones';

    // Traer últimas 25
    $rows = $wpdb->get_results(
        $wpdb->prepare("SELECT id,type,entity_id,title,body,status,created_at FROM {$t} WHERE user_id=%d ORDER BY id DESC LIMIT 25", $uid),
        ARRAY_A
    );

    $items = [];
    if (is_array($rows)) {
        foreach ($rows as $r) {
            $items[] = [
                'id'        => intval($r['id']),
                'type'      => strtoupper((string)$r['type']),
                'entity_id' => intval($r['entity_id']),
                'title'     => (string)$r['title'],
                'body'      => (string)$r['body'],
                'status'    => strtoupper((string)$r['status']),
                'time_h'    => date_i18n('Y-m-d H:i', strtotime($r['created_at'] ?: 'now')),
            ];
        }
    }

    $unread = intval( $wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE user_id=%d AND status='UNREAD'", $uid) ) );

    wp_send_json_success(['items'=>$items, 'unread'=>$unread]);
}

function gwv_notif_mark_read(){
    if ( !is_user_logged_in() ) wp_send_json_error(['msg'=>'No logueado']);
    $uid = get_current_user_id();
    global $wpdb;
    $t = $wpdb->prefix . 'notificaciones';

    $wpdb->query( $wpdb->prepare("UPDATE {$t} SET status='read', read_at=NOW() WHERE user_id=%d AND status='UNREAD'", $uid) );
    wp_send_json_success(['unread'=>0]);
}
 
// --- Lógica para saber en qué paso va el usuario ---

/**
 * Controla el flujo del paso 8.
 * Si ya se seleccionó escuela/horario y el formulario extra aún no se ha completado,
 * muestra la página extra. En caso contrario, delega al paso existente de documentos.
 */
function gw_step_8_controller($user_id) {
    // Detecta si el voluntario ya seleccionó escuela/horario (ajusta las metas si usas otras)
    $tiene_escuela  = get_user_meta($user_id, 'gw_escuela_id', true);
    $tiene_horario  = get_user_meta($user_id, 'gw_horario', true) ?: get_user_meta($user_id, 'gw_horario_id', true);
    $extra_completo = get_user_meta($user_id, 'gw_step8_extra_completo', true);

    // Si ya eligió escuela y horario pero no ha llenado el intermedio, mostrarlo SIEMPRE
    if ( $tiene_escuela && $tiene_horario && !$extra_completo ) {
        return gw_step_8_extra_form($user_id);
    }

    // Si el plugin ya tiene implementado el paso 8 original, delegamos
    if ( function_exists('gw_step_8_documentos') ) {
        return gw_step_8_documentos($user_id);
    }

    // Fallback si por alguna razón no existe la función original
    return '<div class="notice notice-warning"><p>No se encontró la vista de documentos. Contacta al administrador.</p></div>';
}

/**
 * Página intermedia para jóvenes (slogan + formulario breve) entre selección de escuela/horario
 * y la subida de documentos. Guarda los datos en user_meta y marca gw_step8_extra_completo.
 */
function gw_step_8_extra_form($user_id) {
    $error = '';

    // Procesamiento del formulario
    if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gw_extra_nonce']) && wp_verify_nonce($_POST['gw_extra_nonce'], 'gw_step8_extra') ) {
        $nombre     = sanitize_text_field( $_POST['gw_extra_nombre'] ?? '' );
        $telefono   = sanitize_text_field( $_POST['gw_extra_telefono'] ?? '' );
        $direccion  = sanitize_text_field( $_POST['gw_extra_direccion'] ?? '' );
        $observacion= sanitize_textarea_field( $_POST['gw_extra_observacion'] ?? '' );

        if ( !$nombre || !$telefono || !$direccion ) {
            $error = 'Por favor completa nombre, teléfono y dirección.';
        } else {
            update_user_meta($user_id, 'gw_extra_nombre', $nombre);
            update_user_meta($user_id, 'gw_extra_telefono', $telefono);
            update_user_meta($user_id, 'gw_extra_direccion', $direccion);
            update_user_meta($user_id, 'gw_extra_observacion', $observacion);
            update_user_meta($user_id, 'gw_step8_extra_completo', 1);

            // Redirigir de vuelta al portal para continuar con la subida de documentos
            return '<meta http-equiv="refresh" content="0;url=' . esc_url( site_url('/index.php/portal-voluntario/') ) . '">';
        }
    }

    // Valores por defecto / precarga
    $user        = get_userdata($user_id);
    $nombre_pref = get_user_meta($user_id, 'gw_nombre', true) ?: ($user ? $user->display_name : '');

    $nombre     = isset($_POST['gw_extra_nombre']) ? sanitize_text_field($_POST['gw_extra_nombre']) : $nombre_pref;
    $telefono   = isset($_POST['gw_extra_telefono']) ? sanitize_text_field($_POST['gw_extra_telefono']) : get_user_meta($user_id, 'gw_telefono', true);
    $direccion  = isset($_POST['gw_extra_direccion']) ? sanitize_text_field($_POST['gw_extra_direccion']) : get_user_meta($user_id, 'gw_extra_direccion', true);
    $observacion= isset($_POST['gw_extra_observacion']) ? sanitize_textarea_field($_POST['gw_extra_observacion']) : get_user_meta($user_id, 'gw_extra_observacion', true);

    ob_start();
    ?>
    <style>
    .gw-extra-wrapper{max-width:980px;margin:0 auto;padding:24px}
    .gw-extra-hero{background:linear-gradient(135deg,#eef7ff 0,#f8fff0 100%);border:1px solid #e7f0ff;border-radius:14px;padding:28px 24px;margin-bottom:22px;display:flex;gap:18px;align-items:center}
    .gw-extra-hero .gw-emoji{font-size:38px;line-height:1}
    .gw-extra-hero h2{margin:0 0 6px 0;font-size:26px}
    .gw-extra-hero p{margin:0;color:#3b4856}
    .gw-extra-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
    .gw-extra-grid .full{grid-column:1/-1}
    .gw-extra-card{background:#fff;border:1px solid #e7ecf1;border-radius:12px;padding:18px}
    .gw-extra-card label{font-weight:600;display:block;margin-bottom:6px}
    .gw-extra-card input,.gw-extra-card textarea{width:100%;border:1px solid #cfd6df;border-radius:10px;padding:10px 12px}
    .gw-extra-actions{margin-top:18px;display:flex;gap:12px}
    .gw-btn-primary{background:linear-gradient(135deg,#c4c33f 0,#a3a332 100%);color:#fff;border:none;border-radius:10px;padding:10px 16px;font-weight:700;cursor:pointer}
    .gw-btn-secondary{background:#eef2f7;border:1px solid #d8e0ea;color:#2b3a4b;border-radius:10px;padding:10px 16px;font-weight:600;text-decoration:none}
    .gw-error{background:#fff4f4;border:1px solid #ffcece;color:#9b2c2c;padding:10px 12px;border-radius:10px;margin-bottom:12px}
    </style>

    <div class="gw-extra-wrapper">
        <div class="gw-extra-hero">
            <div class="gw-emoji">🚀</div>
            <div>
                <h2>¡Sigue adelante! Tu energía puede cambiar vidas</h2>
                <p>Antes de subir tus documentos, cuéntanos un poco más de ti. ¡Esto nos ayuda a acompañarte mejor!</p>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="gw-error"><?php echo esc_html($error); ?></div>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field('gw_step8_extra', 'gw_extra_nonce'); ?>
            <div class="gw-extra-grid">
                <div class="gw-extra-card">
                    <label for="gw_extra_nombre">Nombre</label>
                    <input type="text" id="gw_extra_nombre" name="gw_extra_nombre" value="<?php echo esc_attr($nombre); ?>" required>
                </div>
                <div class="gw-extra-card">
                    <label for="gw_extra_telefono">Número de teléfono</label>
                    <input type="text" id="gw_extra_telefono" name="gw_extra_telefono" value="<?php echo esc_attr($telefono); ?>" required>
                </div>
                <div class="gw-extra-card full">
                    <label for="gw_extra_direccion">Dirección de residencia</label>
                    <input type="text" id="gw_extra_direccion" name="gw_extra_direccion" value="<?php echo esc_attr($direccion); ?>" required>
                </div>
                <div class="gw-extra-card full">
                    <label for="gw_extra_observacion">Observación (opcional)</label>
                    <textarea id="gw_extra_observacion" name="gw_extra_observacion" rows="4" placeholder="Escribe aquí cualquier comentario que quieras compartir..."><?php echo esc_textarea($observacion); ?></textarea>
                </div>
            </div>
            <div class="gw-extra-actions">
                <a class="gw-btn-secondary" href="<?php echo esc_url( site_url('/index.php/portal-voluntario/') ); ?>">Regresar</a>
                <button type="submit" class="gw-btn-primary">Guardar y continuar</button>
            </div>
        </form>
    </div>
    <?php
    return ob_get_clean();
}
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
                        <h3>Charlas</h3>
                        <p>Regístrate en la charla asignada y participa.</p>
                    </div>
                </div>
                <div class="gw-step-item">
                    <div class="gw-step-number">4</div>
                    <div class="gw-step-content">
                        <h3>Selección de proyecto</h3>
                        <p>Elige el proyecto en el que participarás.</p>
                    </div>
                </div>
                <div class="gw-step-item">
                    <div class="gw-step-number">5</div>
                    <div class="gw-step-content">
                        <h3>Capacitaciones</h3>
                        <p>Inscríbete y marca tu asistencia para continuar.</p>
                    </div>
                </div>

                      <!-- Paso 7 -->
                    <div class="gw-step-item">
                        <div class="gw-step-number">6</div>
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


                    <!-- Paso 5 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">3</div>
                    <div class="gw-step-content">
                        <h3>Charlas</h3>
                        <p>Regístrate en la charla asignada y participa.</p>
                    </div>
                    </div>

                    <!-- Paso 6 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">4</div>
                    <div class="gw-step-content">
                        <h3>Selección de proyecto</h3>
                        <p>Elige el proyecto en el que participarás.</p>
                    </div>
                    </div>

                    <!-- Paso 7 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">5</div>
                    <div class="gw-step-content">
                        <h3>Capacitaciones</h3>
                        <p>Inscríbete y marca tu asistencia para continuar.</p>
                    </div>
                    </div>

                      <!-- Paso 8 -->
                    <div class="gw-step-item">
                        <div class="gw-step-number">6</div>
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


                    <!-- Paso 5 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">3</div>
                    <div class="gw-step-content">
                        <h3>Charlas</h3>
                        <p>Regístrate en la charla asignada y participa.</p>
                    </div>
                    </div>

                    <!-- Paso 6 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">4</div>
                    <div class="gw-step-content">
                        <h3>Selección de proyecto</h3>
                        <p>Elige el proyecto en el que participarás.</p>
                    </div>
                    </div>

                    <!-- Paso 7 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">5</div>
                    <div class="gw-step-content">
                        <h3>Capacitaciones</h3>
                        <p>Inscríbete y marca tu asistencia para continuar.</p>
                    </div>
                    </div>

                      <!-- Paso 8 -->
                    <div class="gw-step-item">
                        <div class="gw-step-number">6</div>
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


                    <div class="gw-step-item active">
                    <div class="gw-step-number">3</div>
                    <div class="gw-step-content">
                        <h3>Charlas</h3>
                        <p>Regístrate en la charla asignada y participa.</p>
                    </div>
                    </div>

                    <div class="gw-step-item">
                    <div class="gw-step-number">4</div>
                    <div class="gw-step-content">
                        <h3>Selección de proyecto</h3>
                        <p>Elige el proyecto en el que participarás.</p>
                    </div>
                    </div>

                    <div class="gw-step-item">
                    <div class="gw-step-number">5</div>
                    <div class="gw-step-content">
                        <h3>Capacitaciones</h3>
                        <p>Inscríbete y marca tu asistencia para continuar.</p>
                    </div>
                    </div>

                                          <!-- Paso 8 -->
                                          <div class="gw-step-item">
                        <div class="gw-step-number">6</div>
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
        // Reinicia el flag de asistencia aprobada para la nueva charla
        delete_user_meta($user_id, 'gw_charla_asistio');
        delete_user_meta($user_id, 'gw_charla_asistio_' . intval($charla_id));
        
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
        
        $cid_current = !empty($charla_agendada['charla_id']) ? intval($charla_agendada['charla_id']) : 0;
        $already_completed_current = ($cid_current && in_array($cid_current, array_map('intval', (array)$charlas_completadas), true));
        $predicted_completadas = count((array)$charlas_completadas) + ($already_completed_current ? 0 : 1);
        $has_more = count((array)$charlas_asignadas) > $predicted_completadas;
        $button_label = $has_more ? 'Siguiente charla' : 'Ir a Selección de proyecto';
        
        $content .= '
            </div>
        </div>
        ';

        // Bloqueo del avance hasta que el admin apruebe asistencia
        // Acepta tanto meta global como meta por charla específica (gw_charla_asistio_{ID})
        $approved_flag = get_user_meta($user_id, 'gw_charla_asistio', true);
        $cid_current   = !empty($charla_agendada['charla_id']) ? intval($charla_agendada['charla_id']) : 0;
        if (!$approved_flag && $cid_current) {
            $approved_flag = get_user_meta($user_id, 'gw_charla_asistio_' . $cid_current, true);
        }
        // Aceptar varios formatos posibles de "aprobado"
        $approved_bool = (
            $approved_flag === 1 || $approved_flag === '1' || $approved_flag === true ||
            (is_string($approved_flag) && in_array(strtolower($approved_flag), ['si','sí','asistio','aprobado','yes'], true))
        );
        // Si no hay flag explícito, considerar aprobado si la charla ya figura en el array de completadas
        if (!$approved_bool && $cid_current) {
            $approved_bool = in_array($cid_current, array_map('intval', (array)$charlas_completadas), true);
        }

        if ($approved_bool) {
        // Aprobado: mostrar el formulario real que permite avanzar
        $content .= '
        <form method="post" class="gw-form">
        ' . wp_nonce_field('gw_charla_asistencia', 'gw_charla_asistencia_nonce', true, false) . '
        <div class="gw-form-actions">
            <button type="submit" class="gw-btn-primary">' . esc_html($button_label) . '</button>
        </div>
         </form>
    ';
    } else {
    // NO aprobado: botón gris e interceptar clic con mensaje
    $content .= '
    <div class="gw-form-actions">
        <button type="button"
                class="gw-btn-primary"
                id="gw-next-charla-locked"
                aria-disabled="true"
                title="Tu asistencia debe ser aprobada por un administrador para continuar."
                style="opacity:.5;cursor:not-allowed;">
            ' . esc_html($button_label) . '
        </button>
    </div>
    <p class="gw-charla-note">Tu asistencia debe ser aprobada por un administrador para continuar.</p>
    <script>
    (function(){
        var b = document.getElementById("gw-next-charla-locked");
        if(!b) return;
        b.addEventListener("click", function(e){
            e.preventDefault();
            alert("Tu asistencia debe ser aprobada por un administrador para continuar.");
        });
    })();
    </script>
    ';
}

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
            // Limpiar el flag para futuras charlas (evita que quede desbloqueado)
            delete_user_meta($user_id, 'gw_charla_asistio');
            if (!empty($charla_agendada['charla_id'])) {
                delete_user_meta($user_id, 'gw_charla_asistio_' . intval($charla_agendada['charla_id']));
            }
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
                    <div class="gw-step-number">5</div>
                    <div class="gw-step-content">
                        <h3>Capacitaciones</h3>
                        <p>Inscríbete y marca tu asistencia para continuar.</p>
                    </div>
                    </div>

                    <!-- Paso 8 -->
                    <div class="gw-step-item">
                        <div class="gw-step-number">6</div>
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
                    <div class="gw-step-number">4</div>
                    <div class="gw-step-content">
                        <h3>Selección de proyecto</h3>
                        <p>Elige el proyecto en el que participarás.</p>
                    </div>
                    </div>

                    <!-- Paso 7 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">5</div>
                    <div class="gw-step-content">
                        <h3>Capacitaciones</h3>
                        <p>Inscríbete y marca tu asistencia para continuar.</p>
                    </div>
                    </div>

                                          <!-- Paso 8 -->
                                          <div class="gw-step-item">
                        <div class="gw-step-number">6</div>
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
                    <div class="gw-step-number">4</div>
                    <div class="gw-step-content">
                        <h3>Selección de proyecto</h3>
                        <p>Elige el proyecto en el que participarás.</p>
                    </div>
                    </div>

                    <!-- Paso 7 -->
                    <div class="gw-step-item">
                    <div class="gw-step-number">5</div>
                    <div class="gw-step-content">
                        <h3>Capacitaciones</h3>
                        <p>Inscríbete y marca tu asistencia para continuar.</p>
                    </div>
                    </div>

                                          <!-- Paso 8 -->
                                          <div class="gw-step-item">
                        <div class="gw-step-number">6</div>
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
        <div class="gw-step-item"><div class="gw-step-number">✓</div><div class="gw-step-content"><h3>Charlas</h3><p>Regístrate en la charla asignada y participa.</p></div></div>
        <div class="gw-step-item"><div class="gw-step-number">✓</div><div class="gw-step-content"><h3>Selección de proyecto</h3><p>Elige el proyecto en el que participarás.</p></div></div>
        <div class="gw-step-item active"><div class="gw-step-number">5</div><div class="gw-step-content"><h3>Capacitaciones</h3><p>Inscríbete y marca tu asistencia para continuar.</p></div></div>
        <div class="gw-step-item"><div class="gw-step-number">6</div><div class="gw-step-content"><h3>Documentos y escuela</h3><p>Selecciona tu escuela y sube tus documentos.</p></div></div>
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
        // Reinicia el flag de asistencia aprobada para la nueva capacitación
        delete_user_meta($user_id, 'gw_cap_asistio');
        if (!empty($cap_id)) {
            delete_user_meta($user_id, 'gw_cap_asistio_' . intval($cap_id));
        }

        $content = '<div class="gw-success-registration"><div class="gw-success-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="10"/></svg></div><h2 class="gw-success-title">¡Te has registrado con éxito!</h2><p class="gw-success-description">Tu registro en la capacitación <strong>' . esc_html($capacitacion->post_title) . '</strong> ha sido confirmado.</p><div class="gw-loading-spinner"></div></div><meta http-equiv="refresh" content="3;url=' . esc_url(site_url('/index.php/portal-voluntario/')) . '">';

        return $render_layout('¡Registro exitoso!', 'Serás redirigido automáticamente...', $content);
    }

    // ===== 4/5) Recordatorio + \"Siguiente capacitación\" =====
    if ($capacitacion_agendada && !$forzar_menu) {
        $predicted   = count($capacitaciones_completadas) + 1;
        $has_more    = count($capacitaciones_asignadas) > $predicted;
        $button_text = $has_more ? 'Siguiente capacitación' : 'Ir a documentos y escuela';

        // Procesar avance SOLO si el admin ya aprobó asistencia
        if (
            $_SERVER['REQUEST_METHOD'] === 'POST'
            && isset($_POST['gw_capacitacion_asistencia_nonce'])
            && wp_verify_nonce($_POST['gw_capacitacion_asistencia_nonce'], 'gw_capacitacion_asistencia')
        ) {
            // Revisar flag de aprobación del admin
            $cap_approved_flag = get_user_meta($user_id, 'gw_cap_asistio', true);
            if (!$cap_approved_flag && !empty($capacitacion_agendada['cap_id'])) {
                $cap_approved_flag = get_user_meta($user_id, 'gw_cap_asistio_' . intval($capacitacion_agendada['cap_id']), true);
            }
            $approved_cap_bool = ($cap_approved_flag === '1' || $cap_approved_flag === 1 || $cap_approved_flag === true);

            if (!$approved_cap_bool) {
                // Seguridad extra: no permitir avanzar por POST si no está aprobado
                wp_die('Tu asistencia debe ser aprobada por un administrador para continuar.');
            }

            // Marcar esta capacitación como completada
            $capacitaciones_completadas[] = (int)$capacitacion_agendada['cap_id'];
            $capacitaciones_completadas = array_values(array_unique(array_map('intval', $capacitaciones_completadas)));
            update_user_meta($user_id, 'gw_capacitaciones_completadas', $capacitaciones_completadas);

            // Limpiar agenda actual y flags de asistencia
            delete_user_meta($user_id, 'gw_capacitacion_agendada');
            delete_user_meta($user_id, 'gw_cap_asistio');
            if (!empty($capacitacion_agendada['cap_id'])) {
                delete_user_meta($user_id, 'gw_cap_asistio_' . intval($capacitacion_agendada['cap_id']));
            }

            // ¿Quedan más?
            $quedan = false;
            $done = array_map('intval', get_user_meta($user_id, 'gw_capacitaciones_completadas', true) ?: []);
            foreach ($capacitaciones_asignadas as $cid) {
                if (!in_array((int)$cid, $done, true)) { $quedan = true; break; }
            }
            if (!$quedan) update_user_meta($user_id, 'gw_step7_completo', 1);

            if ($has_more) {
                wp_safe_redirect(site_url('/index.php/portal-voluntario/'));
            } else {
                wp_safe_redirect(site_url('/index.php/portal-voluntario/?paso8_menu=1'));
            }
            exit;
        }

        // Comprobar si el admin ya aprobó la asistencia de esta capacitación
        $cap_approved_flag = get_user_meta($user_id, 'gw_cap_asistio', true);
        if (!$cap_approved_flag && !empty($capacitacion_agendada['cap_id'])) {
            $cap_approved_flag = get_user_meta($user_id, 'gw_cap_asistio_' . intval($capacitacion_agendada['cap_id']), true);
        }
        $approved_cap_bool = ($cap_approved_flag === '1' || $cap_approved_flag === 1 || $cap_approved_flag === true);

        $enlace_html = !empty($capacitacion_agendada['enlace'])
            ? '<a href="' . esc_url($capacitacion_agendada['enlace']) . '" target="_blank" rel="noopener">Unirse a la capacitación</a>'
            : '<span class="gw-text-muted">No hay enlace configurado</span>';

        if ($approved_cap_bool) {
            // Botón habilitado (aprobado por admin)
            $content = '
<div class="gw-charla-info">
  <div class="gw-charla-title">' . esc_html($capacitacion_agendada['cap_title']) . ' <span class="gw-charla-modalidad">(' . ucfirst($capacitacion_agendada['modalidad']) . ')</span></div>
  <div class="gw-charla-details">
    <div class="gw-detail-item"><strong>Fecha:</strong> ' . date('d/m/Y', strtotime($capacitacion_agendada['fecha'])) . '</div>
    <div class="gw-detail-item"><strong>Hora:</strong> ' . esc_html($capacitacion_agendada['hora']) . '</div>' .
    ($capacitacion_agendada['modalidad'] === 'presencial'
      ? '<div class="gw-detail-item"><strong>Lugar:</strong> ' . esc_html($capacitacion_agendada['lugar']) . '</div>'
      : '<div class="gw-detail-item"><strong>Enlace:</strong> ' . $enlace_html . '</div>') .
  '</div>
</div>
<form method="post" class="gw-form">' .
  wp_nonce_field('gw_capacitacion_asistencia', 'gw_capacitacion_asistencia_nonce', true, false) .
  '<div class="gw-form-actions">
    <button type="submit" class="gw-btn-primary">' . esc_html($button_text) . '</button>
  </div>
</form>
<div class="gw-charla-note"><p>Recuerda ir al enlace y marcar tu asistencia.</p></div>';
        } else {
            // Botón bloqueado (no aprobado por admin)
            $content = '
<div class="gw-charla-info">
  <div class="gw-charla-title">' . esc_html($capacitacion_agendada['cap_title']) . ' <span class="gw-charla-modalidad">(' . ucfirst($capacitacion_agendada['modalidad']) . ')</span></div>
  <div class="gw-charla-details">
    <div class="gw-detail-item"><strong>Fecha:</strong> ' . date('d/m/Y', strtotime($capacitacion_agendada['fecha'])) . '</div>
    <div class="gw-detail-item"><strong>Hora:</strong> ' . esc_html($capacitacion_agendada['hora']) . '</div>' .
    ($capacitacion_agendada['modalidad'] === 'presencial'
      ? '<div class="gw-detail-item"><strong>Lugar:</strong> ' . esc_html($capacitacion_agendada['lugar']) . '</div>'
      : '<div class="gw-detail-item"><strong>Enlace:</strong> ' . $enlace_html . '</div>') .
  '</div>
</div>
<div class="gw-form-actions">
  <button type="button"
          class="gw-btn-primary"
          id="gw-next-cap-locked"
          aria-disabled="true"
          title="Tu asistencia debe ser aprobada por un administrador para continuar."
          style="opacity:.5;cursor:not-allowed;">' . esc_html($button_text) . '</button>
</div>
<p class="gw-charla-note">Tu asistencia debe ser aprobada por un administrador para continuar.</p>
<script>
  (function(){
    var b = document.getElementById("gw-next-cap-locked");
    if(!b) return;
    b.addEventListener("click", function(e){
      e.preventDefault();
      alert("Tu asistencia debe ser aprobada por un administrador para continuar.");
    });
  })();
</script>';
        }

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

// ==== AJAX (frontend) para estado de asistencia: charla/cap ====
// Se usa para bloquear el botón "Siguiente Charla / Siguiente Capacitación" hasta que el admin marque asistencia.
add_action('wp_ajax_gw_asist_status', function(){
    if ( !is_user_logged_in() ) {
        wp_send_json_error(['msg' => 'nologin']);
    }
    $uid = get_current_user_id();
    // Estas metas deben ser puestas en true/1 por el modal "Asistencias" en el panel (gw-manager)
    $charla_aprobada = get_user_meta($uid, 'gw_charla_asistio', true) === '1';
    $cap_aprobada    = get_user_meta($uid, 'gw_cap_asistio', true) === '1';

    wp_send_json_success([
        'charla' => $charla_aprobada,
        'cap'    => $cap_aprobada,
    ]);
});

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
                        <div class="gw-step-number">6</div>
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


                        <!-- Formulario de preguntas ClassWin (colocar ANTES del formulario de documentos) -->
<!-- Formulario de preguntas ClassWin (colocar ANTES del formulario de documentos) -->
<?php if ($escuela_id && $horario): ?>
  <div class="gw-section gw-classwin-form-wrap">
    <div class="gw-classwin-header">
      <h2>Preguntas sobre Glasswing</h2>
      <p>Responde las siguientes preguntas. (Preguntas de ejemplo con “lor en ipsu”)</p>
    </div>

    <form method="post" class="gw-classwin-form" id="gw-classwin-form">
      <?php wp_nonce_field('gw_classwin_qa', 'gw_classwin_nonce'); ?>

      <div class="gw-qa-grid">
        <!-- Q1 -->
        <div class="gw-qa-row">
          <div>
            <p class="gw-field-label">Pregunta 1: <span class="gw-required">*</span></p>
            <p class="gw-question-text">Lor en ipsu dolor sit amet, ¿ejemplo de pregunta 1?</p>
          </div>
          <div>
            <label class="gw-field-label" for="classwin_a1">
              Respuesta 1 <span class="gw-required">*</span>
            </label>
            <textarea
              id="classwin_a1"
              name="classwin_a1"
              class="gw-textarea"
              placeholder="Escribe tu respuesta aquí..."
              required
            ></textarea>
          </div>
        </div>

        <!-- Q2 -->
        <div class="gw-qa-row">
          <div>
            <p class="gw-field-label">Pregunta 2: <span class="gw-required">*</span></p>
            <p class="gw-question-text">Lor en ipsu dolor sit amet, ¿ejemplo de pregunta 2?</p>
          </div>
          <div>
            <label class="gw-field-label" for="classwin_a2">
              Respuesta 2 <span class="gw-required">*</span>
            </label>
            <textarea
              id="classwin_a2"
              name="classwin_a2"
              class="gw-textarea"
              placeholder="Escribe tu respuesta aquí..."
              required
            ></textarea>
          </div>
        </div>

        <!-- Q3 -->
        <div class="gw-qa-row">
          <div>
            <p class="gw-field-label">Pregunta 3: <span class="gw-required">*</span></p>
            <p class="gw-question-text">Lor en ipsu dolor sit amet, ¿ejemplo de pregunta 3?</p>
          </div>
          <div>
            <label class="gw-field-label" for="classwin_a3">
              Respuesta 3 <span class="gw-required">*</span>
            </label>
            <textarea
              id="classwin_a3"
              name="classwin_a3"
              class="gw-textarea"
              placeholder="Escribe tu respuesta aquí..."
              required
            ></textarea>
          </div>
        </div>
      </div>

      <div class="gw-form-actions">
        <button type="submit" class="gw-btn-primary" id="gw-submit-classwin">
          <span class="gw-btn-text">Guardar respuestas</span>
          <span class="gw-btn-loading" style="display:none;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
              <path d="M12 2V6M12 18V22M4.93 4.93L7.76 7.76M16.24 16.24L19.07 19.07M2 12H6M18 12H22M4.93 19.07L7.76 16.24M16.24 7.76L19.07 4.93" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            Guardando...
          </span>
        </button>
      </div>
    </form>

    <script>
(function(){
  var form = document.getElementById('gw-classwin-form');
  if(!form) return;

  var a1 = document.getElementById('classwin_a1');
  var a2 = document.getElementById('classwin_a2');
  var a3 = document.getElementById('classwin_a3');
  var btn = document.getElementById('gw-submit-classwin');
  var txt = btn ? btn.querySelector('.gw-btn-text') : null;
  var load = btn ? btn.querySelector('.gw-btn-loading') : null;

  // Mensaje inline bajo el botón
  var statusEl = document.createElement('div');
  statusEl.id = 'gw-classwin-inline-status';
  statusEl.style.cssText = 'margin-top:8px;font-size:13px;color:#059669;font-weight:600;display:none;';
  var actions = form.querySelector('.gw-form-actions') || form;
  actions.appendChild(statusEl);

  // Clave de storage por usuario
  var KEY = 'gw_classwin_' + <?php echo intval($user_id); ?>;

  // Prefill desde localStorage
  try{
    var saved = JSON.parse(localStorage.getItem(KEY) || '{}');
    if(saved.a1 && !a1.value) a1.value = saved.a1;
    if(saved.a2 && !a2.value) a2.value = saved.a2;
    if(saved.a3 && !a3.value) a3.value = saved.a3;
    if(saved.ts){
      statusEl.textContent = 'Borrador recuperado ('+ new Date(saved.ts).toLocaleTimeString() +')';
      statusEl.style.display = 'block';
    }
  }catch(e){}

  function showStatus(msg, ok){
    statusEl.textContent = msg + ' • ' + new Date().toLocaleTimeString();
    statusEl.style.color = ok===false ? '#b91c1c' : '#059669';
    statusEl.style.display = 'block';
  }

  // Autosave (500ms tras escribir)
  var t;
  function queueSave(){
    clearTimeout(t);
    t = setTimeout(function(){
      var data = {
        a1: a1 ? a1.value : '',
        a2: a2 ? a2.value : '',
        a3: a3 ? a3.value : '',
        ts: Date.now()
      };
      localStorage.setItem(KEY, JSON.stringify(data));
      showStatus('Guardado localmente');
    }, 500);
  }
  [a1,a2,a3].forEach(function(el){ el && el.addEventListener('input', queueSave); });

  // Envío AJAX (mantiene tu endpoint)
  form.addEventListener('submit', function(ev){
    ev.preventDefault();
    if(!btn) return;
    if(txt) txt.style.display = 'none';
    if(load) load.style.display = 'inline-flex';
    btn.disabled = true;

    var fd = new FormData(form);
    fd.append('action','gw_step8_save_answers');
    fd.append('nonce','<?php echo wp_create_nonce('gw_step8'); ?>');

    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {method:'POST', credentials:'same-origin', body: fd})
      .then(function(r){return r.json();})
      .then(function(res){
        if(txt) txt.style.display = '';
        if(load) load.style.display = 'none';
        btn.disabled = false;
        if(res && res.success){
          localStorage.removeItem(KEY);
          showStatus('Respuestas guardadas', true);
        } else {
          showStatus('No se pudo guardar', false);
        }
      })
      .catch(function(){
        if(txt) txt.style.display = '';
        if(load) load.style.display = 'none';
        btn.disabled = false;
        showStatus('Error de red', false);
      });
  });
})();
</script>

    <script>
    (function(){
      var form = document.getElementById('gw-classwin-form');
      var submitBtn = document.getElementById('gw-submit-classwin');
      if(!form || !submitBtn) return;

      var AJAX  = "<?php echo esc_js( admin_url('admin-ajax.php') ); ?>";
      var NONCE = "<?php echo esc_js( wp_create_nonce('gw_step8') ); ?>";

      function setLoading(on){
        var txt  = submitBtn.querySelector('.gw-btn-text');
        var load = submitBtn.querySelector('.gw-btn-loading');
        if(on){
          if(txt)  txt.style.display = 'none';
          if(load) load.style.display = 'inline-flex';
          submitBtn.disabled = true;
        } else {
          if(txt)  txt.style.display = '';
          if(load) load.style.display = 'none';
          submitBtn.disabled = false;
        }
      }

      function toast(msg){
        var el = document.createElement('div');
        el.textContent = msg;
        el.style.cssText = 'position:fixed;top:16px;right:16px;background:#111;color:#fff;padding:10px 14px;border-radius:8px;z-index:999999;opacity:.95';
        document.body.appendChild(el);
        setTimeout(function(){ el.remove(); }, 2000);
      }

      submitBtn.addEventListener('click', function(ev){
        ev.preventDefault();

        var a1 = form.querySelector('[name="classwin_a1"]') ? form.querySelector('[name="classwin_a1"]').value : '';
        var a2 = form.querySelector('[name="classwin_a2"]') ? form.querySelector('[name="classwin_a2"]').value : '';
        var a3 = form.querySelector('[name="classwin_a3"]') ? form.querySelector('[name="classwin_a3"]').value : '';

        setLoading(true);

        var fd = new FormData();
        fd.append('action', 'gw_step8_save_answers');
        fd.append('nonce', NONCE);
        fd.append('answers[pregunta_1]', a1);
        fd.append('answers[pregunta_2]', a2);
        fd.append('answers[pregunta_3]', a3);

        fetch(AJAX, {method:'POST', credentials:'same-origin', body:fd})
          .then(function(r){ return r.json(); })
          .then(function(res){
            setLoading(false);
            if(res && res.success){ toast('Respuestas guardadas'); }
            else { toast('No se pudieron guardar'); }
          })
          .catch(function(){
            setLoading(false);
            toast('Error de conexión');
          });
      });
    })();
    </script>
  </div>
<?php endif; ?>


<style>/* Estilos base para formulario de preguntas */
.gw-classwin-form-wrap {
  background: #fff;
  border: 1px solid #e9e9ef;
  border-radius: 16px;
  padding: 24px;
  box-shadow: 0 4px 14px rgba(17, 24, 39, 0.06);
  margin-bottom: 32px;
}

.gw-classwin-header h2 {
  font-size: 22px;
  font-weight: 800;
  margin-bottom: 6px;
  color: #111827;
}

.gw-classwin-header p {
  color: #6b7280;
  font-size: 14px;
  margin-bottom: 18px;
}

.gw-qa-grid {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.gw-qa-row {
  display: grid;
  gap: 16px;
  background: #fafafb;
  border: 1px dashed #e5e7eb;
  border-radius: 12px;
  padding: 16px;
}

@media (min-width: 768px) {
  .gw-qa-row {
    grid-template-columns: 1fr 1fr;
  }
}

.gw-field-label {
  display: flex;
  align-items: center;
  gap: 5px;
  font-weight: 700;
  margin-bottom: 6px;
  color: #111827;
}

.gw-required {
  color: #dc2626;
}

.gw-input,
.gw-textarea {
  width: 100%;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  padding: 10px 12px;
  font-size: 14px;
  color: #111827;
  background: #fff;
  transition: border-color .2s, box-shadow .2s;
  box-sizing: border-box;
}

.gw-input::placeholder,
.gw-textarea::placeholder {
  color: #9ca3af;
}

.gw-input:focus,
.gw-textarea:focus {
  border-color: #a78bfa;
  box-shadow: 0 0 0 3px rgba(167, 139, 250, 0.25);
  outline: none;
}

.gw-textarea {
  min-height: 100px;
  resize: vertical;
}

.gw-form-actions {
  display: flex;
  justify-content: flex-end;
  margin-top: 20px;
}

.gw-btn-primary {
  border: none;
  border-radius: 12px;
  padding: 12px 18px;
  background: #c0c34d;
  color: white;
  font-weight: 600;
  font-size: 15px;
  cursor: pointer;
  transition: background .2s;
  display: flex;
  align-items: center;
  gap: 6px;
}

.gw-btn-primary:hover {
  background: #a2a63d;
}

.gw-btn-loading {
  display: flex;
  align-items: center;
  gap: 6px;
}

/* Estilos para sección de documentos */
.gw-section {
  background: #fff;
  border: 1px solid #e9e9ef;
  border-radius: 16px;
  padding: 24px;
  box-shadow: 0 4px 14px rgba(17, 24, 39, 0.06);
  margin-bottom: 32px;
}

.gw-section-header h2 {
  font-size: 22px;
  font-weight: 800;
  margin-bottom: 6px;
  color: #111827;
}

.gw-section-header p {
  color: #6b7280;
  font-size: 14px;
  margin-bottom: 18px;
}

/* Grid de documentos - Responsive */
.gw-documents-grid {
  display: grid;
  gap: 20px;
  grid-template-columns: 1fr;
}

@media (min-width: 768px) {
  .gw-documents-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}

/* Cada upload de documento */
.gw-document-upload {
  border: 1px dashed #e5e7eb;
  border-radius: 12px;
  padding: 16px;
  background: #fafafb;
  transition: border-color 0.2s;
}

.gw-document-upload:hover {
  border-color: #c0c34d;
}

.gw-upload-label {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 5px;
  font-weight: 700;
  margin-bottom: 12px;
  color: #111827;
}

.gw-label-text {
  font-size: 14px;
}

/* Preview de documento */
.gw-document-preview {
  position: relative;
  margin-bottom: 12px;
  border-radius: 8px;
  overflow: hidden;
  background: #f3f4f6;
}

.gw-document-preview img {
  width: 100%;
  height: auto;
  max-height: 200px;
  object-fit: cover;
  display: block;
}

.gw-document-status {
  position: absolute;
  top: 8px;
  right: 8px;
  color: white;
  padding: 4px 8px;
  border-radius: 6px;
  font-size: 11px;
  font-weight: 600;
}

/* File upload */
.gw-file-upload {
  position: relative;
}

.gw-file-input {
  position: absolute;
  opacity: 0;
  width: 0.1px;
  height: 0.1px;
  overflow: hidden;
}

.gw-file-label {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 12px;
  border: 2px dashed #d1d5db;
  border-radius: 8px;
  background: #fff;
  color: #6b7280;
  cursor: pointer;
  transition: all 0.2s;
  text-align: center;
  min-height: 50px;
}

.gw-file-label:hover {
  border-color: #c0c34d;
  color: #c0c34d;
  background: #fefefe;
}

.gw-file-label svg {
  flex-shrink: 0;
}

/* Botón agregar fotos */
.gw-add-photos-section {
  text-align: center;
  margin: 20px 0;
  padding: 16px;
  background: #f8fafc;
  border-radius: 8px;
}

.gw-add-photo-btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 10px 16px;
  background: #f3f4f6;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  color: #374151;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.2s;
}

.gw-add-photo-btn:hover {
  background: #e5e7eb;
  border-color: #9ca3af;
}

.gw-photo-help {
  margin-top: 8px;
  font-size: 12px;
  color: #6b7280;
}

/* Sección de consentimientos */
.gw-consent-section {
  margin: 24px 0;
  padding: 16px;
  background: #f8fafc;
  border-radius: 8px;
}

.gw-consent-item {
  display: flex;
  align-items: flex-start;
  margin-bottom: 12px;
  gap: 8px;
}

.gw-consent-item:last-child {
  margin-bottom: 0;
}

.gw-consent-item input[type="checkbox"] {
  position: absolute;
  opacity: 0;
  width: 0;
  height: 0;
}

.gw-consent-item label {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  cursor: pointer;
  font-size: 13px;
  line-height: 1.4;
  color: #374151;
}

.gw-checkbox-custom {
  width: 16px;
  height: 16px;
  border: 2px solid #d1d5db;
  border-radius: 3px;
  background: #fff;
  flex-shrink: 0;
  position: relative;
  margin-top: 1px;
  transition: all 0.2s;
}

.gw-consent-item input[type="checkbox"]:checked + label .gw-checkbox-custom {
  background: #c0c34d;
  border-color: #c0c34d;
}

.gw-consent-item input[type="checkbox"]:checked + label .gw-checkbox-custom::after {
  content: '✓';
  position: absolute;
  top: -1px;
  left: 2px;
  color: white;
  font-size: 11px;
  font-weight: bold;
}

/* Resumen de documentos */
.gw-documents-summary {
  margin: 24px 0;
  padding: 16px;
  background: #f8fafc;
  border-radius: 8px;
  border: 1px solid #e5e7eb;
  text-align: center;
}

.gw-documents-summary h4 {
  margin: 0 0 12px 0;
  font-size: 14px;
  font-weight: 600;
  color: #111827;
}

.gw-doc-status-grid {
  display: grid;
  gap: 8px;
}

.gw-doc-status-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 6px 0;
  border-bottom: 1px solid #e5e7eb;
}

.gw-doc-status-item:last-child {
  border-bottom: none;
}

.gw-doc-name {
  font-size: 13px;
  color: #6b7280;
}

.gw-doc-state {
  font-size: 11px;
  font-weight: 700;
}

/* Responsivo móvil */
@media (max-width: 767px) {
  .gw-classwin-form-wrap,
  .gw-section {
    padding: 16px;
    margin-bottom: 20px;
    border-radius: 12px;
  }
  
  .gw-classwin-header h2,
  .gw-section-header h2 {
    font-size: 18px;
  }
  
  .gw-qa-row {
    padding: 12px;
    gap: 12px;
  }
  
  .gw-documents-grid {
    gap: 16px;
  }
  
  .gw-document-upload {
    padding: 12px;
  }
  
  .gw-upload-label {
    font-size: 13px;
    margin-bottom: 10px;
  }
  
  .gw-file-label {
    padding: 10px;
    font-size: 13px;
    min-height: 45px;
    flex-direction: column;
    gap: 4px;
  }
  
  .gw-file-label svg {
    width: 18px;
    height: 18px;
  }
  
  .gw-form-actions {
    justify-content: center;
    margin-top: 16px;
  }
  
  .gw-btn-primary {
    width: 100%;
    justify-content: center;
    padding: 14px 20px;
    font-size: 14px;
  }
  
  .gw-consent-item label {
    font-size: 12px;
  }
  
  .gw-add-photo-btn {
    font-size: 13px;
    padding: 8px 12px;
  }
  
  .gw-textarea {
    min-height: 80px;
  }
  
  .gw-documents-summary,
  .gw-consent-section,
  .gw-add-photos-section {
    margin: 16px 0;
    padding: 12px;
  }
}

/* Mejoras adicionales para móvil pequeño */
@media (max-width: 480px) {
  .gw-classwin-form-wrap,
  .gw-section {
    padding: 12px;
    margin: 0 0 16px 0;
  }
  
  .gw-upload-label {
    flex-direction: column;
    align-items: flex-start;
    gap: 2px;
  }
  
  .gw-doc-status-item {
    flex-direction: column;
    align-items: flex-start;
    gap: 4px;
    padding: 8px 0;
  }
  
  .gw-consent-item {
    margin-bottom: 16px;
  }
  
  .gw-file-label span {
    text-align: center;
  }
}</style>


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
    
    // FUNCIÓN DE COMPRESIÓN
    function compressImage(file, maxWidth = 1024, maxHeight = 1024, quality = 0.8) {
        return new Promise((resolve) => {
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            const img = new Image();
            
            img.onload = function() {
                let { width, height } = img;
                
                if (width > height) {
                    if (width > maxWidth) {
                        height = (height * maxWidth) / width;
                        width = maxWidth;
                    }
                } else {
                    if (height > maxHeight) {
                        width = (width * maxHeight) / height;
                        height = maxHeight;
                    }
                }
                
                canvas.width = width;
                canvas.height = height;
                ctx.drawImage(img, 0, 0, width, height);
                canvas.toBlob(resolve, 'image/jpeg', quality);
            };
            
            img.src = URL.createObjectURL(file);
        });
    }
    
    // FUNCIONES BÁSICAS
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
    
    // INICIALIZAR COUNTDOWNS
    <?php if ($just_submitted): ?>
        startCountdown(10, logoutAndRedirect);
    <?php elseif ($todos_aceptados): ?>
        startCountdown(15, logoutAndRedirect);
    <?php endif; ?>
    
    // MANEJO DEL FORMULARIO DE DOCUMENTOS
    <?php if (!$just_submitted && !$todos_aceptados): ?>
    
    const form = document.getElementById('gw-documents-form');
    if (!form) return;
    
    const submitBtn = document.getElementById('gw-submit-docs');
    const btnText = submitBtn?.querySelector('.gw-btn-text');
    const btnLoading = submitBtn?.querySelector('.gw-btn-loading');
    const fileInputs = form.querySelectorAll('.gw-file-input');
    const checkboxes = form.querySelectorAll('input[type="checkbox"]');

    // ========= Enlazador reutilizable para inputs de archivo =========
    function bindFileInput(input){
        if (!input || input._gwBound) return;
        input._gwBound = true;

        input.addEventListener('change', async function(e) {
            const file = e.target.files[0];
            if (!file) return;

            // Limpiar mensajes previos
            const existingError = this.parentNode.querySelector('.gw-file-error');
            if (existingError) existingError.remove();
            const existingCompression = this.parentNode.querySelector('.gw-compression-info');
            if (existingCompression) existingCompression.remove();

            const validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
            if (!validTypes.includes(file.type)) {
                this.showError('Por favor selecciona un archivo de imagen válido (JPG, PNG, GIF, WEBP)');
                this.value = '';
                return;
            }

            const maxSize = 10 * 1024 * 1024; // 10MB antes de compresión
            if (file.size > maxSize) {
                this.showError('El archivo es demasiado grande (' + (file.size / (1024*1024)).toFixed(1) + 'MB). Máximo 10MB.');
                this.value = '';
                return;
            }

            // Mostrar compresión en progreso
            const compressingDiv = document.createElement('div');
            compressingDiv.className = 'gw-compressing';
            compressingDiv.style.cssText = 'color: #0066cc; font-size: 12px; margin-top: 5px; padding: 8px; background: #e3f2fd; border: 1px solid #bbdefb; border-radius: 4px;';
            compressingDiv.textContent = '🔄 Comprimiendo imagen...';
            this.parentNode.appendChild(compressingDiv);

            try {
                // Comprimir imagen
                const compressedFile = await compressImage(file, 1024, 1024, 0.8);

                // Reemplazar archivo con versión comprimida
                const dt = new DataTransfer();
                const compressedFileObj = new File([compressedFile], file.name.replace(/\.[^/.]+$/, ".jpg"), {
                    type: 'image/jpeg',
                    lastModified: Date.now()
                });
                dt.items.add(compressedFileObj);
                this.files = dt.files;

                // Quitar indicador de compresión
                compressingDiv.remove();

                // Mostrar resultados
                const originalSize = (file.size / 1024).toFixed(1);
                const compressedSize = (compressedFile.size / 1024).toFixed(1);
                const reduction = (((file.size - compressedFile.size) / file.size) * 100).toFixed(1);

                const infoDiv = document.createElement('div');
                infoDiv.className = 'gw-compression-info';
                infoDiv.style.cssText = 'color: #388e3c; font-size: 12px; margin-top: 5px; padding: 8px; background: #e8f5e9; border: 1px solid #c8e6c9; border-radius: 4px;';
                infoDiv.innerHTML = `
  <div>✅ Imagen comprimida</div>
  <div>📦 ${originalSize}KB → ${compressedSize}KB</div>
  <div>📈 Reducción: ${reduction}%</div>
`;
                this.parentNode.appendChild(infoDiv);

                // Actualizar label
                const label = this.nextElementSibling;
                if (label) {
                    const span = label.querySelector('span');
                    if (span) span.textContent = `${file.name} (${compressedSize}KB)`;
                    label.classList.add('file-selected');
                }

                // ===== PREVIEW en la tarjeta del documento =====
                try {
                    const wrapper = this.closest('.gw-document-upload');
                    if (wrapper) {
                        // Buscar / crear contenedor de preview
                        let preview = wrapper.querySelector('.gw-document-preview');
                        if (!preview) {
                            preview = document.createElement('div');
                            preview.className = 'gw-document-preview';
                            preview.innerHTML =
  '<img alt="Previsualización" />' +
  '<div class="gw-document-status" style="background:#ffb900;">⏳ En revisión</div>';
                            // Insertar antes del bloque de subida, si existe
                            const uploadBlock = wrapper.querySelector('.gw-file-upload');
                            if (uploadBlock) {
                                wrapper.insertBefore(preview, uploadBlock);
                            } else {
                                wrapper.appendChild(preview);
                            }
                        }
                        const img = preview.querySelector('img');
                        if (img) {
                            const objectUrl = URL.createObjectURL(compressedFileObj);
                            img.src = objectUrl;
                            img.onload = function(){ URL.revokeObjectURL(objectUrl); };
                        }
                        // Asegurar el chip de estado en "En revisión"
                        const chip = preview.querySelector('.gw-document-status');
                        if (chip) {
                            chip.textContent = '⏳ En revisión';
                            chip.style.background = '#ffb900';
                        }
                    }
                } catch(_e) { /* noop */ }

            } catch (error) {
                compressingDiv.remove();
                this.showError('Error al comprimir la imagen. Inténtalo de nuevo.');
                console.error('Error comprimiendo imagen:', error);
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
    }

    // Enlazar los inputs de archivo existentes
    document.querySelectorAll('.gw-file-input').forEach(bindFileInput);

    // ========= Botón "Agregar otra foto" (añade doc_3 y luego doc_4) =========
    (function(){
        var addBtn = document.getElementById('add-photo-btn');
        if (!addBtn) return;

        addBtn.addEventListener('click', function(){
            // Preferimos insertar dentro del recuadro punteado del botón
            var hostSection = document.getElementById('add-photo-btn') ? document.getElementById('add-photo-btn').closest('.gw-add-photos-section') : null;
            var container = hostSection || document.getElementById('documents-container');
            if (!container) return;

            // Siguiente slot disponible: 3 primero, luego 4
            var next = !container.querySelector('#documento_3') ? 3 :
                       (!container.querySelector('#documento_4') ? 4 : null);

            if (!next){
                addBtn.disabled = true;
                addBtn.textContent = 'Has agregado todas las fotos';
                return;
            }

            var html = `
  <div class="gw-document-upload optional-doc" data-doc="${next}" style="display:flex;">
    <label class="gw-upload-label">
      <span class="gw-label-text">Documento adicional (Foto ${next})</span>
      <span style="color:#ffb900;font-size:10px;font-weight:bold;margin-left:10px;">PENDIENTE</span>
    </label>

    <div class="gw-file-upload">
      <input type="file" name="documento_${next}" id="documento_${next}" accept="image/*" class="gw-file-input">
      <label for="documento_${next}" class="gw-file-label">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
          <polyline points="7,10 12,15 17,10"></polyline>
          <line x1="12" y1="15" x2="12" y2="3"></line>
        </svg>
        <span>Seleccionar archivo</span>
      </label>
    </div>
  </div>`;

            // Insertar al final del recuadro punteado, debajo del texto de ayuda
            container.insertAdjacentHTML('beforeend', html);

            // Enlazar eventos de compresión/preview al nuevo input
            var newInput = container.querySelector('#documento_' + next);
            bindFileInput(newInput);

            // Si ya se agregaron 3 y 4, desactivar botón (buscar dentro del mismo contenedor)
            var scope = hostSection || container;
            if (scope.querySelector('#documento_3') && scope.querySelector('#documento_4')){
                addBtn.disabled = true;
                addBtn.textContent = 'Has agregado todas las fotos';
            }
        });
    })();
    
    // VALIDACIÓN DEL FORMULARIO
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
    
    // ENVÍO DEL FORMULARIO
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
    
    // MANEJO DE CHECKBOXES
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

    // GATE DE AVANCE
    (function(){
        try {
            var ajaxurlGate = '<?php echo esc_js( admin_url('admin-ajax.php') ); ?>';
            var body = 'action=gw_asist_status';
            fetch(ajaxurlGate, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: body
            })
            .then(function(r){ return r.json(); })
            .then(function(resp){
                if (!resp || !resp.success) return;
                var st = resp.data || resp;
                gateNextButtons(st);
            })
            .catch(function(){ /* silencio en caso de error */ });
        } catch(e) { /* noop */ }

        function gateNextButtons(st){
            if (!st) return;

            function gate(matchRe, msg){
                var nodes = document.querySelectorAll('a,button');
                for (var i=0;i<nodes.length;i++){
                    var el = nodes[i];
                    var label = (el.textContent || '').trim();
                    if (!label) continue;
                    if (matchRe.test(label)){
                        if (el.getAttribute('data-gw-gated') === '1') continue;
                        el.setAttribute('data-gw-gated','1');
                        el.setAttribute('aria-disabled','true');
                        el.disabled = true;
                        if (el.tagName === 'A' && el.hasAttribute('href')) {
                            el.dataset.hrefBackup = el.getAttribute('href');
                            el.removeAttribute('href');
                        }
                        el.style.opacity = '0.6';
                        el.style.cursor = 'not-allowed';
                        el.title = msg;

                        el.addEventListener('click', function(ev){
                            ev.preventDefault();
                            alert(msg);
                        });
                    }
                }
            }

            if (st.charla !== true) {
                gate(/siguiente\s*charla/i, 'Tu asistencia a la charla debe ser aprobada por un administrador para continuar.');
            }
            if (st.cap !== true) {
                gate(/siguiente\s*capacitaci/i, 'Tu asistencia a la capacitación debe ser aprobada por un administrador para continuar.');
            }
        }
    })();

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

