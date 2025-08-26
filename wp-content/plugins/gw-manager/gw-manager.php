<?php
require_once __DIR__ . '/vendor/autoload.php';
/**
 * Plugin Name: Glasswing Voluntariado
 * Description: Plugin personalizado para gestión de voluntariado (Países, Proyectos, Emparejamientos).
 * Version: 1.0
 * Author: Carlos Montalvo
 */

if (!defined('ABSPATH')) exit;

// === ROLES DEL PROYECTO: registro y saneo ==============================
// Se registran siempre en init para que estén disponibles en front/back.
if (!function_exists('gw_manager_register_roles')) {
    function gw_manager_register_roles() {
        // Voluntario
        if (!get_role('voluntario')) {
            add_role('voluntario', 'Voluntario', [
                'read'        => true,
                // capability homónima para `current_user_can('voluntario')` si hiciera falta
                'voluntario'  => true,
            ]);
        }
        // Coach
        if (!get_role('coach')) {
            add_role('coach', 'Coach', [
                'read'   => true,
                'coach'  => true,
            ]);
        }
        // Coordinador de país
        if (!get_role('coordinador_pais')) {
            add_role('coordinador_pais', 'Coordinador de país', [
                'read'              => true,
                // Se usa en checks como current_user_can('coordinador_pais')
                'coordinador_pais'  => true,
            ]);
        }
    }
}
add_action('init', 'gw_manager_register_roles', 1);

// Asegurar que el rol Administrador también cuente con las caps personalizadas
add_action('init', function(){
    $admin = get_role('administrator');
    if ($admin) {
        if (!$admin->has_cap('coordinador_pais')) { $admin->add_cap('coordinador_pais'); }
        if (!$admin->has_cap('coach')) { $admin->add_cap('coach'); }
    }
}, 2);

// Migración ligera: usuarios sin rol asignado => Voluntario (una sola vez)
add_action('admin_init', function(){
    if (!current_user_can('manage_options')) return;
    if (get_option('gw_roles_migration_v1_done')) return;
    // Asegurar roles creados antes de migrar
    if (function_exists('gw_manager_register_roles')) gw_manager_register_roles();
    $users = get_users(['fields' => ['ID']]);
    foreach ($users as $uobj) {
        $u = get_user_by('id', $uobj->ID);
        if ($u && empty($u->roles)) {
            $u->set_role('voluntario');
        }
    }
    update_option('gw_roles_migration_v1_done', 1, false);
});
// === FIN ROLES DEL PROYECTO ============================================

// === UTILIDADES DE USUARIO (estado activo) ===
if (!function_exists('gw_user_is_active')) {
    function gw_user_is_active($uid = 0){
        if (!$uid) {
            $u = wp_get_current_user();
            if (!$u || !$u->ID) return false;
            $uid = $u->ID;
        }
        $activo = get_user_meta($uid, 'gw_active', true);
        if ($activo === '') $activo = '1'; // por defecto activo
        return ($activo === '1');
    }
}

// === AUSENCIAS: ajustes y helpers ===
if (!function_exists('gw_abs_get_settings')) {
    function gw_abs_get_settings() {
      $defaults = [
        'reminder_count'         => 3,  // máximo 10
        'reminder_interval_hours'=> 48, // cada 48h
        'grace_minutes'          => 30, // margen tras hora de inicio
        'subject'                => 'Recordatorio de capacitación pendiente',
        'body'                   => "Hola {nombre}\nNo asististe a la sesión '{capacitacion}' del {fecha} a las {hora}.\nPor favor reagenda aquí: {reagendar_url}\nGracias.",
        'deact_subject'          => 'Cuenta inactiva por inasistencias',
        'deact_body'             => "Hola {nombre}\nDebido a inasistencias, tu cuenta ha sido marcada inactiva. Contacta a tu coach para reactivarla.",
      ];
      $opt = get_option('gw_abs_settings', []);
      return wp_parse_args(is_array($opt) ? $opt : [], $defaults);
    }
  }
  if (!function_exists('gw_abs_update_settings')) {
    function gw_abs_update_settings($data) {
      $s = gw_abs_get_settings();
      $s['reminder_count']          = max(0, min(10, intval($data['reminder_count'] ?? $s['reminder_count'])));
      $s['reminder_interval_hours'] = max(1, intval($data['reminder_interval_hours'] ?? $s['reminder_interval_hours']));
      $s['grace_minutes']           = max(0, intval($data['grace_minutes'] ?? $s['grace_minutes']));
      $s['subject']                 = sanitize_text_field($data['subject'] ?? $s['subject']);
      $s['deact_subject']           = sanitize_text_field($data['deact_subject'] ?? $s['deact_subject']);
      $s['body']                    = wp_kses_post($data['body'] ?? $s['body']);
      $s['deact_body']              = wp_kses_post($data['deact_body'] ?? $s['deact_body']);
      update_option('gw_abs_settings', $s, false);
      return $s;
    }
  }
  if (!function_exists('gw_abs_template')) {
    function gw_abs_template($tpl, $user, $cap_title, $fecha, $hora) {
      $reagendar_url = site_url('/index.php/portal-voluntario/?paso7_menu=1');
      $map = [
        '{nombre}'        => $user->display_name ?: $user->user_login,
        '{capacitacion}'  => $cap_title,
        '{fecha}'         => $fecha,
        '{hora}'          => $hora,
        '{reagendar_url}' => $reagendar_url,
      ];
      return strtr($tpl, $map);
    }
  }
  
  // === Cron: detectar ausencias y enviar recordatorios ===
  add_action('gw_abs_cron', 'gw_abs_cron_run');
  function gw_abs_cron_run(){
    $settings = gw_abs_get_settings();
    $now_ts = current_time('timestamp');
  
    // 1) Detectar ausencias: usuarios con capacitación agendada vencida
    $users = get_users([ 'meta_key' => 'gw_capacitacion_agendada', 'meta_compare' => 'EXISTS', 'fields' => ['ID'] ]);
    foreach ($users as $uobj) {
      $uid = $uobj->ID;
      $ag = get_user_meta($uid, 'gw_capacitacion_agendada', true);
      if (!is_array($ag) || empty($ag['fecha']) || empty($ag['hora']) || empty($ag['cap_id'])) continue;
  
      $ts = strtotime($ag['fecha'].' '.$ag['hora']);
      if (!$ts) continue;
  
      $grace = intval($settings['grace_minutes']) * 60;
      $step7_done = get_user_meta($uid, 'gw_step7_completo', true);
  
      if (($now_ts > ($ts + $grace)) && !$step7_done) {
        gw_abs_insert_if_new($uid, intval($ag['cap_id']), intval($ag['idx'] ?? 0), $ag['fecha'].' '.$ag['hora']);
      }
    }
  
    // 2) Procesar recordatorios
    gw_abs_process_reminders($settings);
  }
  
  function gw_abs_insert_if_new($uid, $cap_id, $idx, $fecha_hora) {
    global $wpdb; $table = $wpdb->prefix.'gw_ausencias';
    $exists = $wpdb->get_var($wpdb->prepare(
      "SELECT id FROM {$table} WHERE user_id=%d AND cap_id=%d AND sesion_idx=%d AND status IN ('pendiente','inactivo')",
      $uid, $cap_id, $idx
    ));
    if ($exists) return;
    $now = current_time('mysql');
    $wpdb->insert($table, [
      'user_id'        => $uid,
      'cap_id'         => $cap_id,
      'sesion_idx'     => $idx,
      'fecha'          => $fecha_hora,
      'status'         => 'pendiente',
      'reminders_sent' => 0,
      'last_sent'      => null,
      'hidden'         => 0,
      'created_at'     => $now,
      'updated_at'     => $now,
    ]);
  }
  
  function gw_abs_process_reminders($settings) {
    global $wpdb; $table = $wpdb->prefix.'gw_ausencias';
    $rows = $wpdb->get_results("SELECT * FROM {$table} WHERE status='pendiente' AND hidden=0", ARRAY_A);
    if (!$rows) return;
  
    foreach ($rows as $row) {
      $uid = intval($row['user_id']);
      $user = get_user_by('id', $uid);
      if (!$user) continue;
  
      // Si ya completó el paso 7, resolvemos
      if (get_user_meta($uid, 'gw_step7_completo', true)) {
        gw_abs_mark_resuelto_db($row['id']);
        continue;
      }
  
      $count    = intval($row['reminders_sent']);
      $max      = max(0, min(10, intval($settings['reminder_count'])));
      $interval = max(1, intval($settings['reminder_interval_hours'])) * HOUR_IN_SECONDS;
      $last     = $row['last_sent'] ? strtotime($row['last_sent']) : 0;
  
      if ($count >= $max) {
        // Desactivar usuario y marcar 'inactivo'
        update_user_meta($uid, 'gw_active', '0');
        gw_abs_set_status($row['id'], 'inactivo');
        // (Opcional) Notificación de desactivación:
        $cap_title = get_the_title(intval($row['cap_id'])) ?: 'Capacitación';
        $fh = strtotime($row['fecha']); $fecha = $fh ? date_i18n('Y-m-d', $fh) : ''; $hora = $fh ? date_i18n('H:i', $fh) : '';
        wp_mail($user->user_email, $settings['deact_subject'], gw_abs_template($settings['deact_body'], $user, $cap_title, $fecha, $hora));
        continue;
      }
  
      if ($last && (current_time('timestamp') - $last) < $interval) continue;
  
      // Enviar recordatorio
      $cap_title = get_the_title(intval($row['cap_id'])) ?: 'Capacitación';
      $fh = strtotime($row['fecha']); $fecha = $fh ? date_i18n('Y-m-d', $fh) : ''; $hora = $fh ? date_i18n('H:i', $fh) : '';
      $subject = $settings['subject'];
      $body    = gw_abs_template($settings['body'], $user, $cap_title, $fecha, $hora);
      wp_mail($user->user_email, $subject, $body);
  
      $now = current_time('mysql');
      $wpdb->update($table, [
        'reminders_sent' => $count + 1,
        'last_sent'      => $now,
        'updated_at'     => $now,
      ], ['id' => $row['id']]);
    }
  }
  
  function gw_abs_mark_resuelto_db($id){
    global $wpdb; $table = $wpdb->prefix.'gw_ausencias';
    $wpdb->update($table, [ 'status' => 'resuelto', 'updated_at' => current_time('mysql') ], ['id' => $id]);
  }
  function gw_abs_set_status($id, $status){
    global $wpdb; $table = $wpdb->prefix.'gw_ausencias';
    $wpdb->update($table, [ 'status' => $status, 'updated_at' => current_time('mysql') ], ['id' => $id]);
  }

// Activación del plugin
register_activation_hook(__FILE__, 'gw_manager_activate');
function gw_manager_activate() {
    // Aquí puedes crear tablas si deseas
    // Asegura que los roles del proyecto existan desde la activación
    if (function_exists('gw_manager_register_roles')) {
        gw_manager_register_roles();
    }
}


// === Ausencias: crear tabla y programar cron ===
global $wpdb;
$table = $wpdb->prefix . 'gw_ausencias';
$charset_collate = $wpdb->get_charset_collate();
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
$sql = "CREATE TABLE IF NOT EXISTS {$table} (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT(20) UNSIGNED NOT NULL,
  cap_id BIGINT(20) UNSIGNED NOT NULL,
  sesion_idx INT(11) NOT NULL DEFAULT 0,
  fecha DATETIME NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pendiente',
  reminders_sent INT(11) NOT NULL DEFAULT 0,
  last_sent DATETIME NULL,
  hidden TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY user_id (user_id),
  KEY cap_id (cap_id),
  KEY status (status),
  KEY hidden (hidden)
) {$charset_collate};";
dbDelta($sql);

// Cron cada hora (primera corrida en ~5 min)
if (!wp_next_scheduled('gw_abs_cron')) {
  wp_schedule_event(time() + 300, 'hourly', 'gw_abs_cron');
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
if (!function_exists('gw_google_login_url')) {
    function gw_google_login_url() {
        // CSRF state
        $state = wp_generate_password(24, false, false);
        set_transient('gw_google_state_'.$state, '1', 10 * MINUTE_IN_SECONDS);

        $params = [
            'client_id'     => GW_GOOGLE_CLIENT_ID,
            'redirect_uri'  => GW_GOOGLE_REDIRECT,
            'response_type' => 'code',
            'scope'         => GW_GOOGLE_SCOPES,
            'state'         => $state,
            'prompt'        => 'select_account',
            'access_type'   => 'offline',
        ];
        return GW_GOOGLE_AUTH . '?' . http_build_query($params);
    }
}

// Iniciar flujo OAuth (no logueado)
add_action('admin_post_nopriv_gw_google_start', function () {
    wp_redirect(gw_google_login_url());
    exit;
});

// Callback OAuth (no logueado)
add_action('admin_post_nopriv_gw_google_callback', function () {
    if (!isset($_GET['state'], $_GET['code'])) wp_die('OAuth inválido');
    $state = sanitize_text_field($_GET['state']);
    if (!get_transient('gw_google_state_'.$state)) wp_die('Estado inválido/expirado');
    delete_transient('gw_google_state_'.$state);

    $code = sanitize_text_field($_GET['code']);

    // 1) Intercambio code -> token
// 1) Intercambio por tokens
$resp = wp_remote_post(GW_GOOGLE_TOKEN, [
    'timeout' => 20,
    'headers' => [ 'Accept' => 'application/json' ],
    'body' => [
        'code'          => $code,
        'client_id'     => GW_GOOGLE_CLIENT_ID,
        'client_secret' => GW_GOOGLE_CLIENT_SECRET,
        'redirect_uri'  => GW_GOOGLE_REDIRECT,
        'grant_type'    => 'authorization_code',
    ],
]);

if (is_wp_error($resp)) {
    wp_die('Error de token: '. $resp->get_error_message());
}

$raw = wp_remote_retrieve_body($resp);
$data = json_decode($raw, true);

// --- LOG útil para debug (se va a wp-content/debug.log)
error_log('GW_GOOGLE_TOKEN_RESPONSE: ' . $raw);

if (empty($data['access_token'])) {
    // Muestra el error concreto en pantalla para resolver rápido
    wp_die('<pre>Intercambio falló: ' . esc_html($raw) . '</pre>');
}


    // 2) Userinfo
    $u = wp_remote_get(GW_GOOGLE_USERINFO, [
        'headers' => ['Authorization' => 'Bearer '.$data['access_token']],
        'timeout' => 20,
    ]);
    if (is_wp_error($u)) wp_die('Error userinfo');
    $userInfo = json_decode(wp_remote_retrieve_body($u), true);

    $email = isset($userInfo['email']) ? sanitize_email($userInfo['email']) : '';
    if (!$email) wp_die('No se obtuvo email de Google');
    $name  = isset($userInfo['name']) ? sanitize_text_field($userInfo['name']) : '';
    $sub   = isset($userInfo['sub'])  ? sanitize_text_field($userInfo['sub'])  : '';

    // 3) Buscar/crear usuario WP
    $user = get_user_by('email', $email);
    if (!$user) {
        $uid = wp_create_user($email, wp_generate_password(20, true, true), $email);
        if (is_wp_error($uid)) wp_die('No se pudo crear el usuario');
        wp_update_user(['ID' => $uid, 'display_name' => ($name ?: $email)]);
        $user = get_user_by('id', $uid);

        // Rol por defecto: voluntario
        $user->set_role('voluntario');

        // Metadatos útiles
        update_user_meta($uid, 'gw_google_sub', $sub);
        update_user_meta($uid, 'gw_active', '1');
    }

    // 4) Iniciar sesión
    wp_set_auth_cookie($user->ID, true);
    wp_set_current_user($user->ID);

    // 5) Redirecciones (igual a tu lógica)
    if (in_array('administrator', $user->roles) || in_array('coach', $user->roles) || in_array('coordinador_pais', $user->roles)) {
        wp_redirect(site_url('/panel-administrativo')); exit;
    }
    if (in_array('voluntario', $user->roles)) {
        $active = get_user_meta($user->ID, 'gw_active', true); if ($active === '') $active = '1';
        if ($active === '0') { wp_redirect(site_url('/index.php/portal-voluntario?inactivo=1')); exit; }
        wp_redirect(site_url('/index.php/portal-voluntario')); exit;
    }
    wp_redirect(site_url('/')); exit;
});

// Botón “Continuar con Google”
if (!function_exists('gw_login_google_button_html')) {
    function gw_login_google_button_html() {
        ob_start(); ?>
        <div class="gw-login-google" style="margin-top:18px; text-align:center;">
          <a href="<?php echo esc_url( admin_url('admin-post.php?action=gw_google_start') ); ?>"
             class="gw-google-btn"
             style="display:inline-flex;align-items:center;gap:10px;justify-content:center;
                    width:100%;max-width:420px;height:44px;border-radius:999px;border:1px solid #d0d7e2;
                    background:#fff;text-decoration:none;font-weight:600;">
            <img src="https://www.gstatic.com/firebasejs/ui/2.0.0/images/auth/google.svg" alt=""
                 style="width:20px;height:20px;" />
            <span>Continuar con Google</span>
          </a>
        </div>
        <?php return ob_get_clean();
    }
}

// ===========================================================
//  TU SHORTCODE + PÁGINA DE LOGIN (CON EL BOTÓN YA INCRUSTADO)
// ===========================================================
if (!function_exists('gw_login_home_shortcode')) {
    add_shortcode('gw_login_home', 'gw_login_home_shortcode');
    function gw_login_home_shortcode() {
        wp_enqueue_style('gw-login-style', plugin_dir_url(__FILE__) . 'css/gw-login-style.css', [], '3.0');

        if (is_user_logged_in() && !(defined('REST_REQUEST') && REST_REQUEST) && !(defined('DOING_AJAX') && DOING_AJAX)) {
            $user = wp_get_current_user();
            if (in_array('administrator', $user->roles) || in_array('coach', $user->roles) || in_array('coordinador_pais', $user->roles)) {
                wp_redirect(site_url('/panel-administrativo')); exit;
            } else {
                wp_redirect(site_url('/index.php/portal-voluntario')); exit;
            }
        }

        ob_start();
        ?>
        <div class="gw-login-wrapper">
            <!-- Panel izquierdo estilo Glasswing -->
            <div class="gw-login-hero">
                <!-- Logo flotante arriba a la izquierda -->
                <div class="gw-hero-logo">
                    <img src="https://glasswing.org/es/wp-content/uploads/2023/08/Logo-Glasswing-02.png" alt="Logo Glasswing">
                </div>
            </div>

            <!-- Panel derecho con tarjeta estilo Glasswing -->
            <div class="gw-login-panel">
                <div class="gw-login-card">
                    <div class="gw-login-container">
                        <h2 class="gw-welcome-title">Únete a la red de voluntarios Glasswing</h2>
                        <?php
                        wp_login_form([
                            'echo' => true,
                            'redirect' => '',
                            'form_id' => 'gw_loginform',
                            'label_username' => 'Correo electrónico',
                            'label_password' => 'Contraseña',
                            'label_remember' => 'Recordarme',
                            'label_log_in' => 'Entrar',
                            'remember' => true,
                        ]);
                        ?>

                        <!-- Enlace recuperar contraseña -->
                        <div style="margin-top:18px; text-align:center;">
                            <a href="<?php echo wp_lostpassword_url(); ?>" class="gw-forgot-link">¿Olvidaste tu contraseña?</a>
                        </div>

                        <!-- Botón Continuar con Google -->
                        <?php echo gw_login_google_button_html(); ?>

                        <!-- Botón para mostrar registro -->
                        <div class="gw-signup-toggle" style="text-align: center; margin-top: 32px;">
                            <button type="button" id="toggleSignup" class="gw-toggle-btn">
                                <span class="toggle-text">¿Nuevo voluntario?</span>
                                <span class="toggle-arrow">↓</span>
                            </button>
                        </div>

                        <script>
                        const toggleBtn = document.getElementById("toggleSignup");
                        const signupSection = document.getElementById("signupSection");
                        toggleBtn.addEventListener("click", () => {
                          const isVisible = signupSection.style.display === "block";
                          if (!isVisible) {
                            signupSection.style.display = "block";
                            setTimeout(() => {
                              signupSection.style.opacity = "1";
                              signupSection.style.transform = "translateY(0)";
                            }, 10);
                            signupSection.scrollIntoView({ behavior: "smooth", block: "start" });
                          } else {
                            signupSection.style.opacity = "0";
                            signupSection.style.transform = "translateY(-20px)";
                            setTimeout(() => { signupSection.style.display = "none"; }, 400);
                          }
                        });
                        </script>

                        <!-- Sección registro colapsable -->
                        <div class="gw-voluntario-registro" id="signupSection" style="display: none; margin-top: 24px; opacity: 0; transform: translateY(-20px); transition: all 0.4s ease;">
                            <h4>Crear cuenta de voluntario</h4>
                            <form method="post">
                                <input type="text" name="gw_reg_nombre" placeholder="Nombre completo" required>
                                <input type="email" name="gw_reg_email" placeholder="Correo electrónico" required>
                                <input type="password" name="gw_reg_pass" placeholder="Crear contraseña" required>
                                <?php
                                $paises = get_posts(['post_type' => 'pais', 'numberposts' => -1, 'orderby'=>'title','order'=>'ASC']);

                                $pais_id_preasignado = isset($_GET['gw_pais']) ? intval($_GET['gw_pais']) : '';
                                $asignar_automaticamente = false;
                                $current_user = null;

                                if (is_user_logged_in()) {
                                    $current_user = wp_get_current_user();
                                    $tiene_pais = get_user_meta($current_user->ID, 'gw_pais_id', true);
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
                                    if ($asignar_automaticamente && $pais_id_preasignado) {
                                        foreach ($paises as $pais) {
                                            if ($pais->ID == $pais_id_preasignado) {
                                                echo '<option value="'.$pais->ID.'" selected>'.esc_html($pais->post_title).'</option>';
                                            }
                                        }
                                        echo "<script>
                                        document.addEventListener('DOMContentLoaded',function(){
                                            var sel = document.querySelector('select[name=\"gw_reg_pais\"]');
                                            if(sel) {
                                                sel.setAttribute('readonly','readonly');
                                                sel.setAttribute('disabled','disabled');
                                                sel.style.opacity = '0.7';
                                                sel.style.pointerEvents = 'none';
                                            }
                                        });
                                        </script>";
                                        echo '<input type="hidden" name="gw_reg_pais" value="'.$pais_id_preasignado.'" />';
                                    } else {
                                        foreach ($paises as $pais) {
                                            echo '<option value="'.$pais->ID.'">'.esc_html($pais->post_title).'</option>';
                                        }
                                    }
                                    ?>
                                </select>
                                <button type="submit" name="gw_reg_submit">Crear mi perfil</button>
                            </form>
                            <?php
                            if (isset($_POST['gw_reg_submit'])) {
                                $nombre = sanitize_text_field($_POST['gw_reg_nombre']);
                                $correo = sanitize_email($_POST['gw_reg_email']);
                                $pass = $_POST['gw_reg_pass'];
                                $pais_id = $pais_id_preasignado ? $pais_id_preasignado : intval($_POST['gw_reg_pais']);

                                if (username_exists($correo) || email_exists($correo)) {
                                    echo '<div style="color:#b00; margin:10px 0;">Este correo ya está registrado. <a href="'.wp_lostpassword_url().'" style="color:#dc2626; text-decoration: underline;">¿Recuperar contraseña?</a></div>';
                                } else if (strlen($pass) < 6) {
                                    echo '<div style="color:#b00; margin:10px 0;">La contraseña debe tener al menos 6 caracteres.</div>';
                                } else {
                                    $uid = wp_create_user($correo, $pass, $correo);
                                    if (!is_wp_error($uid)) {
                                        wp_update_user(['ID'=>$uid, 'display_name'=>$nombre]);
                                        $user = get_user_by('id', $uid);
                                        $user->set_role('voluntario');
                                        update_user_meta($uid, 'gw_pais_id', $pais_id);

                                        $charlas_flujo = get_post_meta($pais_id, '_gw_charlas', true);
                                        if (!is_array($charlas_flujo)) $charlas_flujo = [];
                                        update_user_meta($uid, 'gw_charlas_asignadas', $charlas_flujo);

                                        wp_set_auth_cookie($uid, true);
                                        $pais_url = get_permalink($pais_id);

                                        echo '<div style="color:#008800;margin:15px 0; text-align:center;">
                                            ¡Bienvenido a Glasswing! 🎉<br>
                                            <small>Redirigiendo a tu portal...</small>
                                        </div>';
                                        echo '<script>
                                            setTimeout(function(){
                                                window.location.href="'.esc_url($pais_url).'";
                                            }, 2000);
                                        </script>';
                                        exit;
                                    } else {
                                        echo '<div style="color:#b00; margin:10px 0;">Error al crear la cuenta. Intenta de nuevo.</div>';
                                    }
                                }
                            }
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script>
        // Mejora UX
        document.addEventListener('DOMContentLoaded', function() {
            const toggleBtn = document.getElementById('toggleSignup');
            const signupSection = document.getElementById('signupSection');
            const toggleArrow = document.querySelector('.toggle-arrow');
            let isOpen = false;

            if (toggleBtn) {
                toggleBtn.addEventListener('click', function() {
                    isOpen = !isOpen;
                    if (isOpen) {
                        signupSection.style.display = 'block';
                        setTimeout(() => {
                            signupSection.style.opacity = '1';
                            signupSection.style.transform = 'translateY(0)';
                        }, 10);
                        if (toggleArrow) toggleArrow.textContent = '↑';
                    } else {
                        signupSection.style.opacity = '0';
                        signupSection.style.transform = 'translateY(-20px)';
                        setTimeout(() => { signupSection.style.display = 'none'; }, 400);
                        if (toggleArrow) toggleArrow.textContent = '↓';
                    }
                });
            }

            const submitButtons = document.querySelectorAll('input[type="submit"], button[type="submit"]:not(#toggleSignup)');
            submitButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const originalText = this.value || this.innerHTML;
                    if (this.tagName === 'INPUT') { this.value = 'Entrando...'; }
                    else { this.innerHTML = '✨ Creando tu perfil...'; }
                    this.style.opacity = '0.8';
                    setTimeout(() => {
                        if (this.tagName === 'INPUT') { this.value = originalText; }
                        else { this.innerHTML = originalText; }
                        this.style.opacity = '1';
                    }, 3000);
                });
            });

            const inputs = document.querySelectorAll('input, select');
            inputs.forEach(input => {
                input.addEventListener('focus', function() {
                    this.style.transform = 'translateY(-1px)';
                    this.style.boxShadow = '0 8px 25px rgba(52, 152, 219, 0.15)';
                });
                input.addEventListener('blur', function() {
                    this.style.transform = 'translateY(0)';
                });
            });

            const buttons = document.querySelectorAll('button, input[type="submit"], .gw-login-google a');
            buttons.forEach(button => {
                if (button.id !== 'toggleSignup') {
                    button.addEventListener('mouseenter', function() {
                        this.style.boxShadow = '0 10px 30px rgba(52, 152, 219, 0.3)';
                    });
                    button.addEventListener('mouseleave', function() {
                        this.style.boxShadow = '';
                    });
                }
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }
}

// Redirección automática después de login (credenciales normales)
add_filter('login_redirect', 'gw_redireccionar_por_rol', 10, 3);
function gw_redireccionar_por_rol($redirect_to, $request, $user) {
    if (is_wp_error($user)) return $redirect_to;
    if (in_array('administrator', $user->roles) || in_array('coach', $user->roles) || in_array('coordinador_pais', $user->roles)) {
        return site_url('/panel-administrativo');
    }
    if (in_array('voluntario', $user->roles)) {
        $active = get_user_meta($user->ID, 'gw_active', true);
        if ($active === '') { $active = '1'; }
        if ($active === '0') { return site_url('/index.php/portal-voluntario?inactivo=1'); }
        return site_url('/index.php/portal-voluntario');
    }
    return site_url('/');
}

// (Opcional) Redirección para Nextend si aún lo usas en otras partes
add_filter('nsl_login_redirect_url', function($url, $provider, $user) {
    if ($user && is_a($user, 'WP_User')) {
        if (in_array('administrator', $user->roles) || in_array('coach', $user->roles) || in_array('coordinador_pais', $user->roles)) {
            return site_url('/panel-administrativo');
        }
        if (in_array('voluntario', $user->roles)) {
            $active = get_user_meta($user->ID, 'gw_active', true);
            if ($active === '') { $active = '1'; }
            if ($active === '0') {
                return site_url('/index.php/portal-voluntario?inactivo=1');
            }
            return site_url('/index.php/portal-voluntario');
        }
    }
    return $url;
}, 10, 3);
// Mostrar botón "Mi progreso" en la página de detalles de capacitación para voluntarios
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

// Si la cuenta está inactiva, mostrar aviso y bloquear el flujo en el portal
add_filter('the_content', function($content){
    if (!is_user_logged_in()) return $content;
    $u = wp_get_current_user();
    $activo = get_user_meta($u->ID, 'gw_active', true);
    if ($activo === '') $activo = '1';
    if ($activo === '0') {
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        $matches_portal = (strpos($uri, 'portal-voluntario') !== false);
        $matches_caps   = (strpos($uri, 'capacitacion') !== false);
        $matches_charla = (strpos($uri, 'charla') !== false);
        $matches_proy   = (strpos($uri, 'proyecto') !== false);
        if ($matches_portal || $matches_caps || $matches_charla || $matches_proy || isset($_GET['inactivo'])) {
            ob_start(); ?>
            <div class="gw-alert-inactivo" style="max-width:880px;margin:40px auto;padding:22px 26px;border-radius:12px;background:#fff3cd;border:1px solid #ffeeba;">
                <h2 style="margin-top:0;color:#8a6d3b;">Tu cuenta está inactiva</h2>
                <p>Por el momento no puedes avanzar en el flujo. Por favor contacta a un administrador para reactivarla.</p>
                <div style="margin-top:16px;">
                    <a class="button" href="<?php echo esc_url( site_url('/') ); ?>">Volver al inicio</a>
                </div>
            </div>
            <?php return ob_get_clean();
        }
    }
    return $content;
}, 1);

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

    // Agregar al administrador actual para pruebas
    if (current_user_can('manage_options')) {
        $current = wp_get_current_user();
        $exists = false;
        foreach ($voluntarios as $u) { if ($u->ID === $current->ID) { $exists = true; break; } }
        if (!$exists) array_unshift($voluntarios, $current);
    }

    ob_start(); ?>
    <div>
      <h2>Resumen de Progreso de Voluntarios</h2>
      <table class="widefat striped">
        <thead>
          <tr>
            <th>Nombre</th>
            <th>Correo</th>
            <th>Charlas</th>
            <th>Capacitación</th>
            <th>Fecha</th>
            <th>Hora</th>
            <th>Acción</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($voluntarios as $v): ?>
            <?php
              $cap_id    = get_user_meta($v->ID, 'gw_capacitacion_id', true);
              $cap_title = $cap_id ? get_the_title($cap_id) : '-';
              $fecha     = get_user_meta($v->ID, 'gw_fecha', true) ?: '-';
              $hora      = get_user_meta($v->ID, 'gw_hora', true) ?: '-';

              $charlas_asignadas = get_user_meta($v->ID, 'gw_charlas_asignadas', true);
              if (!is_array($charlas_asignadas)) $charlas_asignadas = [];
              $lista_charlas = [];
              foreach ($charlas_asignadas as $charla_key) {
                  $estado = get_user_meta($v->ID, 'gw_' . $charla_key, true) ? '✅' : '❌';
                  $lista_charlas[] = esc_html($charla_key) . ' ' . $estado;
              }
            ?>
            <tr>
              <td><?php echo esc_html($v->display_name); ?></td>
              <td><?php echo esc_html($v->user_email); ?></td>
              <td><?php echo implode('<br>', $lista_charlas); ?></td>
              <td><?php echo esc_html($cap_title); ?></td>
              <td><?php echo esc_html($fecha); ?></td>
              <td><?php echo esc_html($hora); ?></td>
              <td>
                <button type="button" class="button button-small gw-revisar-docs" data-user-id="<?php echo esc_attr($v->ID); ?>">
                  Revisar documentos
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Modal -->
    <div id="gw-modal-overlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.35); z-index:99998;"></div>
    <div id="gw-modal" style="display:none; position:fixed; z-index:99999; left:50%; top:50%; transform:translate(-50%,-50%);
         width:760px; max-width:92vw; background:#fff; border-radius:12px; box-shadow:0 20px 60px rgba(0,0,0,.25); overflow:hidden;">
      <div style="padding:14px 16px; background:#f7fafd; border-bottom:1px solid #e3e3e3; display:flex; justify-content:space-between; align-items:center;">
        <strong>Documentos del voluntario</strong>
        <button type="button" class="button button-small" id="gw-modal-close">Cerrar</button>
      </div>
      <div id="gw-modal-body" style="padding:14px; max-height:70vh; overflow:auto;"></div>
    </div>

    <script>
    jQuery(function($){ // <- asegura que jQuery esté listo

      // ajaxurl (frontend)
      if (typeof window.ajaxurl === 'undefined') {
        window.ajaxurl = "<?php echo esc_js( admin_url('admin-ajax.php') ); ?>";
      }

      function abrirModal(){ $('#gw-modal, #gw-modal-overlay').show(); }
      function cerrarModal(){ $('#gw-modal, #gw-modal-overlay').hide(); $('#gw-modal-body').html(''); }

      $(document).on('click', '#gw-modal-close, #gw-modal-overlay', cerrarModal);

      // Cargar HTML (no JSON) en el modal
      function cargarDocs(userId){
        $('#gw-modal-body').html('<p>Cargando…</p>');
        $.ajax({
          url: ajaxurl,
          method: 'POST',
          data: { action: 'gw_obtener_docs_voluntario', user_id: userId },
          dataType: 'html'
        })
        .done(function(html){ $('#gw-modal-body').html(html); })
        .fail(function(xhr){
          var raw = (xhr && xhr.responseText) ? xhr.responseText.substring(0,500) : '';
          $('#gw-modal-body').html('<p>Error al cargar documentos.</p><pre style="white-space:pre-wrap">'+raw+'</pre>');
        });
      }

      // Abrir modal desde la tabla
      $(document).on('click', '.gw-revisar-docs', function(){
        var userId = $(this).data('user-id');
        abrirModal();
        cargarDocs(userId);
      });

      // Ver archivo embebido
      $(document).on('click', '.gw-ver-archivo', function(e){
        e.preventDefault();
        var url  = $(this).data('url');
        var tipo = $(this).data('tipo');

        var $wrap = $('#gw-doc-viewer');
        var $body = $('#gw-doc-viewer-body');
        if (!$wrap.length) {
          $('#gw-modal-body').prepend(
            '<div id="gw-doc-viewer" style="margin-bottom:14px; border:1px solid #e3e3e3; border-radius:10px; overflow:hidden;">' +
              '<div style="padding:8px 10px; background:#f7fafd; border-bottom:1px solid #e3e3e3; display:flex; justify-content:space-between; align-items:center;">' +
                '<strong>Vista previa del archivo</strong>' +
                '<button type="button" class="button button-small" id="gw-cerrar-visor">Cerrar</button>' +
              '</div>' +
              '<div id="gw-doc-viewer-body" style="height:420px; background:#fff;"></div>' +
            '</div>'
          );
          $wrap = $('#gw-doc-viewer');
          $body = $('#gw-doc-viewer-body');
        }

        $body.empty();

        if (tipo === 'image') {
          $body.append($('<img>', { src:url, alt:'Vista previa',
            css:{ maxWidth:'100%', maxHeight:'100%', display:'block', margin:'0 auto' }}));
        } else if (tipo === 'pdf') {
          $body.append($('<iframe>', { src:url, css:{ width:'100%', height:'100%', border:0 }, allow:'fullscreen' }));
        } else {
          $body.append(
            $('<div>', { css:{ padding:'10px', background:'#fff' } }).append(
              $('<p>').text('Este tipo de archivo puede no mostrarse embebido. Puedes abrirlo aquí: '),
              $('<a>', { href:url, text:'Abrir/Descargar', target:'_self', rel:'noopener' })
            )
          );
          $body.append($('<iframe>', { src:url, css:{ width:'100%', height:'100%', border:0 } }));
        }
        $wrap.show();
        var cont = $wrap.get(0); if (cont && cont.scrollIntoView) cont.scrollIntoView({ behavior:'smooth', block:'start' });
      });

      // Cerrar visor
      $(document).on('click', '#gw-cerrar-visor', function(){
        $('#gw-doc-viewer-body').empty();
        $('#gw-doc-viewer').hide();
      });

      // Aprobar / Rechazar — ENVÍA row_id + nonce (no doc_id)
      $(document).on('click', '.gw-aprobar-doc, .gw-rechazar-doc', function(e){
        e.preventDefault();
        var isApprove = $(this).hasClass('gw-aprobar-doc');
        var action    = isApprove ? 'gw_aprobar_doc' : 'gw_rechazar_doc';

        var rowId  = parseInt($(this).data('row-id'), 10);
        var userId = parseInt($(this).data('user-id'), 10);
        var nonce  = $(this).data('nonce');

        if (!rowId || !userId || !nonce) {
          alert('Error: faltan datos (row_id/nonce).');
          console.error({rowId,userId,nonce});
          return;
        }

        var $btn = $(this).prop('disabled', true);

        $.ajax({
          url: ajaxurl,
          method: 'POST',
          dataType: 'json',
          data: { action: action, row_id: rowId, user_id: userId, nonce: nonce }
        })
        .done(function(resp){
          if (resp && resp.success) {
            cargarDocs(userId); // refresca la lista y los botones
          } else {
            alert('Error: ' + (resp && resp.data && resp.data.message ? resp.data.message : 'No se pudo actualizar'));
            console.error(resp);
            $btn.prop('disabled', false);
          }
        })
        .fail(function(xhr){
          alert('Error: No se pudo actualizar');
          console.error(xhr);
          $btn.prop('disabled', false);
        });
      });

    });
    </script>
    <?php
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



// ====== MÓDULO GESTIÓN DE USUARIOS: helpers y AJAX ======

// Guard para no registrar 2 veces
if (!defined('GW_USERS_AJAX_BOUND')) {
  define('GW_USERS_AJAX_BOUND', true);

  // ---- Helpers que ya usas ----
  if (!function_exists('gw_admin_add_user_log')) {
    function gw_admin_add_user_log($user_id, $msg){
      $log = get_user_meta($user_id, 'gw_activity_log', true);
      if(!is_array($log)) $log = [];
      $log[] = ['time'=> current_time('mysql'), 'admin'=> get_current_user_id(), 'msg'=> $msg];
      update_user_meta($user_id, 'gw_activity_log', $log);
    }
  }

  // Último acceso
  add_action('wp_login', function($user_login, $user){
    if (is_a($user, 'WP_User')) {
      update_user_meta($user->ID, 'gw_last_login', current_time('mysql'));
    }
  }, 10, 2);

  // ---- Permisos: más flexibles (admin o quien pueda ver/editar usuarios) ----
  if (!function_exists('gw_admin_can_manage_users')) {
    function gw_admin_can_manage_users(){
      $ok = current_user_can('manage_options') || current_user_can('edit_users') || current_user_can('list_users');
      return apply_filters('gw_admin_can_manage_users', $ok);
    }
  }

  // ========= Handlers como funciones (no anónimos) =========
  function gw_ajax_get_user_handler(){
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'gw_admin_users')) {
      wp_send_json_error(['msg'=>'Nonce inválido']);
    }
    if (!is_user_logged_in() || !gw_admin_can_manage_users()) {
      wp_send_json_error(['msg'=>'No autorizado']);
    }

    $uid = intval($_POST['user_id'] ?? 0);
    $u = get_user_by('id', $uid);
    if(!$u) wp_send_json_error(['msg'=>'Usuario no encontrado']);

    $role = count($u->roles) ? $u->roles[0] : '';
    $pais_id = get_user_meta($u->ID, 'gw_pais_id', true);
    $activo = get_user_meta($u->ID, 'gw_active', true);
    if($activo === '') $activo = '1';
    $last_login = get_user_meta($u->ID, 'gw_last_login', true);
    $logs = get_user_meta($u->ID, 'gw_activity_log', true);
    if(!is_array($logs)) $logs = [];

    wp_send_json_success([
      'user' => [
        'ID' => $u->ID,
        'display_name' => $u->display_name,
        'user_email' => $u->user_email,
        'role' => $role,
        'pais_id' => $pais_id,
        'activo' => $activo === '1',
        'last_login' => $last_login
      ],
      'logs' => $logs
    ]);
  }

  function gw_ajax_save_user_handler(){
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'gw_admin_users')) {
      wp_send_json_error(['msg'=>'Nonce inválido']);
    }
    if (!is_user_logged_in() || !gw_admin_can_manage_users()) {
      wp_send_json_error(['msg'=>'No autorizado']);
    }

    $uid = intval($_POST['user_id'] ?? 0);
    $u = get_user_by('id', $uid);
    if(!$u) wp_send_json_error(['msg'=>'Usuario no encontrado']);

    $display_name = sanitize_text_field($_POST['display_name'] ?? '');
    $email = sanitize_email($_POST['user_email'] ?? '');
    $role = sanitize_text_field($_POST['role'] ?? '');
    $allowed_roles = ['administrator','coordinador_pais','coach','voluntario'];
    if ($role && !in_array($role, $allowed_roles, true)) {
        wp_send_json_error(['msg' => 'Rol no permitido']);
    }
    $pais_id = intval($_POST['pais_id'] ?? 0);
    $activo = isset($_POST['activo']) ? sanitize_text_field($_POST['activo']) : '1';

    $args = ['ID' => $uid];
    $changes = [];

    if($display_name !== '' && $display_name !== $u->display_name){
      $args['display_name'] = $display_name;
      $changes[] = 'Nombre actualizado';
    }
    if($email && $email !== $u->user_email){
      $exists = email_exists($email);
      if($exists && intval($exists) !== $uid){
        wp_send_json_error(['msg'=>'El email ya está en uso por otro usuario']);
      }
      $args['user_email'] = $email;
      $changes[] = 'Email actualizado';
    }
    if(count($args) > 1){
      $res = wp_update_user($args);
      if (is_wp_error($res)) wp_send_json_error(['msg'=>$res->get_error_message()]);
    }

    if($role && (!in_array($role, (array)$u->roles) || count($u->roles) !== 1)){
      if(get_current_user_id() === $uid && $role !== 'administrator'){
        // seguridad: no auto-degradarse
      } else {
        $u->set_role($role);
        $changes[] = 'Rol cambiado a ' . $role;
      }
    }

    $old_pais = get_user_meta($uid, 'gw_pais_id', true);
    if((int)$old_pais !== (int)$pais_id){
      if($pais_id){
        update_user_meta($uid, 'gw_pais_id', $pais_id);
        $changes[] = 'País reasignado a "' . get_the_title($pais_id) . '"';
      } else {
        delete_user_meta($uid, 'gw_pais_id');
        $changes[] = 'País eliminado';
      }
    }

    $prev_activo = get_user_meta($uid, 'gw_active', true);
    if($prev_activo === '') $prev_activo = '1';
    if($prev_activo !== $activo){
      update_user_meta($uid, 'gw_active', $activo === '1' ? '1' : '0');
      $changes[] = ($activo === '1') ? 'Usuario activado' : 'Usuario desactivado';
    }

    if(!empty($changes)){
      gw_admin_add_user_log($uid, implode(' | ', $changes));
    }

    // Render de fila (usa tu función si existe fuera)
    if(!function_exists('gw_admin_render_user_row_inline')){
      function gw_admin_render_user_row_inline($u){
        $roles_labels = ['administrator'=>'Administrador','coach'=>'Coach','coordinador_pais'=>'Coordinador de país','voluntario'=>'Voluntario'];
        $role = count($u->roles) ? $u->roles[0] : '';
        $role_label = isset($roles_labels[$role]) ? $roles_labels[$role] : $role;
        $pais_id = get_user_meta($u->ID, 'gw_pais_id', true);
        $pais_titulo = $pais_id ? get_the_title($pais_id) : '—';
        $activo = get_user_meta($u->ID, 'gw_active', true); if($activo==='') $activo='1';
        $badge = $activo==='1' ? '<span style="background:#e8f5e9;color:#1b5e20;padding:2px 8px;border-radius:12px;font-size:12px;">Activo</span>' :
                                 '<span style="background:#ffebee;color:#b71c1c;padding:2px 8px;border-radius:12px;font-size:12px;">Inactivo</span>';
        ob_start(); ?>
        <tr id="gw-user-row-<?php echo $u->ID; ?>" data-role="<?php echo esc_attr($role); ?>" data-active="<?php echo esc_attr($activo); ?>">
          <td><?php echo esc_html($u->display_name ?: $u->user_login); ?></td>
          <td><?php echo esc_html($u->user_email); ?></td>
          <td><?php echo esc_html($role_label ?: '—'); ?></td>
          <td><?php echo esc_html($pais_titulo); ?></td>
          <td><?php echo $badge; ?></td>
          <td>
            <button type="button" title="Editar usuario" class="button button-small gw-user-edit" data-user-id="<?php echo $u->ID; ?>" onclick="window.gwUserEdit(<?php echo (int)$u->ID; ?>)">Editar</button>
            <button type="button" title="Activar/Desactivar" class="button button-small gw-user-toggle" data-user-id="<?php echo $u->ID; ?>" onclick="window.gwUserToggle(<?php echo (int)$u->ID; ?>)"><?php echo ($activo==='1' ? 'Desactivar' : 'Activar'); ?></button>
            <button type="button" title="Ver historial" class="button button-small gw-user-history" data-user-id="<?php echo $u->ID; ?>" onclick="window.gwUserHistory(<?php echo (int)$u->ID; ?>)">Historial</button>
          </td>
        </tr>
        <?php return ob_get_clean();
      }
    }
    $u_refreshed = get_user_by('id', $uid);
    $row_html = function_exists('gw_admin_render_user_row') ? gw_admin_render_user_row($u_refreshed) : gw_admin_render_user_row_inline($u_refreshed);
    wp_send_json_success(['row_html' => $row_html]);
  }

// =======================
// AJAX: Toggle usuario
// =======================
add_action('wp_ajax_gw_admin_toggle_active', 'gw_ajax_toggle_active_handler');
function gw_ajax_toggle_active_handler(){
  if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'gw_admin_users')) {
    wp_send_json_error(['msg'=>'Nonce inválido']);
  }
  if (!is_user_logged_in() || !current_user_can('administrator')) {
    wp_send_json_error(['msg'=>'No autorizado']);
  }

  $uid = intval($_POST['user_id'] ?? 0);
  $u = get_user_by('id', $uid);
  if(!$u) wp_send_json_error(['msg'=>'Usuario no encontrado']);

  // toggle estado
  $activo = get_user_meta($uid, 'gw_active', true);
  if($activo === '') $activo = '1';
  $nuevo = ($activo === '1') ? '0' : '1';
  update_user_meta($uid, 'gw_active', $nuevo);

  // log de acción
  if(function_exists('gw_admin_add_user_log')){
    gw_admin_add_user_log($uid, $nuevo==='1' ? 'Usuario activado' : 'Usuario desactivado');
  }

  // asegurar renderer disponible
  if (!function_exists('gw_admin_render_user_row')) {
    function gw_admin_render_user_row($u){
      $roles_labels = [
        'administrator'=>'Administrador',
        'coordinador_pais'=>'Coordinador de país',
        'coach'=>'Coach',
        'voluntario'=>'Voluntario'
      ];
      $role = count($u->roles) ? $u->roles[0] : '';
      $role_label = isset($roles_labels[$role]) ? $roles_labels[$role] : $role;
      $pais_id = get_user_meta($u->ID, 'gw_pais_id', true);
      $pais_titulo = $pais_id ? get_the_title($pais_id) : '—';
      $activo = get_user_meta($u->ID, 'gw_active', true); if($activo==='') $activo='1';
      $badge = $activo==='1'
        ? '<span style="background:#e8f5e9;color:#1b5e20;padding:2px 8px;border-radius:12px;font-size:12px;">Activo</span>'
        : '<span style="background:#ffebee;color:#b71c1c;padding:2px 8px;border-radius:12px;font-size:12px;">Inactivo</span>';
      ob_start(); ?>
      <tr id="gw-user-row-<?php echo $u->ID; ?>" data-role="<?php echo esc_attr($role); ?>" data-active="<?php echo esc_attr($activo); ?>">
        <td><?php echo esc_html($u->display_name ?: $u->user_login); ?></td>
        <td><?php echo esc_html($u->user_email); ?></td>
        <td><?php echo esc_html($role_label ?: '—'); ?></td>
        <td><?php echo esc_html($pais_titulo); ?></td>
        <td><?php echo $badge; ?></td>
        <td>
          <button type="button" class="button button-small gw-user-edit" data-user-id="<?php echo $u->ID; ?>" onclick="window.gwUserEdit(<?php echo (int)$u->ID; ?>)">Editar</button>
          <button type="button" class="button button-small gw-user-toggle" data-user-id="<?php echo $u->ID; ?>" onclick="window.gwUserToggle(<?php echo (int)$u->ID; ?>)"><?php echo ($activo==='1' ? 'Desactivar' : 'Activar'); ?></button>
          <button type="button" class="button button-small gw-user-history" data-user-id="<?php echo $u->ID; ?>" onclick="window.gwUserHistory(<?php echo (int)$u->ID; ?>)">Historial</button>
        </td>
      </tr>
      <?php return ob_get_clean();
    }
  }

  // refrescar fila
  $u2 = get_user_by('id', $uid);
  $row_html = gw_admin_render_user_row($u2);
  wp_send_json_success(['row_html'=>$row_html]);
}


  // ========= Registro de handlers en init (clave para admin-ajax) =========
  add_action('init', function(){
    add_action('wp_ajax_gw_admin_get_user', 'gw_ajax_get_user_handler');
    add_action('wp_ajax_gw_admin_save_user', 'gw_ajax_save_user_handler');
    add_action('wp_ajax_gw_admin_toggle_active', 'gw_ajax_toggle_active_handler');
  });
}
// ====== FIN MÓDULO GESTIÓN DE USUARIOS ======




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

add_shortcode('gw_panel_admin', function() {
    if (!current_user_can('manage_options')) {
        // CSS ya se incluye aquí
        $css_url = plugin_dir_url(__FILE__) . 'css/gw-admin.css';
        ob_start();
        ?>
        <link rel="stylesheet" href="<?php echo $css_url; ?>?v=<?php echo time(); ?>">
        
        <div class="gw-no-permissions">
            <div class="gw-no-permissions-content">
                <div class="gw-no-permissions-logo">
                    <img src="https://glasswing.org/es/wp-content/uploads/2023/08/Logo-Glasswing-02.png" alt="Glasswing International">
                </div>
                <h1>Panel Administrativo</h1>
                <p>No tienes permisos para ver este panel. Contacta al administrador para obtener acceso.</p>
                <a href="<?php echo home_url(); ?>" class="button">Volver al inicio</a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    ob_start();
    ?>




<?php
$css_url = plugin_dir_url(__FILE__) . 'css/gw-admin.css';
?>
<!-- DEBUG: CSS URL: <?php echo $css_url; ?> -->
<link rel="stylesheet" href="<?php echo $css_url; ?>?v=<?php echo time(); ?>">

<!-- ESTRUCTURA CORREGIDA -->
<div class="gw-modern-wrapper">
    <div class="gw-form-wrapper">
        <!-- PANEL LATERAL IZQUIERDO -->
        <div class="gw-sidebar">
            <div class="gw-hero-logo2">
                <img src="https://glasswing.org/es/wp-content/uploads/2023/08/Logo-Glasswing-02.png" alt="Logo Glasswing">
            </div> 

            <div class="gw-steps-container">
                <!-- Botón 1 -->
                <div class="gw-step-item active gw-admin-tab-btn" data-tab="paises">
                    <div class="gw-step-number">1</div>
                    <div class="gw-step-content">
                        <h3>Gestión de países</h3>
                        <p>Administra países y sus charlas asociadas.</p>
                    </div>
                </div>

                <!-- Botón 2 -->
                <div class="gw-step-item gw-admin-tab-btn" data-tab="usuarios">
                    <div class="gw-step-number">2</div>
                    <div class="gw-step-content">
                        <h3>Gestión de usuarios</h3>
                        <p>Administra usuarios del sistema.</p>
                    </div>
                </div>

                <!-- Botón 3 -->
                <div class="gw-step-item gw-admin-tab-btn" data-tab="charlas">
                    <div class="gw-step-number">3</div>
                    <div class="gw-step-content">
                        <h3>Charlas</h3>
                        <p>Gestiona charlas y sus sesiones.</p>
                    </div>
                </div>

                <!-- Botón 4 -->
                <div class="gw-step-item gw-admin-tab-btn" data-tab="proyectos">
                    <div class="gw-step-number">4</div>
                    <div class="gw-step-content">
                        <h3>Proyectos</h3>
                        <p>Administra proyectos disponibles.</p>
                    </div>
                </div>

                <!-- Botón 5 -->
                <div class="gw-step-item gw-admin-tab-btn" data-tab="capacitaciones">
                    <div class="gw-step-number">5</div>
                    <div class="gw-step-content">
                        <h3>Capacitaciones</h3>
                        <p>Gestiona capacitaciones y sesiones.</p>
                    </div>
                </div>

                <!-- Botón 6 -->
                <div class="gw-step-item gw-admin-tab-btn" data-tab="progreso">
                    <div class="gw-step-number">6</div>
                    <div class="gw-step-content">
                        <h3>Progreso del voluntario</h3>
                        <p>Monitorea el progreso de voluntarios.</p>
                    </div>
                </div>

                <!-- Botón 7 -->
                <div class="gw-step-item gw-admin-tab-btn" data-tab="ausencias">
                    <div class="gw-step-number">7</div>
                    <div class="gw-step-content">
                        <h3>Seguimiento de ausencias</h3>
                        <p>Control de asistencia de voluntarios.</p>
                    </div>
                </div>

                <!-- Botón 8 -->
                <div class="gw-step-item gw-admin-tab-btn" data-tab="reportes">
                    <div class="gw-step-number">8</div>
                    <div class="gw-step-content">
                        <h3>Reportes y listados</h3>
                        <p>Genera reportes del sistema.</p>
                    </div>
                </div>
            </div>

            <div class="gw-sidebar-footer">
                <div class="gw-help-section">
                    <div class="gw-help-text">
                        <h4>Panel Administrativo</h4>
                        <p>
                            Gestiona todo el sistema desde aquí
                            <a href="https://glasswing.org/" target="_blank" rel="noopener noreferrer">
                            Ve a glasswing.org
                            </a>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- CONTENIDO PRINCIPAL -->
        <div class="gw-main-content">
            <div class="gw-form-container">
                
                <!-- TAB PAÍSES -->
                <div class="gw-admin-tab-content" id="gw-admin-tab-paises" style="display:block;">
                    <div class="gw-form-header">
                        <h1>Gestión de países</h1>
                        <p>Administra países y asocia charlas disponibles.</p>
                    </div>

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
                </div>

<!-- TAB USUARIOS -->
<div class="gw-admin-tab-content" id="gw-admin-tab-usuarios" style="display:none;">
  <div class="gw-form-header">
    <h1>Gestión de usuarios</h1>
    <p>Administra usuarios del sistema y sus roles.</p>
  </div>

  <?php
    // Nonce para acciones AJAX de usuarios
    $gw_users_nonce = wp_create_nonce('gw_admin_users');

    if (!function_exists('get_editable_roles')) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
    }
    function gw_admin_roles_labels(){
        if (!function_exists('get_editable_roles')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }
        $registered = get_editable_roles();
        $allowed = [
            'administrator'    => 'Administrador',
            'coordinador_pais' => 'Coordinador de país',
            'coach'            => 'Coach',
            'voluntario'       => 'Voluntario',
        ];
        $labels = [];
        foreach ($allowed as $slug => $label) {
            if (isset($registered[$slug])) {
                $labels[$slug] = $label;
            }
        }
        return $labels;
    }

    function gw_admin_render_user_row($u){
        $roles_labels = gw_admin_roles_labels();
        $role = count($u->roles) ? $u->roles[0] : '';
        $role_label = isset($roles_labels[$role]) ? $roles_labels[$role] : $role;

        $pais_id = get_user_meta($u->ID, 'gw_pais_id', true);
        $pais_titulo = $pais_id ? get_the_title($pais_id) : '—';
        $activo = get_user_meta($u->ID, 'gw_active', true);
        if($activo === '') $activo = '1';
        $badge = $activo === '1' ? '<span style="background:#e8f5e9;color:#1b5e20;padding:2px 8px;border-radius:12px;font-size:12px;">Activo</span>' :
                                   '<span style="background:#ffebee;color:#b71c1c;padding:2px 8px;border-radius:12px;font-size:12px;">Inactivo</span>';

        $btn_toggle = $activo === '1' ? 'Desactivar' : 'Activar';

        ob_start(); ?>
        <tr id="gw-user-row-<?php echo $u->ID; ?>" data-role="<?php echo esc_attr($role); ?>" data-active="<?php echo esc_attr($activo); ?>">
          <td><?php echo esc_html($u->display_name ?: $u->user_login); ?></td>
          <td><?php echo esc_html($u->user_email); ?></td>
          <td><?php echo esc_html($role_label ?: '—'); ?></td>
          <td><?php echo esc_html($pais_titulo); ?></td>
          <td><?php echo $badge; ?></td>
          <td>
            <button type="button" class="button button-small gw-user-edit" data-user-id="<?php echo $u->ID; ?>" onclick="window.gwUserEdit(<?php echo (int)$u->ID; ?>)">Editar</button>
            <button type="button" class="button button-small gw-user-toggle" data-user-id="<?php echo $u->ID; ?>" onclick="window.gwUserToggle(<?php echo (int)$u->ID; ?>)"><?php echo $btn_toggle; ?></button>
            <button type="button" class="button button-small gw-user-history" data-user-id="<?php echo $u->ID; ?>" onclick="window.gwUserHistory(<?php echo (int)$u->ID; ?>)">Historial</button>
          </td>
        </tr>
        <?php return ob_get_clean();
    }

    $usuarios = get_users(['number' => 200, 'fields' => 'all']);
    $paises_all = get_posts(['post_type'=>'pais','numberposts'=>-1,'orderby'=>'title','order'=>'ASC']);
    $roles_labels = gw_admin_roles_labels();
  ?>

  <style>
    .gw-users-toolbar{display:flex;gap:8px;align-items:center;margin:10px 0 16px;}
    .gw-users-toolbar input[type="text"], .gw-users-toolbar select{padding:6px 8px;min-width:190px;}
    table.gw-users{width:100%;border-collapse:collapse;background:#fff;min-width:980px;}
    table.gw-users th, table.gw-users td{border-bottom:1px solid #e9eef4;padding:10px 8px;text-align:left;}
    table.gw-users th{background:#f7f9fc;font-weight:600;}
    .gw-users-responsive{overflow-x:auto;-webkit-overflow-scrolling:touch;width:100%;}
    #gw-user-modal, #gw-user-history-modal{display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,.35);z-index:99999;}
    .gw-modal-box{background:#fff;max-width:640px;margin:6% auto;padding:22px;border-radius:12px;position:relative;box-shadow:0 10px 30px rgba(0,0,0,.2);}
    .gw-modal-close{position:absolute;right:14px;top:10px;border:none;background:transparent;font-size:22px;cursor:pointer;}
    .gw-user-form label{display:block;margin:8px 0 4px;font-weight:600;}
    .gw-user-form input, .gw-user-form select{width:100%;padding:8px;border:1px solid #cfd8dc;border-radius:6px;}
    .gw-user-form .actions{margin-top:14px;display:flex;gap:8px;justify-content:flex-end;}
    .gw-user-form .desc{font-size:12px;color:#78909c;}
    .gw-log-list{max-height:300px;overflow:auto;border:1px solid #e0e0e0;border-radius:8px;padding:8px;background:#fafafa;}
    .gw-log-item{border-bottom:1px dashed #e0e0e0;padding:6px 4px;}
    .gw-log-item small{color:#607d8b;}
  </style>

  <div class="gw-users-toolbar">
    <input type="text" id="gw-users-search" placeholder="Buscar por nombre o email…">
    <select id="gw-users-role-filter">
      <option value="">Todos los roles</option>
      <?php foreach($roles_labels as $slug=>$label): ?>
        <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($label); ?></option>
      <?php endforeach; ?>
    </select>
    <select id="gw-users-status-filter">
      <option value="">Todos</option>
      <option value="1">Activos</option>
      <option value="0">Inactivos</option>
    </select>
  </div>

  <div class="gw-users-responsive">
    <table class="widefat striped gw-users" id="gw-users-table">
      <thead>
        <tr><th>Nombre</th><th>Email</th><th>Rol</th><th>País</th><th>Estado</th><th>Acciones</th></tr>
      </thead>
      <tbody>
        <?php foreach($usuarios as $u){ echo gw_admin_render_user_row($u); } ?>
      </tbody>
    </table>
  </div>

  <!-- Modal edición -->
  <div id="gw-user-modal">
    <div class="gw-modal-box">
      <button class="gw-modal-close" onclick="document.getElementById('gw-user-modal').style.display='none'">&times;</button>
      <h3>Editar usuario</h3>
      <form class="gw-user-form" id="gw-user-form">
        <input type="hidden" name="user_id" id="gw_user_id">
        <label>Nombre a mostrar</label>
        <input type="text" name="display_name" id="gw_display_name" required>
        <label>Email</label>
        <input type="email" name="user_email" id="gw_user_email" required>
        <label>Rol</label>
        <select name="role" id="gw_role" required>
          <?php foreach($roles_labels as $slug=>$label): ?>
            <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($label); ?></option>
          <?php endforeach; ?>
        </select>
        <label>País</label>
        <select name="pais_id" id="gw_pais_id">
          <option value="">— Sin país —</option>
          <?php foreach($paises_all as $p): ?>
            <option value="<?php echo $p->ID; ?>"><?php echo esc_html($p->post_title); ?></option>
          <?php endforeach; ?>
        </select>
        <label>Estado</label>
        <select name="activo" id="gw_activo">
          <option value="1">Activo</option>
          <option value="0">Inactivo</option>
        </select>
        <div class="desc" id="gw_last_login_info"></div>
        <div class="actions">
          <button type="button" class="button" onclick="document.getElementById('gw-user-modal').style.display='none'">Cancelar</button>
          <button type="submit" class="button button-primary">Guardar</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Modal historial -->
  <div id="gw-user-history-modal">
    <div class="gw-modal-box">
      <button class="gw-modal-close" onclick="document.getElementById('gw-user-history-modal').style.display='none'">&times;</button>
      <h3>Historial de actividad</h3>
      <div class="gw-log-list" id="gw-user-log-list"></div>
    </div>
  </div>

  <script>
  (function(){
    var ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
    var nonce = '<?php echo esc_js($gw_users_nonce); ?>';
    window.gwAjaxUrl = ajaxurl;
    window.gwUsersNonce = nonce;

    // Filtros
    var search = document.getElementById('gw-users-search');
    var roleFilter = document.getElementById('gw-users-role-filter');
    var statusFilter = document.getElementById('gw-users-status-filter');
    function applyFilters(){
      var q = (search.value || '').toLowerCase();
      var rf = roleFilter.value || '';
      var sf = statusFilter.value || '';
      document.querySelectorAll('#gw-users-table tbody tr').forEach(function(tr){
        var name = tr.children[0].innerText.toLowerCase();
        var email = tr.children[1].innerText.toLowerCase();
        var roleSlug = tr.getAttribute('data-role') || '';
        var active = tr.getAttribute('data-active') || '';
        var match = (name.indexOf(q) !== -1 || email.indexOf(q) !== -1);
        if(rf && roleSlug !== rf) match = false;
        if(sf !== '' && active !== sf) match = false;
        tr.style.display = match ? '' : 'none';
      });
    }
    search.addEventListener('input', applyFilters);
    roleFilter.addEventListener('change', applyFilters);
    statusFilter.addEventListener('change', applyFilters);

    // Editar
    window.gwUserEdit = function(uid){
      var data = new FormData();
      data.append('action','gw_admin_get_user');
      data.append('nonce', nonce);
      data.append('user_id', uid);
      fetch(ajaxurl, {method:'POST', credentials:'same-origin', body:data})
        .then(r=>r.json()).then(function(res){
          if(!res.success) return;
          var u = res.data.user;
          document.getElementById('gw_user_id').value = u.ID;
          document.getElementById('gw_display_name').value = u.display_name || '';
          document.getElementById('gw_user_email').value = u.user_email || '';
          document.getElementById('gw_role').value = u.role || '';
          document.getElementById('gw_pais_id').value = u.pais_id || '';
          document.getElementById('gw_activo').value = u.activo ? '1' : '0';
          document.getElementById('gw_last_login_info').innerText = u.last_login ? ('Último acceso: ' + u.last_login) : '';
          // <-- FIX
          document.getElementById('gw-user-modal').style.display = 'block';
        });
    };

    // Guardar
    document.getElementById('gw-user-form').addEventListener('submit', function(e){
      e.preventDefault();
      var data = new FormData(this);
      data.append('action','gw_admin_save_user');
      data.append('nonce', nonce);
      fetch(ajaxurl, {method:'POST', credentials:'same-origin', body:data})
        .then(r=>r.json()).then(function(res){
          if(res.success){
            if(res.data && res.data.row_html){
              var uid = document.getElementById('gw_user_id').value;
              var tmp = document.createElement('tbody'); tmp.innerHTML = res.data.row_html.trim();
              var newRow = tmp.firstElementChild;
              var oldRow = document.getElementById('gw-user-row-'+uid);
              if(oldRow) oldRow.replaceWith(newRow);
            }
            document.getElementById('gw-user-modal').style.display='none';
          } else {
            alert(res.data && res.data.msg ? res.data.msg : 'No se pudo guardar');
          }
        });
    });

    // Toggle
    window.gwUserToggle = function(uid){
      var data = new FormData();
      data.append('action','gw_admin_toggle_active');
      data.append('nonce', nonce);
      data.append('user_id', uid);
      fetch(ajaxurl, {method:'POST', credentials:'same-origin', body:data})
        .then(r=>r.json()).then(function(res){
          if(res && res.success && res.data && res.data.row_html){
            var tmp = document.createElement('tbody'); tmp.innerHTML = res.data.row_html.trim();
            var newRow = tmp.firstElementChild;
            var oldRow = document.getElementById('gw-user-row-'+uid);
            if(oldRow && newRow) oldRow.replaceWith(newRow);
          }
        });
    };

    // Historial
    window.gwUserHistory = function(uid){
      var data = new FormData();
      data.append('action','gw_admin_get_user');
      data.append('nonce', nonce);
      data.append('user_id', uid);
      fetch(ajaxurl, {method:'POST', credentials:'same-origin', body:data})
        .then(r=>r.json()).then(function(res){
          if(!res.success) return;
          var logs = res.data.logs || [];
          var box = document.getElementById('gw-user-log-list');
          box.innerHTML = '';
          if(!logs.length){ box.innerHTML = '<div class="gw-log-item"><em>Sin registros</em></div>'; }
          logs.slice().reverse().forEach(function(it){
            var el = document.createElement('div');
            el.className = 'gw-log-item';
            el.innerHTML = '<small>'+ (it.time || '') +'</small><br>'+ (it.msg || '');
            box.appendChild(el);
          });
          // <-- FIX
          document.getElementById('gw-user-history-modal').style.display = 'block';
        });
    };
  })();
  </script>
</div>


<!-- TAB CHARLAS -->
<div class="gw-admin-tab-content" id="gw-admin-tab-charlas" style="display:none;">
    <div class="gw-form-header">
        <h1>Charlas</h1>
        <p>Gestiona charlas y programa sus sesiones.</p>
    </div>

    <div style="max-width:900px;">
        <!-- Formulario para agregar charla y filtros -->
        <div style="margin-bottom:20px; padding:16px; border:1px solid #ddd; border-radius:8px; background:#f9f9f9;">
            <!-- Formulario agregar charla -->
            <div style="margin-bottom:16px;">
                <form id="gw-form-nueva-charla" style="display:flex;gap:10px;align-items:center;">
                    <input type="text" id="gw-nueva-charla-title" placeholder="Nombre de la charla" required style="padding:7px;width:230px;">
                    <button type="submit" class="button button-primary">Agregar charla</button>
                    <span id="gw-charla-guardado" style="color:#388e3c;display:none;">Guardado</span>
                </form>
            </div>
            
            <!-- Filtros de búsqueda -->
            <div style="border-top:1px solid #ddd; padding-top:16px;">
                <h4 style="margin:0 0 12px 0;">Filtrar charlas:</h4>
                <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                    <input type="text" id="gw-filtro-nombre" placeholder="Buscar por nombre..." style="padding:6px;width:200px;">
                    <select id="gw-filtro-modalidad" style="padding:6px;">
                        <option value="">Todas las modalidades</option>
                        <option value="Presencial">Presencial</option>
                        <option value="Virtual">Virtual</option>
                        <option value="Mixta">Mixta</option>
                    </select>
                    <input type="text" id="gw-filtro-lugar" placeholder="Filtrar por lugar..." style="padding:6px;width:160px;">
                    <button type="button" id="gw-limpiar-filtros" class="button button-secondary">Limpiar filtros</button>
                    <button type="button" id="gw-ver-eliminadas" class="button button-secondary" style="background:#dc3545;border-color:#dc3545;color:white;">
                        🗑️ Ver eliminadas (<span id="gw-count-eliminadas">0</span>)
                    </button>
                </div>
            </div>
        </div>

        <!-- Área de charlas eliminadas (oculta por defecto) -->
        <div id="gw-charlas-eliminadas-panel" style="display:none;margin-bottom:20px;padding:16px;border:1px solid #dc3545;border-radius:8px;background:#fff5f5;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                <h3 style="margin:0;color:#dc3545;">🗑️ Charlas Eliminadas</h3>
                <button type="button" id="gw-cerrar-eliminadas" class="button button-small">Cerrar</button>
            </div>
            <div id="gw-listado-eliminadas"></div>
        </div>

        <!-- Listado de charlas activas -->
        <div id="gw-listado-charlas">
            <?php
            // Función para renderizar una charla individual
            function gw_render_charla_individual($charla) {
                $sesiones = get_post_meta($charla->ID, '_gw_sesiones', true);
                if (!is_array($sesiones)) $sesiones = [];
                if (empty($sesiones)) $sesiones = [[]];
                
                // Determinar modalidades presentes
                $modalidades = [];
                $lugares = [];
                foreach ($sesiones as $sesion) {
                    if (isset($sesion['modalidad'])) {
                        $modalidades[] = $sesion['modalidad'];
                    }
                    if (isset($sesion['lugar']) && !empty($sesion['lugar'])) {
                        $lugares[] = $sesion['lugar'];
                    }
                }
                $modalidades = array_unique($modalidades);
                $lugares = array_unique($lugares);
                
                echo '<div class="gw-charla-item" data-modalidades="'.implode(',', $modalidades).'" data-lugares="'.implode(',', $lugares).'" data-nombre="'.esc_attr(strtolower($charla->post_title)).'" style="border:1px solid #c8d6e5;padding:18px;border-radius:9px;margin-bottom:20px;background:#fafdff;position:relative;">';
                
                // Botón eliminar mejorado
                echo '<button type="button" class="gw-eliminar-charla" data-charla-id="'.$charla->ID.'" style="position:absolute;top:18px;right:18px;background:linear-gradient(135deg, #dc3545, #c82333);color:white;border:none;border-radius:8px;width:34px;height:34px;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 4px rgba(220,53,69,0.3);transition:all 0.2s ease;" title="Eliminar charla">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M3 6h18m-2 0v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2M10 11v6M14 11v6"/>
                    </svg>
                </button>';
                
                echo '<h3 style="margin:0 0 12px 0;padding-right:40px;">' . esc_html($charla->post_title) . '</h3>';
                
                // Mostrar tags de modalidades y lugares
                if (!empty($modalidades)) {
                    echo '<div style="margin-bottom:8px;">';
                    foreach ($modalidades as $mod) {
                        $color = $mod === 'Virtual' ? '#007cba' : '#46b450';
                        echo '<span style="background:'.$color.';color:white;padding:2px 8px;border-radius:12px;font-size:11px;margin-right:6px;">'.$mod.'</span>';
                    }
                    echo '</div>';
                }
                
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

            // Listado de charlas
            function gw_render_listado_charlas_panel() {
                $charlas = get_posts([
                    'post_type' => 'charla',
                    'numberposts' => -1,
                    'orderby' => 'title',
                    'order' => 'ASC',
                    'meta_query' => [
                        [
                            'key' => '_gw_eliminada',
                            'compare' => 'NOT EXISTS'
                        ]
                    ]
                ]);
                
                if (empty($charlas)) {
                    echo '<p>No hay charlas registradas aún.</p>';
                } else {
                    foreach ($charlas as $index => $charla) {
                        $display_style = $index >= 5 ? 'style="display:none;"' : '';
                        echo '<div class="gw-charla-wrapper" '.$display_style.'>';
                        gw_render_charla_individual($charla);
                        echo '</div>';
                    }
                    
                    // Botón ver más si hay más de 5 charlas
                    if (count($charlas) > 5) {
                        echo '<div id="gw-ver-mas-container" style="text-align:center;margin-top:20px;">';
                        echo '<button type="button" id="gw-ver-mas-charlas" class="button button-secondary">Ver más charlas (' . (count($charlas) - 5) . ' restantes)</button>';
                        echo '</div>';
                    }
                }
            }
            
            gw_render_listado_charlas_panel();
            ?>
        </div>
    </div>

    <script>
    (function(){
        // Variables globales
        var charlasVisible = 5;
        var totalCharlas = 0;
        
        // Función para contar charlas eliminadas
        function actualizarContadorEliminadas() {
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                credentials: 'same-origin',
                body: 'action=gw_contar_eliminadas'
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    document.getElementById('gw-count-eliminadas').textContent = res.data.count;
                }
            });
        }

        // Inicializar contador al cargar
        actualizarContadorEliminadas();

        // Formulario nueva charla (mantener funcionalidad original)
        var form = document.getElementById('gw-form-nueva-charla');
        if(form && !form._gwInit){
            form._gwInit = true;
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
                        // Re-inicializar
                        gwInitSesionesCharlasPanel();
                        initFiltrosYEliminacion();
                    }
                });
            });
        }

        // Función para aplicar filtros
        function aplicarFiltros() {
            var filtroNombre = document.getElementById('gw-filtro-nombre').value.toLowerCase();
            var filtroModalidad = document.getElementById('gw-filtro-modalidad').value;
            var filtroLugar = document.getElementById('gw-filtro-lugar').value.toLowerCase();
            
            var charlas = document.querySelectorAll('.gw-charla-item');
            var charlasVisibles = 0;
            
            charlas.forEach(function(charla) {
                var nombre = charla.dataset.nombre || '';
                var modalidades = charla.dataset.modalidades || '';
                var lugares = charla.dataset.lugares || '';
                
                var nombreMatch = !filtroNombre || nombre.includes(filtroNombre);
                var modalidadMatch = !filtroModalidad || modalidades.includes(filtroModalidad);
                var lugarMatch = !filtroLugar || lugares.toLowerCase().includes(filtroLugar);
                
                if (nombreMatch && modalidadMatch && lugarMatch) {
                    charla.parentElement.style.display = '';
                    charlasVisibles++;
                } else {
                    charla.parentElement.style.display = 'none';
                }
            });
            
            // Actualizar botón "Ver más"
            var verMasContainer = document.getElementById('gw-ver-mas-container');
            if (verMasContainer) {
                verMasContainer.style.display = charlasVisibles > 5 ? '' : 'none';
            }
        }

        // Función para eliminar charla
        function eliminarCharla(charlaId, boton) {
            if (!confirm('¿Estás seguro de que quieres eliminar esta charla?')) return;
            
            var data = new FormData();
            data.append('action', 'gw_eliminar_charla');
            data.append('charla_id', charlaId);
            
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                credentials: 'same-origin',
                body: data
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    // Remover de la vista
                    var charlaElement = boton.closest('.gw-charla-wrapper');
                    charlaElement.remove();
                    
                    // Actualizar contador
                    actualizarContadorEliminadas();
                    
                    // Mostrar mensaje
                    var mensaje = document.createElement('div');
                    mensaje.style.cssText = 'background:#d4edda;border:1px solid #c3e6cb;color:#155724;padding:12px;border-radius:4px;margin-bottom:20px;';
                    mensaje.innerHTML = '✅ Charla eliminada por el administrador';
                    document.getElementById('gw-listado-charlas').insertBefore(mensaje, document.getElementById('gw-listado-charlas').firstChild);
                    
                    setTimeout(() => mensaje.remove(), 3000);
                }
            });
        }

        // Función para cargar charlas eliminadas
        function cargarCharlasEliminadas() {
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                credentials: 'same-origin',
                body: 'action=gw_obtener_eliminadas'
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    document.getElementById('gw-listado-eliminadas').innerHTML = res.data.html;
                    document.getElementById('gw-charlas-eliminadas-panel').style.display = '';
                    
                    // Inicializar botones después de cargar el contenido
                    setTimeout(() => {
                        initBotonesEliminadas();
                    }, 100);
                }
            });
        }

        // Función para manejar botones en charlas eliminadas
        function initBotonesEliminadas() {
            // Restaurar charla
            document.querySelectorAll('.gw-restaurar-charla').forEach(function(boton) {
                if (!boton._gwInit) {
                    boton._gwInit = true;
                    boton.addEventListener('click', function() {
                        if (!confirm('¿Restaurar esta charla?')) return;
                        
                        var data = new FormData();
                        data.append('action', 'gw_restaurar_charla');
                        data.append('charla_id', this.dataset.charlaId);
                        
                        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                            method: 'POST',
                            credentials: 'same-origin',
                            body: data
                        })
                        .then(r => r.json())
                        .then(res => {
                            if (res.success) {
                                // Remover de la vista de eliminadas
                                this.closest('div[style*="border:1px solid #dc3545"]').remove();
                                
                                // Actualizar contador
                                actualizarContadorEliminadas();
                                
                                // Mostrar mensaje
                                var mensaje = document.createElement('div');
                                mensaje.style.cssText = 'background:#d4edda;border:1px solid #c3e6cb;color:#155724;padding:12px;border-radius:4px;margin-bottom:20px;';
                                mensaje.innerHTML = '✅ Charla restaurada correctamente';
                                
                                var listado = document.getElementById('gw-listado-eliminadas');
                                listado.insertBefore(mensaje, listado.firstChild);
                                
                                setTimeout(() => mensaje.remove(), 3000);
                                
                                // Si no quedan más charlas eliminadas
                                if (listado.children.length === 1) { // Solo queda el mensaje
                                    setTimeout(() => {
                                        listado.innerHTML = '<p style="color:#666;">No hay charlas eliminadas.</p>';
                                    }, 3000);
                                }
                                
                                // Recargar el listado principal para mostrar la charla restaurada
                                location.reload();
                            }
                        });
                    });
                }
            });
            
            // Eliminar definitivamente
            document.querySelectorAll('.gw-eliminar-definitivo').forEach(function(boton) {
                if (!boton._gwInit) {
                    boton._gwInit = true;
                    boton.addEventListener('click', function() {
                        if (!confirm('¿ELIMINAR DEFINITIVAMENTE esta charla? Esta acción no se puede deshacer.')) return;
                        
                        var data = new FormData();
                        data.append('action', 'gw_eliminar_definitivo');
                        data.append('charla_id', this.dataset.charlaId);
                        
                        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                            method: 'POST',
                            credentials: 'same-origin',
                            body: data
                        })
                        .then(r => r.json())
                        .then(res => {
                            if (res.success) {
                                // Remover de la vista
                                this.closest('div[style*="border:1px solid #dc3545"]').remove();
                                
                                // Actualizar contador
                                actualizarContadorEliminadas();
                                
                                // Mostrar mensaje
                                var mensaje = document.createElement('div');
                                mensaje.style.cssText = 'background:#f8d7da;border:1px solid #f5c6cb;color:#721c24;padding:12px;border-radius:4px;margin-bottom:20px;';
                                mensaje.innerHTML = '⚠️ Charla eliminada definitivamente';
                                
                                var listado = document.getElementById('gw-listado-eliminadas');
                                listado.insertBefore(mensaje, listado.firstChild);
                                
                                setTimeout(() => mensaje.remove(), 3000);
                                
                                // Si no quedan más charlas eliminadas
                                if (listado.children.length === 1) { // Solo queda el mensaje
                                    setTimeout(() => {
                                        listado.innerHTML = '<p style="color:#666;">No hay charlas eliminadas.</p>';
                                    }, 3000);
                                }
                            }
                        });
                    });
                }
            });
        }

        // Inicializar filtros y eliminación
        function initFiltrosYEliminacion() {
            // Event listeners para filtros
            document.getElementById('gw-filtro-nombre').addEventListener('input', aplicarFiltros);
            document.getElementById('gw-filtro-modalidad').addEventListener('change', aplicarFiltros);
            document.getElementById('gw-filtro-lugar').addEventListener('input', aplicarFiltros);
            
            // Limpiar filtros
            document.getElementById('gw-limpiar-filtros').addEventListener('click', function() {
                document.getElementById('gw-filtro-nombre').value = '';
                document.getElementById('gw-filtro-modalidad').value = '';
                document.getElementById('gw-filtro-lugar').value = '';
                aplicarFiltros();
            });
            
            // Ver eliminadas
            document.getElementById('gw-ver-eliminadas').addEventListener('click', cargarCharlasEliminadas);
            document.getElementById('gw-cerrar-eliminadas').addEventListener('click', function() {
                document.getElementById('gw-charlas-eliminadas-panel').style.display = 'none';
            });
            
            // Botones eliminar charla
            document.querySelectorAll('.gw-eliminar-charla').forEach(function(boton) {
                if (!boton._gwInit) {
                    boton._gwInit = true;
                    boton.addEventListener('click', function() {
                        eliminarCharla(this.dataset.charlaId, this);
                    });
                }
            });
            
            // Botón ver más
            var verMasBtn = document.getElementById('gw-ver-mas-charlas');
            if (verMasBtn && !verMasBtn._gwInit) {
                verMasBtn._gwInit = true;
                verMasBtn.addEventListener('click', function() {
                    var charlasOcultas = document.querySelectorAll('.gw-charla-wrapper[style*="display:none"], .gw-charla-wrapper[style*="display: none"]');
                    var mostradas = 0;
                    
                    charlasOcultas.forEach(function(charla) {
                        if (mostradas < 5) {
                            charla.style.display = '';
                            mostradas++;
                        }
                    });
                    
                    charlasVisible += mostradas;
                    var restantes = charlasOcultas.length - mostradas;
                    
                    if (restantes <= 0) {
                        this.parentElement.style.display = 'none';
                    } else {
                        this.innerHTML = 'Ver más charlas (' + restantes + ' restantes)';
                    }
                });
            }
        }

        // Inicializar todo
        initFiltrosYEliminacion();
        
        // Hacer función global para que se pueda llamar desde otros lugares
        window.gwInitSesionesCharlasPanel = gwInitSesionesCharlasPanel;
        window.initFiltrosYEliminacion = initFiltrosYEliminacion;
    })();

    // Función original para sesiones (mantener intacta)
    function gwInitSesionesCharlasPanel() {
        document.querySelectorAll('.gw-form-sesiones-charla').forEach(function(form){
            if(form._gwInit) return;
            form._gwInit = true;
            var charlaId = form.getAttribute('data-charla');
            var container = form.querySelector('#gw-sesiones-list-'+charlaId);
            
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
                        if(res.html){
                            document.getElementById('gw-listado-charlas').innerHTML = res.html;
                            gwInitSesionesCharlasPanel();
                            if(typeof initFiltrosYEliminacion === 'function') initFiltrosYEliminacion();
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
    .gw-charla-item {
        transition: all 0.3s ease;
    }
    .gw-charla-item:hover {
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        transform: translateY(-1px);
    }
    .gw-eliminar-charla:hover {
        background: linear-gradient(135deg, #c82333, #a71e2a) !important;
        transform: scale(1.05);
        box-shadow: 0 4px 8px rgba(220,53,69,0.4) !important;
    }
    .gw-eliminar-charla:active {
        transform: scale(0.95);
    }
    .gw-eliminar-charla svg {
        stroke: currentColor;
        fill: none;
        stroke-width: 2;
        stroke-linecap: round;
        stroke-linejoin: round;
    }
    #gw-filtro-nombre, #gw-filtro-lugar {
        transition: all 0.2s ease;
        border: 1px solid #ddd;
        border-radius: 4px;
    }
    #gw-filtro-nombre:focus, #gw-filtro-lugar:focus {
        border-color: #007cba;
        outline: none;
        box-shadow: 0 0 0 3px rgba(0,124,186,0.1);
    }
    #gw-filtro-modalidad {
        border: 1px solid #ddd;
        border-radius: 4px;
        transition: all 0.2s ease;
    }
    #gw-filtro-modalidad:focus {
        border-color: #007cba;
        outline: none;
        box-shadow: 0 0 0 3px rgba(0,124,186,0.1);
    }
    .gw-charla-wrapper {
        transition: opacity 0.3s ease;
    }
    </style>
</div>





<!-- TAB PROYECTOS -->
<div class="gw-admin-tab-content" id="gw-admin-tab-proyectos" style="display:none;">
  <div class="gw-form-header">
    <h1>Proyectos</h1>
    <p>Administra los proyectos disponibles.</p>
  </div>

  <!-- FILTROS DE BÚSQUEDA -->
  <div style="background:#f9f9f9;padding:15px;border-radius:5px;margin-bottom:20px;">
    <div style="display:flex;gap:15px;align-items:center;flex-wrap:wrap;">
      <div>
        <label><strong>Filtrar por país:</strong></label>
        <select id="gw-filtro-pais" style="margin-left:8px;padding:5px;">
          <option value="">Todos los países</option>
          <!-- Los países se llenan dinámicamente -->
        </select>
      </div>
      <div>
        <label><strong>Buscar:</strong></label>
        <input type="text" id="gw-buscar-proyecto" placeholder="Nombre del proyecto..." style="margin-left:8px;padding:5px;width:200px;">
      </div>
      <div>
        <label><strong>Estado:</strong></label>
        <select id="gw-filtro-estado" style="margin-left:8px;padding:5px;">
          <option value="activo">Activos</option>
          <option value="">Todos</option>
          <option value="eliminado">Eliminados</option>
        </select>
      </div>
      <button type="button" id="gw-limpiar-filtros" class="button">Limpiar filtros</button>
    </div>
  </div>

  <?php if (current_user_can('manage_options') || current_user_can('coordinador_pais')): ?>
  <!-- FORMULARIO NUEVO PROYECTO -->
  <div style="background:#fff;border:1px solid #ddd;padding:20px;border-radius:5px;margin-bottom:28px;">
    <form id="gw-form-nuevo-proyecto">
      <h3 style="margin-top:0;">Agregar nuevo proyecto</h3>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;max-width:600px;">
        <div>
          <label for="gw-nuevo-proyecto-title"><strong>Nombre del proyecto:</strong></label>
          <input type="text" id="gw-nuevo-proyecto-title" name="titulo" required style="width:100%;padding:8px;margin-top:5px;">
        </div>
        <div>
          <label for="gw-nuevo-proyecto-pais"><strong>País:</strong></label>
          <select id="gw-nuevo-proyecto-pais" name="pais_id_visual" required style="width:100%;padding:8px;margin-top:5px;">
            <option value="">Seleccionar país</option>
            <!-- Se llenará dinámicamente con ID como value -->
          </select>
        </div>
        <div>
          <label for="gw-nuevo-proyecto-coach"><strong>Coach responsable:</strong></label>
          <select id="gw-nuevo-proyecto-coach" name="coach" required style="width:100%;padding:8px;margin-top:5px;">
            <option value="">Seleccionar coach</option>
          </select>
        </div>
        <div>
          <label for="gw-nuevo-proyecto-descripcion"><strong>Descripción breve:</strong></label>
          <textarea id="gw-nuevo-proyecto-descripcion" name="descripcion" style="width:100%;padding:8px;margin-top:5px;height:60px;" placeholder="Descripción opcional..."></textarea>
        </div>
      </div>
      <div style="margin-top:15px;">
        <button type="submit" class="button button-primary">Agregar Proyecto</button>
        <span id="gw-proyecto-guardado" style="margin-left:12px;color:#388e3c;display:none;">✓ Proyecto guardado exitosamente</span>
        <span id="gw-proyecto-error" style="margin-left:12px;color:#d32f2f;display:none;">Error al guardar proyecto</span>
      </div>
    </form>
  </div>

  <script>
  (function(){
    const ajaxBase = '<?php echo admin_url('admin-ajax.php'); ?>';

    // Estado global de países (id <-> nombre)
    const GW_COUNTRIES = {
      list: [],                 // [{id, nombre}]
      byId: new Map(),          // id -> nombre
      byNameNorm: new Map(),    // nombre_normalizado -> id
    };

    const normalize = (s) => {
      s = (s || '').toString().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
      s = s.replace(/\s+/g,' ').trim().toLowerCase();
      return s;
    };

    // ----- CARGAR PAISES (ID + nombre) y poblar selects -----
    function cargarPaisesCodigos() {
      return fetch(`${ajaxBase}?action=gw_listar_paises_codigos`, { credentials:'same-origin' })
        .then(r=>r.json())
        .then(res=>{
          if (!res.success) return;

          GW_COUNTRIES.list = res.data || [];
          GW_COUNTRIES.byId.clear();
          GW_COUNTRIES.byNameNorm.clear();

          const selNuevoPais = document.getElementById('gw-nuevo-proyecto-pais');
          if (selNuevoPais) {
            selNuevoPais.innerHTML = '<option value="">Seleccionar país</option>';
          }

          GW_COUNTRIES.list.forEach(p=>{
            GW_COUNTRIES.byId.set(p.id, p.nombre);
            GW_COUNTRIES.byNameNorm.set(normalize(p.nombre), p.id);

            if (selNuevoPais) {
              selNuevoPais.add(new Option(p.nombre, p.id)); // value = ID
            }
          });
        });
    }

    // ----- CARGAR COACHES (todos) -----
    function cargarCoaches() {
      fetch(`${ajaxBase}?action=gw_obtener_coaches`, {
        credentials:'same-origin'
      })
      .then(r=>r.json()).then(res=>{
        if(res.success) {
          const select = document.getElementById('gw-nuevo-proyecto-coach');
          if (!select) return;
          select.innerHTML = '<option value="">Seleccionar coach</option>';
          res.data.forEach(coach => {
            select.add(new Option(coach.name, coach.id));
          });
        }
      });
    }

    // ----- CARGAR COACHES POR pais_id -----
    function cargarCoachesPorPaisId(paisId) {
      const select = document.getElementById('gw-nuevo-proyecto-coach');
      if (!select) return;

      select.innerHTML = '<option value="">Cargando coaches...</option>';
      select.disabled = true;

      if (!paisId) {
        select.innerHTML = '<option value="">Selecciona un país</option>';
        select.disabled = true;
        return;
      }

      fetch(`${ajaxBase}?action=gw_obtener_coaches_por_pais&pais_id=${encodeURIComponent(paisId)}`, {
        credentials: 'same-origin'
      })
      .then(r => r.json())
      .then(res => {
        if (res.success && Array.isArray(res.data) && res.data.length) {
          select.innerHTML = '<option value="">Seleccionar coach</option>';
          res.data.forEach(c => {
            // Si no quieres incluir admin aquí, ya lo excluimos en el backend
            select.add(new Option(`${c.name}${c.tipo ? ' ('+c.tipo+')':''}`, c.id));
          });
          select.disabled = false;
        } else {
          select.innerHTML = '<option value="">No hay coaches para este país</option>';
          select.disabled = true;
        }
      })
      .catch(() => {
        select.innerHTML = '<option value="">Error al cargar coaches</option>';
        select.disabled = true;
      });
    }

    // ----- SUBMIT NUEVO PROYECTO -----
    const form = document.getElementById('gw-form-nuevo-proyecto');
    if (form) {
      form.addEventListener('submit', function(e){
        e.preventDefault();
        const titulo = document.getElementById('gw-nuevo-proyecto-title').value;
        const selPais = document.getElementById('gw-nuevo-proyecto-pais');
        const paisId  = parseInt(selPais.value || '0', 10);
        const paisNombre = selPais.options[selPais.selectedIndex]?.text || '';
        const coach = document.getElementById('gw-nuevo-proyecto-coach').value;
        const descripcion = document.getElementById('gw-nuevo-proyecto-descripcion').value;

        if(!titulo || !paisId || !coach) {
          mostrarError('Por favor completa todos los campos obligatorios');
          return;
        }

        // Guardamos NOMBRE de país (como ya lo hace tu listado)
        const data = new FormData();
        data.append('action', 'gw_nuevo_proyecto');
        data.append('titulo', titulo);
        data.append('pais', paisNombre);   // <- nombre
        data.append('coach', coach);
        data.append('descripcion', descripcion);

        fetch(ajaxBase, {
          method:'POST',
          credentials:'same-origin',
          body: data
        }).then(r=>r.json()).then(res=>{
          if(res.success){
            form.reset();
            cargarCoaches();
            mostrarExito('Proyecto guardado exitosamente');
            actualizarListado();
            actualizarFiltros();
          } else {
            mostrarError(res.data?.msg || 'Error al guardar proyecto');
          }
        }).catch(()=> mostrarError('Error de conexión'));
      });
    }

    // ----- Eventos -----
    document.getElementById('gw-nuevo-proyecto-pais').addEventListener('change', function () {
      const paisId = parseInt(this.value || '0', 10);
      cargarCoachesPorPaisId(paisId);
    });

    function mostrarExito(msg) {
      const elem = document.getElementById('gw-proyecto-guardado');
      elem.textContent = '✓ ' + msg;
      elem.style.display = '';
      document.getElementById('gw-proyecto-error').style.display = 'none';
      setTimeout(()=>{elem.style.display='none';}, 3000);
    }
    function mostrarError(msg) {
      const elem = document.getElementById('gw-proyecto-error');
      elem.textContent = '✗ ' + msg;
      elem.style.display = '';
      document.getElementById('gw-proyecto-guardado').style.display = 'none';
      setTimeout(()=>{elem.style.display='none';}, 4000);
    }

    // ----- EDITAR PROYECTO -----
    window.gwEditarProyecto = function(id, titulo, paisNombre, coach, descripcion) {
      const modal = document.createElement('div');
      modal.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;display:flex;align-items:center;justify-content:center;';

      modal.innerHTML = `
        <div style="background:white;padding:30px;border-radius:8px;max-width:500px;width:90%;">
          <h3>Editar Proyecto</h3>
          <form id="gw-form-editar-proyecto">
            <p><label><strong>Título:</strong><br>
              <input type="text" id="edit-titulo" value="${titulo}" style="width:100%;padding:8px;">
            </label></p>
            <p><label><strong>País:</strong><br>
              <select id="edit-pais" style="width:100%;padding:8px;">
                <option value="">Cargando países...</option>
              </select>
            </label></p>
            <p><label><strong>Coach:</strong><br>
              <select id="edit-coach" style="width:100%;padding:8px;">
                <option value="">Cargando coaches...</option>
              </select>
            </label></p>
            <p><label><strong>Descripción:</strong><br>
              <textarea id="edit-descripcion" style="width:100%;padding:8px;height:60px;">${descripcion || ''}</textarea>
            </label></p>
            <div style="text-align:right;margin-top:20px;">
              <button type="button" class="button" onclick="this.closest('[style*=position]').remove()">Cancelar</button>
              <button type="submit" class="button button-primary" style="margin-left:10px;">Guardar</button>
            </div>
          </form>
        </div>
      `;
      document.body.appendChild(modal);

      // Poblar select de países con IDs reales
      const selEditPais = modal.querySelector('#edit-pais');
      selEditPais.innerHTML = '<option value="">Seleccionar país</option>';
      GW_COUNTRIES.list.forEach(p => selEditPais.add(new Option(p.nombre, p.id)));

      // Preseleccionar por nombre (buscamos su id con normalize)
      const preId = GW_COUNTRIES.byNameNorm.get(normalize(paisNombre)) || '';
      if (preId) selEditPais.value = preId;

      // Cargar coaches del país preseleccionado
      cargarCoachesParaEdicion(preId, coach);

      selEditPais.addEventListener('change', function(){
        const nuevoId = parseInt(this.value || '0', 10);
        cargarCoachesParaEdicion(nuevoId, null);
      });

      modal.querySelector('#gw-form-editar-proyecto').addEventListener('submit', function(e){
        e.preventDefault();

        const newTitulo = modal.querySelector('#edit-titulo').value;
        const paisIdSel = parseInt(selEditPais.value || '0', 10);
        const paisNombreSel = selEditPais.options[selEditPais.selectedIndex]?.text || '';
        const coachSel = modal.querySelector('#edit-coach').value;
        const descSel  = modal.querySelector('#edit-descripcion').value;

        const data = new FormData();
        data.append('action', 'gw_editar_proyecto');
        data.append('proyecto_id', id);
        data.append('titulo', newTitulo);
        data.append('pais', paisNombreSel);  // seguimos guardando NOMBRE (tu listado usa texto)
        data.append('coach', coachSel);
        data.append('descripcion', descSel);

        fetch(ajaxBase, {
          method: 'POST',
          credentials:'same-origin',
          body: data
        }).then(r=>r.json()).then(res=>{
          if (res.success) {
            modal.remove();
            actualizarListado();
          } else {
            alert('Error: ' + (res.data?.msg || 'Error desconocido'));
          }
        });
      });

      function cargarCoachesParaEdicion(paisId, coachSeleccionado) {
        const selectCoach = modal.querySelector('#edit-coach');
        selectCoach.innerHTML = '<option value="">Cargando...</option>';

        if (!paisId) {
          // sin país => listar todos
          fetch(`${ajaxBase}?action=gw_obtener_coaches`, { credentials:'same-origin' })
          .then(r=>r.json()).then(res=>{
            if(res.success) {
              selectCoach.innerHTML = '<option value="">Seleccionar coach</option>';
              res.data.forEach(coach => {
                const selected = (coach.id == coachSeleccionado) ? 'selected' : '';
                const opt = new Option(coach.name, coach.id);
                if (selected) opt.selected = true;
                selectCoach.add(opt);
              });
            }
          });
        } else {
          // con país => filtrar por pais_id
          fetch(`${ajaxBase}?action=gw_obtener_coaches_por_pais&pais_id=${encodeURIComponent(paisId)}`, {
            credentials:'same-origin'
          }).then(r=>r.json()).then(res=>{
            if(res.success && res.data.length > 0) {
              selectCoach.innerHTML = '<option value="">Seleccionar coach</option>';
              res.data.forEach(c => {
                const opt = new Option(`${c.name}${c.tipo ? ' ('+c.tipo+')':''}`, c.id);
                if (coachSeleccionado && String(c.id) === String(coachSeleccionado)) opt.selected = true;
                selectCoach.add(opt);
              });
            } else {
              selectCoach.innerHTML = '<option value="">No hay coaches para este país</option>';
            }
          });
        }
      }
    };

    // ----- LISTADO / FILTROS -----
    document.addEventListener('DOMContentLoaded', function() {
      cargarPaisesCodigos().then(()=>{
        // Cargar coaches inicial (sin filtro)
        cargarCoaches();
      });
      actualizarListado();
      actualizarFiltros();
    });

    document.getElementById('gw-filtro-pais')?.addEventListener('change', actualizarListado);
    document.getElementById('gw-filtro-estado')?.addEventListener('change', actualizarListado);
    document.getElementById('gw-buscar-proyecto')?.addEventListener('input', debounce(actualizarListado, 500));
    document.getElementById('gw-limpiar-filtros')?.addEventListener('click', limpiarFiltros);

    function actualizarListado() {
      var filtros = {
        pais: document.getElementById('gw-filtro-pais')?.value || '',
        busqueda: document.getElementById('gw-buscar-proyecto')?.value || '',
        estado: document.getElementById('gw-filtro-estado')?.value || ''
      };

      var params = new URLSearchParams();
      params.append('action', 'gw_listar_proyectos');
      Object.keys(filtros).forEach(key => {
        if(filtros[key]) params.append(key, filtros[key]);
      });

      fetch(`${ajaxBase}?${params.toString()}`, { credentials:'same-origin' })
      .then(r=>r.json()).then(res=>{
        if(res.success) {
          document.getElementById('gw-listado-proyectos').innerHTML = res.data.html;
        }
      });
    }

    function actualizarFiltros() {
      // Este endpoint saca países desde los proyectos existentes (como ya tenías)
      fetch(`${ajaxBase}?action=gw_obtener_paises_proyectos`, { credentials:'same-origin' })
      .then(r=>r.json()).then(res=>{
        if(res.success) {
          var select = document.getElementById('gw-filtro-pais');
          var valorActual = select.value;
          select.innerHTML = '<option value="">Todos los países</option>';
          res.data.forEach(pais => {
            var selected = pais === valorActual ? 'selected' : '';
            select.innerHTML += `<option value="${pais}" ${selected}>${pais}</option>`;
          });
        }
      });
    }

    function limpiarFiltros() {
      document.getElementById('gw-filtro-pais').value = '';
      document.getElementById('gw-buscar-proyecto').value = '';
      document.getElementById('gw-filtro-estado').value = 'activo';
      actualizarListado();
    }

    function debounce(func, wait) {
      let timeout;
      return function executedFunction(...args) {
        const later = () => {
          clearTimeout(timeout);
          func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
      };
    }

    // Acciones globales (eliminar, restaurar, etc.) – sin cambios
    window.gwEliminarProyecto = function(id) {
      if(!confirm('¿Estás seguro de que quieres eliminar este proyecto?')) return;
      var data = new FormData();
      data.append('action', 'gw_eliminar_proyecto');
      data.append('proyecto_id', id);
      fetch(ajaxBase, { method:'POST', credentials:'same-origin', body: data })
      .then(r=>r.json()).then(res=>{ if(res.success) actualizarListado(); else alert('Error al eliminar'); });
    };

    window.gwRestaurarProyecto = function(id) {
      var data = new FormData();
      data.append('action', 'gw_restaurar_proyecto');
      data.append('proyecto_id', id);
      fetch(ajaxBase, { method:'POST', credentials:'same-origin', body: data })
      .then(r=>r.json()).then(res=>{ if(res.success) actualizarListado(); else alert('Error al restaurar'); });
    };

    window.gwVerHistorial = function(id) {
      alert('Funcionalidad de historial en desarrollo para proyecto ID: ' + id);
    };

  })();
  </script>
  <?php endif; ?>

  <!-- LISTADO DE PROYECTOS -->
  <div id="gw-listado-proyectos"></div>
</div>
<style>
.gw-proyecto-item {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 5px;
    padding: 15px;
    margin-bottom: 10px;
    transition: box-shadow 0.2s;
}
.gw-proyecto-item:hover {
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
}
.gw-proyecto-eliminado {
    background: #ffebee;
    border-color: #f44336;
}
.gw-proyecto-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}
.gw-proyecto-acciones {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.gw-proyecto-acciones button {
    padding: 4px 8px;
    border: none;
    border-radius: 3px;
    cursor: pointer;
    font-size: 12px;
    white-space: nowrap;
}
.gw-btn-eliminar { background: #f44336; color: white; }
.gw-btn-restaurar { background: #4caf50; color: white; }
.gw-btn-historial { background: #2196f3; color: white; }
.gw-btn-editar { background: #ff9800; color: white; }
.gw-btn-wp { background: #0073aa; color: white; }
</style>


<!-- TAB CAPACITACIONES (COMPLETO CON NONCE) -->
<div class="gw-admin-tab-content" id="gw-admin-tab-capacitaciones" style="display:none;">
  <div class="gw-form-header">
    <h1>Capacitaciones</h1>
    <p>Gestiona capacitaciones y sus sesiones.</p>
  </div>

  <div id="gw-capacitacion-wizard">
    <style>
      .gw-wizard-steps{display:flex;justify-content:space-between;margin-bottom:30px;margin-top:10px}
      .gw-wizard-step{flex:1;text-align:center;padding:12px 0;position:relative;background:#f7fafd;border-radius:8px;font-weight:600;color:#1d3557;cursor:pointer;border:2px solid #31568d;transition:.2s;margin:0 4px}
      .gw-wizard-step.active,.gw-wizard-step:hover{background:#31568d;color:#fff}
      .gw-wizard-form{background:#fff;padding:24px 30px;border-radius:14px;box-shadow:0 2px 10px #dde7f2;max-width:560px;margin:0 auto 28px}
      .gw-wizard-form label{display:block;margin-top:14px;font-weight:500;color:#2c3e50}
      .gw-wizard-form input,.gw-wizard-form select{width:100%;padding:10px;margin-top:5px;border-radius:6px;border:1px solid #bcd;font-size:14px;transition:border-color .3s}
      .gw-wizard-form input:focus,.gw-wizard-form select:focus{outline:none;border-color:#31568d;box-shadow:0 0 0 2px rgba(49,86,141,.1)}
      .gw-wizard-form select:disabled{background:#f8f9fa;color:#6c757d;cursor:not-allowed}
      .gw-wizard-sesiones{margin-top:18px}
      .gw-wizard-sesion{border:1px solid #bfd9f7;border-radius:8px;padding:16px;margin-bottom:12px;grid-template-columns:120px 140px 100px 1fr auto auto;gap:12px;align-items:center;background:#f8f9fa}
      .gw-wizard-sesion input,.gw-wizard-sesion select{width:100%;margin:0}
      .gw-wizard-sesion input[name="sesion_link[]"]{grid-column:span 3}
      .gw-wizard-sesion input[name="sesion_lugar[]"]{grid-column:span 3}
      .gw-wizard-sesion .remove-sesion,.gw-wizard-sesion .crear-meet{padding:8px 12px;border:none;border-radius:6px;cursor:pointer;font-size:12px;font-weight:500;transition:background-color .3s}
      .gw-wizard-sesion .remove-sesion{background:#dc3545;color:#fff}
      .gw-wizard-sesion .remove-sesion:hover{background:#c82333}
      .gw-wizard-sesion .crear-meet{background:#28a745;color:#fff}
      .gw-wizard-sesion .crear-meet:hover{background:#218838}
      .gw-wizard-form .add-sesion{margin-top:12px;background:#31568d;color:#fff;padding:10px 20px;border:none;border-radius:6px;cursor:pointer;font-weight:500;transition:background-color .3s}
      .gw-wizard-form .add-sesion:hover{background:#254469}
      .gw-capacitacion-list{max-width:900px;margin:0 auto}
      .gw-capacitacion-card{background:#fff;border-radius:12px;padding:20px;margin-bottom:16px;box-shadow:0 2px 8px rgba(0,0,0,.08);border-left:4px solid #31568d;transition:transform .2s,box-shadow .2s}
      .gw-capacitacion-card:hover{transform:translateY(-2px);box-shadow:0 4px 16px rgba(0,0,0,.12)}
      .gw-capacitacion-title{font-size:18px;font-weight:600;color:#2c3e50;margin-bottom:8px}
      .gw-capacitacion-meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:8px;margin-bottom:12px}
      .gw-capacitacion-meta span{color:#6c757d;font-size:14px}
      .gw-capacitacion-actions{display:flex;gap:8px;margin-top:12px}
      .gw-cap-edit,.gw-cap-restore{color:#007bff;text-decoration:none;padding:6px 12px;border:1px solid #007bff;border-radius:6px;font-size:13px;cursor:pointer;transition:all .3s}
      .gw-cap-edit:hover,.gw-cap-restore:hover{background:#007bff;color:#fff}
      .gw-cap-delete,.gw-cap-delete-forever{color:#dc3545;text-decoration:none;padding:6px 12px;border:1px solid #dc3545;border-radius:6px;font-size:13px;cursor:pointer;transition:all .3s}
      .gw-cap-delete:hover,.gw-cap-delete-forever:hover{background:#dc3545;color:#fff}
      .gw-cap-restore{color:#28a745;border-color:#28a745}
      .gw-cap-restore:hover{background:#28a745;color:#fff}
    </style>

    <div class="gw-wizard-steps">
      <div class="gw-wizard-step active" data-step="1">País</div>
      <div class="gw-wizard-step" data-step="2">Proyecto</div>
      <div class="gw-wizard-step" data-step="3">Coach</div>
      <div class="gw-wizard-step" data-step="4">Sesiones</div>
    </div>

    <form class="gw-wizard-form" id="gw-capacitacion-form">
      <div class="gw-wizard-step-content step-1">
        <label>Nombre de la capacitación:</label>
        <input type="text" name="titulo" required placeholder="Nombre de la capacitación">
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
        <button type="button" class="next-step" style="float:right;margin-top:16px;">Siguiente →</button>
      </div>

      <div class="gw-wizard-step-content step-2" style="display:none;">
        <label>Proyecto relacionado:</label>
        <select name="proyecto" required disabled>
          <option value="">Primero selecciona un país</option>
        </select>
        <button type="button" class="prev-step" style="margin-top:16px;">← Anterior</button>
        <button type="button" class="next-step" style="float:right;margin-top:16px;">Siguiente →</button>
      </div>

      <div class="gw-wizard-step-content step-3" style="display:none;">
        <label>Coach responsable:</label>
        <select name="coach" required disabled>
          <option value="">Primero selecciona un proyecto</option>
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

    <!-- Filtros (opcional visual) -->
    <div class="gw-filtros-container" style="background:#f8f9fa;padding:16px;border-radius:8px;margin-bottom:20px;">
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:12px;">
        <select id="filtro-pais" style="padding:8px;border:1px solid #ddd;border-radius:4px;">
          <option value="">Todos los países</option>
          <?php
            $paises_filtro = get_posts(['post_type'=>'pais','numberposts'=>-1,'orderby'=>'title','order'=>'ASC']);
            foreach($paises_filtro as $pais_f){
              echo '<option value="'.$pais_f->ID.'">'.esc_html($pais_f->post_title).'</option>';
            }
          ?>
        </select>
        <select id="filtro-coach" style="padding:8px;border:1px solid #ddd;border-radius:4px;">
          <option value="">Todos los coaches</option>
          <?php
            $coaches_filtro = get_users(['role'=>'coach']);
            foreach($coaches_filtro as $coach_f){
              echo '<option value="'.$coach_f->ID.'">'.esc_html($coach_f->display_name).'</option>';
            }
          ?>
        </select>
        <select id="filtro-modalidad" style="padding:8px;border:1px solid #ddd;border-radius:4px;">
          <option value="">Todas las modalidades</option>
          <option value="presencial">Presencial</option>
          <option value="virtual">Virtual</option>
          <option value="mixta">Mixta</option>
        </select>
        <input type="text" id="filtro-nombre" placeholder="Buscar por nombre..." style="padding:8px;border:1px solid #ddd;border-radius:4px;">
      </div>
      <div style="display:flex;gap:8px;">
        <button id="limpiar-filtros" style="background:#6c757d;color:#fff;border:none;padding:8px 16px;border-radius:4px;cursor:pointer;">Limpiar Filtros</button>
        <button id="ver-papelera" style="background:#dc3545;color:#fff;border:none;padding:8px 16px;border-radius:4px;cursor:pointer;margin-left:auto;">Ver Papelera</button>
      </div>
    </div>

    <div id="gw-capacitaciones-listado">
      <?php
        $caps = get_posts(['post_type'=>'capacitacion','numberposts'=>-1,'orderby'=>'title','order'=>'ASC']);
        if(empty($caps)){
          echo '<p>No hay capacitaciones registradas.</p>';
        } else {
          foreach($caps as $cap){
            $pais_id = get_post_meta($cap->ID, '_gw_pais_relacionado', true);
            $proy = get_post_meta($cap->ID, '_gw_proyecto_relacionado', true);
            $coach_id = get_post_meta($cap->ID, '_gw_coach_asignado', true);
            $sesiones = get_post_meta($cap->ID, '_gw_sesiones', true);

            $pais_title = $pais_id ? get_the_title($pais_id) : '-';
            $proy_title = $proy ? get_the_title($proy) : '-';
            $coach_name = $coach_id ? get_userdata($coach_id)->display_name : '-';
            $num_sesiones = is_array($sesiones) ? count($sesiones) : 0;

            $modalidad = 'N/A';
            if (is_array($sesiones) && !empty($sesiones)) {
              $modalidades = array_unique(array_column($sesiones, 'modalidad'));
              $modalidad = count($modalidades) > 1 ? 'Mixta' : ($modalidades[0] ?? 'N/A');
            }

            echo '<div class="gw-capacitacion-card" data-pais="'.$pais_id.'" data-coach="'.$coach_id.'" data-modalidad="'.strtolower($modalidad).'">';
            echo '<div class="gw-capacitacion-title">'.esc_html($cap->post_title).'</div>';
            echo '<div class="gw-capacitacion-meta">';
            echo '<span><strong>País:</strong> '.$pais_title.'</span>';
            echo '<span><strong>Proyecto:</strong> '.$proy_title.'</span>';
            echo '<span><strong>Coach:</strong> '.$coach_name.'</span>';
            echo '<span><strong>Sesiones:</strong> '.$num_sesiones.' ('.$modalidad.')</span>';
            echo '</div>';
            echo '<div class="gw-capacitacion-actions">';
            echo '<span class="gw-cap-edit" data-id="'.$cap->ID.'">Editar</span>';
            echo '<span class="gw-cap-delete" data-id="'.$cap->ID.'">Eliminar</span>';
            echo '</div>';
            echo '</div>';
          }
        }
      ?>
    </div>
  </div>

  <script>
  // JAVASCRIPT COMPLETO (con NONCE y todos los fetch arreglados)
  (function(){
    const ajaxurl  = '<?php echo admin_url('admin-ajax.php'); ?>';
    const GW_NONCE = '<?php echo wp_create_nonce('gw_caps'); ?>';

    let currentStep = 1;

    function showStep(n){
      document.querySelectorAll('.gw-wizard-step-content').forEach(div => div.style.display = 'none');
      const active = document.querySelector('.step-' + n);
      if (active) active.style.display = '';
      document.querySelectorAll('.gw-wizard-step').forEach(btn => btn.classList.remove('active'));
      const tab = document.querySelector('.gw-wizard-step[data-step="' + n + '"]');
      if (tab) tab.classList.add('active');
    }

    const wizardForm = document.getElementById('gw-capacitacion-form');
    if (!wizardForm) return;

    const paisSelectWizard     = wizardForm.querySelector('select[name="pais"]');
    const proyectoSelectWizard = wizardForm.querySelector('select[name="proyecto"]');
    const coachSelectWizard    = wizardForm.querySelector('select[name="coach"]');

    // ----- Cargar proyectos por país -----
    function cargarProyectosPorPais(paisId) {
      const proyectoSelect = proyectoSelectWizard;
      const coachSelect    = coachSelectWizard;

      if (!proyectoSelect || !coachSelect) return;

      if (!paisId) {
        proyectoSelect.innerHTML = '<option value="">Primero selecciona un país</option>';
        proyectoSelect.disabled  = true;
        coachSelect.innerHTML    = '<option value="">Primero selecciona un proyecto</option>';
        coachSelect.disabled     = true;
        return;
      }

      proyectoSelect.innerHTML = '<option value="">Cargando proyectos...</option>';
      proyectoSelect.disabled  = true;

      fetch(`${ajaxurl}?action=gw_obtener_proyectos_por_pais&pais_id=${encodeURIComponent(paisId)}&security=${GW_NONCE}`)
        .then(r => r.json())
        .then(res => {
          if (res.success && Array.isArray(res.data) && res.data.length) {
            proyectoSelect.innerHTML = '<option value="">Selecciona un proyecto</option>';
            res.data.forEach(p => {
              proyectoSelect.innerHTML += `<option value="${p.id}">${p.titulo}</option>`;
            });
            proyectoSelect.disabled = false;
          } else {
            proyectoSelect.innerHTML = '<option value="">No hay proyectos disponibles</option>';
            proyectoSelect.disabled  = true;
          }
          coachSelect.innerHTML = '<option value="">Primero selecciona un proyecto</option>';
          coachSelect.disabled  = true;
        })
        .catch(() => {
          proyectoSelect.innerHTML = '<option value="">Error al cargar proyectos</option>';
          proyectoSelect.disabled  = true;
        });
    }

    // ----- Cargar coaches por proyecto -----
    function cargarCoachesPorProyecto(proyectoId) {
      const coachSelect = coachSelectWizard;
      if (!coachSelect) return;

      if (!proyectoId) {
        coachSelect.innerHTML = '<option value="">Primero selecciona un proyecto</option>';
        coachSelect.disabled  = true;
        return;
      }

      coachSelect.innerHTML = '<option value="">Cargando coaches...</option>';
      coachSelect.disabled  = true;

      fetch(`${ajaxurl}?action=gw_obtener_coaches_por_proyecto&proyecto_id=${encodeURIComponent(proyectoId)}&security=${GW_NONCE}`)
        .then(r => r.json())
        .then(res => {
          if (res.success && Array.isArray(res.data) && res.data.length) {
            coachSelect.innerHTML = '<option value="">Selecciona un coach</option>';
            res.data.forEach(c => {
              coachSelect.innerHTML += `<option value="${c.id}">${c.nombre}</option>`;
            });
            coachSelect.disabled = false;
          } else {
            coachSelect.innerHTML = '<option value="">No hay coaches disponibles</option>';
            coachSelect.disabled  = true;
          }
        })
        .catch(() => {
          coachSelect.innerHTML = '<option value="">Error al cargar coaches</option>';
          coachSelect.disabled  = true;
        });
    }

    // Listeners selects
    paisSelectWizard?.addEventListener('change', function(){ cargarProyectosPorPais(this.value); });
    proyectoSelectWizard?.addEventListener('change', function(){ cargarCoachesPorProyecto(this.value); });

    // ----- Botones Siguiente/Anterior -----
    function assignNextStepEvents(){
      document.querySelectorAll('.next-step').forEach(btn=>{
        btn.onclick = function(){
          if (currentStep === 1) {
            const tituloInput = wizardForm.querySelector('input[name="titulo"]');
            const pais = paisSelectWizard?.value || '';
            const titulo = tituloInput ? tituloInput.value.trim() : '';
            if (!titulo) { alert('Por favor ingresa el nombre de la capacitación'); tituloInput?.focus(); return; }
            if (!pais)   { alert('Por favor selecciona un país');              paisSelectWizard?.focus(); return; }
          } else if (currentStep === 2) {
            const proyecto = proyectoSelectWizard?.value || '';
            if (!proyecto) { alert('Por favor selecciona un proyecto'); proyectoSelectWizard?.focus(); return; }
          } else if (currentStep === 3) {
            const coach = coachSelectWizard?.value || '';
            if (!coach) { alert('Por favor selecciona un coach'); coachSelectWizard?.focus(); return; }
          }
          if (currentStep < 4) showStep(++currentStep);
        };
      });
    }
    assignNextStepEvents();

    document.querySelectorAll('.prev-step').forEach(btn=>{
      btn.onclick = function(){ if (currentStep > 1) showStep(--currentStep); };
    });

    // ----- Sesiones -----
    const sesionesWrap = wizardForm.querySelector('.gw-wizard-sesiones');

    function addSesion(data){
      data = data || {};
      const sesion = document.createElement('div');
      sesion.className = 'gw-wizard-sesion';
      sesion.innerHTML = `
        <select name="sesion_modalidad[]">
          <option value="Presencial"${data.modalidad=="Presencial"?" selected":""}>Presencial</option>
          <option value="Virtual"${data.modalidad=="Virtual"?" selected":""}>Virtual</option>
        </select>
        <input type="date" name="sesion_fecha[]" value="${data.fecha||""}" required>
        <input type="time" name="sesion_hora[]" value="${data.hora||""}" required>
        <input type="text" name="sesion_lugar[]" placeholder="Lugar físico" value="${data.lugar||""}" ${data.modalidad=="Virtual"?"style='display:none;'":""}>
        <input type="url"  name="sesion_link[]"  placeholder="Pega aquí el link de Google Meet" value="${data.link||""}" ${data.modalidad!="Virtual"?"style='display:none;'":""}>
        <button type="button" class="remove-sesion">Eliminar</button>
        <button type="button" class="crear-meet" ${data.modalidad!="Virtual"?"style='display:none;'":""}>Crear Meet</button>
      `;

      const modalidad   = sesion.querySelector('select');
      const lugarInput  = sesion.querySelector('input[name="sesion_lugar[]"]');
      const linkInput   = sesion.querySelector('input[name="sesion_link[]"]');
      const crearMeetBtn= sesion.querySelector('.crear-meet');

      function updateFields(){
        const isVirtual = modalidad.value == 'Virtual';
        if (isVirtual){
          lugarInput.style.display = "none"; lugarInput.value = "";
          linkInput.style.display  = "";
          crearMeetBtn.style.display = "";
        } else {
          lugarInput.style.display = "";
          linkInput.style.display  = "none"; linkInput.value = "";
          crearMeetBtn.style.display = "none";
        }
      }
      modalidad.onchange = updateFields;

      crearMeetBtn.onclick = function(){
        const fecha = sesion.querySelector('input[name="sesion_fecha[]"]').value;
        const hora  = sesion.querySelector('input[name="sesion_hora[]"]').value;
        const tituloCapacitacion = wizardForm.querySelector('input[name="titulo"]')?.value || 'Capacitación Virtual';
        if (!fecha || !hora) { alert('Por favor completa la fecha y hora antes de crear el Meet'); return; }

        const dt = new Date(fecha + 'T' + hora);
        const pad = n => String(n).padStart(2,'0');

        const ini = dt.getFullYear() + pad(dt.getMonth()+1) + pad(dt.getDate()) + 'T' + pad(dt.getHours()) + pad(dt.getMinutes()) + '00';
        const dt2 = new Date(dt.getTime() + 60*60*1000);
        const fin = dt2.getFullYear() + pad(dt2.getMonth()+1) + pad(dt2.getDate()) + 'T' + pad(dt2.getHours()) + pad(dt2.getMinutes()) + '00';

        const url = `https://calendar.google.com/calendar/u/0/r/eventedit?text=${encodeURIComponent(tituloCapacitacion)}&dates=${ini}/${fin}&details=${encodeURIComponent('Sesión de capacitación virtual')}&vcon=meet&hl=es-419&ctz=America/Tegucigalpa`;
        window.open(url, '_blank');
      };

      sesion.querySelector('.remove-sesion').onclick = function(){ sesionesWrap.removeChild(sesion); };

      updateFields();
      sesionesWrap.appendChild(sesion);
    }

    wizardForm.querySelector('.add-sesion')?.addEventListener('click', () => addSesion());

    // ----- Envío del formulario -----
    function handleFormSubmit(e){
      e.preventDefault();

      if (!wizardForm.querySelectorAll('.gw-wizard-sesion').length) { alert('Debe agregar al menos una sesión'); return; }

      const titulo   = wizardForm.querySelector('input[name="titulo"]')?.value.trim();
      const pais     = paisSelectWizard?.value;
      const proyecto = proyectoSelectWizard?.value;
      const coach    = coachSelectWizard?.value;

      if (!titulo){ alert('El título es requerido'); showStep(1); return; }
      if (!pais){   alert('El país es requerido');   showStep(1); return; }
      if (!proyecto){ alert('El proyecto es requerido'); showStep(2); return; }
      if (!coach){    alert('El coach es requerido');    showStep(3); return; }

      const data = new FormData(wizardForm);
      data.append('action','gw_guardar_capacitacion_wizard');
      data.append('security', GW_NONCE);

      fetch(ajaxurl, { method:'POST', credentials:'same-origin', body:data })
        .then(r => r.json())
        .then(res => {
          if (res.success){
            wizardForm.reset();
            sesionesWrap.innerHTML = '';
            const editInput = wizardForm.querySelector('input[name="edit_id"]');
            if (editInput){ editInput.value = ''; editInput.setAttribute('value',''); }
            proyectoSelectWizard.innerHTML = '<option value="">Primero selecciona un país</option>';
            proyectoSelectWizard.disabled  = true;
            coachSelectWizard.innerHTML    = '<option value="">Primero selecciona un proyecto</option>';
            coachSelectWizard.disabled     = true;

            currentStep = 1; showStep(1);
            updateListado(res);
            alert('Capacitación guardada exitosamente');
          } else {
            alert('Error: ' + (res.data?.msg || res.msg || 'No se pudo guardar'));
          }
        })
        .catch(err => { alert('Error de conexión: ' + err.message); });
    }
    wizardForm.addEventListener('submit', handleFormSubmit);

    // ----- Delegación edición/eliminación/listado -----
    function handleListClick(e){
      // Editar
      if (e.target.classList.contains('gw-cap-edit')){
        const id = e.target.getAttribute('data-id');
        fetch(`${ajaxurl}?action=gw_obtener_capacitacion&id=${encodeURIComponent(id)}&security=${GW_NONCE}`)
          .then(r => r.json())
          .then(res => {
            if (res.success && res.data){
              const d = res.data;
              wizardForm.reset();
              sesionesWrap.innerHTML = '';

              setTimeout(() => {
                const tituloInput = wizardForm.querySelector('input[name="titulo"]');
                const editIdInput = wizardForm.querySelector('input[name="edit_id"]');

                if (tituloInput) tituloInput.value = d.titulo || '';
                if (editIdInput) editIdInput.value = id;

                if (paisSelectWizard && d.pais){
                  paisSelectWizard.value = d.pais;
                  cargarProyectosPorPais(d.pais);

                  setTimeout(() => {
                    if (proyectoSelectWizard && d.proyecto){
                      proyectoSelectWizard.value = d.proyecto;
                      cargarCoachesPorProyecto(d.proyecto);

                      setTimeout(() => {
                        if (coachSelectWizard && d.coach){
                          coachSelectWizard.value = d.coach;
                        }
                      }, 500);
                    }
                  }, 500);
                }

                if (Array.isArray(d.sesiones) && d.sesiones.length){
                  d.sesiones.forEach(s => addSesion(s));
                } else {
                  addSesion();
                }

                currentStep = 1; showStep(1);
                window.scrollTo(0, document.getElementById('gw-capacitacion-wizard').offsetTop - 40);
                alert('Datos cargados para edición');
              }, 100);
            } else {
              alert('Error al cargar los datos para editar');
            }
          })
          .catch(err => alert('Error al cargar: ' + err.message));
      }

      // Eliminar (papelera)
      if (e.target.classList.contains('gw-cap-delete')){
        if (!confirm('¿Eliminar esta capacitación?')) return;
        const id = e.target.getAttribute('data-id');
        const data = new FormData();
        data.append('action','gw_eliminar_capacitacion');
        data.append('id', id);
        data.append('security', GW_NONCE);

        fetch(ajaxurl, { method:'POST', credentials:'same-origin', body:data })
          .then(r => r.json())
          .then(res => { if (res.success) updateListado(res); else alert('Error al eliminar'); })
          .catch(err => alert('Error: ' + err.message));
      }

      // Restaurar papelera
      if (e.target.classList.contains('gw-cap-restore')){
        if (!confirm('¿Restaurar esta capacitación?')) return;
        const id = e.target.getAttribute('data-id');
        const data = new FormData();
        data.append('action','gw_restaurar_capacitacion');
        data.append('id', id);
        data.append('security', GW_NONCE);

        fetch(ajaxurl, { method:'POST', credentials:'same-origin', body:data })
          .then(r => r.json())
          .then(res => { if (res.success){ alert('Capacitación restaurada'); verPapelera(); } else alert('Error al restaurar'); });
      }

      // Eliminar permanentemente
      if (e.target.classList.contains('gw-cap-delete-forever')){
        if (!confirm('¿Eliminar PERMANENTEMENTE esta capacitación?')) return;
        const id = e.target.getAttribute('data-id');
        const data = new FormData();
        data.append('action','gw_eliminar_permanente_capacitacion');
        data.append('id', id);
        data.append('security', GW_NONCE);

        fetch(ajaxurl, { method:'POST', credentials:'same-origin', body:data })
          .then(r => r.json())
          .then(res => { if (res.success){ alert('Eliminada permanentemente'); verPapelera(); } else alert('Error al eliminar'); });
      }
    }

    function updateListado(res){
      const listadoElement = document.getElementById('gw-capacitaciones-listado');
      if (res.data?.html) listadoElement.innerHTML = res.data.html;
      else if (res.html)  listadoElement.innerHTML = res.html;
      else listadoElement.innerHTML = '<p>No hay capacitaciones registradas.</p>';
      assignListEvents();
    }

    function assignListEvents(){
      const listado = document.getElementById('gw-capacitaciones-listado');
      if (listado){
        listado.removeEventListener('click', handleListClick);
        listado.addEventListener('click', handleListClick);
      }
    }
    assignListEvents();

    // ----- Papelera / Activas -----
    function verPapelera(){
      fetch(`${ajaxurl}?action=gw_obtener_capacitaciones_eliminadas&security=${GW_NONCE}`)
        .then(r => r.json())
        .then(res => {
          if (!res.success) return;
          let html = '<h3>Papelera de Capacitaciones</h3>';
          if (res.data?.length){
            res.data.forEach(cap => {
              html += `
                <div class="gw-capacitacion-card" style="opacity:.7;border-left-color:#dc3545;">
                  <div class="gw-capacitacion-title">${cap.titulo} (Eliminada)</div>
                  <div class="gw-capacitacion-meta">
                    <span><strong>País:</strong> ${cap.pais || '-'}</span>
                    <span><strong>Proyecto:</strong> ${cap.proyecto || '-'}</span>
                    <span><strong>Coach:</strong> ${cap.coach || '-'}</span>
                    <span><strong>Eliminada:</strong> ${cap.fecha_eliminacion || ''}</span>
                  </div>
                  <div class="gw-capacitacion-actions">
                    <span class="gw-cap-restore" data-id="${cap.id}" style="color:#28a745;cursor:pointer;padding:6px 12px;border:1px solid #28a745;border-radius:6px;">Restaurar</span>
                    <span class="gw-cap-delete-forever" data-id="${cap.id}" style="color:#dc3545;cursor:pointer;padding:6px 12px;border:1px solid #dc3545;border-radius:6px;">Eliminar Permanentemente</span>
                  </div>
                </div>`;
            });
            html += '<button onclick="cargarCapacitacionesActivas()" style="background:#6c757d;color:#fff;border:none;padding:10px 20px;border-radius:4px;cursor:pointer;margin-top:16px;">Volver a Capacitaciones Activas</button>';
          } else {
            html += '<p>La papelera está vacía.</p>' +
                    '<button onclick="cargarCapacitacionesActivas()" style="background:#6c757d;color:#fff;border:none;padding:10px 20px;border-radius:4px;cursor:pointer;">Volver a Capacitaciones Activas</button>';
          }
          document.getElementById('gw-capacitaciones-listado').innerHTML = html;
          assignListEvents();
        });
    }
    document.getElementById('ver-papelera')?.addEventListener('click', verPapelera);

    window.cargarCapacitacionesActivas = function(){
      fetch(`${ajaxurl}?action=gw_obtener_capacitaciones_activas&security=${GW_NONCE}`)
        .then(r => r.json())
        .then(res => {
          if (res.success){
            document.getElementById('gw-capacitaciones-listado').innerHTML = res.data.html;
            assignListEvents();
          }
        });
    };

    // Paso inicial visible
    showStep(1);
  })();
  </script>
</div>

                <!-- TAB PROGRESO -->
                <div class="gw-admin-tab-content" id="gw-admin-tab-progreso" style="display:none;">
                    <div class="gw-form-header">
                        <h1>Progreso del voluntario</h1>
                        <p>Monitorea el progreso de los voluntarios.</p>
                    </div>

                    <?php
                    // Mostrar el shortcode de progreso del voluntario (admin)
                    echo do_shortcode('[gw_progreso_voluntario]');
                    ?>
                </div>

                <!-- TAB AUSENCIAS -->
                <div class="gw-admin-tab-content" id="gw-admin-tab-ausencias" style="display:none;">
                <div class="gw-form-header">
                    <h1>Seguimiento de ausencias</h1>
                    <p>Detecta inasistencias, programa recordatorios y gestiona el estado de los voluntarios.</p>
                </div>
                <?php $abs = gw_abs_get_settings(); $nonce_abs = wp_create_nonce('gw_abs_admin'); ?>

                <div style="display:flex;gap:24px;flex-wrap:wrap;">
                    <!-- Ajustes -->
                    <form id="gw-abs-settings" style="flex:1 1 360px;max-width:560px;border:1px solid #e1e8f0;border-radius:10px;padding:14px;background:#fff;">
                    <h3 style="margin-top:0;">Ajustes de recordatorios</h3>
                    <label>Máximo de correos (0–10)</label>
                    <input type="number" name="reminder_count" value="<?php echo esc_attr($abs['reminder_count']); ?>" min="0" max="10" style="width:120px;">
                    <label style="display:block;margin-top:8px;">Intervalo entre correos (horas)</label>
                    <input type="number" name="reminder_interval_hours" value="<?php echo esc_attr($abs['reminder_interval_hours']); ?>" min="1" style="width:120px;">
                    <label style="display:block;margin-top:8px;">Margen de gracia tras hora de inicio (minutos)</label>
                    <input type="number" name="grace_minutes" value="<?php echo esc_attr($abs['grace_minutes']); ?>" min="0" style="width:120px;">
                    <label style="display:block;margin-top:12px;">Asunto (recordatorio)</label>
                    <input type="text" name="subject" value="<?php echo esc_attr($abs['subject']); ?>" style="width:100%;">
                    <label style="display:block;margin-top:8px;">Cuerpo (recordatorio)</label>
                    <textarea name="body" rows="6" style="width:100%;"><?php echo esc_textarea($abs['body']); ?></textarea>
                    <label style="display:block;margin-top:12px;">Asunto (desactivación)</label>
                    <input type="text" name="deact_subject" value="<?php echo esc_attr($abs['deact_subject']); ?>" style="width:100%;">
                    <label style="display:block;margin-top:8px;">Cuerpo (desactivación)</label>
                    <textarea name="deact_body" rows="6" style="width:100%;"><?php echo esc_textarea($abs['deact_body']); ?></textarea>
                    <div style="margin-top:12px;">
                        <button type="submit" class="button button-primary">Guardar ajustes</button>
                        <span id="gw-abs-save-ok" style="display:none;margin-left:10px;color:#1e7e34;">Guardado</span>
                    </div>
                    </form>

                    <!-- Listado -->
                    <div style="flex:1 1 520px;min-width:420px;">
                    <h3 style="margin-top:0;">Ausencias detectadas</h3>
                    <?php
                    global $wpdb; $table = $wpdb->prefix.'gw_ausencias';
                    $rows = $wpdb->get_results("SELECT * FROM {$table} WHERE hidden=0 ORDER BY updated_at DESC LIMIT 300", ARRAY_A);
                    if (!$rows) {
                        echo '<p>No hay ausencias registradas.</p>';
                    } else {
                        echo '<table class="widefat striped"><thead><tr><th>Usuario</th><th>Capacitación</th><th>Fecha/Hora</th><th>Estado</th><th>Recordatorios</th><th>Acciones</th></tr></thead><tbody>';
                        foreach ($rows as $r) {
                        $u = get_user_by('id', intval($r['user_id']));
                        $cap_title = get_the_title(intval($r['cap_id'])) ?: ('ID '.$r['cap_id']);
                        echo '<tr data-aid="'.intval($r['id']).'">';
                        echo '<td>'. esc_html($u ? ($u->display_name ?: $u->user_email) : ('#'.$r['user_id'])) .'</td>';
                        echo '<td>'. esc_html($cap_title) .'</td>';
                        echo '<td>'. esc_html($r['fecha']) .'</td>';
                        echo '<td>'. esc_html($r['status']) .'</td>';
                        echo '<td>'. intval($r['reminders_sent']) .'</td>';
                        echo '<td>'
                            .'<button type="button" class="button button-small gw-abs-resolver" data-id="'.intval($r['id']).'">Marcar resuelto</button> '
                            .'<button type="button" class="button button-small gw-abs-reactivar" data-uid="'.intval($r['user_id']).'">Reactivar usuario</button> '
                            .'<button type="button" class="button button-small gw-abs-ocultar" data-id="'.intval($r['id']).'">Ocultar</button>'
                            .'</td>';
                        echo '</tr>';
                        }
                        echo '</tbody></table>';
                    }
                    ?>
                    </div>
                </div>

                <script>
                (function(){
                    var ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
                    var nonce   = '<?php echo esc_js($nonce_abs); ?>';

                    // Guardar ajustes
                    var form = document.getElementById('gw-abs-settings');
                    if (form) {
                    form.addEventListener('submit', function(e){
                        e.preventDefault();
                        var data = new FormData(form);
                        data.append('action','gw_abs_save_settings');
                        data.append('nonce', nonce);
                        fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:data})
                        .then(r=>r.json()).then(function(res){
                            if(res && res.success){
                            var ok = document.getElementById('gw-abs-save-ok');
                            ok.style.display=''; setTimeout(()=>{ok.style.display='none';},1500);
                            }
                        });
                    });
                    }

                    // Acciones en filas
                    document.addEventListener('click', function(ev){
                    var el;
                    if (el = ev.target.closest('.gw-abs-resolver')) {
                        var id = el.getAttribute('data-id');
                        var fd = new FormData(); fd.append('action','gw_abs_mark_resuelto'); fd.append('nonce',nonce); fd.append('id',id);
                        fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:fd})
                        .then(r=>r.json()).then(function(res){ if(res && res.success){ el.closest('tr').remove(); } });
                    }
                    if (el = ev.target.closest('.gw-abs-reactivar')) {
                        var uid = el.getAttribute('data-uid');
                        var fd = new FormData(); fd.append('action','gw_abs_reactivar_usuario'); fd.append('nonce',nonce); fd.append('user_id',uid);
                        fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:fd})
                        .then(r=>r.json()).then(function(res){ /* opcional: feedback */ });
                    }
                    if (el = ev.target.closest('.gw-abs-ocultar')) {
                        var id = el.getAttribute('data-id');
                        var fd = new FormData(); fd.append('action','gw_abs_ocultar'); fd.append('nonce',nonce); fd.append('id',id);
                        fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:fd})
                        .then(r=>r.json()).then(function(res){ if(res && res.success){ el.closest('tr').remove(); } });
                    }
                    });
                })();
                </script>
                </div>

                <!-- TAB REPORTES -->
                <div class="gw-admin-tab-content" id="gw-admin-tab-reportes" style="display:none;">
                    <div class="gw-form-header">
                        <h1>Reportes y listados</h1>
                        <p>Genera reportes del sistema.</p>
                    </div>

                    <p>Aquí va la gestión de reportes y listados.</p>
                </div>

            </div>
        </div>
    </div>
</div>



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
$gw_qr_base = 'https://b97e34cfbb1f.ngrok-free.app/gwproject';
// $gw_qr_base = site_url('/');
?>

<script>
// JavaScript para navegación entre tabs
(function(){
    const stepItems = document.querySelectorAll('.gw-step-item.gw-admin-tab-btn');
    const tabContents = document.querySelectorAll('.gw-admin-tab-content');
    
    stepItems.forEach(item => {
        item.addEventListener('click', function() {
            // Remover clase active de todos los items
            stepItems.forEach(step => step.classList.remove('active'));
            // Agregar clase active al item clickeado
            this.classList.add('active');
            
            // Obtener el tab a mostrar
            const tabToShow = this.getAttribute('data-tab');
            
            // Ocultar todos los tabs
            tabContents.forEach(tab => {
                tab.style.display = 'none';
            });
            
            // Mostrar el tab seleccionado
            const targetTab = document.getElementById('gw-admin-tab-' + tabToShow);
            if (targetTab) {
                targetTab.style.display = 'block';
            }
        });
    });
})();

// JavaScript para QR codes
document.querySelectorAll('.gw-generar-qr-btn').forEach(btn => {
  btn.addEventListener('click', function(){
    var paisId = this.getAttribute('data-pais-id');
    var paisNombre = this.getAttribute('data-pais-nombre');
    var url = '<?php echo $gw_qr_base; ?>?gw_pais=' + paisId;
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

    <?php
    return ob_get_clean();
});


// === AUSENCIAS: AJAX ===
add_action('wp_ajax_gw_abs_save_settings', function(){
    if (!current_user_can('manage_options') && !current_user_can('coordinador_pais') && !current_user_can('coach')) wp_send_json_error();
    check_ajax_referer('gw_abs_admin','nonce');
    $s = gw_abs_update_settings($_POST);
    wp_send_json_success($s);
  });
  add_action('wp_ajax_gw_abs_mark_resuelto', function(){
    if (!current_user_can('manage_options') && !current_user_can('coordinador_pais') && !current_user_can('coach')) wp_send_json_error();
    check_ajax_referer('gw_abs_admin','nonce');
    $id = intval($_POST['id'] ?? 0); if(!$id) wp_send_json_error();
    gw_abs_mark_resuelto_db($id);
    wp_send_json_success();
  });
  add_action('wp_ajax_gw_abs_reactivar_usuario', function(){
    if (!current_user_can('manage_options') && !current_user_can('coordinador_pais') && !current_user_can('coach')) wp_send_json_error();
    check_ajax_referer('gw_abs_admin','nonce');
    $uid = intval($_POST['user_id'] ?? 0); if(!$uid) wp_send_json_error();
    update_user_meta($uid, 'gw_active', '1');
    wp_send_json_success();
  });
  add_action('wp_ajax_gw_abs_ocultar', function(){
    if (!current_user_can('manage_options') && !current_user_can('coordinador_pais') && !current_user_can('coach')) wp_send_json_error();
    check_ajax_referer('gw_abs_admin','nonce');
    global $wpdb; $table = $wpdb->prefix.'gw_ausencias';
    $id = intval($_POST['id'] ?? 0); if(!$id) wp_send_json_error();
    $wpdb->update($table, ['hidden'=>1, 'updated_at'=>current_time('mysql')], ['id'=>$id]);
    wp_send_json_success();
  });




// HANDLER ÚNICO - NO DUPLICAR
add_action('wp_ajax_gw_obtener_coaches_por_pais', function() {
    $pais = sanitize_text_field($_GET['pais'] ?? '');
    if (empty($pais)) {
        wp_send_json_error(['msg' => 'País no especificado']);
    }
    
    $usuarios = get_users([
        'role__in' => ['administrator', 'editor', 'coordinador_pais', 'coach'],
        'orderby' => 'display_name',
        'order' => 'ASC'
    ]);
    
    $resultado = [];
    foreach($usuarios as $usuario) {
        $pais_usuario = get_user_meta($usuario->ID, 'pais', true);
        $es_admin = user_can($usuario->ID, 'manage_options');
        $es_coach = array_intersect(['coach', 'coordinador_pais', 'editor'], $usuario->roles);
        
        if ($es_admin || ($es_coach && $pais_usuario === $pais)) {
            $resultado[] = [
                'id' => $usuario->ID,
                'name' => $usuario->display_name ?: $usuario->user_login,
                'tipo' => $es_admin ? 'Administrador' : 'Coach'
            ];
        }
    }
    
    wp_send_json_success($resultado);
});

// AJAX handler para eliminar charla
add_action('wp_ajax_gw_eliminar_charla', function() {
    if (!current_user_can('manage_options')) wp_send_json_error();
    
    $charla_id = intval($_POST['charla_id'] ?? 0);
    if (!$charla_id) wp_send_json_error(['msg' => 'ID inválido']);
    
    // Marcar como eliminada en lugar de borrar completamente
    update_post_meta($charla_id, '_gw_eliminada', true);
    update_post_meta($charla_id, '_gw_eliminada_fecha', current_time('mysql'));
    update_post_meta($charla_id, '_gw_eliminada_por', get_current_user_id());
    
    wp_send_json_success(['msg' => 'Charla eliminada correctamente']);
});

// AJAX handler para contar charlas eliminadas
add_action('wp_ajax_gw_contar_eliminadas', function() {
    if (!current_user_can('manage_options')) wp_send_json_error();
    
    $eliminadas = get_posts([
        'post_type' => 'charla',
        'numberposts' => -1,
        'meta_query' => [
            [
                'key' => '_gw_eliminada',
                'value' => true,
                'compare' => '='
            ]
        ]
    ]);
    
    wp_send_json_success(['count' => count($eliminadas)]);
});

// AJAX handler para obtener charlas eliminadas
add_action('wp_ajax_gw_obtener_eliminadas', function() {
    if (!current_user_can('manage_options')) wp_send_json_error();
    
    $eliminadas = get_posts([
        'post_type' => 'charla',
        'numberposts' => -1,
        'meta_query' => [
            [
                'key' => '_gw_eliminada',
                'value' => true,
                'compare' => '='
            ]
        ],
        'orderby' => 'meta_value',
        'meta_key' => '_gw_eliminada_fecha',
        'order' => 'DESC'
    ]);
    
    ob_start();
    if (empty($eliminadas)) {
        echo '<p style="color:#666;">No hay charlas eliminadas.</p>';
    } else {
        foreach ($eliminadas as $charla) {
            $fecha_eliminacion = get_post_meta($charla->ID, '_gw_eliminada_fecha', true);
            $eliminada_por_id = get_post_meta($charla->ID, '_gw_eliminada_por', true);
            $usuario = get_user_by('id', $eliminada_por_id);
            $nombre_usuario = $usuario ? $usuario->display_name : 'Administrador';
            
            echo '<div style="border:1px solid #dc3545;padding:12px;margin-bottom:12px;border-radius:8px;background:#fff5f5;">';
            echo '<div style="display:flex;justify-content:space-between;align-items:center;">';
            echo '<h4 style="margin:0;color:#dc3545;">🗑️ ' . esc_html($charla->post_title) . '</h4>';
            echo '<div style="display:flex;gap:8px;">';
            echo '<button type="button" class="gw-restaurar-charla button button-small" data-charla-id="'.$charla->ID.'" style="background:#28a745;border-color:#28a745;color:white;">Restaurar</button>';
            echo '<button type="button" class="gw-eliminar-definitivo button button-small" data-charla-id="'.$charla->ID.'" style="background:#dc3545;border-color:#dc3545;color:white;">Eliminar definitivo</button>';
            echo '</div>';
            echo '</div>';
            echo '<p style="margin:8px 0 0 0;font-size:12px;color:#666;">';
            echo 'Eliminada el ' . date('d/m/Y H:i', strtotime($fecha_eliminacion)) . ' por ' . esc_html($nombre_usuario);
            echo '</p>';
            echo '</div>';
        }
    }
    $html = ob_get_clean();
    
    wp_send_json_success(['html' => $html]);
});

// AJAX handler para restaurar charla
add_action('wp_ajax_gw_restaurar_charla', function() {
    if (!current_user_can('manage_options')) wp_send_json_error();
    
    $charla_id = intval($_POST['charla_id'] ?? 0);
    if (!$charla_id) wp_send_json_error(['msg' => 'ID inválido']);
    
    // Remover marcas de eliminación
    delete_post_meta($charla_id, '_gw_eliminada');
    delete_post_meta($charla_id, '_gw_eliminada_fecha');
    delete_post_meta($charla_id, '_gw_eliminada_por');
    
    wp_send_json_success(['msg' => 'Charla restaurada correctamente']);
});

// AJAX handler para eliminar definitivamente charla
add_action('wp_ajax_gw_eliminar_definitivo', function() {
    if (!current_user_can('manage_options')) wp_send_json_error();
    
    $charla_id = intval($_POST['charla_id'] ?? 0);
    if (!$charla_id) wp_send_json_error(['msg' => 'ID inválido']);
    
    // Eliminar definitivamente
    wp_delete_post($charla_id, true);
    
    wp_send_json_success(['msg' => 'Charla eliminada definitivamente']);
});

// Modificar el handler original de agregar charla para que devuelva HTML actualizado
add_action('wp_ajax_gw_agregar_charla', function() {
    if (!current_user_can('manage_options')) wp_send_json_error();
    
    $titulo = sanitize_text_field($_POST['titulo'] ?? '');
    if (empty($titulo)) wp_send_json_error(['msg' => 'Título requerido']);
    
    $charla_id = wp_insert_post([
        'post_type' => 'charla',
        'post_title' => $titulo,
        'post_status' => 'publish'
    ]);
    
    if (is_wp_error($charla_id)) {
        wp_send_json_error(['msg' => 'Error al crear la charla']);
    }
    
    // Devolver HTML actualizado
    ob_start();
    
    // Función para renderizar una charla individual (repetida para el AJAX)
    function gw_render_charla_individual_ajax($charla) {
        $sesiones = get_post_meta($charla->ID, '_gw_sesiones', true);
        if (!is_array($sesiones)) $sesiones = [];
        if (empty($sesiones)) $sesiones = [[]];
        
        // Determinar modalidades presentes
        $modalidades = [];
        $lugares = [];
        foreach ($sesiones as $sesion) {
            if (isset($sesion['modalidad'])) {
                $modalidades[] = $sesion['modalidad'];
            }
            if (isset($sesion['lugar']) && !empty($sesion['lugar'])) {
                $lugares[] = $sesion['lugar'];
            }
        }
        $modalidades = array_unique($modalidades);
        $lugares = array_unique($lugares);
        
        echo '<div class="gw-charla-item" data-modalidades="'.implode(',', $modalidades).'" data-lugares="'.implode(',', $lugares).'" data-nombre="'.esc_attr(strtolower($charla->post_title)).'" style="border:1px solid #c8d6e5;padding:18px;border-radius:9px;margin-bottom:20px;background:#fafdff;position:relative;">';
        
        // Botón eliminar
        echo '<button type="button" class="gw-eliminar-charla" data-charla-id="'.$charla->ID.'" style="position:absolute;top:12px;right:12px;background:#dc3545;color:white;border:none;border-radius:50%;width:30px;height:30px;cursor:pointer;font-size:14px;" title="Eliminar charla">🗑️</button>';
        
        echo '<h3 style="margin:0 0 12px 0;padding-right:40px;">' . esc_html($charla->post_title) . '</h3>';
        
        // Mostrar tags de modalidades y lugares
        if (!empty($modalidades)) {
            echo '<div style="margin-bottom:8px;">';
            foreach ($modalidades as $mod) {
                $color = $mod === 'Virtual' ? '#007cba' : '#46b450';
                echo '<span style="background:'.$color.';color:white;padding:2px 8px;border-radius:12px;font-size:11px;margin-right:6px;">'.$mod.'</span>';
            }
            echo '</div>';
        }
        
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
    
    $charlas = get_posts([
        'post_type' => 'charla',
        'numberposts' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
        'meta_query' => [
            [
                'key' => '_gw_eliminada',
                'compare' => 'NOT EXISTS'
            ]
        ]
    ]);
    
    if (empty($charlas)) {
        echo '<p>No hay charlas registradas aún.</p>';
    } else {
        foreach ($charlas as $index => $charla) {
            $display_style = $index >= 5 ? 'style="display:none;"' : '';
            echo '<div class="gw-charla-wrapper" '.$display_style.'>';
            gw_render_charla_individual_ajax($charla);
            echo '</div>';
        }
        
        // Botón ver más si hay más de 5 charlas
        if (count($charlas) > 5) {
            echo '<div id="gw-ver-mas-container" style="text-align:center;margin-top:20px;">';
            echo '<button type="button" id="gw-ver-mas-charlas" class="button button-secondary">Ver más charlas (' . (count($charlas) - 5) . ' restantes)</button>';
            echo '</div>';
        }
    }
    
    $html = ob_get_clean();
    
    wp_send_json_success(['html' => $html]);
});

// Actualizar el handler original de guardar sesiones para que también maneje la paginación
add_action('wp_ajax_gw_guardar_sesiones_charla', function() {
    if (!current_user_can('manage_options')) wp_send_json_error();
    $charla_id = intval($_POST['charla_id'] ?? 0);
    if (!$charla_id) wp_send_json_error(['msg'=>'ID inválido']);
    
    // Verificar nonce
    $nonce_field = 'gw_sesiones_charla_nonce';
    if (!isset($_POST[$nonce_field]) || !wp_verify_nonce($_POST[$nonce_field], 'gw_sesiones_charla_'.$charla_id)) {
        wp_send_json_error(['msg'=>'Nonce inválido']);
    }
    
    // Procesar sesiones (código original mantenido)
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
    
    // Solo devolver HTML actualizado si es necesario (mantener comportamiento original)
    wp_send_json_success(['msg' => 'Sesiones guardadas correctamente']);
});











// ==============================================
// FUNCIONES PHP AJAX PARA GESTIÓN DE PROYECTOS
// ==============================================

// ---- LISTAR PAÍSES (ID + nombre) desde CPT 'pais' ----
remove_all_actions('wp_ajax_gw_listar_paises_codigos');
add_action('wp_ajax_gw_listar_paises_codigos', function () {
    if ( ! current_user_can('read') ) wp_send_json_error(['msg' => 'No autorizado']);

    $paises = get_posts([
        'post_type'   => 'pais',
        'post_status' => 'publish',
        'numberposts' => -1,
        'orderby'     => 'title',
        'order'       => 'ASC',
    ]);

    $out = array_map(function($p){
        return ['id' => (int)$p->ID, 'nombre' => $p->post_title];
    }, $paises);

    wp_send_json_success($out);
});

// ---- OBTENER COACHES (sin filtro) ----
remove_all_actions('wp_ajax_gw_obtener_coaches');
add_action('wp_ajax_gw_obtener_coaches', function(){
    if ( ! current_user_can('read') ) wp_send_json_error(['msg' => 'No autorizado']);

    $coaches = get_users([
        'role__in' => ['administrator', 'editor', 'coordinador_pais', 'coach'],
        'orderby'  => 'display_name',
        'order'    => 'ASC',
        'fields'   => 'all',
    ]);

    $data = array_map(function($user) {
        // Nombre de país (si existe)
        $pais_nombre = get_user_meta($user->ID, 'pais', true);
        if (!$pais_nombre) {
            // Si no hay nombre, intenta resolver por ID
            $pais_id = get_user_meta($user->ID, 'gw_pais_id', true);
            if (is_numeric($pais_id) && $pais_id) {
                $post = get_post((int)$pais_id);
                if ($post && $post->post_type === 'pais') {
                    $pais_nombre = $post->post_title;
                }
            }
        }
        $pais_nombre = $pais_nombre ?: 'Sin especificar';

        return [
            'id'   => (int)$user->ID,
            'name' => $user->display_name ?: $user->user_login,
            'pais' => $pais_nombre
        ];
    }, $coaches);

    wp_send_json_success($data);
});

// ---- OBTENER COACHES POR pais_id (match por usermeta gw_pais_id) ----
remove_all_actions('wp_ajax_gw_obtener_coaches_por_pais');
add_action('wp_ajax_gw_obtener_coaches_por_pais', function () {
    if ( ! current_user_can('read') ) wp_send_json_error(['msg' => 'No autorizado']);

    $pais_id = isset($_GET['pais_id']) ? intval($_GET['pais_id']) : 0;
    if (!$pais_id) {
        wp_send_json_error(['msg' => 'pais_id requerido']);
    }

    // Solo roles relevantes (coach / coordinador_pais). Quita/añade según tu flujo.
    $q = new WP_User_Query([
        'role__in'   => ['coach','coordinador_pais'],
        'orderby'    => 'display_name',
        'order'      => 'ASC',
        'fields'     => ['ID','display_name','user_login'],
        'meta_query' => [
            [
                'key'     => 'gw_pais_id',
                'value'   => $pais_id,
                'compare' => '='
            ]
        ],
    ]);
    $users = $q->get_results();

    $out = [];
    foreach ($users as $u) {
        $out[] = [
            'id'   => (int)$u->ID,
            'name' => $u->display_name ?: $u->user_login,
            'tipo' => 'Coach',
        ];
    }

    // (Opcional) Si quieres incluir admin siempre, descomenta:
    /*
    $admin_q = new WP_User_Query([
        'role'   => 'administrator',
        'fields' => ['ID','display_name','user_login'],
        'number' => 1
    ]);
    $admins = $admin_q->get_results();
    if (!empty($admins)) {
        $a = $admins[0];
        array_unshift($out, [
            'id'   => (int)$a->ID,
            'name' => $a->display_name ?: $a->user_login,
            'tipo' => 'Administrador',
        ]);
    }
    */

    wp_send_json_success($out);
});

// ---- CREAR PROYECTO ----
add_action('wp_ajax_gw_nuevo_proyecto', function(){
    if (!current_user_can('manage_options') && !current_user_can('coordinador_pais')) {
        wp_send_json_error(['msg' => 'No tienes permisos']);
    }

    $titulo      = sanitize_text_field($_POST['titulo'] ?? '');
    $pais_nombre = sanitize_text_field($_POST['pais'] ?? ''); // seguimos guardando el NOMBRE del país para tu listado
    $coach       = intval($_POST['coach'] ?? 0);
    $descripcion = sanitize_textarea_field($_POST['descripcion'] ?? '');

    if (!$titulo || !$pais_nombre || !$coach) {
        wp_send_json_error(['msg' => 'Faltan campos obligatorios']);
    }

    $coach_user = get_user_by('ID', $coach);
    if (!$coach_user) {
        wp_send_json_error(['msg' => 'Coach no válido']);
    }

    $proyecto_id = wp_insert_post([
        'post_title'   => $titulo,
        'post_type'    => 'proyecto',
        'post_status'  => 'publish',
        'post_content' => $descripcion,
        'meta_input'   => [
            '_gw_proyecto_pais'           => $pais_nombre, // nombre (tu listado actual usa texto)
            '_gw_proyecto_coach'          => $coach,
            '_gw_proyecto_estado'         => 'activo',
            '_gw_proyecto_fecha_creacion' => current_time('mysql'),
            '_gw_proyecto_creado_por'     => get_current_user_id()
        ]
    ]);

    if (!$proyecto_id) {
        wp_send_json_error(['msg' => 'Error al guardar en base de datos']);
    }

    gw_registrar_historial_proyecto($proyecto_id, 'creado', 'Proyecto creado');
    wp_send_json_success(['msg' => 'Proyecto creado exitosamente']);
});

// ---- LISTAR PROYECTOS (con filtros) ----
add_action('wp_ajax_gw_listar_proyectos', function(){
    $pais     = sanitize_text_field($_GET['pais'] ?? '');
    $busqueda = sanitize_text_field($_GET['busqueda'] ?? '');
    $estado   = sanitize_text_field($_GET['estado'] ?? 'activo');

    $args = [
        'post_type'   => 'proyecto',
        'numberposts' => -1,
        'orderby'     => 'title',
        'order'       => 'ASC',
        'post_status' => 'publish'
    ];

    if ($busqueda) {
        $args['s'] = $busqueda;
    }

    $proyectos = get_posts($args);

    $proyectos_filtrados = [];
    foreach ($proyectos as $proyecto) {
        $pais_proyecto   = get_post_meta($proyecto->ID, '_gw_proyecto_pais', true);
        $estado_proyecto = get_post_meta($proyecto->ID, '_gw_proyecto_estado', true);

        if (empty($estado_proyecto)) {
            $estado_proyecto = 'activo';
            update_post_meta($proyecto->ID, '_gw_proyecto_estado', 'activo');
            update_post_meta($proyecto->ID, '_gw_proyecto_fecha_creacion', $proyecto->post_date);
            update_post_meta($proyecto->ID, '_gw_proyecto_creado_por', 1);
        }

        if (empty($pais_proyecto)) {
            $pais_proyecto = 'Sin especificar';
            update_post_meta($proyecto->ID, '_gw_proyecto_pais', 'Sin especificar');
        }

        $incluir = true;
        if ($pais && $pais_proyecto !== $pais) {
            $incluir = false;
        }
        if ($estado && $estado_proyecto !== $estado) {
            $incluir = false;
        }
        if ($incluir) {
            $proyectos_filtrados[] = $proyecto;
        }
    }

    ob_start();
    if (empty($proyectos_filtrados)) {
        echo '<p>No se encontraron proyectos con los filtros aplicados.</p>';
    } else {
        foreach ($proyectos_filtrados as $proyecto) {
            $pais_proyecto   = get_post_meta($proyecto->ID, '_gw_proyecto_pais', true) ?: 'Sin especificar';
            $coach_id        = get_post_meta($proyecto->ID, '_gw_proyecto_coach', true);
            $estado_proyecto = get_post_meta($proyecto->ID, '_gw_proyecto_estado', true) ?: 'activo';
            $fecha_creacion  = get_post_meta($proyecto->ID, '_gw_proyecto_fecha_creacion', true) ?: $proyecto->post_date;

            $coach_name = 'Sin asignar';
            if ($coach_id) {
                $coach = get_user_by('ID', $coach_id);
                $coach_name = $coach ? $coach->display_name : 'Sin asignar';
            }

            $clase_css = $estado_proyecto === 'eliminado' ? 'gw-proyecto-item gw-proyecto-eliminado' : 'gw-proyecto-item';

            echo '<div class="' . $clase_css . '">';
            echo '<div class="gw-proyecto-header">';
            echo '<div>';
            echo '<h4 style="margin:0;">' . esc_html($proyecto->post_title) . '</h4>';
            echo '<div style="color:#666;font-size:14px;margin-top:5px;">';
            echo '<strong>País:</strong> ' . esc_html($pais_proyecto) . ' | ';
            echo '<strong>Coach:</strong> ' . esc_html($coach_name) . ' | ';
            echo '<strong>Creado:</strong> ' . date('d/m/Y', strtotime($fecha_creacion));
            if ($estado_proyecto === 'eliminado') {
                echo ' | <span style="color:#f44336;"><strong>ELIMINADO</strong></span>';
            }
            echo '</div>';
            echo '</div>';

            if (current_user_can('manage_options') || current_user_can('coordinador_pais')) {
                echo '<div class="gw-proyecto-acciones">';
                if ($estado_proyecto === 'activo') {
                    $descripcion = $proyecto->post_content ?: '';
                    echo '<button class="gw-btn-editar" onclick="gwEditarProyecto(' . $proyecto->ID . ', \'' . esc_js($proyecto->post_title) . '\', \'' . esc_js($pais_proyecto) . '\', ' . $coach_id . ', \'' . esc_js($descripcion) . '\')">Editar</button>';
                    echo '<button class="gw-btn-wp" onclick="window.open(\'' . admin_url('post.php?post=' . $proyecto->ID . '&action=edit') . '\', \'_blank\')">WP</button>';
                    echo '<button class="gw-btn-eliminar" onclick="gwEliminarProyecto(' . $proyecto->ID . ')">Eliminar</button>';
                } else {
                    echo '<button class="gw-btn-restaurar" onclick="gwRestaurarProyecto(' . $proyecto->ID . ')">Restaurar</button>';
                }
                echo '<button class="gw-btn-historial" onclick="gwVerHistorial(' . $proyecto->ID . ')">Historial</button>';
                echo '</div>';
            }

            echo '</div>';

            if ($proyecto->post_content) {
                echo '<div style="margin-top:10px;color:#666;">';
                echo '<strong>Descripción:</strong> ' . esc_html($proyecto->post_content);
                echo '</div>';
            }

            echo '</div>';
        }
    }
    $html = ob_get_clean();

    wp_send_json_success(['html' => $html]);
});

// ---- OBTENER PAÍSES (para el filtro de listado de proyectos) ----
add_action('wp_ajax_gw_obtener_paises_proyectos', function(){
    global $wpdb;

    $proyectos_sin_pais = $wpdb->get_results($wpdb->prepare("
        SELECT p.ID 
        FROM {$wpdb->posts} p 
        LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_gw_proyecto_pais'
        WHERE p.post_type = 'proyecto' 
        AND p.post_status = 'publish'
        AND pm.meta_value IS NULL
    "));
    foreach ($proyectos_sin_pais as $proyecto) {
        update_post_meta($proyecto->ID, '_gw_proyecto_pais', 'Sin especificar');
    }

    $paises = $wpdb->get_col($wpdb->prepare("
        SELECT DISTINCT pm.meta_value 
        FROM {$wpdb->postmeta} pm 
        INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id 
        WHERE pm.meta_key = '_gw_proyecto_pais' 
        AND p.post_type = 'proyecto' 
        AND p.post_status = 'publish'
        ORDER BY pm.meta_value ASC
    "));

    wp_send_json_success($paises);
});

// ---- ELIMINAR / RESTAURAR / EDITAR PROYECTO ----
add_action('wp_ajax_gw_eliminar_proyecto', function(){
    if (!current_user_can('manage_options') && !current_user_can('coordinador_pais')) {
        wp_send_json_error(['msg' => 'No tienes permisos']);
    }
    $proyecto_id = intval($_POST['proyecto_id'] ?? 0);
    if (!$proyecto_id) wp_send_json_error(['msg' => 'ID no válido']);

    $result = update_post_meta($proyecto_id, '_gw_proyecto_estado', 'eliminado');
    if ($result !== false) {
        gw_registrar_historial_proyecto($proyecto_id, 'eliminado', 'Proyecto eliminado por usuario');
        wp_send_json_success();
    } else {
        wp_send_json_error(['msg' => 'Error al actualizar']);
    }
});

add_action('wp_ajax_gw_restaurar_proyecto', function(){
    if (!current_user_can('manage_options') && !current_user_can('coordinador_pais')) {
        wp_send_json_error(['msg' => 'No tienes permisos']);
    }
    $proyecto_id = intval($_POST['proyecto_id'] ?? 0);
    if (!$proyecto_id) wp_send_json_error(['msg' => 'ID no válido']);

    $result = update_post_meta($proyecto_id, '_gw_proyecto_estado', 'activo');
    if ($result !== false) {
        gw_registrar_historial_proyecto($proyecto_id, 'restaurado', 'Proyecto restaurado por usuario');
        wp_send_json_success();
    } else {
        wp_send_json_error(['msg' => 'Error al actualizar']);
    }
});

add_action('wp_ajax_gw_editar_proyecto', function(){
    if (!current_user_can('manage_options') && !current_user_can('coordinador_pais')) {
        wp_send_json_error(['msg' => 'No tienes permisos']);
    }

    $proyecto_id = intval($_POST['proyecto_id'] ?? 0);
    $titulo      = sanitize_text_field($_POST['titulo'] ?? '');
    $pais_nombre = sanitize_text_field($_POST['pais'] ?? '');
    $coach       = intval($_POST['coach'] ?? 0);
    $descripcion = sanitize_textarea_field($_POST['descripcion'] ?? '');

    if (!$proyecto_id || !$titulo) {
        wp_send_json_error(['msg' => 'Faltan datos obligatorios']);
    }

    $result = wp_update_post([
        'ID'           => $proyecto_id,
        'post_title'   => $titulo,
        'post_content' => $descripcion
    ]);
    if (is_wp_error($result)) {
        wp_send_json_error(['msg' => 'Error al actualizar proyecto']);
    }

    if ($pais_nombre) {
        update_post_meta($proyecto_id, '_gw_proyecto_pais', $pais_nombre);
    }
    if ($coach) {
        $coach_user = get_user_by('ID', $coach);
        if (!$coach_user) {
            wp_send_json_error(['msg' => 'Coach no válido']);
        }
        update_post_meta($proyecto_id, '_gw_proyecto_coach', $coach);
    }

    gw_registrar_historial_proyecto($proyecto_id, 'editado', 'Proyecto editado por usuario');
    wp_send_json_success(['msg' => 'Proyecto actualizado exitosamente']);
});

// ---- Historial de proyectos (helper) ----
function gw_registrar_historial_proyecto($proyecto_id, $accion, $descripcion = '') {
    global $wpdb;
    $tabla_historial = $wpdb->prefix . 'gw_historial_proyectos';

    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS {$tabla_historial} (
        id int(11) NOT NULL AUTO_INCREMENT,
        proyecto_id int(11) NOT NULL,
        usuario_id int(11) NOT NULL,
        accion varchar(50) NOT NULL,
        descripcion text,
        fecha datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY proyecto_id (proyecto_id),
        KEY usuario_id (usuario_id)
    ) {$charset_collate};";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    $wpdb->insert($tabla_historial, [
        'proyecto_id' => $proyecto_id,
        'usuario_id'  => get_current_user_id(),
        'accion'      => $accion,
        'descripcion' => $descripcion,
        'fecha'       => current_time('mysql')
    ]);
}

// ---- Registrar CPT 'proyecto' ----
add_action('init', function() {
    if (!post_type_exists('proyecto')) {
        register_post_type('proyecto', [
            'labels' => [
                'name'               => 'Proyectos',
                'singular_name'      => 'Proyecto',
                'add_new'            => 'Agregar Proyecto',
                'add_new_item'       => 'Agregar Nuevo Proyecto',
                'edit_item'          => 'Editar Proyecto',
                'new_item'           => 'Nuevo Proyecto',
                'view_item'          => 'Ver Proyecto',
                'search_items'       => 'Buscar Proyectos',
                'not_found'          => 'No se encontraron proyectos',
                'not_found_in_trash' => 'No hay proyectos en la papelera'
            ],
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => false,
            'supports'     => ['title', 'editor'],
            'menu_icon'    => 'dashicons-portfolio',
            'has_archive'  => false,
            'rewrite'      => false
        ]);
    }
});

// ---- Migración automática (una sola vez) ----
add_action('wp_loaded', function() {
    $migrado = get_option('gw_proyectos_migrados', false);
    if (!$migrado) {
        gw_migrar_proyectos_existentes();
        update_option('gw_proyectos_migrados', true);
    }
});

function gw_migrar_proyectos_existentes() {
    $proyectos = get_posts([
        'post_type'   => 'proyecto',
        'numberposts' => -1,
        'post_status' => 'publish'
    ]);

    $migrados = 0;
    foreach ($proyectos as $proyecto) {
        $necesita = false;

        $estado = get_post_meta($proyecto->ID, '_gw_proyecto_estado', true);
        if (empty($estado)) {
            update_post_meta($proyecto->ID, '_gw_proyecto_estado', 'activo');
            $necesita = true;
        }
        $fecha_creacion = get_post_meta($proyecto->ID, '_gw_proyecto_fecha_creacion', true);
        if (empty($fecha_creacion)) {
            update_post_meta($proyecto->ID, '_gw_proyecto_fecha_creacion', $proyecto->post_date);
            $necesita = true;
        }
        $creado_por = get_post_meta($proyecto->ID, '_gw_proyecto_creado_por', true);
        if (empty($creado_por)) {
            update_post_meta($proyecto->ID, '_gw_proyecto_creado_por', 1);
            $necesita = true;
        }
        $pais = get_post_meta($proyecto->ID, '_gw_proyecto_pais', true);
        if (empty($pais)) {
            update_post_meta($proyecto->ID, '_gw_proyecto_pais', 'Sin especificar');
            $necesita = true;
        }

        if ($necesita) {
            $migrados++;
            gw_registrar_historial_proyecto($proyecto->ID, 'migrado', 'Proyecto migrado al nuevo sistema');
        }
    }

    return $migrados;
}

// ---- Debug usuarios (opcional) ----
add_action('wp_ajax_gw_debug_usuarios', function(){
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['msg' => 'No tienes permisos']);
    }
    $todos_usuarios = get_users(['orderby' => 'display_name', 'order' => 'ASC']);
    $debug_info = [];

    foreach($todos_usuarios as $usuario) {
        $pais = get_user_meta($usuario->ID, 'pais', true);
        $debug_info[] = [
            'id'    => $usuario->ID,
            'nombre'=> $usuario->display_name ?: $usuario->user_login,
            'email' => $usuario->user_email,
            'pais'  => $pais ?: '(no asignado)',
            'roles' => implode(', ', $usuario->roles)
        ];
    }
    wp_send_json_success($debug_info);
});





















// ======================================================
// Helpers comunes
// ======================================================
if ( ! function_exists('gw_norm') ) {
    function gw_norm($s){
      $s = wp_strip_all_tags((string)$s);
      $s = trim(preg_replace('/\s+/u',' ', $s));
      $s = remove_accents($s);
      return mb_strtolower($s, 'UTF-8');
    }
  }
  if ( ! function_exists('gw_find_pais_id_by_name') ) {
    function gw_find_pais_id_by_name($nombre){
      if (!$nombre) return 0;
  
      // 1) exacto por título
      $p = get_page_by_title($nombre, OBJECT, 'pais');
      if ($p) return (int)$p->ID;
  
      // 2) normalizado
      $norm = gw_norm($nombre);
      $todos = get_posts([
        'post_type'   => 'pais',
        'post_status' => 'publish',
        'numberposts' => -1,
        'fields'      => 'ids'
      ]);
      foreach ($todos as $pid){
        $t = get_the_title($pid);
        if ($t && gw_norm($t) === $norm) return (int)$pid;
      }
      return 0;
    }
  }
  
  // Chequeo global (permisos + nonce) y utilidades de render
  if ( ! function_exists('gw_caps_check') ) {
    function gw_caps_check($require_id = false, $id_key = 'id') {
      if ( ! current_user_can('manage_options') ) {
        wp_send_json_error(['msg'=>'Sin permisos'], 403);
      }
      if ( ! isset($_REQUEST['security']) || ! wp_verify_nonce($_REQUEST['security'], 'gw_caps') ) {
        wp_send_json_error(['msg'=>'Solicitud no verificada (nonce)'], 400);
      }
      if ( $require_id ) {
        $id = intval($_REQUEST[$id_key] ?? 0);
        if ( ! $id || get_post_type($id) !== 'capacitacion' ) {
          wp_send_json_error(['msg'=>'ID inválido'], 400);
        }
        return $id;
      }
      return true;
    }
  }
  
  if ( ! function_exists('gw_caps_render_list_html') ) {
    function gw_caps_render_list_html( $status = 'publish' ) {
      $caps = get_posts([
        'post_type'   => 'capacitacion',
        'post_status' => $status,
        'numberposts' => -1,
        'orderby'     => 'title',
        'order'       => 'ASC',
      ]);
      if ( ! $caps ) {
        return '<p>No hay capacitaciones registradas.</p>';
      }
      ob_start();
      foreach ($caps as $cap) {
        $pais_id   = (int) get_post_meta($cap->ID, '_gw_pais_relacionado', true);
        $proy_id   = (int) get_post_meta($cap->ID, '_gw_proyecto_relacionado', true);
        $coach_id  = (int) get_post_meta($cap->ID, '_gw_coach_asignado', true);
        $sesiones  = get_post_meta($cap->ID, '_gw_sesiones', true);
  
        $pais_t    = $pais_id  ? get_the_title($pais_id) : '-';
        $proy_t    = $proy_id  ? get_the_title($proy_id) : '-';
        $coach_t   = $coach_id ? (get_userdata($coach_id)->display_name ?? '-') : '-';
        $num       = is_array($sesiones) ? count($sesiones) : 0;
  
        $modalidad = 'N/A';
        if ( is_array($sesiones) && $sesiones ) {
          $mods = array_unique(array_map(function($s){ return $s['modalidad'] ?? ''; }, $sesiones));
          $modalidad = count($mods) > 1 ? 'Mixta' : ($mods[0] ?: 'N/A');
        }
        ?>
        <div class="gw-capacitacion-card" data-pais="<?php echo esc_attr($pais_id); ?>" data-coach="<?php echo esc_attr($coach_id); ?>" data-modalidad="<?php echo esc_attr(strtolower($modalidad)); ?>">
          <div class="gw-capacitacion-title"><?php echo esc_html($cap->post_title); ?></div>
          <div class="gw-capacitacion-meta">
            <span><strong>País:</strong> <?php echo esc_html($pais_t); ?></span>
            <span><strong>Proyecto:</strong> <?php echo esc_html($proy_t); ?></span>
            <span><strong>Coach:</strong> <?php echo esc_html($coach_t); ?></span>
            <span><strong>Sesiones:</strong> <?php echo esc_html($num); ?> (<?php echo esc_html($modalidad); ?>)</span>
          </div>
          <div class="gw-capacitacion-actions">
            <span class="gw-cap-edit" data-id="<?php echo esc_attr($cap->ID); ?>">Editar</span>
            <span class="gw-cap-delete" data-id="<?php echo esc_attr($cap->ID); ?>">Eliminar</span>
          </div>
        </div>
        <?php
      }
      return ob_get_clean();
    }
  }
  
  // ======================================================
  // ENDPOINTS AJAX (TODOS con nonce/permisos)
  // ======================================================
  
  // --- Proyectos por país ---
  add_action('wp_ajax_gw_obtener_proyectos_por_pais', function(){
    gw_caps_check();
  
    $pais_id = intval($_GET['pais_id'] ?? 0);
    if(!$pais_id) {
      wp_send_json_error(['msg'=>'ID de país inválido'], 400);
    }
  
    $pais_title = get_the_title($pais_id); // "Perú"
    $vals_match = array_unique(array_filter([
      $pais_title,
      remove_accents($pais_title),
      mb_strtolower($pais_title,'UTF-8'),
      mb_strtolower(remove_accents($pais_title),'UTF-8'),
    ]));
  
    $proyectos = get_posts([
      'post_type'   => 'proyecto',
      'numberposts' => -1,
      'orderby'     => 'title',
      'order'       => 'ASC',
      'meta_query'  => [
        'relation' => 'OR',
        [
          'key'     => '_gw_pais_relacionado',
          'value'   => $pais_id,
          'compare' => '='
        ],
        [
          'key'     => '_gw_proyecto_pais',
          'value'   => $vals_match,
          'compare' => 'IN'
        ],
      ],
    ]);
  
    $data = [];
    foreach($proyectos as $p){
      $ok = false;
      $m_id = get_post_meta($p->ID, '_gw_pais_relacionado', true);
      if ($m_id && intval($m_id) === $pais_id) $ok = true;
  
      if (!$ok){
        $m_str = get_post_meta($p->ID, '_gw_proyecto_pais', true);
        if ($m_str){
          $ok = in_array(gw_norm($m_str), array_map('gw_norm', $vals_match), true);
        }
      }
      if ($ok){
        $data[] = ['id'=>$p->ID, 'titulo'=>$p->post_title];
      }
    }
  
    wp_send_json_success($data);
  });
  
  // --- Coaches por proyecto ---
  add_action('wp_ajax_gw_obtener_coaches_por_proyecto', function(){
    gw_caps_check();
  
    $proyecto_id = intval($_GET['proyecto_id'] ?? 0);
    if(!$proyecto_id) {
      wp_send_json_error(['msg'=>'ID de proyecto inválido'], 400);
    }
  
    $pais_id = intval(get_post_meta($proyecto_id, '_gw_pais_relacionado', true));
    if (!$pais_id){
      $pais_str = get_post_meta($proyecto_id, '_gw_proyecto_pais', true);
      if ($pais_str){
        $pais_id = gw_find_pais_id_by_name($pais_str);
      }
    }
    if (!$pais_id){
      wp_send_json_success([]); // sin país -> sin coaches
    }
  
    // Admin fallback (opcional)
    $admins = (new WP_User_Query([
      'role'   => 'administrator',
      'number' => 1,
      'fields' => ['ID','display_name','user_login']
    ]))->get_results();
  
    // Coaches por país
    $users = (new WP_User_Query([
      'role__in'   => ['coach','coordinador_pais','editor','administrator'],
      'orderby'    => 'display_name',
      'order'      => 'ASC',
      'fields'     => ['ID','display_name','user_login'],
      'meta_query' => [[
        'key'     => 'gw_pais_id',
        'value'   => $pais_id,
        'compare' => '=',
        'type'    => 'NUMERIC'
      ]],
    ]))->get_results();
  
    $out = [];
    if (!empty($admins)) {
      $a = $admins[0];
      $out[] = ['id'=>(int)$a->ID, 'nombre'=> $a->display_name ?: $a->user_login];
    }
    foreach($users as $u){
      if (!empty($admins) && $u->ID === $admins[0]->ID) continue;
      $out[] = ['id'=>(int)$u->ID, 'nombre'=> $u->display_name ?: $u->user_login];
    }
  
    wp_send_json_success($out);
  });
  
  // --- Guardar (crear/editar) ---
  add_action('wp_ajax_gw_guardar_capacitacion_wizard', function(){
    gw_caps_check();
  
    $edit_id = intval($_POST['edit_id'] ?? 0);
    $titulo  = sanitize_text_field($_POST['titulo'] ?? '');
    $pais    = intval($_POST['pais'] ?? 0);
    $proy    = intval($_POST['proyecto'] ?? 0);
    $coach   = intval($_POST['coach'] ?? 0);
  
    if ( ! $titulo || ! $pais || ! $proy || ! $coach ) {
      wp_send_json_error(['msg'=>'Campos obligatorios faltantes'], 400);
    }
  
    // Sesiones
    $mods  = $_POST['sesion_modalidad'] ?? [];
    $fechs = $_POST['sesion_fecha'] ?? [];
    $horas = $_POST['sesion_hora'] ?? [];
    $lug   = $_POST['sesion_lugar'] ?? [];
    $lnk   = $_POST['sesion_link'] ?? [];
  
    $sesiones = [];
    if ( is_array($mods) ) {
      $n = count($mods);
      for ($i=0; $i<$n; $i++) {
        $mod  = $mods[$i]  ?? '';
        $fec  = $fechs[$i] ?? '';
        $hor  = $horas[$i] ?? '';
        $lgr  = $lug[$i]   ?? '';
        $lnk_i= $lnk[$i]   ?? '';
  
        if ( ! $fec || ! $hor ) continue;
  
        $sesiones[] = [
          'modalidad' => ($mod === 'Virtual' ? 'Virtual' : 'Presencial'),
          'fecha'     => sanitize_text_field($fec),
          'hora'      => sanitize_text_field($hor),
          'lugar'     => $mod === 'Virtual' ? '' : sanitize_text_field($lgr),
          'link'      => $mod === 'Virtual' ? esc_url_raw($lnk_i) : '',
        ];
      }
    }
    if ( empty($sesiones) ) {
      wp_send_json_error(['msg'=>'Debes agregar al menos una sesión válida'], 400);
    }
  
    $postarr = [
      'post_type'   => 'capacitacion',
      'post_title'  => $titulo,
      'post_status' => 'publish',
    ];
  
    if ( $edit_id ) {
      $postarr['ID'] = $edit_id;
      $id = wp_update_post($postarr, true);
    } else {
      $id = wp_insert_post($postarr, true);
    }
    if ( is_wp_error($id) ) {
      wp_send_json_error(['msg'=>$id->get_error_message()], 400);
    }
  
    update_post_meta($id, '_gw_pais_relacionado', $pais);
    update_post_meta($id, '_gw_proyecto_relacionado', $proy);
    update_post_meta($id, '_gw_coach_asignado', $coach);
    update_post_meta($id, '_gw_sesiones', $sesiones);
  
    $html = gw_caps_render_list_html('publish');
    wp_send_json_success(['id'=>$id, 'html'=>$html]);
  });
  
  // --- Obtener una capacitación (para editar) ---
  add_action('wp_ajax_gw_obtener_capacitacion', function(){
    $id = gw_caps_check(true, 'id');
  
    $data = [
      'titulo'   => get_the_title($id),
      'pais'     => (int) get_post_meta($id, '_gw_pais_relacionado', true),
      'proyecto' => (int) get_post_meta($id, '_gw_proyecto_relacionado', true),
      'coach'    => (int) get_post_meta($id, '_gw_coach_asignado', true),
      'sesiones' => get_post_meta($id, '_gw_sesiones', true) ?: [],
    ];
    wp_send_json_success($data);
  });
  
  // --- Enviar a papelera ---
  add_action('wp_ajax_gw_eliminar_capacitacion', function(){
    $id = gw_caps_check(true, 'id');
    $res = wp_trash_post($id);
    if ( ! $res ) wp_send_json_error(['msg'=>'No se pudo enviar a papelera'], 400);
    $html = gw_caps_render_list_html('publish');
    wp_send_json_success(['html'=>$html]);
  });
  
  // --- Restaurar desde papelera ---
  add_action('wp_ajax_gw_restaurar_capacitacion', function(){
    $id = gw_caps_check(true, 'id');
    $res = wp_untrash_post($id);
    if ( ! $res ) wp_send_json_error(['msg'=>'No se pudo restaurar'], 400);
    wp_send_json_success(['msg'=>'Restaurada']);
  });
  
  // --- Eliminar permanentemente ---
  add_action('wp_ajax_gw_eliminar_permanente_capacitacion', function(){
    $id = gw_caps_check(true, 'id');
    $res = wp_delete_post($id, true);
    if ( ! $res ) wp_send_json_error(['msg'=>'No se pudo eliminar permanentemente'], 400);
    wp_send_json_success(['msg'=>'Eliminada permanentemente']);
  });
  
  // --- Listar papelera (JSON) ---
  add_action('wp_ajax_gw_obtener_capacitaciones_eliminadas', function(){
    gw_caps_check();
    $caps = get_posts([
      'post_type'   => 'capacitacion',
      'post_status' => 'trash',
      'numberposts' => -1,
      'orderby'     => 'title',
      'order'       => 'ASC',
    ]);
    $data = [];
    foreach ($caps as $c) {
      $data[] = [
        'id'    => $c->ID,
        'titulo'=> $c->post_title,
        'pais'  => get_the_title( (int) get_post_meta($c->ID, '_gw_pais_relacionado', true) ),
        'proyecto' => get_the_title( (int) get_post_meta($c->ID, '_gw_proyecto_relacionado', true) ),
        'coach' => ( ($u = get_userdata( (int) get_post_meta($c->ID, '_gw_coach_asignado', true) )) ? $u->display_name : '-' ),
        'fecha_eliminacion' => get_post_time( 'Y-m-d H:i', false, $c, true ),
      ];
    }
    wp_send_json_success($data);
  });
  
  // --- Listar activas (HTML) ---
  add_action('wp_ajax_gw_obtener_capacitaciones_activas', function(){
    gw_caps_check();
    wp_send_json_success([ 'html' => gw_caps_render_list_html('publish') ]);
  });













// =================== INICIO BLOQUE REVISIÓN/ACEPTACIÓN DE DOCUMENTOS ===================


// APROBAR
add_action('wp_ajax_gw_aprobar_doc', function () {
    if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Sin permisos'], 403);
    if (isset($_POST['nonce']) && !wp_verify_nonce($_POST['nonce'], 'gw_docs_review')) {
        wp_send_json_error(['message'=>'Nonce inválido'], 400);
    }

    global $wpdb;
    $table  = $wpdb->prefix . 'voluntario_docs';
    $row_id = intval($_POST['row_id'] ?? $_POST['doc_id'] ?? 0);
    $user_id= intval($_POST['user_id'] ?? 0);
    if (!$row_id || !$user_id) wp_send_json_error(['message'=>'Parámetros inválidos'], 400);

    $res = $wpdb->update(
        $table,
        ['status'=>'aceptado','fecha_revision'=>current_time('mysql'),'revisado_por'=>get_current_user_id()],
        ['id'=>$row_id,'user_id'=>$user_id],
        ['%s','%s','%d'], ['%d','%d']
    );
    if ($res === false) wp_send_json_error(['message'=>$wpdb->last_error ?: 'DB error']);
    wp_send_json_success(['message'=>'OK','status'=>'aceptado']);
});

// RECHAZAR (igual que lo tenías, no cambia)
add_action('wp_ajax_gw_rechazar_doc', function () {
    if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Sin permisos'], 403);
    if (isset($_POST['nonce']) && !wp_verify_nonce($_POST['nonce'], 'gw_docs_review')) {
        wp_send_json_error(['message'=>'Nonce inválido'], 400);
    }

    global $wpdb;
    $table  = $wpdb->prefix . 'voluntario_docs';
    $row_id = intval($_POST['row_id'] ?? $_POST['doc_id'] ?? 0);
    $user_id= intval($_POST['user_id'] ?? 0);
    if (!$row_id || !$user_id) wp_send_json_error(['message'=>'Parámetros inválidos'], 400);

    $res = $wpdb->update(
        $table,
        ['status'=>'rechazado','fecha_revision'=>current_time('mysql'),'revisado_por'=>get_current_user_id()],
        ['id'=>$row_id,'user_id'=>$user_id],
        ['%s','%s','%d'], ['%d','%d']
    );
    if ($res === false) wp_send_json_error(['message'=>$wpdb->last_error ?: 'DB error']);
    wp_send_json_success(['message'=>'OK','status'=>'rechazado']);
});



// AJAX: Obtener documentos subidos por voluntario
add_action('wp_ajax_gw_obtener_docs_voluntario', function() {
    if (!current_user_can('manage_options')) { status_header(403); echo '<p>Sin permisos.</p>'; wp_die(); }

    global $wpdb;
    $user_id = intval($_POST['user_id'] ?? 0);
    if (!$user_id) { echo '<p>ID inválido.</p>'; wp_die(); }

    $table = $wpdb->prefix . 'voluntario_docs';
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table WHERE user_id = %d ORDER BY fecha_subida DESC LIMIT 1",
        $user_id
    ));
    if (!$row) { echo '<p>El voluntario no ha subido documentos.</p>'; wp_die(); }

    // Forzar esquema https si la página está en https (evita mixed content)
    $to_https = function($url){
        if (!$url) return '';
        return esc_url( set_url_scheme( esc_url_raw($url), is_ssl() ? 'https' : 'http') );
    };

    $docs = [];
    if (!empty($row->documento_1_url)) $docs[] = ['label'=>'Documento 1', 'url'=>$to_https($row->documento_1_url)];
    if (!empty($row->documento_2_url)) $docs[] = ['label'=>'Documento 2', 'url'=>$to_https($row->documento_2_url)];

    $tipo = function($url){
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) return 'image';
        if ($ext === 'pdf') return 'pdf';
        return 'other';
    };

    $nonce  = wp_create_nonce('gw_docs_review');
    $estado = $row->status ? esc_html($row->status) : 'pendiente';

    // Visor
    echo '<div id="gw-doc-viewer" style="display:none; margin-bottom:14px; border:1px solid #e3e3e3; border-radius:10px; overflow:hidden;">
            <div style="padding:8px 10px; background:#f7fafd; border-bottom:1px solid #e3e3e3; display:flex; justify-content:space-between; align-items:center;">
                <strong>Vista previa del archivo</strong>
                <button type="button" class="button button-small" id="gw-cerrar-visor">Cerrar</button>
            </div>
            <div id="gw-doc-viewer-body" style="height:420px; background:#fff;"></div>
          </div>';

    echo '<table class="widefat striped"><thead><tr>
            <th>Documento</th><th>Archivo</th><th>Estado</th><th>Acción</th>
          </tr></thead><tbody>';

    if (empty($docs)) {
        echo '<tr><td colspan="4">No hay URLs de documentos para este voluntario.</td></tr>';
    } else {
        foreach ($docs as $d) {
            $t = $tipo($d['url']);
            echo '<tr>';
            echo '<td>'.esc_html($d['label']).'</td>';
            echo '<td>';
              echo '<button type="button" class="button gw-ver-archivo" data-url="'.$d['url'].'" data-tipo="'.$t.'">Ver archivo</button>';
              if ($t === 'image') {
                echo '<div style="margin-top:8px;"><img src="'.$d['url'].'" alt="Documento" style="max-width:120px;max-height:120px;border:1px solid #ccc;border-radius:6px;" loading="lazy"></div>';
              }
            echo '</td>';
            echo '<td>'.ucfirst($estado).'</td>';
            echo '<td>';
              if ($estado !== 'aprobado') {
                echo '<button class="button gw-aprobar-doc" data-row-id="'.intval($row->id).'" data-user-id="'.intval($user_id).'" data-nonce="'.$nonce.'">Aprobar</button> ';
              }
              if ($estado !== 'rechazado') {
                echo '<button class="button gw-rechazar-doc" data-row-id="'.intval($row->id).'" data-user-id="'.intval($user_id).'" data-nonce="'.$nonce.'">Rechazar</button>';
              }
            echo '</td>';
            echo '</tr>';
        }
    }

    echo '</tbody></table>';
    wp_die();
});


add_action('wp_enqueue_scripts', function () {
    if (is_page('panel-administrativo')) {
        wp_enqueue_script('jquery');
        wp_add_inline_script('jquery', 'window.ajaxurl = "'. esc_js( admin_url('admin-ajax.php') ) .'";', 'after');
    }
});

// =================== FIN BLOQUE REVISIÓN/ACEPTACIÓN DE DOCUMENTOS ===================



