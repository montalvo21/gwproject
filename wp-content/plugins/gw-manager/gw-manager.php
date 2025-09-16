<?php
require_once __DIR__ . '/vendor/autoload.php';
// Dompdf para exportar PDF
use Dompdf\Dompdf;

/**
 * Plugin Name: Glasswing Voluntariado
 * Description: Plugin personalizado para gestión de voluntariado (Países, Proyectos, Emparejamientos).
 * Version: 1.0
 * Author: Carlos Montalvo
 */

if (!defined('ABSPATH')) exit;
if (!defined('GW_ALLOW_INSTANT_RESET')) 
//WARNING Antes de subir a dev/prod, cambia esa línea a false
define('GW_ALLOW_INSTANT_RESET', true);

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

// === AJAX: Generar link/QR para País (robusto) ===
if (!function_exists('gw_ajax_generar_link_qr_pais')) {
  function gw_ajax_generar_link_qr_pais(){
    if (!is_user_logged_in()) wp_send_json_error(['msg'=>'No logueado']);
    if (!( current_user_can('manage_options') || current_user_can('coordinador_pais') || current_user_can('coach') )) {
      wp_send_json_error(['msg'=>'No autorizado']);
    }
    $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
    if (!wp_verify_nonce($nonce, 'gw_paises_qr')) {
      wp_send_json_error(['msg'=>'Nonce inválido/expirado']);
    }
    $pais_id = intval($_POST['pais_id'] ?? 0);
    if (!$pais_id) wp_send_json_error(['msg'=>'ID de país requerido']);
    $pais = get_post($pais_id);
    if (!$pais || $pais->post_type !== 'pais') wp_send_json_error(['msg'=>'País no válido']);

    // URL destino (ajusta a tu landing si corresponde)
    $target = add_query_arg('gw_pais', $pais_id, home_url('/'));

    // URLs de QR con múltiples proveedores (fallbacks)
    $qr_google    = 'https://chart.googleapis.com/chart?chs=300x300&cht=qr&choe=UTF-8&chl=' . rawurlencode($target);
    $qr_qrserver  = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . rawurlencode($target);
    $qr_quickchart= 'https://quickchart.io/qr?size=300&text=' . rawurlencode($target);

    wp_send_json_success([
      'url'     => $target,
      'qr'      => $qr_qrserver, // primario
      'qr_alt'  => $qr_google,   // fallback 1
      'qr_alt2' => $qr_quickchart, // fallback 2
      'pais'    => get_the_title($pais_id),
    ]);
  }
}
add_action('wp_ajax_gw_generar_link_qr_pais', 'gw_ajax_generar_link_qr_pais');


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


// ===============================================
// Shortcode para mostrar capacitaciones inscritas
// ===============================================
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

    $output  = '<div class="gw-mis-capacitaciones">';
    $output .= '<h2>Mis Capacitaciones</h2>';
    $output .= '<p><strong>Capacitación:</strong> <a href="'.esc_url($capacitacion_url).'">'.esc_html($capacitacion_title).'</a></p>';
    $output .= '<p><strong>Fecha:</strong> '.esc_html($fecha).'</p>';
    $output .= '<p><strong>Hora:</strong> '.esc_html($hora).'</p>';
    $output .= '</div>';

    return $output;
}

// ===============================================
// Google OAuth: URL de inicio
// ===============================================
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

/* -------------------------------------------------------
   Helpers de País
--------------------------------------------------------*/
/** Devuelve el código de país y lo persiste en 'gw_pais' si sólo hay 'gw_pais_id'. */
function gw_resolve_user_pais_code(int $user_id): string {
    $code = (string) get_user_meta($user_id, 'gw_pais', true);
    if ($code !== '') return $code;

    $pid = (int) get_user_meta($user_id, 'gw_pais_id', true);
    if ($pid > 0) {
        $code = (string) get_post_meta($pid, 'codigo', true);
        if ($code === '') $code = (string) get_post_field('post_name', $pid);
        if ($code !== '') update_user_meta($user_id, 'gw_pais', $code);
    }
    return $code;
}

/** Devuelve el ID de país; si sólo hay código, permite resolverlo vía filtro. */
function gw_resolve_user_pais_id(int $user_id): int {
    $pid = (int) get_user_meta($user_id, 'gw_pais_id', true);
    if ($pid > 0) return $pid;

    $code = gw_resolve_user_pais_code($user_id);
    if ($code !== '') {
        // Puedes mapear código → post_id (CPT País) con este filtro si lo necesitas.
        $pid = (int) apply_filters('gw/resolve_pais_id_from_code', 0, $code);
        if ($pid > 0) update_user_meta($user_id, 'gw_pais_id', $pid);
    }
    return $pid;
}

/* -------------------------------------------------------
   Detección de Sesiones Próximas (ajustable por filtros)
--------------------------------------------------------*/
/**
 * Determina si una charla tiene al menos una sesión futura.
 * Soporta:
 *  A) CPT de sesiones (por defecto 'sesion_charla')
 *  B) Meta en charla 'gw_sesiones' (array de fechas/timestamps)
 */
function gw_charla_tiene_sesion_activa(int $charla_id): bool {
    $charla_id = (int) $charla_id;
    if ($charla_id <= 0) return false;

    $now_ts = current_time('timestamp');

    // A) CPT de sesiones
    $cpt_sesion     = apply_filters('gw/sesion_cpt', 'sesion_charla');
    $meta_charla_fk = apply_filters('gw/sesion_meta/charla_fk', 'charla_id');
    $meta_inicio    = apply_filters('gw/sesion_meta/inicio', 'inicio'); // o 'fecha_inicio'
    $meta_estado    = apply_filters('gw/sesion_meta/estado', 'estado');
    $usar_estado    = (bool) apply_filters('gw/sesion_usa_estado', false);
    $valor_activo   = apply_filters('gw/sesion_valor_estado_activo', 'activa');

    $mq = [
        'relation' => 'AND',
        [
            'key'     => $meta_charla_fk,
            'value'   => $charla_id,
            'compare' => '='
        ],
        [
            'key'     => $meta_inicio,
            'value'   => $now_ts,
            'type'    => 'NUMERIC',
            'compare' => '>='
        ]
    ];
    if ($usar_estado) {
        $mq[] = [
            'key'     => $meta_estado,
            'value'   => $valor_activo,
            'compare' => '='
        ];
    }

    $q = new WP_Query([
        'post_type'      => $cpt_sesion,
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'no_found_rows'  => true,
        'fields'         => 'ids',
        'meta_query'     => $mq,
    ]);
    if ($q->have_posts()) return true;

    // B) Meta en la charla
    $ses = get_post_meta($charla_id, 'gw_sesiones', true);
    if (is_array($ses)) {
        foreach ($ses as $s) {
            $ini = is_array($s) ? ($s['inicio'] ?? null) : $s;
            if (!$ini) continue;
            $ts = is_numeric($ini) ? (int) $ini : strtotime((string) $ini);
            if ($ts && $ts >= $now_ts) return true;
        }
    }

    return false;
}

/* -------------------------------------------------------
   Charlas activas por País (y con sesión)
--------------------------------------------------------*/
/**
 * Devuelve IDs de charlas del país que estén "activas" y con sesión futura.
 * - "Activa" por meta: gw_activa = '1' (ajustable por filtros).
 * - Relación país↔charla por meta 'gw_pais_id' (o taxonomía vía filtros).
 * - Fallback: meta del post País '_gw_charlas' (IDs) si no hay relación directa.
 */
function gw_get_charlas_activas_con_sesion_por_pais(int $pais_id): array {
    $pais_id = (int) $pais_id;
    if ($pais_id <= 0) return [];

    $meta_activa_key   = apply_filters('gw/charla_meta/activa_key', 'gw_activa');
    $meta_activa_value = apply_filters('gw/charla_meta/activa_value', '1');

    $usar_taxonomia_pais = (bool) apply_filters('gw/charla_relacion/usa_taxonomia', false);
    $taxonomia_pais      = apply_filters('gw/charla_relacion/taxonomia', 'pais');
    $meta_rel_pais       = apply_filters('gw/charla_relacion/meta_pais_fk', 'gw_pais_id');

    $args = [
        'post_type'      => 'charla',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'orderby'        => 'ID',
        'order'          => 'ASC',
        'meta_query'     => [
            'relation' => 'AND',
            [
                'key'     => $meta_activa_key,
                'value'   => $meta_activa_value,
                'compare' => '='
            ],
        ],
    ];

    if ($usar_taxonomia_pais) {
        $args['tax_query'] = [[
            'taxonomy' => $taxonomia_pais,
            'field'    => 'term_id',
            'terms'    => (int) apply_filters('gw/map_pais_post_to_term', $pais_id, $pais_id),
        ]];
    } else {
        $args['meta_query'][] = [
            'key'     => $meta_rel_pais,
            'value'   => $pais_id,
            'compare' => '=',
            'type'    => 'NUMERIC'
        ];
    }

    $ids = get_posts($args);

    // Fallback: IDs desde el post País
    if (empty($ids)) {
        $ids = get_post_meta($pais_id, '_gw_charlas', true);
        $ids = is_array($ids) ? array_map('intval', $ids) : [];
        if ($ids) {
            $ids = array_values(array_filter($ids, function($cid) use ($meta_activa_key, $meta_activa_value){
                return get_post_status($cid) === 'publish'
                    && (string) get_post_meta($cid, $meta_activa_key, true) === (string) $meta_activa_value;
            }));
        }
    }

    if (empty($ids)) return [];

    // Mantener sólo las que tengan sesión próxima
    $ids = array_values(array_filter($ids, 'gw_charla_tiene_sesion_activa'));

    return $ids;
}

/* -------------------------------------------------------
   Defaults (si el país no tiene ninguna válida)
--------------------------------------------------------*/
function gw_get_charlas_default(): array {
    $ids = [];
    $charlas = get_posts([
        'post_type'      => 'charla',
        'post_status'    => 'publish',
        'numberposts'    => 5,
        'orderby'        => 'ID',
        'order'          => 'ASC'
    ]);
    foreach ($charlas as $p) $ids[] = (int) $p->ID;
    // Filtra sólo las que tengan sesión
    $ids = array_values(array_filter($ids, 'gw_charla_tiene_sesion_activa'));
    return $ids;
}

/* -------------------------------------------------------
   Sincronizador idempotente (país + activas + sesión)
--------------------------------------------------------*/

if (!function_exists('gw_get_charlas_asociadas_por_pais_simple')) {
  /**
   * Devuelve SOLO las charlas ligadas explícitamente al país (meta _gw_charlas),
   * normalizadas y publicadas. No agrega extras.
   */
  function gw_get_charlas_asociadas_por_pais_simple(int $pais_id): array {
    $ids = get_post_meta($pais_id, '_gw_charlas', true);
    if (!is_array($ids)) $ids = [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

    // Mantener únicamente publicadas
    $pub = [];
    foreach ($ids as $cid) {
      if (get_post_status($cid) === 'publish') $pub[] = $cid;
    }
    return $pub;
  }
}
function gw_sync_charlas_para_usuario(int $user_id): void {
  if ($user_id <= 0) return;

  // 1) Resolver país del usuario
  $pais_id = gw_resolve_user_pais_id($user_id);

  // 2) Base: SOLO charlas asociadas explícitamente al país (Gestión de países → _gw_charlas)
  $final = [];
  if ($pais_id > 0) {
    $final = gw_get_charlas_asociadas_por_pais_simple($pais_id);
  }

  // 3) Conservar charla(s) agendada(s) (si hubiera)
  $agendada = get_user_meta($user_id, 'gw_charla_agendada', true);
  if (!empty($agendada)) {
    if (is_array($agendada)) {
      if (isset($agendada['charla_id'])) {
        $final[] = (int) $agendada['charla_id'];
      } else {
        foreach ($agendada as $cid) {
          if (is_numeric($cid)) $final[] = (int) $cid;
        }
      }
    } elseif (is_numeric($agendada)) {
      $final[] = (int) $agendada;
    }
  }

  // 4) Conservar charlas ya cursadas (historial) para que siempre aparezcan
  $hist = get_user_meta($user_id, 'gw_charlas_historial', true);
  if (is_array($hist)) {
    foreach ($hist as $row) {
      $cid = isset($row['charla_id']) ? (int) $row['charla_id'] : 0;
      if ($cid > 0) $final[] = $cid;
    }
  }

  // 5) Normalizar y quedarnos sólo con publicadas
  $final   = array_values(array_unique(array_filter(array_map('intval', $final))));
  $validas = [];
  foreach ($final as $cid) {
    if (get_post_status($cid) === 'publish') $validas[] = $cid;
  }

  // 6) Persistir
  update_user_meta($user_id, 'gw_charlas_asignadas', $validas);
}

/* -------------------------------------------------------
   Capacitaciones: asociadas por País (simple) + sync usuario
--------------------------------------------------------*/
if (!function_exists('gw_get_capacitaciones_asociadas_por_pais_simple')) {
  /**
   * Devuelve SOLO las capacitaciones ligadas explícitamente al país (meta _gw_capacitaciones),
   * normalizadas y publicadas. No agrega extras.
   */
  function gw_get_capacitaciones_asociadas_por_pais_simple(int $pais_id): array {
    $ids = get_post_meta($pais_id, '_gw_capacitaciones', true);
    if (!is_array($ids)) $ids = [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

    // Mantener únicamente publicadas
    $pub = [];
    foreach ($ids as $cid) {
      if (get_post_status($cid) === 'publish') $pub[] = $cid;
    }
    return $pub;
  }
}

/**
 * Sincroniza la meta `gw_capacitaciones_asignadas` para el usuario a partir de:
 *  - Capacitaciones del País (_gw_capacitaciones)
 *  - Capacitaciones agendadas (meta `gw_capacitacion_agendada`)
 *  - Historial (meta `gw_capacitaciones_historial`)
 *
 * No elimina información de otras metas y NO toca estados/fechas.
 */
function gw_sync_capacitaciones_para_usuario(int $user_id): void {
  if ($user_id <= 0) return;

  // 1) Resolver país del usuario
  $pais_id = gw_resolve_user_pais_id($user_id);

  // 2) Base: SOLO capacitaciones asociadas explícitamente al país
  $final = [];
  if ($pais_id > 0) {
    $final = gw_get_capacitaciones_asociadas_por_pais_simple($pais_id);
  }

  // 3) Conservar capacitación(es) agendada(s)
  $agendada = get_user_meta($user_id, 'gw_capacitacion_agendada', true);
  if (!empty($agendada)) {
    if (is_array($agendada)) {
      if (isset($agendada['cap_id'])) {
        $final[] = (int) $agendada['cap_id'];
      } else {
        foreach ($agendada as $cid) {
          if (is_numeric($cid)) $final[] = (int) $cid;
        }
      }
    } elseif (is_numeric($agendada)) {
      $final[] = (int) $agendada;
    }
  }

  // 4) Conservar historial (capacitaciones ya cursadas)
  $hist = get_user_meta($user_id, 'gw_capacitaciones_historial', true);
  if (is_array($hist)) {
    foreach ($hist as $row) {
      $cid = 0;
      if (isset($row['cap_id']))            $cid = (int) $row['cap_id'];
      elseif (isset($row['capacitacion_id'])) $cid = (int) $row['capacitacion_id'];
      if ($cid > 0) $final[] = $cid;
    }
  }

  // 5) Normalizar y quedarnos sólo con publicadas
  $final   = array_values(array_unique(array_filter(array_map('intval', $final))));
  $validas = [];
  foreach ($final as $cid) {
    if (get_post_status($cid) === 'publish') $validas[] = $cid;
  }

  // 6) Persistir
  update_user_meta($user_id, 'gw_capacitaciones_asignadas', $validas);
}

/* -------------------------------------------------------
   Flujo OAuth Google (start + callback)
--------------------------------------------------------*/
add_action('admin_post_nopriv_gw_google_start', function () {
    wp_redirect(gw_google_login_url());
    exit;
});

add_action('admin_post_nopriv_gw_google_callback', function () {
    if (!isset($_GET['state'], $_GET['code'])) wp_die('OAuth inválido');
    $state = sanitize_text_field($_GET['state']);
    if (!get_transient('gw_google_state_'.$state)) wp_die('Estado inválido/expirado');
    delete_transient('gw_google_state_'.$state);

    $code = sanitize_text_field($_GET['code']);

    // 1) Token
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
    if (is_wp_error($resp)) wp_die('Error de token: '.$resp->get_error_message());

    $raw  = wp_remote_retrieve_body($resp);
    $data = json_decode($raw, true);
    if (empty($data['access_token'])) wp_die('Intercambio falló: '.$raw);

    // 2) Userinfo
    $u = wp_remote_get(GW_GOOGLE_USERINFO, [
        'headers' => ['Authorization' => 'Bearer '.$data['access_token']],
        'timeout' => 20,
    ]);
    if (is_wp_error($u)) wp_die('Error userinfo');
    $userInfo = json_decode(wp_remote_retrieve_body($u), true);

    $email = sanitize_email($userInfo['email'] ?? '');
    $name  = sanitize_text_field($userInfo['name'] ?? '');
    $sub   = sanitize_text_field($userInfo['sub'] ?? '');
    if (!$email) wp_die('No se obtuvo email de Google');

    // 3) Usuario
    $user = get_user_by('email', $email);
    $is_new_user = false;
    if (!$user) {
        $is_new_user = true;
        $uid = wp_create_user($email, wp_generate_password(20,true,true), $email);
        if (is_wp_error($uid)) wp_die('No se pudo crear el usuario');
        wp_update_user(['ID'=>$uid,'display_name'=>($name?:$email)]);
        $user = get_user_by('id', $uid);
        $user->set_role('voluntario');

        update_user_meta($uid, 'gw_google_sub', $sub);
        update_user_meta($uid, 'gw_active', '1');

        if (!empty($_COOKIE['gw_pais_pending'])) {
            $pid = (int) $_COOKIE['gw_pais_pending'];
            if ($pid>0) {
                update_user_meta($uid,'gw_pais_id',$pid);
                $code = gw_resolve_user_pais_code($uid);
                error_log("Asignado país $pid ($code) al usuario $uid");
            }
            setcookie('gw_pais_pending','',time()-3600,COOKIEPATH,COOKIE_DOMAIN,is_ssl(),true);
        }
    }

    // 4) Login
    wp_set_auth_cookie($user->ID,true);
    wp_set_current_user($user->ID);

    // 5) Sync charlas (país + activas + con sesión)
    gw_sync_charlas_para_usuario($user->ID);
    // 5b) Sync capacitaciones (país)
    gw_sync_capacitaciones_para_usuario($user->ID);

    // 6) Redirect
    if (array_intersect(['administrator','coach','coordinador_pais'],$user->roles)) {
        wp_redirect(site_url('/index.php/panel-administrativo')); exit;
    }
    if (in_array('voluntario',$user->roles)) {
        $active = get_user_meta($user->ID,'gw_active',true) ?: '1';
        if ($active==='0') {
            wp_redirect(site_url('/index.php/portal-voluntario?inactivo=1')); exit;
        }
        wp_redirect(site_url('/index.php/portal-voluntario')); exit;
    }
    wp_redirect(site_url('/')); exit;
});

/* -------------------------------------------------------
   Hooks de login / sesión
--------------------------------------------------------*/
// wp_login (incluye login tradicional)
add_action('wp_login', function($user_login, $user){
    if (!in_array('voluntario',$user->roles,true) && !in_array('subscriber',$user->roles,true)) {
        $u=new WP_User($user->ID); $u->add_role('subscriber');
    }

    // País pendiente en cookie
    if (!empty($_COOKIE['gw_pais_pending'])) {
        $pid=(int)$_COOKIE['gw_pais_pending'];
        if ($pid>0) {
            update_user_meta($user->ID,'gw_pais_id',$pid);
            gw_resolve_user_pais_code($user->ID);
        }
        setcookie('gw_pais_pending','',time()-3600,COOKIEPATH,COOKIE_DOMAIN,is_ssl(),true);
    }

    // Sync charlas
    gw_sync_charlas_para_usuario((int)$user->ID);
    gw_sync_capacitaciones_para_usuario((int)$user->ID);

    // Normalizar arrays (por si vinieran como JSON string)
    foreach(['gw_charlas_asignadas','gw_capacitaciones_asignadas'] as $k){
        $v=get_user_meta($user->ID,$k,true);
        if(!is_array($v)){
            $tmp=json_decode((string)$v,true);
            $v=is_array($tmp)?array_values($tmp):[];
            update_user_meta($user->ID,$k,$v);
        }
    }
},10,2);

// set_auth_cookie (paracaídas para SSO que no dispara wp_login)
add_action('set_auth_cookie', function($auth_cookie, $expire, $expiration, $user_id){
    if (!empty($user_id)) {
        gw_sync_charlas_para_usuario((int)$user_id);
        gw_sync_capacitaciones_para_usuario((int)$user_id);
    }
}, 10, 4);

// init (refresco suave al cargar el sitio ya logueado)
add_action('init',function(){
    if(is_user_logged_in()){
        $u=wp_get_current_user();
        if(in_array('voluntario',$u->roles)){
            gw_sync_charlas_para_usuario((int)$u->ID);
            gw_sync_capacitaciones_para_usuario((int)$u->ID);
        }
    }
});

/* -------------------------------------------------------
   Botón "Continuar con Google"
--------------------------------------------------------*/
if(!function_exists('gw_login_google_button_html')){
function gw_login_google_button_html(){
    ob_start(); ?>
    <div class="gw-login-google" style="margin-top:18px; text-align:center;">
      <a href="<?php echo esc_url(admin_url('admin-post.php?action=gw_google_start')); ?>"
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
}}

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
                wp_redirect(site_url('/index.php/panel-administrativo')); exit;
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
                            <a href="#" data-href="<?php echo esc_url( gw_get_password_reset_url() ); ?>" class="gw-forgot-link">¿Olvidaste tu contraseña?</a>
                        </div>
                        <div id="gw-reset-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.3);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);z-index:100000;"></div>
<div id="gw-reset-modal" style="display:none;position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);width:520px;max-width:92vw;background:rgba(255,255,255,0.25);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border-radius:24px;border:1px solid rgba(255,255,255,0.3);box-shadow:0 20px 40px rgba(0,0,0,0.1);z-index:100001;overflow:hidden;">
 <div style="display:flex;justify-content:space-between;align-items:center;padding:20px 24px;border-bottom:1px solid rgba(0,0,0,0.1);background:white">
   <strong id="gw-reset-title" style="color:#2c3e50;font-size:18px;font-weight:700;">Restablecer contraseña</strong>
   <button type="button" id="gw-reset-close" class="button" style="background:rgba(255,255,255,0.4);border:2px solid rgba(255,255,255,0.3);color:#4a5568;padding:8px 16px;border-radius:12px;cursor:pointer;transition:all 0.3s ease;backdrop-filter:blur(10px);font-weight:600;">Cerrar</button>
 </div>
 <div id="gw-reset-body" style="padding:32px 24px; background:white;">
   <!-- Paso 1: solicitar enlace -->
   <form id="gw-lostpass-form" method="post" autocomplete="off">
     <p style="color:#4a5568;margin-bottom:24px;font-size:14px;font-weight:500;">Escribe tu correo o usuario. Te enviaremos un enlace para restablecerla.</p>
     <p style="margin-bottom:24px;"><input type="text" id="gw_lost_user" name="gw_user_login" placeholder="Correo o usuario" required style="width:100%;padding:16px 20px;background:rgba(255,255,255,0.4);border:2px solid rgba(255,255,255,0.3);border-radius:16px;color:#2c3e50;font-size:16px;backdrop-filter:blur(10px);transition:all 0.3s ease;" onfocus="this.style.borderColor='#3182ce';this.style.background='rgba(255,255,255,0.6)';this.style.boxShadow='0 0 0 4px rgba(49,130,206,0.1)'" onblur="this.style.borderColor='rgba(255,255,255,0.3)';this.style.background='rgba(255,255,255,0.4)';this.style.boxShadow='none'"></p>
     <input type="hidden" id="gw_lost_nonce" value="<?php echo esc_attr( wp_create_nonce('gw_lostpass_ajax') ); ?>">
     <p><button type="submit" class="button button-primary" style="width:100%;padding:16px;background:linear-gradient(135deg,#3182ce,#2b77cb);border:none;border-radius:16px;color:white;font-size:16px;font-weight:600;cursor:pointer;backdrop-filter:blur(10px);transition:all 0.3s ease;box-shadow:0 8px 25px rgba(49,130,206,0.3);" onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 12px 30px rgba(49,130,206,0.4)'" onmouseout="this.style.transform='translateY(0)';this.style.boxShadow='0 8px 25px rgba(49,130,206,0.3)'">Enviar enlace</button></p>
     <div id="gw-lostpass-msg" style="display:none;margin-top:16px;padding:16px;border-radius:12px;background:rgba(255,255,255,0.3);color:#2c3e50;font-size:14px;backdrop-filter:blur(10px);"></div>
   </form>

   <!-- Paso 2: definir nueva contraseña -->
   <form id="gw-resetpass-form" method="post" style="display:none;" autocomplete="off">
     <input type="hidden" id="gw_rp_login">
     <input type="hidden" id="gw_rp_key">
     <p style="margin-bottom:20px;"><label style="color:#4a5568;font-size:14px;display:block;margin-bottom:8px;font-weight:600;">Nueva contraseña<br><input type="password" id="gw_rp_pass1" required style="width:100%;padding:16px 20px;background:rgba(255,255,255,0.4);border:2px solid rgba(255,255,255,0.3);border-radius:16px;color:#2c3e50;font-size:16px;backdrop-filter:blur(10px);transition:all 0.3s ease;" onfocus="this.style.borderColor='#3182ce';this.style.background='rgba(255,255,255,0.6)';this.style.boxShadow='0 0 0 4px rgba(49,130,206,0.1)'" onblur="this.style.borderColor='rgba(255,255,255,0.3)';this.style.background='rgba(255,255,255,0.4)';this.style.boxShadow='none'"></label></p>
     <p style="margin-bottom:24px;"><label style="color:#4a5568;font-size:14px;display:block;margin-bottom:8px;font-weight:600;">Repite la contraseña<br><input type="password" id="gw_rp_pass2" required style="width:100%;padding:16px 20px;background:rgba(255,255,255,0.4);border:2px solid rgba(255,255,255,0.3);border-radius:16px;color:#2c3e50;font-size:16px;backdrop-filter:blur(10px);transition:all 0.3s ease;" onfocus="this.style.borderColor='#3182ce';this.style.background='rgba(255,255,255,0.6)';this.style.boxShadow='0 0 0 4px rgba(49,130,206,0.1)'" onblur="this.style.borderColor='rgba(255,255,255,0.3)';this.style.background='rgba(255,255,255,0.4)';this.style.boxShadow='none'"></label></p>
     <input type="hidden" id="gw_reset_nonce" value="<?php echo esc_attr( wp_create_nonce('gw_resetpass_ajax') ); ?>">
     <p><button type="submit" class="button button-primary" style="width:100%;padding:16px;background:linear-gradient(135deg,#3182ce,#2b77cb);border:none;border-radius:16px;color:white;font-size:16px;font-weight:600;cursor:pointer;backdrop-filter:blur(10px);transition:all 0.3s ease;box-shadow:0 8px 25px rgba(49,130,206,0.3);" onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 12px 30px rgba(49,130,206,0.4)'" onmouseout="this.style.transform='translateY(0)';this.style.boxShadow='0 8px 25px rgba(49,130,206,0.3)'">Guardar contraseña</button></p>
     <div id="gw-resetpass-msg" style="display:none;margin-top:16px;padding:16px;border-radius:12px;background:rgba(255,255,255,0.3);color:#2c3e50;font-size:14px;backdrop-filter:blur(10px);"></div>
   </form>
 </div>
</div>

<style>
/* Estilos adicionales para efectos hover */
#gw-reset-close:hover {
   background: rgba(255,255,255,0.6) !important;
   transform: scale(1.05);
}

input::placeholder {
   color: rgba(74, 85, 104, 0.6) !important;
}

/* Animaciones */
@keyframes fadeIn {
   from { opacity: 0; }
   to { opacity: 1; }
}

@keyframes slideUp {
   from { 
       opacity: 0;
       transform: translate(-50%, -40%) scale(0.9);
   }
   to { 
       opacity: 1;
       transform: translate(-50%, -50%) scale(1);
   }
}

#gw-reset-overlay[style*="display: block"],
#gw-reset-overlay[style*="display: flex"] {
   animation: fadeIn 0.3s ease-out;
}

#gw-reset-modal[style*="display: block"],
#gw-reset-modal[style*="display: flex"] {
   animation: slideUp 0.4s ease-out;
}

/* === Ausencias: estilos para ambos tabs === */
#gw-admin-tab-ausencias > div[style*="display:flex"],
#gw-admin-tab-ausencias-detectadas > div[style*="display:flex"],
#gw-admin-tab-ausencias_detectadas > div[style*="display:flex"]{
    gap: 24px;
    align-items: flex-start;
    margin-top: 18px;
}
#gw-admin-tab-ausencias .widefat td,
#gw-admin-tab-ausencias-detectadas .widefat td{
    font-size: 15px;
    padding: 7px 10px;
}
#gw-admin-tab-ausencias .widefat th,
#gw-admin-tab-ausencias-detectadas .widefat th{
    font-size: 15px;
    background: #f8fafc;
}
#gw-admin-tab-ausencias .button.button-small.gw-abs-resolver,
#gw-admin-tab-ausencias-detectadas .button.button-small.gw-abs-resolver{
    background: #48bb78;
    border-color: #38a169;
    color: #fff;
}
#gw-admin-tab-ausencias .button.button-small.gw-abs-reactivar,
#gw-admin-tab-ausencias-detectadas .button.button-small.gw-abs-reactivar{
    background: #3182ce;
    border-color: #2b6cb0;
    color: #fff;
}
#gw-admin-tab-ausencias .button.button-small.gw-abs-ocultar,
#gw-admin-tab-ausencias-detectadas .button.button-small.gw-abs-ocultar{
    background: #e53e3e;
    border-color: #c53030;
    color: #fff;
}
/* Tab 7: solo AJUSTES (oculta el listado) */
#gw-admin-tab-ausencias .gw-abs-list { display: none; }

/* Tab 8: solo LISTADO (oculta el formulario de ajustes) */
#gw-admin-tab-ausencias-detectadas #gw-abs-settings { display: none; }
</style>

<script>
(function(){
  if (typeof window.ajaxurl === 'undefined') {
    window.ajaxurl = "<?php echo esc_js( admin_url('admin-ajax.php') ); ?>";
  }
  function qs(k){ try{ return new URL(window.location.href).searchParams.get(k)||''; }catch(e){ return ''; } }

  var $ol = document.getElementById('gw-reset-overlay');
  var $md = document.getElementById('gw-reset-modal');
  var $ttl = document.getElementById('gw-reset-title');
  var $lost = document.getElementById('gw-lostpass-form');
  var $lostMsg = document.getElementById('gw-lostpass-msg');
  var $set  = document.getElementById('gw-resetpass-form');
  var $setMsg = document.getElementById('gw-resetpass-msg');

  function openModal(mode){
    $ol.style.display = 'block';
    $md.style.display = 'block';
    document.body.style.overflow = 'hidden';
    if (mode === 'set') {
      $ttl.textContent = 'Define tu nueva contraseña';
      $lost.style.display = 'none';
      $set.style.display = 'block';
    } else {
      $ttl.textContent = 'Restablecer contraseña';
      $lost.style.display = 'block';
      $set.style.display = 'none';
    }
  }
  function closeModal(){
    $ol.style.display = 'none';
    $md.style.display = 'none';
    document.body.style.overflow = '';
    $lostMsg.style.display = 'none'; $lostMsg.textContent = '';
    $setMsg.style.display = 'none'; $setMsg.textContent = '';
  }
  document.getElementById('gw-reset-close').addEventListener('click', closeModal);
  $ol.addEventListener('click', closeModal);

  // Abrir al click en "¿Olvidaste tu contraseña?"
  (function(){
    var a = document.querySelector('.gw-forgot-link');
    if (a){
      a.addEventListener('click', function(e){
        e.preventDefault();
        openModal('request');
      });
    }
  })();

  // Si vienen rp_login/rp_key en la URL (desde el email), abrir modal en modo "set"
  (function(){
    var login = qs('rp_login') || qs('login');
    var key   = qs('rp_key')   || qs('key');
    if (login && key){
      document.getElementById('gw_rp_login').value = login;
      document.getElementById('gw_rp_key').value   = key;
      openModal('set');
    }
  })();

  // --- AJAX: solicitar (o habilitar) restablecimiento en el modal ---
  $lost.addEventListener('submit', function(ev){
    ev.preventDefault();
    $lostMsg.style.display = 'block';
    $lostMsg.style.color = '#666';
    $lostMsg.textContent = 'Procesando…';

    var fd = new FormData();
    fd.append('action','gw_lostpass_request');
    fd.append('nonce', document.getElementById('gw_lost_nonce').value);
    fd.append('user_login', document.getElementById('gw_lost_user').value);

    fetch(ajaxurl, {method:'POST', credentials:'same-origin', body:fd})
    .then(r => r.json())
    .then(function(resp){
      if (resp && resp.success) {
        // Modo instantáneo (DEV): pasar directo a establecer contraseña
        if (resp.data && (resp.data.instant || resp.data.login)) {
          document.getElementById('gw_rp_login').value = resp.data.login || document.getElementById('gw_lost_user').value;
          document.getElementById('gw_rp_key').value   = resp.data.key || '';
          $ttl.textContent = 'Define tu nueva contraseña';
          $lost.style.display = 'none';
          $set.style.display  = 'block';
          try { document.getElementById('gw_rp_pass1').focus(); } catch(e) {}
          $lostMsg.style.display = 'none';
        } else {
          // Producción: aviso genérico
          $lostMsg.style.color = '#065f46';
          $lostMsg.textContent = 'Si el correo/usuario existe, te enviamos un enlace para restablecer tu contraseña.';
        }
      } else {
        $lostMsg.style.color = '#b91c1c';
        $lostMsg.textContent = 'No se pudo procesar. Intenta de nuevo.';
      }
    })
    .catch(function(){
      $lostMsg.style.color = '#b91c1c';
      $lostMsg.textContent = 'Error de red.';
    });
  });

  // --- AJAX: guardar nueva contraseña (instantáneo o con key) ---
  $set.addEventListener('submit', function(ev){
    ev.preventDefault();
    var p1 = document.getElementById('gw_rp_pass1').value;
    var p2 = document.getElementById('gw_rp_pass2').value;
    if (p1.length < 6){ $setMsg.style.display='block'; $setMsg.style.color='#b91c1c'; $setMsg.textContent='La contraseña debe tener al menos 6 caracteres.'; return; }
    if (p1 !== p2){ $setMsg.style.display='block'; $setMsg.style.color='#b91c1c'; $setMsg.textContent='Las contraseñas no coinciden.'; return; }
    $setMsg.style.display='block'; $setMsg.style.color='#666'; $setMsg.textContent='Guardando…';

    var fd = new FormData();
    fd.append('action','gw_resetpass_perform');
    fd.append('nonce', document.getElementById('gw_reset_nonce').value);
    fd.append('login', document.getElementById('gw_rp_login').value);
    fd.append('key', document.getElementById('gw_rp_key').value);
    fd.append('pass1', p1);
    fd.append('pass2', p2);

    fetch(ajaxurl, {method:'POST', credentials:'same-origin', body:fd})
    .then(r => r.json())
    .then(function(resp){
      if (resp && resp.success && resp.data && resp.data.redirect){
        $setMsg.style.color = '#065f46';
        $setMsg.textContent = '¡Listo! Redirigiendo…';
        setTimeout(function(){ window.location.href = resp.data.redirect; }, 600);
      } else {
        var msg = (resp && resp.data && resp.data.msg) ? resp.data.msg : 'No se pudo actualizar.';
        $setMsg.style.color = '#b91c1c'; $setMsg.textContent = msg;
      }
    })
    .catch(function(){
      $setMsg.style.color = '#b91c1c';
      $setMsg.textContent = 'Error de red.';
    });
  });
})();
</script>

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

// =========================
// RESTABLECER CONTRASEÑA
// Página: crea una página con el slug "restablecer-contrasena"
// y coloca el shortcode [gw_password_reset]
// =========================

// AJAX: solicitar enlace de restablecimiento (no revela si el usuario existe)
add_action('wp_ajax_nopriv_gw_lostpass_request', 'gw_ajax_lostpass_request');
add_action('wp_ajax_gw_lostpass_request', 'gw_ajax_lostpass_request');

function gw_ajax_lostpass_request(){
  $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
  if ( ! wp_verify_nonce($nonce, 'gw_lostpass_ajax') ) {
    wp_send_json_error(['msg'=>'Nonce inválido']);
  }

  $user_login = sanitize_text_field($_POST['user_login'] ?? '');
  $payload = ['msg' => 'ok'];

  if ($user_login !== '') {
    // Acepta correo o usuario
    $u = get_user_by('email', $user_login);
    if (!$u) { $u = get_user_by('login', $user_login); }

    if ($u) {
      // Si está permitido el modo "instantáneo" (solo DEV), pasamos directo al paso de definir contraseña
      if (defined('GW_ALLOW_INSTANT_RESET') && GW_ALLOW_INSTANT_RESET) {
        $payload['instant'] = true;
        $payload['login']   = $u->user_login;
      } else {
        // Producción: enviar correo estándar de WordPress
        retrieve_password($u->user_login);
      }
    }
  }

  // Siempre success para no revelar si el usuario existe
  wp_send_json_success($payload);
}

// AJAX: fijar nueva contraseña + login + redirección
add_action('wp_ajax_nopriv_gw_resetpass_perform', 'gw_ajax_resetpass_perform');
add_action('wp_ajax_gw_resetpass_perform', 'gw_ajax_resetpass_perform');
function gw_ajax_resetpass_perform() {
  $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
  if ( ! wp_verify_nonce($nonce, 'gw_resetpass_ajax') ) {
    wp_send_json_error(['msg'=>'Nonce inválido']);
  }

  $login = sanitize_text_field($_POST['login'] ?? '');
  $key   = sanitize_text_field($_POST['key']   ?? '');
  $pass1 = (string) ($_POST['pass1'] ?? '');
  $pass2 = (string) ($_POST['pass2'] ?? '');

  if ($pass1 === '' || strlen($pass1) < 6) {
    wp_send_json_error(['msg' => 'La contraseña debe tener al menos 6 caracteres.']);
  }
  if ($pass1 !== $pass2) {
    wp_send_json_error(['msg' => 'Las contraseñas no coinciden.']);
  }

  // Modo instantáneo (solo DEV): no requiere key
  if (defined('GW_ALLOW_INSTANT_RESET') && GW_ALLOW_INSTANT_RESET && $login && $key === '') {
    $u = get_user_by('login', $login);
    if ( ! $u ) { $u = get_user_by('email', $login); }
    if ( ! $u ) { wp_send_json_error(['msg' => 'Usuario no válido.']); }

    // Actualiza la contraseña
    wp_set_password($pass1, $u->ID);

    // Iniciar sesión automáticamente con la nueva contraseña
    $creds = ['user_login' => $u->user_login, 'user_password' => $pass1, 'remember' => true];
    $signon = wp_signon($creds, false);
    if (is_wp_error($signon)) {
      wp_send_json_error(['msg' => 'Contraseña actualizada, pero no se pudo iniciar sesión automáticamente.']);
    }

    // Redirección por rol
    $redirect = site_url('/');
    if (in_array('administrator', $signon->roles) || in_array('coach', $signon->roles) || in_array('coordinador_pais', $signon->roles)) {
      $redirect = site_url('/index.php/panel-administrativo');
    } elseif (in_array('voluntario', $signon->roles)) {
      $active = get_user_meta($signon->ID, 'gw_active', true); if ($active === '') $active = '1';
      $redirect = ($active === '0') ? site_url('/index.php/portal-voluntario?inactivo=1') : site_url('/index.php/portal-voluntario');
    }
    wp_send_json_success(['redirect' => $redirect]);
  }

  // Flujo estándar con clave (por si llega desde email)
  if ($login && $key) {
    $user = check_password_reset_key($key, $login);
    if (is_wp_error($user)) {
      wp_send_json_error(['msg' => $user->get_error_message()]);
    }
    reset_password($user, $pass1);
    wp_set_auth_cookie($user->ID, true);
    wp_set_current_user($user->ID);

    $redirect = site_url('/');
    if (in_array('administrator', $user->roles) || in_array('coach', $user->roles) || in_array('coordinador_pais', $user->roles)) {
      $redirect = site_url('/index.php/panel-administrativo');
    } elseif (in_array('voluntario', $user->roles)) {
      $active = get_user_meta($user->ID, 'gw_active', true); if ($active === '') $active = '1';
      $redirect = ($active === '0') ? site_url('/index.php/portal-voluntario?inactivo=1') : site_url('/index.php/portal-voluntario');
    }
    wp_send_json_success(['redirect' => $redirect]);
  }

  // Si llega aquí, faltan datos
  wp_send_json_error(['msg' => 'Datos incompletos.']);
}

// Obtener la URL de restablecimiento (página personalizada o wp-login.php)
if ( ! function_exists('gw_get_password_reset_url') ) {
  function gw_get_password_reset_url(){
    // Siempre usar la home con una bandera para que el modal se abra
    return add_query_arg('gw_reset', 1, site_url('/'));
  }
}

// Forzar que WordPress use nuestra URL personalizada cuando otros llamen wp_lostpassword_url()
add_filter('lostpassword_url', function($url, $redirect){
  $p = get_page_by_path('restablecer-contrasena');
  return $p ? get_permalink($p) : $url;
}, 10, 2);

// Enlace a la MISMA página (home/login) con los parámetros que el JS detecta
add_filter('retrieve_password_message', function($message, $key, $user_login, $user_data){
  $reset_url = add_query_arg([
    'rp_login' => rawurlencode($user_login),
    'rp_key'   => $key,
    'gw_reset' => 1
  ], site_url('/'));

  $site_name = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);
  $msg  = "Hola {$user_login},\n\n";
  $msg .= "Recibimos una solicitud para restablecer tu contraseña en {$site_name}.\n";
  $msg .= "Abre este enlace (se mostrará un modal para definir tu nueva contraseña):\n\n";
  $msg .= $reset_url . "\n\n";
  $msg .= "Si no solicitaste este cambio, puedes ignorar este mensaje.\n\n";
  $msg .= "Saludos,\n{$site_name}\n";
  return $msg;
}, 10, 4);



// Redirección automática después de login (credenciales normales)
add_filter('login_redirect', 'gw_redireccionar_por_rol', 10, 3);
function gw_redireccionar_por_rol($redirect_to, $request, $user) {
    if (is_wp_error($user)) return $redirect_to;
    if (in_array('administrator', $user->roles) || in_array('coach', $user->roles) || in_array('coordinador_pais', $user->roles)) {
        return site_url('/index.php/panel-administrativo');
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
            return site_url('/index.php/panel-administrativo');
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

// === Panel Administrativo: handler para botón "Generar link/QR" (Gestión de Países) ===
add_action('wp_footer', function(){
  if (!is_user_logged_in()) return;
  $u = wp_get_current_user();
  if (!( in_array('administrator', $u->roles) || in_array('coach', $u->roles) || in_array('coordinador_pais', $u->roles) )) return;
  $req = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
  if (strpos($req, 'panel-administrativo') === false) return; // sólo en el panel

  $nonce = wp_create_nonce('gw_paises_qr');
  $ajax  = admin_url('admin-ajax.php');
  ?>
  <script>
  (function(){
    var GW_PAISES_NONCE = '<?php echo esc_js($nonce); ?>';
    var AJAXURL = '<?php echo esc_js($ajax); ?>';

    // Utilidades
    function $(sel, ctx){ return (ctx||document).querySelector(sel); }

    // Inyectar atributos a botones por texto (fallback)
    function tagQRButtons(){
      var nodes = document.querySelectorAll('a,button');
      Array.prototype.forEach.call(nodes, function(n){
        var t = (n.textContent || '').trim().toLowerCase();
        if (/generar\s*link\/?qr/.test(t)) {
          n.setAttribute('data-gw-action','qr-pais');
        }
      });
    }
    tagQRButtons();
    // Observar cambios dinámicos
    var mo = new MutationObserver(tagQRButtons);
    mo.observe(document.documentElement, {subtree:true, childList:true});

    function ensureModal(){
      var wrap = document.getElementById('gw-qr-modal');
      var needBuild = false;

      if (!wrap) {
        needBuild = true;
      } else {
        // Verificar que existan todos los nodos requeridos; si falta alguno, reconstruir
        var required = ['#gw-qr-title','#gw-qr-img','#gw-qr-link','#gw-qr-open','#gw-qr-download'];
        for (var i=0;i<required.length;i++){
          if (!wrap.querySelector(required[i])) { needBuild = true; break; }
        }
        if (needBuild) {
          try { wrap.parentNode && wrap.parentNode.removeChild(wrap); } catch(e){}
        }
      }

      if (needBuild) {
        wrap = document.createElement('div');
        wrap.id = 'gw-qr-modal';
        wrap.innerHTML = '\
          <div id="gw-qr-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:100000;"></div>\
          <div style="position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);z-index:100001;background:#fff;\
                      width:560px;max-width:92vw;border-radius:12px;box-shadow:0 18px 80px rgba(0,0,0,.3);overflow:hidden;">\
            <div style="padding:12px 14px;background:#f7fafd;border-bottom:1px solid #e3e9f1;display:flex;justify-content:space-between;align-items:center;">\
              <strong id="gw-qr-title">Generar link/QR</strong>\
              <button id="gw-qr-close" class="button">Cerrar</button>\
            </div>\
            <div style="padding:18px;display:flex;gap:18px;align-items:center;justify-content:center;flex-wrap:wrap;">\
              <img id="gw-qr-img" src="" alt="QR" style="width:260px;height:260px;border:1px solid #e3e9f1;border-radius:8px;"/>\
              <div style="min-width:240px;max-width:100%;word-break:break-all;">\
                <div style="font-size:12px;color:#667;">Enlace</div>\
                <input id="gw-qr-link" type="text" readonly style="width:100%;padding:8px 10px;border:1px solid #d6dbe6;border-radius:8px;margin:6px 0;"/>\
                <div style="display:flex;gap:10px;flex-wrap:wrap;">\
                  <a id="gw-qr-open" class="button button-primary" target="_blank" rel="noopener">Abrir</a>\
                  <button id="gw-qr-copy" class="button">Copiar</button>\
                  <a id="gw-qr-download" class="button" download>Descargar QR</a>\
                </div>\
              </div>\
            </div>\
          </div>';
        document.body.appendChild(wrap);
        wrap.addEventListener('click', function(e){
          if (e.target.id==='gw-qr-overlay' || e.target.id==='gw-qr-close') wrap.remove();
        });
      }

      return wrap;
    }

    function showQR(data){
      var m = ensureModal();
      m.querySelector('#gw-qr-title').textContent = 'Link/QR — ' + (data.pais || 'País');

      var img = m.querySelector('#gw-qr-img');
      var linkInput = m.querySelector('#gw-qr-link');
      var openBtn   = m.querySelector('#gw-qr-open');
      var dl        = m.querySelector('#gw-qr-download');

      linkInput.value = data.url;
      openBtn.href    = data.url;

      // preparar fallbacks
      var sources = [data.qr, data.qr_alt, data.qr_alt2].filter(Boolean);
      var idx = 0;

      function setSrc(i){
        if (i >= sources.length) return; // no más fallbacks
        img.onerror = function(){ setSrc(i+1); };
        img.onload  = function(){ dl.href = img.src; };
        img.src = sources[i];
        // href de descarga provisional
        dl.href = sources[i];
      }

      setSrc(0);
    }

    function getPaisId(btn){
      if (!btn) return null;
      // 0) Si el botón tiene href con gw_pais=ID (viejo flujo)
      if (btn.href){
        try{ var u = new URL(btn.href, location.origin); var v = u.searchParams.get('gw_pais'); if (v) return v; }catch(e){}
      }
      // 1) data-pais-id en el botón o contenedor
      var id = btn.getAttribute('data-pais-id');
      if (id) return id;
      var row = btn.closest('[data-pais-id]');
      if (row) return row.getAttribute('data-pais-id');
      // 2) select/hidden en el módulo
      var scope = btn.closest('.card,.panel,.box,.country,.gw-pais,.pais') || document;
      var sel = scope.querySelector('select[name="pais_id"], #gw_pais_select, [name="gw_pais_id"]');
      if (sel && sel.value) return sel.value;
      var inp = scope.querySelector('input[name="pais_id"], input[name="gw_pais_id"], input[type="hidden"][name*="pais"]');
      if (inp && inp.value) return inp.value;
      return null;
    }

    // Delegación en captura para ganar prioridad sobre otros listeners
    document.addEventListener('click', function(ev){
      var el = ev.target.closest('a,button');
      if (!el) return;
      var isQR = el.matches('.gw-generar-qr,[data-gw-action="qr-pais"],#gw-generar-qr,button[data-action="generar-qr"],a[data-action="generar-qr"]');
      if (!isQR) {
        var t = (el.textContent||'').trim().toLowerCase();
        isQR = /generar\s*link\/?qr/.test(t);
      }
      if (!isQR) return;
      ev.preventDefault();
      ev.stopPropagation();
      ev.stopImmediatePropagation();

      var paisId = getPaisId(el);
      if (!paisId) { alert('Selecciona un país o usa el botón de su fila.'); return; }

      var form = new URLSearchParams();
      form.append('action','gw_generar_link_qr_pais');
      form.append('pais_id', paisId);
      form.append('nonce', GW_PAISES_NONCE);

      fetch(AJAXURL, { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body: form.toString() })
        .then(function(r){ return r.text(); })
        .then(function(text){
          var resp;
          try { resp = JSON.parse(text); }
          catch(e){ console.error('Respuesta no JSON', text); alert('Error del servidor: ' + text.slice(0,200)); return; }
          if (!resp || !resp.success) { alert('Error: ' + (resp && resp.data && resp.data.msg ? resp.data.msg : 'No se pudo generar el QR')); return; }
          showQR(resp.data || resp);
        })
        .catch(function(err){ console.error(err); alert('Error de red'); });
    }, true);

    // Copiar
    document.addEventListener('click', function(ev){
      if (ev.target && ev.target.id === 'gw-qr-copy'){
        ev.preventDefault();
        var inp = document.getElementById('gw-qr-link');
        if (inp){ inp.select(); try{ document.execCommand('copy'); }catch(e){} }
      }
    });
  })();
  </script>
  <?php
});

// ====== Admin: Modal Asistencias (render) + Historial toggle (AJAX) ======
if (!function_exists('gw_admin_asist_modal')) {
  add_action('wp_ajax_gw_admin_asist_modal', 'gw_admin_asist_modal');
  function gw_admin_asist_modal(){
    if (!is_user_logged_in() || !current_user_can('manage_options')) {
      wp_send_json_error(['msg'=>'No autorizado']);
    }
    $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
    if (!wp_verify_nonce($nonce, 'gw_asist_admin')) {
      wp_send_json_error(['msg'=>'Nonce inválido/expirado']);
    }
    $uid = intval($_POST['user_id'] ?? 0);
    $u   = $uid ? get_user_by('id', $uid) : null;
    if (!$u) wp_send_json_error(['msg'=>'Usuario no encontrado']);

    // Meta charlas
    $charl_asig = get_user_meta($uid, 'gw_charlas_asignadas', true);
    if (!is_array($charl_asig)) { $tmp = json_decode((string)$charl_asig, true); $charl_asig = is_array($tmp)? $tmp : []; }
    $charl_asig = array_values(array_unique(array_map('intval', $charl_asig)));

    $charl_hist = get_user_meta($uid, 'gw_charlas_historial', true);
    if (!is_array($charl_hist)) { $tmp = json_decode((string)$charl_hist, true); $charl_hist = is_array($tmp)? $tmp : []; }
    $charl_hist = array_values(array_unique(array_map('intval', $charl_hist)));

    // Meta capacitación
    $cap_ag = get_user_meta($uid, 'gw_capacitacion_agendada', true);
    if (!is_array($cap_ag)) { $cap_ag = []; }
    $cap_hist = get_user_meta($uid, 'gw_capacitacion_historial', true);
    if (!is_array($cap_hist)) { $tmp = json_decode((string)$cap_hist, true); $cap_hist = is_array($tmp)? $tmp : []; }

    ob_start(); ?>
    <div id="gw-asist-modal" style="position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);z-index:100002;width:920px;max-width:92vw;background:#fff;border-radius:14px;border:1px solid #e5e7eb;box-shadow:0 20px 60px rgba(0,0,0,.2);">
      <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;background:#f7fafc;border-bottom:1px solid #e5e7eb;">
        <strong style="font-size:18px;">Asistencias</strong>
        <button type="button" class="button" onclick="(function(w){w=document.getElementById('gw-asist-overlay'); if(w) w.remove(); w=document.getElementById('gw-asist-modal'); if(w) w.remove();})();">Cerrar</button>
      </div>
      <div class="gw-asist-body" style="padding:18px;">
        <h3 style="margin:6px 0 10px 0;">Charlas</h3>
        <div style="border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;background:#fff;">
          <table class="widefat" style="width:100%;margin:0;border:none;">
            <thead>
              <tr style="background:#f8fafc;">
                <th style="padding:10px 12px;">Charla</th>
                <th style="padding:10px 12px;">Fecha</th>
                <th style="padding:10px 12px;">Hora</th>
                <th style="padding:10px 12px;">Estado</th>
                <th style="padding:10px 12px;">Acción</th>
              </tr>
            </thead>
            <tbody>
              <?php $printed = 0; ?>
              <?php foreach ($charl_hist as $cid): $title = get_the_title($cid) ?: ('#'.$cid); $printed++; ?>
                <tr>
                  <td style="padding:12px 12px;"><?php echo esc_html($title); ?></td>
                  <td style="padding:12px 12px;">—</td>
                  <td style="padding:12px 12px;">—</td>
                  <td style="padding:12px 12px;">Asistió</td>
                  <td style="padding:12px 12px;">
                    <button class="button button-secondary" data-gw-attend="0" data-type="CHARLA" data-user="<?php echo esc_attr($uid); ?>" data-id="<?php echo esc_attr($cid); ?>">Revertir</button>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php foreach ($charl_asig as $cid): if (in_array($cid, $charl_hist, true)) continue; $title = get_the_title($cid) ?: ('#'.$cid); $printed++; ?>
                <tr>
                  <td style="padding:12px 12px;"><?php echo esc_html($title); ?></td>
                  <td style="padding:12px 12px;">—</td>
                  <td style="padding:12px 12px;">—</td>
                  <td style="padding:12px 12px;">Pendiente</td>
                  <td style="padding:12px 12px;">
                    <button class="button button-primary" data-gw-attend="1" data-type="CHARLA" data-user="<?php echo esc_attr($uid); ?>" data-id="<?php echo esc_attr($cid); ?>">Marcar sí asistió</button>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$printed): ?>
                <tr><td colspan="5" style="padding:14px 12px;color:#64748b;">Sin charlas asignadas.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <h3 style="margin:18px 0 10px 0;">Capacitación</h3>
        <div style="border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;background:#fff;">
          <table class="widefat" style="width:100%;margin:0;border:none;">
            <thead>
              <tr style="background:#f8fafc;">
                <th style="padding:10px 12px;">Capacitación</th>
                <th style="padding:10px 12px;">Fecha</th>
                <th style="padding:10px 12px;">Hora</th>
                <th style="padding:10px 12px;">Estado</th>
                <th style="padding:10px 12px;">Acción</th>
              </tr>
            </thead>
            <tbody>
              <?php $printed_cap = 0; ?>
              <?php if (!empty($cap_hist) && is_array($cap_hist)): foreach ($cap_hist as $row): $printed_cap++; $cid=intval($row['cap_id'] ?? 0); $title=$cid? (get_the_title($cid)?:('#'.$cid)) : 'Capacitación'; $f = sanitize_text_field($row['fecha'] ?? ''); $h = sanitize_text_field($row['hora'] ?? ''); ?>
                <tr>
                  <td style="padding:12px 12px;"><?php echo esc_html($title); ?></td>
                  <td style="padding:12px 12px;"><?php echo esc_html($f?:'—'); ?></td>
                  <td style="padding:12px 12px;"><?php echo esc_html($h?:'—'); ?></td>
                  <td style="padding:12px 12px;">Asistió</td>
                  <td style="padding:12px 12px;">
                    <button class="button button-secondary" data-gw-attend="0" data-type="CAPACITACION" data-user="<?php echo esc_attr($uid); ?>" data-id="<?php echo esc_attr($cid); ?>">Revertir</button>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
              <?php if (!empty($cap_ag) && !empty($cap_ag['cap_id'])): $printed_cap++; $cid=intval($cap_ag['cap_id']); $title=$cid? (get_the_title($cid)?:('#'.$cid)) : 'Capacitación'; $f=sanitize_text_field($cap_ag['fecha'] ?? ''); $h=sanitize_text_field($cap_ag['hora'] ?? ''); ?>
                <tr>
                  <td style="padding:12px 12px;"><?php echo esc_html($title); ?></td>
                  <td style="padding:12px 12px;"><?php echo esc_html($f?:'—'); ?></td>
                  <td style="padding:12px 12px;"><?php echo esc_html($h?:'—'); ?></td>
                  <td style="padding:12px 12px;">Pendiente</td>
                  <td style="padding:12px 12px;">
                    <button class="button button-primary" data-gw-attend="1" data-type="CAPACITACION" data-user="<?php echo esc_attr($uid); ?>" data-id="<?php echo esc_attr($cid); ?>">Marcar sí asistió</button>
                  </td>
                </tr>
              <?php endif; ?>
              <?php if (!$printed_cap): ?>
                <tr><td colspan="5" style="padding:14px 12px;color:#64748b;">Sin capacitación agendada.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <div id="gw-asist-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:100001;"></div>
    <?php $html = ob_get_clean(); wp_send_json_success(['html'=>$html]); }
}

if (!function_exists('gw_admin_asist_hist_toggle')) {
  add_action('wp_ajax_gw_admin_asist_hist_toggle', 'gw_admin_asist_hist_toggle');
  function gw_admin_asist_hist_toggle(){
    if (!is_user_logged_in() || !current_user_can('manage_options')) {
      wp_send_json_error(['msg'=>'No autorizado']);
    }
    $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
    if (!wp_verify_nonce($nonce, 'gw_asist_admin')) {
      wp_send_json_error(['msg'=>'Nonce inválido/expirado']);
    }
    $uid  = intval($_POST['user_id'] ?? 0);
    $type = sanitize_text_field($_POST['type'] ?? '');
    $eid  = intval($_POST['entity_id'] ?? 0);
    $att  = intval($_POST['attend'] ?? 0);
    if (!$uid || !$eid || ($type!=='CHARLA' && $type!=='CAPACITACION')) {
      wp_send_json_error(['msg'=>'Datos incompletos']);
    }

    if ($type === 'CHARLA'){
      $asig = get_user_meta($uid, 'gw_charlas_asignadas', true); if(!is_array($asig)){ $tmp=json_decode((string)$asig,true); $asig=is_array($tmp)?$tmp:[]; }
      $hist = get_user_meta($uid, 'gw_charlas_historial', true); if(!is_array($hist)){ $tmp=json_decode((string)$hist,true); $hist=is_array($tmp)?$tmp:[]; }
      $asig = array_map('intval',$asig); $hist = array_map('intval',$hist);
      if ($att===1){ $asig = array_values(array_diff($asig, [$eid])); if(!in_array($eid,$hist,true)) $hist[]=$eid; }
      else { $hist = array_values(array_diff($hist, [$eid])); if(!in_array($eid,$asig,true)) $asig[]=$eid; }
      update_user_meta($uid, 'gw_charlas_asignadas', array_values(array_unique($asig)));
      update_user_meta($uid, 'gw_charlas_historial', array_values(array_unique($hist)));
      wp_send_json_success(['ok'=>1]);
    }

    $cap_ag = get_user_meta($uid, 'gw_capacitacion_agendada', true); if(!is_array($cap_ag)) $cap_ag=[];
    $cap_hi = get_user_meta($uid, 'gw_capacitacion_historial', true); if(!is_array($cap_hi)){ $tmp=json_decode((string)$cap_hi,true); $cap_hi=is_array($tmp)?$tmp:[]; }
    if ($att===1){
      if (!empty($cap_ag) && intval($cap_ag['cap_id'] ?? 0) === $eid){
        $row = [
          'cap_id' => $eid,
          'fecha'  => sanitize_text_field($cap_ag['fecha'] ?? ''),
          'hora'   => sanitize_text_field($cap_ag['hora'] ?? ''),
          'idx'    => intval($cap_ag['idx'] ?? 0),
        ];
        $cap_hi[] = $row;
        delete_user_meta($uid, 'gw_capacitacion_agendada');
        update_user_meta($uid, 'gw_capacitacion_historial', array_values($cap_hi));
        update_user_meta($uid, 'gw_step7_completo', '1');
      }
    } else {
      $kept=[]; $restore=null; foreach ($cap_hi as $r){ $rid=intval($r['cap_id'] ?? 0); if($rid===$eid && !$restore){ $restore=$r; } else { $kept[]=$r; } }
      if ($restore){ update_user_meta($uid, 'gw_capacitacion_agendada', $restore); update_user_meta($uid, 'gw_capacitacion_historial', array_values($kept)); delete_user_meta($uid, 'gw_step7_completo'); }
    }
    wp_send_json_success(['ok'=>1]);
  }
}
// ====== FIN Admin: Modal Asistencias + Toggle ======

// === Panel Administrativo: botón/modal "Asistencias" en Gestión de usuarios ===
add_action('wp_footer', function(){
  if ( ! is_user_logged_in() ) return;
  $u = wp_get_current_user();
  if ( ! ( in_array('administrator',$u->roles) || current_user_can('manage_options') ) ) return;

  $req = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
  if (strpos($req, 'panel-administrativo') === false) return;

  $nonce = wp_create_nonce('gw_asist_admin');
  $ajax  = admin_url('admin-ajax.php');
  ?>
  <script>
  (function(){
    var AJAX  = '<?php echo esc_js($ajax); ?>';
    var NONCE = '<?php echo esc_js($nonce); ?>';

    function ensureAsistModal(){
      var w = document.getElementById('gw-asist-wrap');
      if (w) return w;
      w = document.createElement('div');
      w.id = 'gw-asist-wrap';
      w.innerHTML =
        '<div id="gw-asist-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:100000;"></div>'+
        '<div id="gw-asist-modal" style="display:none;position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);z-index:100001;background:#fff;width:820px;max-width:95vw;border-radius:12px;box-shadow:0 18px 70px rgba(0,0,0,.28);overflow:hidden;">'+
          '<div style="padding:12px 14px;background:#f7fafd;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;">'+
            '<strong>Asistencias</strong>'+
            '<button type="button" id="gw-asist-close" class="button">Cerrar</button>'+
          '</div>'+
          '<div id="gw-asist-body" style="padding:29px;max-height:70vh;overflow:auto;"></div>'+
        '</div>';
      document.body.appendChild(w);
      w.addEventListener('click', function(e){
        if (e.target.id === 'gw-asist-overlay' || e.target.id === 'gw-asist-close'){
          document.getElementById('gw-asist-overlay').style.display='none';
          document.getElementById('gw-asist-modal').style.display='none';
        }
      });
      return w;
    }

    function openAsist(userId){
      ensureAsistModal();
      var ov = document.getElementById('gw-asist-overlay');
      var md = document.getElementById('gw-asist-modal');
      var bd = document.getElementById('gw-asist-body');
      ov.style.display='block'; md.style.display='block';
      bd.innerHTML = '<p>Cargando…</p>';

      var fd = new FormData();
      fd.append('action','gw_admin_asist_modal');
      fd.append('nonce', NONCE);
      fd.append('user_id', userId);
      fetch(AJAX, {method:'POST', credentials:'same-origin', body:fd})
      .then(r=>r.json()).then(function(resp){
        if (resp && resp.success){
          bd.innerHTML = resp.data.html || '';
        } else {
          bd.innerHTML = '<p style="color:#b91c1c">Error al cargar.</p>';
        }
      }).catch(function(){
        bd.innerHTML = '<p style="color:#b91c1c">Error de red.</p>';
      });
    }

    // Delegación clicks: marcar / revertir
    document.addEventListener('click', function(ev){
      var mk = ev.target.closest('.gwAsistMark, .gwAsistRevert');
      if (!mk) return;
      ev.preventDefault();
      var uid  = mk.getAttribute('data-uid');
      var kind = mk.getAttribute('data-kind');
      var key  = mk.getAttribute('data-key') || '';
      var act  = mk.classList.contains('gwAsistRevert') ? 'gw_admin_revert_attendance' : 'gw_admin_mark_attendance';

      var fd = new FormData();
      fd.append('action', act);
      fd.append('nonce', NONCE);
      fd.append('user_id', uid);
      fd.append('kind', kind);
      fd.append('key', key);

      fetch(AJAX, {method:'POST', credentials:'same-origin', body:fd})
  .then(r=>r.json()).then(function(resp){
    if (resp && resp.success){
      // Actualizar badge de la campanita si el servidor devuelve el contador
      try {
        var b = document.getElementById('gw-notif-badge');
        if (b && resp.data && typeof resp.data.badge !== 'undefined') {
          var n = parseInt(resp.data.badge, 10) || 0;
          if (n > 0) { b.style.display = 'inline-flex'; b.textContent = n; }
          else { b.style.display = 'none'; }
        }
      } catch(e){}
      openAsist(uid); // recargar modal
    } else {
      alert((resp && resp.data && resp.data.msg) ? resp.data.msg : 'No se pudo actualizar.');
    }
  }).catch(function(){ alert('Error de red'); });
    });

    // Inyectar botón "Asistencias" en cada fila (columna Acciones)
    function addAsistButtons(ctx){
      var scope = ctx || document;
      var rows = scope.querySelectorAll('table tr');
      Array.prototype.forEach.call(rows, function(tr){
        // Evitar inyectar dentro del propio modal de asistencias
        if (tr.closest('#gw-asist-modal')) return;
        var acciones = tr.querySelector('td:last-child, .acciones, [data-col="acciones"]') || tr;
        if (!acciones) return;
        if (acciones.querySelector('.gw-btn-asist')) return;

        var uid = 0;
        var holder = tr.querySelector('[data-user-id]');
        if (holder) uid = parseInt(holder.getAttribute('data-user-id'), 10) || 0;
        if (!uid) {
          var btnEdit = acciones.querySelector('button, a');
          if (btnEdit) {
            var keys = ['data-user-id','data-id','data-uid','data-user'];
            for (var i=0;i<keys.length;i++){ var v = btnEdit.getAttribute(keys[i]); if (v){ uid = parseInt(v,10)||0; break; } }
          }
        }
        if (!uid) return;

        var b = document.createElement('button');
        b.className = 'button gw-btn-asist';
        b.textContent = 'Asistencias';
        b.style.marginLeft = '-1px';
        b.addEventListener('click', function(){ openAsist(uid); });
        acciones.appendChild(b);
      });
    }
    addAsistButtons();
    var mo = new MutationObserver(function(muts){
      muts.forEach(function(m){
        m.addedNodes && Array.prototype.forEach.call(m.addedNodes, function(n){
          if (n.nodeType === 1) addAsistButtons(n);
        });
      });
    });
    mo.observe(document.documentElement, {subtree:true, childList:true});
  })();
  </script>
  <?php
});

// Hook JS para manejar [data-gw-attend] dentro del modal y refrescar su contenido
add_action('wp_footer', function(){
  if (!is_user_logged_in()) return; $u = wp_get_current_user();
  if (!( in_array('administrator',$u->roles) || current_user_can('manage_options') )) return;
  $req = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
  if (strpos($req, 'panel-administrativo') === false) return;
  $nonce = wp_create_nonce('gw_asist_admin');
  $ajax  = admin_url('admin-ajax.php');
  ?>
  <script>
  (function(){
    var NONCE = '<?php echo esc_js($nonce); ?>';
    var AJAX  = '<?php echo esc_js($ajax); ?>';

    function refreshAsistModal(uid){
      var fd = new FormData();
      fd.append('action','gw_admin_asist_modal');
      fd.append('nonce', NONCE);
      fd.append('user_id', uid);
      fetch(AJAX, {method:'POST', credentials:'same-origin', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(res){
          if(res && res.success && res.data && res.data.html){
            var m = document.getElementById('gw-asist-modal');
            if (m) { m.outerHTML = res.data.html; }
            else {
              var tmp = document.createElement('div');
              tmp.innerHTML = res.data.html;
              var dlg = tmp.firstElementChild;
              if (dlg) document.body.appendChild(dlg);
            }
          }
        });
    }

    document.addEventListener('click', function(ev){
      var b = ev.target.closest('[data-gw-attend]');
      if (!b) return;
      ev.preventDefault(); ev.stopPropagation();
      var uid = b.getAttribute('data-user');
      var typ = b.getAttribute('data-type');
      var eid = b.getAttribute('data-id');
      var att = b.getAttribute('data-gw-attend');

      var fd  = new FormData();
      fd.append('action','gw_admin_asist_hist_toggle');
      fd.append('nonce', NONCE);
      fd.append('user_id', uid);
      fd.append('type', typ);
      fd.append('entity_id', eid);
      fd.append('attend', att);

      fetch(AJAX, {method:'POST', credentials:'same-origin', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(res){
          if(res && res.success){ refreshAsistModal(uid); }
          else { alert((res && res.data && res.data.msg) || 'No se pudo guardar el historial'); }
        })
        .catch(function(){ alert('Error de red'); });
    });
  })();
  </script>
  <?php
});

// ===== Asistencias — Persistencia de Historial (no toca la lógica existente) =====
// Charlas: cuando un ID sale de 'gw_charlas_asignadas' => lo agrega a 'gw_charlas_historial'.
//          cuando un ID vuelve a entrar (revertir)     => lo quita de 'gw_charlas_historial'.
// Capacitación: cuando se limpia 'gw_capacitacion_agendada' => lo agrega a 'gw_capacitacion_historial'.
add_filter('update_user_metadata', 'gw_att_hist_on_update', 10, 5);
if (!function_exists('gw_att_hist_on_update')) {
function gw_att_hist_on_update($check, $user_id, $meta_key, $meta_value, $prev_value){

  // === CHARLAS ===
  if ($meta_key === 'gw_charlas_asignadas') {
    $old = get_user_meta($user_id, 'gw_charlas_asignadas', true);
    $old = is_array($old) ? array_map('intval', $old) : [];
    $new = is_array($meta_value) ? array_map('intval', $meta_value) : [];

    $removed = array_diff($old, $new); // salieron => asistió
    $added   = array_diff($new, $old); // entraron => revertido

    if (!empty($removed)) {
      $hist = get_user_meta($user_id, 'gw_charlas_historial', true);
      if (!is_array($hist)) $hist = [];
      $nowTs = current_time('timestamp');

      foreach ($removed as $cid) {
        $cid = (int) $cid;
        // evita duplicados
        $dup = false;
        foreach ($hist as $row) {
          if ((int)($row['id'] ?? 0) === $cid) { $dup = true; break; }
        }
        if ($dup) continue;

        $hist[] = [
          'id'     => $cid,
          'title'  => get_the_title($cid),
          'fecha'  => date_i18n('Y-m-d', $nowTs),
          'hora'   => date_i18n('H:i',   $nowTs),
          'status' => 'asistio',
          'ts'     => $nowTs,
        ];
      }
      update_user_meta($user_id, 'gw_charlas_historial', array_values($hist));
    }

    if (!empty($added)) {
      $hist = get_user_meta($user_id, 'gw_charlas_historial', true);
      if (is_array($hist) && !empty($hist)) {
        $keep = [];
        foreach ($hist as $row) {
          $rid = (int) ($row['id'] ?? 0);
          if (!in_array($rid, $added, true)) $keep[] = $row;
        }
        update_user_meta($user_id, 'gw_charlas_historial', $keep);
      }
    }
  }

  // === CAPACITACIÓN ===
  if ($meta_key === 'gw_capacitacion_agendada') {
    $old = get_user_meta($user_id, 'gw_capacitacion_agendada', true);
    $old = is_array($old) ? $old : [];
    $new = is_array($meta_value) ? $meta_value : [];

    $was_set = !empty($old) && !empty($old['cap_id']);
    $is_set  = !empty($new) && !empty($new['cap_id']);

    // si antes había y ahora no, lo damos por asistido en historial
    if ($was_set && !$is_set) {
      $hist = get_user_meta($user_id, 'gw_capacitacion_historial', true);
      if (!is_array($hist)) $hist = [];
      $nowTs = current_time('timestamp');

      $cap_id = (int) ($old['cap_id'] ?? 0);
      $hist[] = [
        'cap_id' => $cap_id,
        'title'  => get_the_title($cap_id),
        'fecha'  => (string) ($old['fecha'] ?? date_i18n('Y-m-d', $nowTs)),
        'hora'   => (string) ($old['hora']  ?? date_i18n('H:i',   $nowTs)),
        'status' => 'asistio',
        'ts'     => $nowTs,
      ];
      update_user_meta($user_id, 'gw_capacitacion_historial', array_values($hist));
    }
  }

  // No cortocircuitamos la update original
  return $check;
}}

// ===== Notificaciones — Tabla dedicada =====
function gw_notif_table(){
  global $wpdb;
  return $wpdb->prefix . 'notificaciones'; // ej: wp_notificaciones
}

// Crea/actualiza la tabla si no existe
add_action('plugins_loaded', function(){
  global $wpdb;
  $table   = gw_notif_table();
  $charset = $wpdb->get_charset_collate();

  $sql = "CREATE TABLE {$table} (
    id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      BIGINT(20) UNSIGNED NOT NULL,
    `type`       VARCHAR(20) NOT NULL,
    entity_id    BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
    title        VARCHAR(255) NOT NULL,
    body         TEXT NULL,
    `status`     VARCHAR(10) NOT NULL DEFAULT 'UNREAD',
    resolved     TINYINT(1) NOT NULL DEFAULT 0,
    created_at   DATETIME NOT NULL,
    read_at      DATETIME NULL,
    resolved_at  DATETIME NULL,
    resolved_by  BIGINT(20) UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_user_status  (user_id, `status`),
    KEY idx_user_created (user_id, created_at),
    KEY idx_resolved     (resolved),
    KEY idx_type         (`type`)
  ) {$charset};";

  require_once ABSPATH . 'wp-admin/includes/upgrade.php';
  dbDelta($sql);
});

// ===============================
// Notificaciones (campana y feed)
// ===============================

// Estructura de almacenamiento: opción con los últimos N eventos.
if (!function_exists('gw_notif_log')) {
  function gw_notif_log($type, $user_id, $related_id = 0, $title = '', $text = ''){
    global $wpdb;
    $table = gw_notif_table();
    $type  = strtoupper(sanitize_key($type)); // CHARLA | CAPACITACION | DOCUMENTO
    $user  = intval($user_id);
    $rid   = intval($related_id);
    $title = wp_strip_all_tags($title);
    $text  = wp_kses_post($text);
    $now   = current_time('mysql');

    $wpdb->insert($table, [
      'user_id'    => $user,
      'type'       => $type,
      'entity_id'  => $rid,
      'title'      => $title,
      'body'       => $text,
      'status'     => 'UNREAD',
      'resolved'   => 0,
      'created_at' => $now,
    ], ['%d','%s','%d','%s','%s','%s','%d','%s']);

    return intval($wpdb->insert_id);
  }
}

if (!function_exists('gw_notif_max_id')) {
  function gw_notif_max_id(){
    global $wpdb; $t = gw_notif_table();
    $id = (int)$wpdb->get_var("SELECT IFNULL(MAX(id),0) FROM {$t}");
    return $id;
  }
}

// Marca como “resuelta” (p.ej. al aprobar asistencia)
if (!function_exists('gw_notif_mark_done')) {
  function gw_notif_mark_done($type, $uid, $rid){
    global $wpdb; $t = gw_notif_table();
    $type = strtoupper(sanitize_key($type));
    $uid  = intval($uid);
    $rid  = intval($rid);
    $now  = current_time('mysql');
    // Si no tenemos entity_id (p.ej. charla marcada por “key”), resolvemos todas de ese tipo del usuario que estén pendientes.
    if ($rid > 0) {
      $wpdb->query( $wpdb->prepare(
        "UPDATE {$t} SET resolved=1, resolved_at=%s, resolved_by=%d WHERE user_id=%d AND type=%s AND entity_id=%d AND resolved=0",
        $now, get_current_user_id(), $uid, $type, $rid
      ));
    } else {
      $wpdb->query( $wpdb->prepare(
        "UPDATE {$t} SET resolved=1, resolved_at=%s, resolved_by=%d WHERE user_id=%d AND type=%s AND resolved=0",
        $now, get_current_user_id(), $uid, $type
      ));
    }
  }
}

// Cantidad para el badge rojo (solo eventos pendientes relevantes)
if (!function_exists('gw_notif_pending_count')) {
  function gw_notif_pending_count(){
    global $wpdb; $t = gw_notif_table();
    // Solo CHARLA y CAPACITACION cuentan para el badge, y solo mientras no estén resueltas
    $n = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE type IN ('CHARLA','CAPACITACION') AND resolved=0");
    return $n;
  }
}

// Últimos N (orden cron desc). $since_id opcional, igual que tu firma actual.
if (!function_exists('gw_notif_fetch')) {
  function gw_notif_fetch($since_id = 0, $limit = 40){
    global $wpdb; $t = gw_notif_table();
    $since_id = intval($since_id);
    $limit    = max(1, min(80, intval($limit)));

    $sql = $since_id > 0
      ? $wpdb->prepare("SELECT * FROM {$t} WHERE id > %d ORDER BY id DESC LIMIT %d", $since_id, $limit)
      : $wpdb->prepare("SELECT * FROM {$t} ORDER BY id DESC LIMIT %d", $limit);

    $rows = $wpdb->get_results($sql, ARRAY_A);
    if (!$rows) return [];

    $out  = [];
    foreach ($rows as $r){
      $u = get_user_by('id', intval($r['user_id']));
      $r['user_name'] = $u ? ($u->display_name ?: $u->user_login) : 'Usuario';
      $ts  = strtotime($r['created_at']);
      $r['time_h'] = $ts ? date_i18n('Y-m-d H:i', $ts) : '';
      $out[] = $r;
    }
    return $out;
  }
}

// Hooks automáticos cuando cambian metas clave del usuario
if (!function_exists('gw_notif_on_user_meta')) {
  function gw_notif_on_user_meta($meta_id, $user_id, $meta_key, $_meta_value){
    $key = (string)$meta_key;
    $val = maybe_unserialize($_meta_value);
    if (!is_array($val)) {
      $val = get_user_meta($user_id, $meta_key, true);
      if (!is_array($val)) $val = [];
    }

    // 1) Inscripción a charla
    if ($key === 'gw_charla_agendada' && !empty($val)) {
      $title = isset($val['charla_title']) ? $val['charla_title'] : ( isset($val['charla']) ? $val['charla'] : 'Charla' );
      $fecha = isset($val['fecha']) ? $val['fecha'] : '';
      $hora  = isset($val['hora'])  ? $val['hora']  : '';
      $rid   = isset($val['charla_id']) ? intval($val['charla_id']) : 0;
      gw_notif_log('charla', $user_id, $rid, 'Nueva inscripción a charla', $title.' — '.$fecha.' '.$hora);
      return;
    }

    // 2) Inscripción a capacitación
    if ($key === 'gw_capacitacion_agendada' && !empty($val)) {
      $title = isset($val['cap_title']) ? $val['cap_title'] : ( isset($val['capacitacion']) ? $val['capacitacion'] : 'Capacitación' );
      $fecha = isset($val['fecha']) ? $val['fecha'] : '';
      $hora  = isset($val['hora'])  ? $val['hora']  : '';
      $rid   = isset($val['cap_id']) ? intval($val['cap_id']) : 0;
      gw_notif_log('cap', $user_id, $rid, 'Nueva inscripción a capacitación', $title.' — '.$fecha.' '.$hora);
      return;
    }

    // 3) Envío de documentos (último paso)
    $doc_keys = [
      'gw_docs_entregados','gw_docs_subidos','gw_docs_enviados','gw_docs_finalizados','gw_documentos_subidos','gw_docs_status'
    ];
    if (in_array($key, $doc_keys, true)) {
      $status_txt = is_string($_meta_value) ? sanitize_text_field($_meta_value) : '';
      if (is_array($val) && isset($val['status'])) { $status_txt = sanitize_text_field($val['status']); }
      gw_notif_log('docs', $user_id, 0, 'Documentos enviados', ($status_txt ? ('Estado: '.$status_txt) : 'Documentos listos para revisión'));
      return;
    }
  }
}

add_action('added_user_meta', 'gw_notif_on_user_meta', 10, 4);
add_action('updated_user_meta', 'gw_notif_on_user_meta', 10, 4);


// ====== AJAX: obtener y marcar como leídas (DB) ======
add_action('wp_ajax_gw_notif_fetch', function(){
  if (!is_user_logged_in()) wp_send_json_error(['msg'=>'No logueado']);
  $u = wp_get_current_user();
  if (!( in_array('administrator',$u->roles) || current_user_can('manage_options') || current_user_can('coach') || current_user_can('coordinador_pais') )) {
    wp_send_json_error(['msg'=>'No autorizado']);
  }
  $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
  if (!wp_verify_nonce($nonce, 'gw_notif')) wp_send_json_error(['msg'=>'Nonce inválido']);

  $last_seen = intval( get_user_meta($u->ID, 'gw_notif_last_seen', true) );
  $list   = gw_notif_fetch(0, 25);
  $max_id = gw_notif_max_id();
  $unread = max(0, $max_id - $last_seen);
  $badge  = gw_notif_pending_count();

  wp_send_json_success([
    'items'  => $list,
    'unread' => $unread,
    'badge'  => $badge,
    'max_id' => $max_id,
  ]);
});

add_action('wp_ajax_gw_notif_mark_seen', function(){
  if (!is_user_logged_in()) wp_send_json_error(['msg'=>'No logueado']);
  $u = wp_get_current_user();
  if (!( in_array('administrator',$u->roles) || current_user_can('manage_options') || current_user_can('coach') || current_user_can('coordinador_pais') )) {
    wp_send_json_error(['msg'=>'No autorizado']);
  }
  $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
  if (!wp_verify_nonce($nonce, 'gw_notif')) wp_send_json_error(['msg'=>'Nonce inválido']);

  // Marcamos leídas globalmente (no cambia el badge)
  update_user_meta($u->ID, 'gw_notif_last_seen', gw_notif_max_id());

  // Opcional: marcar status=READ para todo lo que estaba UNREAD (no afecta badge)
  global $wpdb; $t = gw_notif_table();
  $wpdb->query("UPDATE {$t} SET status='READ', read_at = NOW() WHERE status='UNREAD'");

  wp_send_json_success(['unread'=>0]);
});

// ===============================
// Tickets de voluntarios (admin)
// ===============================

// Contador de tickets pendientes (no resueltos)
if (!function_exists('gw_ticket_count_pending')) {
  function gw_ticket_count_pending(){
    global $wpdb; $t = gw_notif_table();
    return (int)$wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE type='TICKET' AND resolved=0");
  }
}

// Obtener últimos tickets (incluye nombre/email del voluntario)
if (!function_exists('gw_ticket_fetch')) {
  function gw_ticket_fetch($limit = 80){
    global $wpdb; $t = gw_notif_table();
    $limit = max(1, min(200, intval($limit)));
    $rows = $wpdb->get_results(
      $wpdb->prepare("SELECT * FROM {$t} WHERE type='TICKET' ORDER BY id DESC LIMIT %d", $limit),
      ARRAY_A
    );
    if (!$rows) return [];
    foreach ($rows as &$r){
      $vid = intval($r['entity_id']); // voluntario que creó el ticket
      $u = $vid ? get_user_by('id', $vid) : null;
      $r['vol_name']  = $u ? ($u->display_name ?: $u->user_login) : ('Voluntario #'.$vid);
      $r['vol_email'] = $u ? $u->user_email : '';
      $r['time_h']    = $r['created_at'] ? date_i18n('Y-m-d H:i', strtotime($r['created_at'])) : '';
    }
    return $rows;
  }
}

// AJAX: listar tickets para el panel admin
add_action('wp_ajax_gw_ticket_admin_fetch', function(){
  if (!is_user_logged_in()) wp_send_json_error(['msg'=>'No logueado']);
  $u = wp_get_current_user();
  if (!( in_array('administrator',$u->roles) || current_user_can('manage_options') || current_user_can('coach') || current_user_can('coordinador_pais') )) {
    wp_send_json_error(['msg'=>'No autorizado']);
  }
  $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
  if (!wp_verify_nonce($nonce, 'gw_ticket')) wp_send_json_error(['msg'=>'Nonce inválido']);

  $items = gw_ticket_fetch(80);
  $badge = gw_ticket_count_pending();
  wp_send_json_success(['items'=>$items, 'badge'=>$badge]);
});

// AJAX: resolver ticket y notificar al voluntario
add_action('wp_ajax_gw_ticket_admin_resolve', function(){
  if (!is_user_logged_in()) wp_send_json_error(['msg'=>'No logueado']);
  $u = wp_get_current_user();
  if (!( in_array('administrator',$u->roles) || current_user_can('manage_options') || current_user_can('coach') || current_user_can('coordinador_pais') )) {
    wp_send_json_error(['msg'=>'No autorizado']);
  }
  $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
  if (!wp_verify_nonce($nonce, 'gw_ticket')) wp_send_json_error(['msg'=>'Nonce inválido']);

  $id = intval($_POST['id'] ?? 0);
  if (!$id) wp_send_json_error(['msg'=>'ID inválido']);

  global $wpdb; $t = gw_notif_table();

  // Traer ticket
  $row = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$t} WHERE id=%d AND type='TICKET'", $id), ARRAY_A );
  if (!$row) wp_send_json_error(['msg'=>'Ticket no encontrado']);

  // Marcar resuelto
  $wpdb->update($t, [
    'resolved'    => 1,
    'status'      => 'READ',
    'resolved_at' => current_time('mysql'),
    'resolved_by' => get_current_user_id(),
    'read_at'     => current_time('mysql'),
  ], ['id'=>$id], ['%d','%s','%s','%d','%s'], ['%d']);

  // Notificar al voluntario
  $vol_id = intval($row['entity_id']);
  if ($vol_id > 0 && function_exists('gw_notif_log')) {
    gw_notif_log('TICKET', $vol_id, 0, 'Solicitud corregida', 'Tu solicitud fue corregida por el administrador.');
  }

  $badge = gw_ticket_count_pending();
  wp_send_json_success(['done'=>1, 'badge'=>$badge]);
});

// ====== UI: campana fija en panel administrativo ======
add_action('wp_footer', function(){
  if (!is_user_logged_in()) return;
  $u = wp_get_current_user();
  if (!( in_array('administrator',$u->roles) || current_user_can('manage_options') || current_user_can('coach') || current_user_can('coordinador_pais') )) return;
  $req = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
  if (strpos($req, 'panel-administrativo') === false) return;
  $ajax  = admin_url('admin-ajax.php');
  $nonce = wp_create_nonce('gw_notif');
  ?>
<style>
  #gw-notif-btn {
    position: fixed;
    background: white;
    top: 20px;
    right: 20px;
    z-index: 100005;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 12px 16px;
    display: flex;
    gap: 8px;
    align-items: center;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08), 0 2px 4px rgba(0, 0, 0, 0.04);
    backdrop-filter: blur(10px);
  }

  #gw-notif-btn:hover {
    transform: translateY(-2px) scale(1.02);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12), 0 4px 8px rgba(0, 0, 0, 0.06);
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border-color: #cbd5e1;
  }

  #gw-notif-btn .icon {
    width: 20px;
    height: 20px;
    color: #64748b;
    transition: color 0.2s ease;
  }

  #gw-notif-btn:hover .icon {
    color: #3b82f6;
  }

  #gw-notif-btn .badge {
    min-width: 20px;
    height: 20px;
    border-radius: 10px;
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: white;
    font-size: 11px;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0 6px;
    letter-spacing: 0.5px;
    box-shadow: 0 2px 4px rgba(239, 68, 68, 0.3);
    animation: pulse 2s infinite;
  }

  @keyframes pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.1); }
  }

  #gw-notif-panel {
    position: fixed;
    top: 132px;
    right: 50px;
    width: 380px;
    max-width: 92vw;
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    box-shadow: 0 20px 50px rgba(0, 0, 0, 0.15), 0 8px 20px rgba(0, 0, 0, 0.08);
    z-index: 100004;
    display: none;
    overflow: hidden;
    backdrop-filter: blur(20px);
    animation: slideIn 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  }

  @keyframes slideIn {
    from {
      opacity: 0;
      transform: translateY(-10px) scale(0.95);
    }
    to {
      opacity: 1;
      transform: translateY(0) scale(1);
    }
  }

  #gw-notif-panel header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border-bottom: 1px solid #e5e7eb;
  }

  #gw-notif-panel header strong {
    font-size: 16px;
    font-weight: 600;
    color: #1f2937;
    display: flex;
    align-items: center;
    gap: 8px;
  }

  #gw-notif-panel header .actions {
    display: flex;
    gap: 8px;
  }

  #gw-notif-panel .button {
    padding: 6px 12px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    background: white;
    color: #374151;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
  }

  #gw-notif-panel .button:hover {
    background: #f9fafb;
    border-color: #d1d5db;
    transform: translateY(-1px);
  }

  #gw-notif-mark {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%) !important;
    color: white !important;
    border-color: #2563eb !important;
  }

  #gw-notif-mark:hover {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%) !important;
    border-color: #1d4ed8 !important;
  }

  #gw-notif-list {
    max-height: 400px;
    overflow-y: auto;
    background: white;
    scrollbar-width: thin;
    scrollbar-color: #cbd5e1 #f1f5f9;
  }

  #gw-notif-list::-webkit-scrollbar {
    width: 6px;
  }

  #gw-notif-list::-webkit-scrollbar-track {
    background: #f1f5f9;
  }

  #gw-notif-list::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 3px;
  }

  #gw-notif-list::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
  }

  #gw-notif-list .item {
    padding: 16px 20px;
    border-bottom: 1px solid #f1f5f9;
    transition: background-color 0.2s ease;
    position: relative;
  }

  #gw-notif-list .item:hover {
    background: #f8fafc;
  }

  #gw-notif-list .item:last-child {
    border-bottom: none;
  }

  #gw-notif-list .item.unread {
    background: linear-gradient(90deg, rgba(59, 130, 246, 0.05) 0%, rgba(59, 130, 246, 0.02) 100%);
    border-left: 3px solid #3b82f6;
  }

  #gw-notif-list .item .content {
    display: flex;
    gap: 12px;
    align-items: flex-start;
  }

  #gw-notif-list .item .icon {
    width: 32px;
    height: 32px;
    background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    color: #4f46e5;
  }

  #gw-notif-list .item .text {
    flex: 1;
    min-width: 0;
  }

  #gw-notif-list .item .title {
    font-weight: 600;
    color: #1f2937;
    font-size: 14px;
    line-height: 1.4;
    margin-bottom: 4px;
  }

  #gw-notif-list .item .meta {
    font-size: 12px;
    color: #64748b;
    display: flex;
    align-items: center;
    gap: 8px;
  }

  #gw-notif-list .item .time {
    color: #94a3b8;
    font-size: 11px;
  }

  #gw-notif-list .empty {
    text-align: center;
    padding: 40px 20px;
    color: #6b7280;
  }

  #gw-notif-list .empty .icon {
    width: 48px;
    height: 48px;
    background: #f3f4f6;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 12px;
    color: #9ca3af;
  }

  /* Toque de icono y énfasis */
  #gw-notif-panel .item .icon{ font-size: 15px; }
  #gw-notif-panel .item.unread .title{ font-weight: 700; }

  /* Responsive */
  @media (max-width: 480px) {
    #gw-notif-btn {
      top: 16px;
      right: 16px;
      padding: 10px 12px;
      margin-top: 3rem;
    
    }

    #gw-notif-panel {
        top: 9rem;
        right: 0.8rem;
    }

    #gw-notif-panel header {
      padding: 12px 16px;
      flex-direction: column;
      gap: 8px;
      text-align: center;
    }

    #gw-notif-list .item {
      padding: 12px 16px;
    }
  }

  /* Dark mode support */
  @media (prefers-color-scheme: dark) {
    #gw-notif-btn {
      background: linear-gradient(135deg, #c4c33f 100%);
      color: #e5e7eb;
      margin-top: 3rem;

      @media (min-width: 768px) {
    #gw-notif-btn {

      margin-top: 1rem;
    
    }
    }
  }

    #gw-notif-panel {
      background: #1f2937;
      border-color: #374151;
    }

    #gw-notif-panel header {
      background: linear-gradient(135deg, #374151 0%, #1f2937 100%);
      border-color: #4b5563;
    }

    #gw-notif-list .item {
      border-color: #374151;
    }

    #gw-notif-list .item:hover {
      background: #374151;
    }
  }
</style>

<div id="gw-notif-btn" title="Notificaciones" role="button" tabindex="0">
  <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
    <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
  </svg>
  <span class="badge" id="gw-notif-badge" style="display:none">0</span>
</div>

<div id="gw-notif-panel" role="dialog" aria-label="Panel de Notificaciones">
  <header>
    <strong>
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
        <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
      </svg>
      Notificaciones
    </strong>
    <div class="actions">
      <button id="gw-notif-mark" class="button">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <polyline points="20,6 9,17 4,12"></polyline>
        </svg>
        Marcar como leídas
      </button>
      <button id="gw-notif-close" class="button">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <line x1="18" y1="6" x2="6" y2="18"></line>
          <line x1="6" y1="6" x2="18" y2="18"></line>
        </svg>
        Cerrar
      </button>
    </div>
  </header>
  
  <div id="gw-notif-list">
    <div class="empty">
      <div class="icon">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
          <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
        </svg>
      </div>
      <div class="title">No hay notificaciones</div>
      <div class="meta">Todas las notificaciones aparecerán aquí</div>
    </div>
  </div>
</div>

<script>
  
  // Panel de notificaciones Funcionalidad de la campanita (con backend vía AJAX/DB)
document.addEventListener('DOMContentLoaded', function () {
  var btn     = document.getElementById('gw-notif-btn');
  var panel   = document.getElementById('gw-notif-panel');
  var closeBtn= document.getElementById('gw-notif-close');
  var markBtn = document.getElementById('gw-notif-mark');
  var list    = document.getElementById('gw-notif-list');
  var badge   = document.getElementById('gw-notif-badge');

  // URLs/nonce del backend (vienen del PHP de este mismo bloque)
  var AJAX  = "<?php echo esc_js($ajax); ?>";
  var NONCE = "<?php echo esc_js($nonce); ?>";

  var openedOnce = false;
  var poller = null;

  function setBadge(n){
    n = parseInt(n,10) || 0;
    if (n > 0) { badge.style.display = 'inline-flex'; badge.textContent = n; }
    else { badge.style.display = 'none'; badge.textContent = '0'; }
  }
  // Disponible global para que otros flujos (p.ej. marcar asistencia) lo usen:
  window.gwNotifUpdateBadge = setBadge;

  // helper simple para evitar inyecciones en HTML
  function esc(s){
    return String(s || '')
      .replace(/&/g,'&amp;').replace(/</g,'&lt;')
      .replace(/>/g,'&gt;').replace(/"/g,'&quot;')
      .replace(/'/g,'&#039;');
  }

  function renderItems(items){
    // Limpia la lista
    list.innerHTML = '';

    if (!items || !items.length){
      list.innerHTML = '<div class="empty"><div class="icon">🔔</div><div class="title">No hay notificaciones</div><div class="meta">Todas las notificaciones aparecerán aquí</div></div>';
      return;
    }

    // Mapas de icono y etiqueta de tipo
    var iconMap  = { CHARLA:'🗣️', CAPACITACION:'🎓', DOCUMENTO:'📎' };
    var labelMap = { CHARLA:'Charla', CAPACITACION:'Capacitación', DOCUMENTO:'Documento' };

    var html = '';
    items.forEach(function(it){
      var type = String(it.type || '').toUpperCase();
      var ico  = iconMap[type] || '🔔';
      var lbl  = labelMap[type] || 'Notificación';
      var unreadCls = (String(it.status).toUpperCase()==='UNREAD') ? ' unread' : '';

      html += ''
        + '<div class="item'+ unreadCls +'">'
        +   '<div class="content">'
        +     '<div class="icon">'+ ico +'</div>'
        +     '<div class="text">'
        +       '<div class="title"><strong>'+ lbl +'</strong> — '+ esc(it.title) +'</div>'
        +       '<div class="meta">'
        +         (it.user_name ? esc(it.user_name) : '')
        +         (it.body ? ' · ' + esc(it.body) : '')
        +         (it.time_h ? ' <span class="time">'+ esc(it.time_h) +'</span>' : '')
        +       '</div>'
        +     '</div>'
        +   '</div>'
        + '</div>';
    });

    list.innerHTML = html;
  }

  function fetchNotifs(){
    var fd = new FormData();
    fd.append('action', 'gw_notif_fetch');
    fd.append('nonce', NONCE);
    return fetch(AJAX, {method:'POST', credentials:'same-origin', body:fd})
      .then(function(r){ return r.json(); })
      .then(function(resp){
        if (!resp || !resp.success) throw new Error('Resp NOK');
        var d = resp.data || {};
        renderItems(d.items || []);
        // OJO: el badge representa PENDIENTES (no resueltas). No se borra al abrir.
        setBadge(d.badge || 0);
        return d;
      });
  }

  function markSeen(){
    var fd = new FormData();
    fd.append('action','gw_notif_mark_seen');
    fd.append('nonce', NONCE);
    return fetch(AJAX, {method:'POST', credentials:'same-origin', body:fd})
      .then(function(r){ return r.json(); })
      .then(function(resp){
        // Quitamos aspecto "unread" en UI, pero NO tocamos el badge rojo
        var unread = list.querySelectorAll('.item.unread');
        unread.forEach(function(n){ n.classList.remove('unread'); });
      });
  }

  function openPanel(){
    panel.style.display = 'block';
    if (!openedOnce){
      fetchNotifs().catch(function(){});
      openedOnce = true;
    }
    // Poll suave mientras está abierto
    if (!poller){
      poller = setInterval(function(){ fetchNotifs().catch(function(){}); }, 20000);
    }
  }
  function closePanel(){
    panel.style.display = 'none';
    if (poller){ clearInterval(poller); poller=null; }
  }

  // Toggle del panel
  btn.addEventListener('click', function(e){
    e.stopPropagation();
    var visible = panel.style.display === 'block';
    if (visible) closePanel(); else openPanel();
  });

  // Cerrar
  closeBtn.addEventListener('click', function(){ closePanel(); });
  document.addEventListener('click', function(e){
    if (!btn.contains(e.target) && !panel.contains(e.target)) closePanel();
  });

  // Marcar como leídas (NO toca el badge)
  markBtn.addEventListener('click', function(e){
    e.preventDefault();
    markSeen().catch(function(){});
  });

  // Primer precarga para que el badge aparezca al entrar al panel
  fetchNotifs().catch(function(){});
});
</script>

</script>
  <?php
});

// ====== UI: botón Ticket para admin ======
add_action('wp_footer', function(){
  if (!is_user_logged_in()) return;
  $u = wp_get_current_user();
  if (!( in_array('administrator',$u->roles) || current_user_can('manage_options') || current_user_can('coach') || current_user_can('coordinador_pais') )) return;
  $req = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
  if (strpos($req, 'panel-administrativo') === false) return;

  $ajax  = admin_url('admin-ajax.php');
  $nonce = wp_create_nonce('gw_ticket');
  ?>
<style>
  /* Asegura la campanita en la parte superior fija */
  #gw-notif-btn{
    position: fixed;
    top: 4.8rem !important;
    right: 11rem !important;
    margin-top: 0 !important;
    z-index: 100006;
  }

  /* Botón de tickets: SIEMPRE debajo de la campanita */
  #gw-ticket-btn{
    position:fixed; top:76px !important; right:50px !important; z-index:100005;
    background:#fff; border:1px solid #e2e8f0; border-radius:14px;
    padding:10px 14px; display:flex; gap:8px; align-items:center;
    box-shadow:0 4px 12px rgba(0,0,0,.08);
    cursor:pointer; transition:.2s;
  }
  #gw-ticket-btn:hover{ transform:translateY(-1px); }
  #gw-ticket-btn .badge{
    min-width:20px;height:20px;border-radius:10px;background:#10b981;color:#fff;
    display:none;align-items:center;justify-content:center;font-size:11px;font-weight:700;padding:0 6px;
  }

  /* Panel de tickets: alineado bajo el botón de tickets */
  #gw-ticket-panel{
    position:fixed; top:128px !important; right:50px !important; width:420px; max-width:92vw;
    background:#fff; border:1px solid #e2e8f0; border-radius:14px;
    box-shadow:0 20px 50px rgba(0,0,0,.15); z-index:100004; display:none; overflow:hidden;
  }
  #gw-ticket-panel header{
    display:flex;justify-content:space-between;align-items:center;
    padding:12px 16px;background:#f8fafc;border-bottom:1px solid #e5e7eb;
  }
  #gw-ticket-list{ max-height:420px; overflow:auto; }
  #gw-ticket-list .item{ padding:14px 16px; border-bottom:1px solid #f1f5f9; }
  #gw-ticket-list .item .title{ font-weight:600; margin-bottom:4px; }
  #gw-ticket-list .item .meta{ font-size:12px; color:#64748b; display:flex; flex-wrap:wrap; gap:8px;}
  #gw-ticket-list .item.resolved{ opacity:.6; }

  /* Responsive */
  @media (max-width:480px){
    #gw-notif-btn{ top: 5.8rem !important; right: 8.2rem !important; margin-top: 0 !important; height: 3rem;}
    #gw-ticket-btn{         top: 93px !important;
        right: 12px !important;
        width: 7rem;
        height: 3rem;}
    #gw-ticket-panel{ top: 150px !important;
        right: 11px !important;
        width: calc(100vw - 32px);}
  }
</style>

  <div id="gw-ticket-btn" title="Tickets de voluntarios">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/><path d="M3 7v10a2 2 0 0 0 2 2h14"/></svg>
    <span style="font-weight:600;">Tickets</span>
    <span class="badge" id="gw-ticket-badge">0</span>
  </div>

  <div id="gw-ticket-panel" role="dialog" aria-label="Tickets de voluntarios">
    <header>
      <strong>Tickets de voluntarios</strong>
      <div>
        <button id="gw-ticket-close" class="button">Cerrar</button>
      </div>
    </header>
    <div id="gw-ticket-list">
      <div class="item"><div class="meta">Cargando…</div></div>
    </div>
  </div>

  <script>
  (function(){
    var AJAX  = <?php echo wp_json_encode($ajax); ?>;
    var NONCE = <?php echo wp_json_encode($nonce); ?>;

    var btn   = document.getElementById('gw-ticket-btn');
    var panel = document.getElementById('gw-ticket-panel');
    var close = document.getElementById('gw-ticket-close');
    var list  = document.getElementById('gw-ticket-list');
    var badge = document.getElementById('gw-ticket-badge');
    var poll  = null;

    function setBadge(n){
      n = parseInt(n,10)||0;
      if (n>0){ badge.style.display='inline-flex'; badge.textContent = n; }
      else { badge.style.display='none'; badge.textContent='0'; }
    }

    function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;'); }

    function render(items){
      list.innerHTML = '';
      if (!items || !items.length){
        list.innerHTML = '<div class="item"><div class="meta">No hay tickets.</div></div>';
        return;
      }
      items.forEach(function(it){
        var div = document.createElement('div');
        div.className = 'item' + (parseInt(it.resolved,10)?' resolved':'');
        div.innerHTML =
          '<div class="title">🎫 ' + esc(it.title||'Ticket') + '</div>' +
          '<div class="meta">' +
            (it.vol_name ? esc(it.vol_name) : '') +
            (it.vol_email ? ' · ' + esc(it.vol_email) : '') +
            (it.time_h ? ' · ' + esc(it.time_h) : '') +
          '</div>' +
          (it.body ? '<div style="margin:6px 0 8px;color:#334155;">'+esc(it.body)+'</div>' : '') +
          (parseInt(it.resolved,10) ? '<div style="color:#10b981;font-weight:600;">Resuelto</div>'
                                    : '<button class="button button-primary gw-ticket-resolve" data-id="'+it.id+'">Marcar corregido</button>');
        list.appendChild(div);
      });
    }

    function fetchTickets(){
      var fd = new FormData();
      fd.append('action','gw_ticket_admin_fetch');
      fd.append('nonce', NONCE);
      return fetch(AJAX, {method:'POST', credentials:'same-origin', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(resp){
          if (!resp || !resp.success) throw new Error('Resp NOK');
          render(resp.data.items||[]);
          setBadge(resp.data.badge||0);
        }).catch(function(){});
    }

    function resolveTicket(id){
      var fd = new FormData();
      fd.append('action','gw_ticket_admin_resolve');
      fd.append('nonce', NONCE);
      fd.append('id', id);
      return fetch(AJAX, {method:'POST', credentials:'same-origin', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(resp){
          if (resp && resp.success){
            fetchTickets();
          }
        });
    }

    // Delegación: resolver
    document.addEventListener('click', function(e){
      var b = e.target.closest('.gw-ticket-resolve');
      if (!b) return;
      e.preventDefault();
      var id = parseInt(b.getAttribute('data-id'),10)||0;
      if (!id) return;
      resolveTicket(id);
    });

    function openPanel(){
      panel.style.display='block';
      fetchTickets();
      if (!poll) poll = setInterval(fetchTickets, 30000);
    }
    function closePanel(){
      panel.style.display='none';
      if (poll){ clearInterval(poll); poll=null; }
    }

    btn.addEventListener('click', function(e){
      e.stopPropagation();
      var vis = panel.style.display==='block';
      if (vis) closePanel(); else openPanel();
    });
    close.addEventListener('click', closePanel);
    document.addEventListener('click', function(e){
      if (!btn.contains(e.target) && !panel.contains(e.target)) closePanel();
    });

    // Cargar el badge al entrar al panel
    fetchTickets();
  })();
  </script>
  <?php
});

// === Admin: restablecer contraseña de un usuario (solo Administrador) ===
add_action('wp_ajax_gw_admin_set_user_password', 'gw_admin_set_user_password');
function gw_admin_set_user_password(){
  if ( ! is_user_logged_in() || ! current_user_can('manage_options') ) {
    wp_send_json_error(['msg' => 'No autorizado']);
  }
  $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
  if ( ! wp_verify_nonce($nonce, 'gw_admin_set_pass') ) {
    wp_send_json_error(['msg' => 'Nonce inválido/expirado']);
  }
  $user_id = intval($_POST['user_id'] ?? 0);
  $pass    = (string) ($_POST['pass'] ?? '');
  if ( $user_id <= 0 || $pass === '' ) {
    wp_send_json_error(['msg' => 'Datos incompletos.']);
  }
  if ( strlen($pass) < 6 ) {
    wp_send_json_error(['msg' => 'La contraseña debe tener al menos 6 caracteres.']);
  }
  // Actualiza la contraseña del usuario indicado
  wp_set_password($pass, $user_id);
  wp_send_json_success(['done' => 1]);
}

// === Panel Administrativo: inyectar campo "Nueva contraseña" en el modal Editar usuario ===
// Lo imprimimos en HEAD y en FOOTER para garantizar carga.
$gw_print_admin_pass_injector = function () {
  if ( ! is_user_logged_in() ) return;
  $u = wp_get_current_user();
  if ( ! ( in_array('administrator', $u->roles) || current_user_can('manage_options') ) ) return;
  $req = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
  if ( strpos($req, 'panel-administrativo') === false ) return;

  $nonce = wp_create_nonce('gw_admin_set_pass');
  $ajax  = admin_url('admin-ajax.php');

  // Lista de países para poblar el selector en el modal Editar
  $paises_posts = get_posts([
    'post_type'   => 'pais',
    'numberposts' => -1,
    'orderby'     => 'title',
    'order'       => 'ASC',
  ]);
  $paises_for_js = array_map(function($p){
    return [ 'id' => intval($p->ID), 'title' => $p->post_title ];
  }, $paises_posts);
  ?>
  <script>
  (function(){
    // Visibilidad en consola para verificar que el script cargó
    console.log('[GW] injector contraseña activo');

    // Asegura ajaxurl y nonce disponibles
    var AJAXURL = window.ajaxurl || '<?php echo esc_js($ajax); ?>';
    var GW_ADMIN_SET_PASS_NONCE = '<?php echo esc_js($nonce); ?>';
    var GW_PAISES = <?php echo wp_json_encode( $paises_for_js ); ?>;
    window.GW_PAISES = GW_PAISES;

    // Último user_id detectado al pulsar "Editar"
    var GW_LAST_UID = 0;

    // 1) Al pulsar un botón/enlace que diga "Editar" (o "Edit") guardamos el posible user_id
    document.addEventListener('click', function(ev){
      var el = ev.target.closest('button, a');
      if (!el) return;
      var t = (el.textContent || '').trim().toLowerCase();
      if (t === 'editar' || t === 'edit') {
        // leer posibles atributos/data-*
        var keys = ['data-user-id','data-id','data-uid','data-user'];
        for (var i=0;i<keys.length;i++){
          var v = el.getAttribute(keys[i]);
          if (v) { GW_LAST_UID = parseInt(v, 10) || GW_LAST_UID; }
        }
        var row = el.closest('[data-user-id],[data-id]');
        if (row) {
          var v2 = row.getAttribute('data-user-id') || row.getAttribute('data-id');
          if (v2) GW_LAST_UID = parseInt(v2, 10) || GW_LAST_UID;
        }
      }
    }, true);

    // 2) Inyecta el campo debajo del Email cuando el modal aparece
    function injectPassField(ctx){
      var roots = ctx ? [ctx] : Array.prototype.slice.call(document.querySelectorAll('.gw-user-edit-modal, .gw-user-modal, .modal, [role="dialog"], .swal2-container, .swal2-popup'));
      roots.forEach(function(root){
        // Ubica el campo Email en el modal existente
        var email = root.querySelector('input[type="email"], input[name*="email" i], input[id*="email" i]');
        if (!email) return;

        // Si ya insertamos los campos, no repetir
        if (!root.querySelector('#gwUserNewPass')) {
          var wrap = document.createElement('div');
          wrap.className = 'gw-passreset-field';
          wrap.innerHTML =
            '<label style="display:block;margin-top:12px;">Nueva contraseña</label>'+
            '<input style="    padding: 12px 16px;border: 2px solid #e2e8f0;border-radius: 8px;font-size: 14px;background: white;color: #374151;transition: all 0.3s ease;font-family: inherit;  width: 100%; line-height: 1.5; type="password" id="gwUserNewPass" placeholder="Dejar vacío para no cambiar" style="width:100%;margin-top:6px;">'+
            '<small style="display:block;color:#6b7280;margin-top:6px;     margin-left: 6px;">Mínimo 6 caracteres. Este cambio no afecta a usuarios que inician sesión con Google</small>'+
            '<label style="display:block;margin-top:12px;">Confirmar contraseña</label>'+
            '<input style="    padding: 12px 16px;border: 2px solid #e2e8f0;border-radius: 8px;font-size: 14px;background: white;color: #374151;transition: all 0.3s ease;font-family: inherit;  width: 100%; margin-bottom:12px; type="password" id="gwUserNewPass2" placeholder="Repite la nueva contraseña" style="width:100%;margin-top:6px;">';
          try{ email.insertAdjacentElement('afterend', wrap); }catch(e){}
        }

        // Mejoras en el selector de País: quita (ID) y proporciona <select>
        enhancePaisField(root);
      });
    }

    function enhancePaisField(root){
      root = root || document;

      // 1) Quitar "(ID)" de la etiqueta, si existe
      try {
        var labels = Array.prototype.slice.call(root.querySelectorAll('label'));
        labels.forEach(function(lb){
          var t = (lb.textContent || '').trim();
          if (/pa[íi]s\b/i.test(t) && /\(id\)/i.test(t)) {
            lb.textContent = t.replace(/\s*\(id\)\s*/i, '');
          }
        });
      } catch(e){}

      // 2) Localizar el input real del país (numérico o texto)
      var candidates = root.querySelectorAll(
        'input[name*="pais" i], input[id*="pais" i], input[name*="gw_pais" i]'
      );
      if (!candidates.length) return;

      Array.prototype.forEach.call(candidates, function(hiddenInput){
        // evitar repetir
        if (hiddenInput.dataset.gwPaisEnhanced === '1') return;

        // solo si es un input visible de texto/número (no select ya existente)
        var type = (hiddenInput.getAttribute('type') || '').toLowerCase();
        if (type && type !== 'text' && type !== 'number') return;

        // Crear <select> con todos los países
        var sel = document.createElement('select');
        sel.className = 'gwUserPaisSelect';
        sel.style.width = '100%';

        var currentVal = String(parseInt(hiddenInput.value, 10) || '');

        // Placeholder
        var def = document.createElement('option');
        def.value = '';
        def.textContent = 'Selecciona un país';
        sel.appendChild(def);

        // Poblar opciones
        (window.GW_PAISES || []).forEach(function(p){
          var opt = document.createElement('option');
          opt.value = String(p.id);
          opt.textContent = p.title || ('País ' + p.id);
          if (String(p.id) === currentVal) opt.selected = true;
          sel.appendChild(opt);
        });

        // Mantener sincronía con el input original para que el backend no cambie
        sel.addEventListener('change', function(){
          hiddenInput.value = this.value;
        });

        // Ocultar el input original y marcarlo como mejorado
        hiddenInput.style.display = 'none';
        hiddenInput.dataset.gwPaisEnhanced = '1';

        // Insertar el <select> antes del input original
        try { hiddenInput.parentNode.insertBefore(sel, hiddenInput); } catch(e){}
      });
    }
    // Observa DOM para detectar el modal cuando se inserta
    var mo = new MutationObserver(function(muts){
      muts.forEach(function(m){
        m.addedNodes && Array.prototype.forEach.call(m.addedNodes, function(n){
          if (n.nodeType === 1){
            injectPassField(n);
            enhancePaisField(n);
          }
        });
      });
    });
    mo.observe(document.documentElement, {subtree:true, childList:true});
    // Intento inicial
    injectPassField();
    enhancePaisField(document);

    // 3) Al hacer clic en "Guardar" dentro del modal, si hay nueva contraseña => AJAX
    document.addEventListener('click', function(ev){
      var btn = ev.target.closest('button, input[type="submit"]');
      if (!btn) return;
      var txt = (btn.value || btn.textContent || '').trim().toLowerCase();
      if (txt !== 'guardar' && txt !== 'save') return; // botón principal del modal

      var modal = btn.closest('.gw-user-edit-modal, .gw-user-modal, .modal, [role="dialog"], .swal2-container, .swal2-popup') || document;
      var passEl = modal.querySelector('#gwUserNewPass');
      if (!passEl || !passEl.value) return; // no hay cambio de contraseña
      var pass2 = modal.querySelector('#gwUserNewPass2');
      if (passEl.value.length < 6) { alert('La contraseña debe tener al menos 6 caracteres.'); return; }
      if (pass2 && pass2.value !== passEl.value) { alert('Las contraseñas no coinciden.'); return; }

      // Intentar obtener el ID del usuario desde el modal
      var uid = 0;
      var hid = modal.querySelector('input[type="hidden"][name*="user" i][name*="id" i], input[type="hidden"][id*="user" i][id*="id" i]');
      if (hid) uid = parseInt(hid.value, 10) || 0;
      if (!uid) {
        var holder = modal.querySelector('[data-user-id]') || document.querySelector('tr.is-editing,[data-user-id]');
        if (holder) uid = parseInt(holder.getAttribute('data-user-id'), 10) || 0;
      }
      if (!uid) { uid = GW_LAST_UID || 0; }
      if (!uid) { console.warn('[GW] No se pudo detectar user_id para cambio de contraseña'); return; }

      var fd = new FormData();
      fd.append('action', 'gw_admin_set_user_password');
      fd.append('nonce', GW_ADMIN_SET_PASS_NONCE);
      fd.append('user_id', uid);
      fd.append('pass', passEl.value);

      fetch(AJAXURL, { method:'POST', credentials:'same-origin', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(resp){
        if (resp && resp.success) {
          passEl.value = ''; // limpia el campo
          if (pass2) pass2.value = '';
          console.log('[GW] Contraseña actualizada para user_id', uid);
        } else {
          var msg = (resp && resp.data && resp.data.msg) ? resp.data.msg : 'No se pudo actualizar la contraseña.';
          alert(msg);
        }
      })
      .catch(function(){ alert('Error de red al actualizar la contraseña.'); });
    }, true);
  })();
  </script>
  <?php
};
// Imprimir en head y en footer (redundancia segura)
add_action('wp_head',   $gw_print_admin_pass_injector, 99);
add_action('wp_footer', $gw_print_admin_pass_injector, 1);

// === Fallback JS globals for Gestión de usuarios buttons (Editar/Desactivar/Historial) ===
// If older inline onclicks call window.gwUserEdit / gwUserToggle / gwUserHistory but those
// functions are not present (due to scope/IIFE changes), provide robust global implementations.
add_action('wp_head', function(){
  if ( ! is_user_logged_in() ) return;
  $req = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
  // Only inject on the admin panel page
  if (strpos($req, 'panel-administrativo') === false) return;

  $ajax  = admin_url('admin-ajax.php');
  $nonce = wp_create_nonce('gw_admin_users');
  ?>
  <script>
  (function(){
    var AJAX  = "<?php echo esc_js($ajax); ?>";
    var NONCE = "<?php echo esc_js($nonce); ?>";

    function ensureSimpleModal(){
      var ov = document.getElementById('gw-simple-overlay');
      var md = document.getElementById('gw-simple-modal');
      if (ov && md) return md;

      ov = document.createElement('div');
      ov.id = 'gw-simple-overlay';
      ov.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.35);z-index:99998;display:none;';
      document.body.appendChild(ov);

      md = document.createElement('div');
      md.id = 'gw-simple-modal';
      md.style.cssText = 'position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);width:560px;max-width:92vw;background:#fff;border-radius:12px;box-shadow:0 20px 60px rgba(0,0,0,.25);z-index:99999;display:none;overflow:hidden;';
      md.innerHTML =
        '<div style="padding:12px 14px;background:#f7fafd;border-bottom:1px solid #e3e3e3;display:flex;justify-content:space-between;align-items:center;">' +
          '<strong id="gw-simple-title">Modal</strong>' +
          '<button type="button" class="button" id="gw-simple-close">Cerrar</button>' +
        '</div>' +
        '<div id="gw-simple-body" style="padding:14px;max-height:70vh;overflow:auto;"></div>';
      document.body.appendChild(md);

      function closeModal(){
        ov.style.display = 'none';
        md.style.display = 'none';
        var b = document.getElementById('gw-simple-body');
        if (b) b.innerHTML = '';
      }
      ov.addEventListener('click', closeModal);
      document.getElementById('gw-simple-close').addEventListener('click', closeModal);
      window.gwSimpleClose = closeModal;

      return md;
    }
    function openModal(title){
      ensureSimpleModal();
      document.getElementById('gw-simple-title').textContent = title || 'Modal';
      document.getElementById('gw-simple-overlay').style.display = 'block';
      document.getElementById('gw-simple-modal').style.display = 'block';
    }
    function post(payload){
      var fd = new FormData();
      for (var k in payload) if (Object.prototype.hasOwnProperty.call(payload,k)) fd.append(k, payload[k]);
      return fetch(AJAX, {method:'POST', credentials:'same-origin', body:fd}).then(function(r){ return r.json(); });
    }

    // ---------- Toggle activo/inactivo ----------
    if (typeof window.gwUserToggle !== 'function') {
      window.gwUserToggle = function(uid){
        post({action:'gw_admin_toggle_active', nonce: NONCE, user_id: uid})
        .then(function(resp){
          if (resp && resp.success) {
            var row = document.getElementById('gw-user-row-'+uid);
            if (row) {
              var tmp = document.createElement('tbody');
              tmp.innerHTML = resp.data.row_html;
              if (tmp.firstElementChild) row.parentNode.replaceChild(tmp.firstElementChild, row);
            }
          } else {
            alert((resp && resp.data && resp.data.msg) ? resp.data.msg : 'No se pudo actualizar.');
          }
        }).catch(function(){ alert('Error de red'); });
      };
    }

    // ---------- Historial ----------
    if (typeof window.gwUserHistory !== 'function') {
      window.gwUserHistory = function(uid){
        openModal('Historial');
        var body = document.getElementById('gw-simple-body');
        body.innerHTML = '<p>Cargando…</p>';
        post({action:'gw_admin_get_user', nonce: NONCE, user_id: uid})
        .then(function(resp){
          if (!resp || !resp.success) { body.innerHTML = '<p>Error al cargar.</p>'; return; }
          var logs = resp.data.logs || [];
          if (!logs.length) { body.innerHTML = '<p>Sin actividades registradas.</p>'; return; }
          var html = '<table class="widefat striped"><thead><tr><th>Fecha</th><th>Admin</th><th>Acción</th></tr></thead><tbody>';
          for (var i=0;i<logs.length;i++){
            var l = logs[i] || {};
            html += '<tr><td>'+(l.time||'')+'</td><td>'+(l.admin||'')+'</td><td>'+(l.msg||'')+'</td></tr>';
          }
          html += '</tbody></table>';
          body.innerHTML = html;
        }).catch(function(){ body.innerHTML = '<p>Error de red.</p>'; });
      };
    }

    // ---------- Editar ----------
    if (typeof window.gwUserEdit !== 'function') {
      window.gwUserEdit = function(uid){
        openModal('Editar usuario');
        var body = document.getElementById('gw-simple-body');
        body.innerHTML = '<p>Cargando…</p>';
        post({action:'gw_admin_get_user', nonce: NONCE, user_id: uid})
        .then(function(resp){
          if (!resp || !resp.success){ body.innerHTML = '<p>Error al cargar.</p>'; return; }
          var u = resp.data.user || {};
          var roles = ['administrator','coordinador_pais','coach','voluntario'];
          var labels = {administrator:'Administrador', coordinador_pais:'Coordinador de país', coach:'Coach', voluntario:'Voluntario'};
          var opts = roles.map(function(r){ return '<option value="'+r+'"'+(r===u.role?' selected':'')+'>'+ (labels[r]||r) +'</option>'; }).join('');
          body.innerHTML =
            '<form id="gw-simple-edit-form" class="gw-form">' +
              '<input type="hidden" name="user_id" value="'+(u.ID||uid)+'">' +
              '<p><label>Nombre a mostrar</label><input type="text" name="display_name" value="'+(u.display_name||'')+'" style="width:100%; margin-bottom:12px;"></p>' +
              '<p><label>Email</label><input type="email" name="user_email" value="'+(u.user_email||'')+'" style="width:100%; margin-bottom:12px;"></p>' +
              '<p><label>Rol</label><select name="role" style="width:100%; margin-bottom:12px;">'+opts+'</select></p>' +
              '<p><label>País</label><input type="number" name="pais_id" value="'+(u.pais_id||'')+'" style="width:100%; margin-bottom:12px;"></p>' +
              '<div class="gw-form-actions" style="margin-top:10px;"><button type="submit" class="button button-primary">Guardar</button></div>' +
            '</form>';

          var f = document.getElementById('gw-simple-edit-form');
          f.addEventListener('submit', function(e){
            e.preventDefault();
            var fd = new FormData(f);
            fd.append('action','gw_admin_save_user');
            fd.append('nonce', NONCE);
            fetch(AJAX, {method:'POST', credentials:'same-origin', body: fd})
            .then(function(r){ return r.json(); })
            .then(function(r2){
              if (r2 && r2.success) {
                var row = document.getElementById('gw-user-row-'+uid);
                if (row) {
                  var tmp = document.createElement('tbody');
                  tmp.innerHTML = r2.data.row_html;
                  if (tmp.firstElementChild) row.parentNode.replaceChild(tmp.firstElementChild, row);
                }
                if (typeof window.gwSimpleClose === 'function') window.gwSimpleClose();
              } else {
                alert((r2 && r2.data && r2.data.msg) ? r2.data.msg : 'No se pudo guardar.');
              }
            }).catch(function(){ alert('Error de red'); });
          });
        }).catch(function(){ body.innerHTML = '<p>Error de red.</p>'; });
      };
    }
  })();
  </script>
  <?php
}, 100);

// === ADMIN: Asistencias (marcar asistencia manual) =====================

// 1) Modal HTML (servidor): devuelve lista de charlas y capacitación del usuario
add_action('wp_ajax_gw_admin_asist_modal', 'gw_admin_asist_modal');
function gw_admin_asist_modal(){
  if ( !is_user_logged_in() || !current_user_can('manage_options') ) {
    wp_send_json_error(['msg' => 'No autorizado']);
  }
  $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
  if ( ! wp_verify_nonce($nonce, 'gw_asist_admin') ) {
    wp_send_json_error(['msg' => 'Nonce inválido/expirado']);
  }
  $uid = intval($_POST['user_id'] ?? 0);
  $u   = $uid ? get_user_by('id', $uid) : null;
  if ( ! $u ) wp_send_json_error(['msg' => 'Usuario no encontrado']);

  // CHARLAS
  $charlas_asignadas = get_user_meta($uid, 'gw_charlas_asignadas', true);
  if (!is_array($charlas_asignadas)) $charlas_asignadas = [];
  $html  = '<div class="gw-asist-wrap">';
  $html .= '<h3 style="margin:10px 0 6px;">Charlas</h3>';
  if ($charlas_asignadas) {
    // Intentar obtener la charla actualmente agendada para pintar su fecha/hora
    $charla_ag = get_user_meta($uid, 'gw_charla_agendada', true);
    $ag_id   = is_array($charla_ag) && !empty($charla_ag['charla_id']) ? intval($charla_ag['charla_id']) : 0;
    $ag_fecha= is_array($charla_ag) ? (string)($charla_ag['fecha'] ?? '') : '';
    $ag_hora = is_array($charla_ag) ? (string)($charla_ag['hora']  ?? '') : '';

    // Cargar historial para fechas/horas de charlas ya aprobadas
    $char_hist = get_user_meta($uid, 'gw_charlas_historial', true);
    if (!is_array($char_hist)) $char_hist = [];
    if (empty($char_hist)) {
    $char_hist_bak = get_user_meta($uid, 'gw_charlas_historial_backup', true);
    if (is_array($char_hist_bak) && !empty($char_hist_bak)) {
        update_user_meta($uid, 'gw_charlas_historial', $char_hist_bak);
        $char_hist = $char_hist_bak;
    }
}
    $char_last = [];
    if (is_array($char_hist)) {
    foreach ($char_hist as $row) {
    $cid = intval($row['charla_id'] ?? 0);
    if ($cid) {
      $char_last[$cid] = [
        'fecha' => (string)($row['fecha'] ?? ''),
        'hora'  => (string)($row['hora']  ?? ''),
      ];
    }
  }
}

    $html .= '<table class="widefat striped"><thead><tr><th>Charla</th><th>Fecha</th><th>Hora</th><th>Estado</th><th>Acción</th></tr></thead><tbody>';
    $printed_ids = [];
    foreach ($charlas_asignadas as $i => $key) {
      $charla_id = intval($key);
      $title = get_the_title($charla_id);
      if (!$title) $title = 'Charla';

      // Estado de asistencia
      $done   = get_user_meta($uid, 'gw_'.$key, true) ? 1 : 0;
      $estado = $done ? 'Asistió' : 'Pendiente';

      // Solo mostramos fecha/hora si esta charla es la actualmente agendada por el voluntario
      $f = ($ag_id && $ag_id === $charla_id) ? $ag_fecha : (isset($char_last[$charla_id]) ? $char_last[$charla_id]['fecha'] : '');
      $h = ($ag_id && $ag_id === $charla_id) ? $ag_hora  : (isset($char_last[$charla_id]) ? $char_last[$charla_id]['hora']  : '');

      $btn = $done
        ? '<button class="button gwAsistRevert" data-kind="charla" data-key="'.esc_attr($key).'" data-uid="'.esc_attr($uid).'">Revertir</button>'
        : '<button class="button button-primary gwAsistMark" data-kind="charla" data-key="'.esc_attr($key).'" data-uid="'.esc_attr($uid).'">Marcar sí asistió</button>';

      $html .= '<tr>' .
               '<td>'.esc_html($title).'</td>' .
               '<td>'.esc_html($f ?: '—').'</td>' .
               '<td>'.esc_html($h ?: '—').'</td>' .
               '<td>'.esc_html($estado).'</td>' .
               '<td>'.$btn.'</td>' .
               '</tr>';
      $printed_ids[] = $charla_id;
    }
    // --- Agregar filas desde historial si no están en asignadas ---
    if (is_array($char_hist) && $char_hist) {
      foreach ($char_hist as $row) {
        $cid_hist = intval($row['charla_id'] ?? 0);
        if (!$cid_hist) continue;
        if (in_array($cid_hist, $printed_ids, true)) continue; // ya impreso como asignada
        $title_h = get_the_title($cid_hist) ?: 'Charla';
        $fh = (string)($row['fecha'] ?? '');
        $hh = (string)($row['hora']  ?? '');
        $btn_h = '<button class="button gwAsistRevert" data-kind="charla" data-key="'.esc_attr($cid_hist).'" data-uid="'.esc_attr($uid).'">Revertir</button>';
        $html .= '<tr>'.
                 '<td>'.esc_html($title_h).'</td>'.
                 '<td>'.esc_html($fh ?: '—').'</td>'.
                 '<td>'.esc_html($hh ?: '—').'</td>'.
                 '<td>Asistió</td>'.
                 '<td>'.$btn_h.'</td>'.
                 '</tr>';
      }
    }
    // Historico: mostrar charlas asistidas que ya no están asignadas
if (is_array($char_hist) && !empty($char_hist)) {
  $printed = array_map('intval', $charlas_asignadas);
  foreach ($char_hist as $row) {
      $cid = intval($row['charla_id'] ?? 0);
      if (!$cid || in_array($cid, $printed, true)) continue;
      $titleH = (string)($row['charla_title'] ?? get_the_title($cid) ?: 'Charla');
      $fH = (string)($row['fecha'] ?? '');
      $hH = (string)($row['hora']  ?? '');
      $btnH = '<button class="button gwAsistRevert" data-kind="charla" data-key="'.esc_attr($cid).'" data-uid="'.esc_attr($uid).'">Revertir</button>';
      $html .= '<tr>'
            .  '<td>'.esc_html($titleH).'</td>'
            .  '<td>'.esc_html($fH ?: '—').'</td>'
            .  '<td>'.esc_html($hH ?: '—').'</td>'
            .  '<td>Asistió</td>'
            .  '<td>'.$btnH.'</td>'
            .  '</tr>';
  }
}
$html .= '</tbody></table>';
  } else {
    $html .= '<p style="color:#666">Sin charlas asignadas.</p>';
  }

  // CAPACITACIÓN
$html .= '<h3 style="margin:18px 0 6px;">Capacitación</h3>';
$ag   = get_user_meta($uid, 'gw_capacitacion_agendada', true);
$cap_id = 0; 
$fecha = ''; 
$hora = '';
if (is_array($ag) && !empty($ag['cap_id'])) {
  $cap_id = intval($ag['cap_id']); 
  $fecha  = (string)($ag['fecha'] ?? ''); 
  $hora   = (string)($ag['hora'] ?? '');
} else {
  $cap_id = intval(get_user_meta($uid, 'gw_capacitacion_id', true));
  $fecha  = (string)get_user_meta($uid, 'gw_fecha', true);
  $hora   = (string)get_user_meta($uid, 'gw_hora', true);
}

if ($cap_id) {
  $title  = get_the_title($cap_id) ?: 'Capacitación';
  $done7  = get_user_meta($uid, 'gw_step7_completo', true) ? 1 : 0;
  $estado = $done7 ? 'Asistió' : 'Pendiente';
  $btn    = $done7
    ? '<button class="button gwAsistRevert" data-kind="cap" data-uid="'.esc_attr($uid).'">Revertir</button>'
    : '<button class="button button-primary gwAsistMark" data-kind="cap" data-uid="'.esc_attr($uid).'">Marcar sí asistió</button>';

  // === PASO 4: Fallback a historial si no hay fecha/hora visibles ===
  $cap_hist = get_user_meta($uid, 'gw_capacitaciones_historial', true);
  if (!$fecha && is_array($cap_hist)) {
    foreach (array_reverse($cap_hist) as $row) {
      $rcid = intval($row['cap_id'] ?? 0);
      if ($rcid && $rcid === $cap_id) {
        $fecha = (string)($row['fecha'] ?? '');
        $hora  = (string)($row['hora']  ?? '');
        break;
      }
    }
  }

  // Tabla
  $html .= '<table class="widefat striped"><thead><tr><th>Capacitación</th><th>Fecha</th><th>Hora</th><th>Estado</th><th>Acción</th></tr></thead>'.
           '<tbody><tr>'.
           '<td>'.esc_html($title).'</td>'.
           '<td>'.esc_html($fecha ?: '—').'</td>'.
           '<td>'.esc_html($hora  ?: '—').'</td>'.
           '<td>'.esc_html($estado).'</td>'.
           '<td>'.$btn.'</td>'.
           '</tr></tbody></table>';

} else {
  $html .= '<p style="color:#666">Sin capacitación agendada.</p>';
}

$html .= '</div>';
wp_send_json_success(['html' => $html]);
}

// 2) Marcar/Revertir asistencia (servidor)
add_action('wp_ajax_gw_admin_mark_attendance', 'gw_admin_mark_attendance');
add_action('wp_ajax_gw_admin_revert_attendance', 'gw_admin_revert_attendance');

function gw_admin_mark_attendance(){
  if ( !is_user_logged_in() || !current_user_can('manage_options') ) {
    wp_send_json_error(['msg'=>'No autorizado']);
  }
  $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
  if ( ! wp_verify_nonce($nonce, 'gw_asist_admin') ) {
    wp_send_json_error(['msg'=>'Nonce inválido/expirado']);
  }

  $uid  = intval($_POST['user_id'] ?? 0);
  $kind = sanitize_text_field($_POST['kind'] ?? ''); // 'charla' | 'cap'
  $key  = sanitize_text_field($_POST['key']  ?? ''); // clave de charla

  if (!$uid || !($kind==='charla' || $kind==='cap')) {
    wp_send_json_error(['msg'=>'Parámetros inválidos']);
  }

  if ($kind === 'charla') {
    // 0) Validación
    if ($key === '') { wp_send_json_error(['msg'=>'Falta clave de charla']); }
  
    // 1) Guardar en historial la charla agendada ANTES de liberar
    $ag = get_user_meta($uid, 'gw_charla_agendada', true);
    if (is_array($ag) && !empty($ag)) {
      $hist = get_user_meta($uid, 'gw_charlas_historial', true);
      if (!is_array($hist)) $hist = [];
      $hist[] = [
        'charla_id'    => intval($ag['charla_id'] ?? 0),
        'charla_title' => (string)($ag['charla_title'] ?? get_the_title(intval($ag['charla_id'] ?? 0))),
        'fecha'        => (string)($ag['fecha'] ?? ''),
        'hora'         => (string)($ag['hora']  ?? ''),
        'asistio'      => 1,
        'ts'           => current_time('timestamp'),
      ];
      update_user_meta($uid, 'gw_charlas_historial', $hist);
    
          // 1.b) Fallback: si no hay charla_agendada, registra igualmente en historial usando la clave (ID) de la charla
    if (empty($ag)) {
      $cid = intval($key);
      if ($cid > 0) {
          $fecha = '';
          $hora  = '';

          // Intentar obtener fecha/hora desde meta 'gw_sesiones'
          $ses = get_post_meta($cid, 'gw_sesiones', true);
          if (is_array($ses) && !empty($ses)) {
              $s = end($ses);
              $ini = is_array($s) ? ($s['inicio'] ?? '') : $s;
              if ($ini) {
                  $ts = is_numeric($ini) ? (int)$ini : strtotime((string)$ini);
                  if ($ts) {
                      $fecha = date_i18n('Y-m-d', $ts);
                      $hora  = date_i18n('H:i', $ts);
                  }
              }
          }

          if ($fecha === '') $fecha = date_i18n('Y-m-d');
          if ($hora  === '') $hora  = date_i18n('H:i');

          $hist = get_user_meta($uid, 'gw_charlas_historial', true);
          if (!is_array($hist)) $hist = [];
          $hist[] = [
              'charla_id'    => $cid,
              'charla_title' => get_the_title($cid) ?: 'Charla',
              'fecha'        => $fecha,
              'hora'         => $hora,
              'asistio'      => 1,
              'ts'           => current_time('timestamp'),
          ];

          update_user_meta($uid, 'gw_charlas_historial', $hist);
          // Copia de seguridad para evitar borrados accidentales por otros flujos
          update_user_meta($uid, 'gw_charlas_historial_backup', $hist);
      }
        } else {
      // Mantener copia de seguridad sincronizada cuando sí hubo agendada
      $hist_bak = get_user_meta($uid, 'gw_charlas_historial', true);
      if (is_array($hist_bak)) {
          update_user_meta($uid, 'gw_charlas_historial_backup', $hist_bak);
      }
  }
    }
    // --- Fallback: si no existe 'gw_charla_agendada', registra igualmente usando el ID de la charla ---
    if (!is_array($ag) || empty($ag)) {
      $cid_f = intval($key);
      if ($cid_f > 0) {
        $hist = get_user_meta($uid, 'gw_charlas_historial', true);
        if (!is_array($hist)) $hist = [];
        // Intentar obtener una fecha/hora razonable (primera sesión futura o ahora)
        $fecha_f = '';
        $hora_f  = '';
        $ses = get_post_meta($cid_f, 'gw_sesiones', true);
        if (is_array($ses)) {
          $now_ts = current_time('timestamp');
          foreach ($ses as $s) {
            $ini = is_array($s) ? ($s['inicio'] ?? null) : $s;
            if (!$ini) continue;
            $ts = is_numeric($ini) ? (int)$ini : strtotime((string)$ini);
            if ($ts) { $fecha_f = date_i18n('Y-m-d', $ts); $hora_f = date_i18n('H:i', $ts); break; }
          }
        }
        if (!$fecha_f) {
          $tsn = current_time('timestamp');
          $fecha_f = date_i18n('Y-m-d', $tsn);
          $hora_f  = date_i18n('H:i', $tsn);
        }
        $hist[] = [
          'charla_id'    => $cid_f,
          'charla_title' => (string) get_the_title($cid_f),
          'fecha'        => $fecha_f,
          'hora'         => $hora_f,
          'asistio'      => 1,
          'ts'           => current_time('timestamp'),
        ];
        update_user_meta($uid, 'gw_charlas_historial', $hist);
      }
    }
  
    // 2) Legacy y flags que desbloquean el flujo
    update_user_meta($uid, 'gw_' . $key, 1);
    update_user_meta($uid, 'gw_charla_asistio', '1');
    update_user_meta($uid, 'gw_charla_asistio_' . $key, '1');
  
    // 3) Notificación resuelta => baja el badge
    if (function_exists('gw_notif_mark_done')) {
      $rid = intval($key); // si tu "key" es numérica, esto la usa como rid
      gw_notif_mark_done('charla', $uid, $rid);
    }
    $badge = function_exists('gw_notif_pending_count') ? gw_notif_pending_count() : 0;
  
    // ---- Notificaciones: marcar resuelto y devolver badge ----
  try {
  $type = (isset($_POST['kind']) && $_POST['kind']==='cap') ? 'CAPACITACION' : 'CHARLA';
  $uid  = intval($_POST['user_id'] ?? 0);
  $rid  = 0;
  if ($type === 'CHARLA') {
    $ag = get_user_meta($uid, 'gw_charla_agendada', true);
    if (is_array($ag) && !empty($ag['charla_id'])) $rid = intval($ag['charla_id']);
  } else {
    $ag = get_user_meta($uid, 'gw_capacitacion_agendada', true);
    if (is_array($ag) && !empty($ag['cap_id'])) $rid = intval($ag['cap_id']);
    if (!$rid) $rid = intval(get_user_meta($uid,'gw_capacitacion_id',true));
  }
  gw_notif_mark_done($type, $uid, $rid);
  $badge = gw_notif_pending_count();
  // si ya estabas armando un array $payload, añade $payload['badge'] = $badge;
  if (isset($payload) && is_array($payload)) { $payload['badge'] = $badge; }
} catch (\Throwable $e) {}

    wp_send_json_success(['done'=>1, 'badge'=>$badge]);
  }

  // === Capacitación ===
// 1) Guardar en historial la capacitación agendada ANTES de liberar
$ag = get_user_meta($uid, 'gw_capacitacion_agendada', true);
if (is_array($ag) && !empty($ag)) {
  $hist = get_user_meta($uid, 'gw_capacitaciones_historial', true);
  if (!is_array($hist)) $hist = [];
  $hist[] = [
    'cap_id'    => intval($ag['cap_id'] ?? 0),
    'cap_title' => (string)($ag['cap_title'] ?? get_the_title(intval($ag['cap_id'] ?? 0))),
    'fecha'     => (string)($ag['fecha'] ?? ''),
    'hora'      => (string)($ag['hora']  ?? ''),
    'asistio'   => 1,
    'ts'        => current_time('timestamp'),
  ];
  update_user_meta($uid, 'gw_capacitaciones_historial', $hist);
}

// 2) Completar paso 7 y flags
update_user_meta($uid, 'gw_step7_completo', 1);
update_user_meta($uid, 'gw_cap_asistio', '1');

// 3) Flag por cap_id concreto si existe
$cap_ag = get_user_meta($uid, 'gw_capacitacion_agendada', true);
$cap_id = 0;
if (is_array($cap_ag) && !empty($cap_ag['cap_id'])) {
  $cap_id = intval($cap_ag['cap_id']);
}
if (!$cap_id) {
  $cap_id = intval(get_user_meta($uid, 'gw_capacitacion_id', true));
}
if ($cap_id) {
  update_user_meta($uid, 'gw_cap_asistio_' . $cap_id, '1');
}

// 4) Notificación resuelta => baja el badge
if ($cap_id && function_exists('gw_notif_mark_done')) {
  gw_notif_mark_done('cap', $uid, intval($cap_id));
}
$badge = function_exists('gw_notif_pending_count') ? gw_notif_pending_count() : 0;

wp_send_json_success(['done'=>1, 'badge'=>$badge]);
}

function gw_admin_revert_attendance(){
  if ( !is_user_logged_in() || !current_user_can('manage_options') ) {
    wp_send_json_error(['msg'=>'No autorizado']);
  }
  $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
  if ( ! wp_verify_nonce($nonce, 'gw_asist_admin') ) {
    wp_send_json_error(['msg'=>'Nonce inválido/expirado']);
  }

  $uid  = intval($_POST['user_id'] ?? 0);
  $kind = sanitize_text_field($_POST['kind'] ?? ''); // 'charla' | 'cap'
  $key  = sanitize_text_field($_POST['key']  ?? '');

  if (!$uid || !($kind==='charla' || $kind==='cap')) {
    wp_send_json_error(['msg'=>'Parámetros inválidos']);
  }

  if ($kind === 'charla') {
    if ($key === '') { wp_send_json_error(['msg'=>'Falta clave de charla']); }

    // Legacy
    delete_user_meta($uid, 'gw_' . $key);
    // Flags de flujo
    delete_user_meta($uid, 'gw_charla_asistio');
    delete_user_meta($uid, 'gw_charla_asistio_' . $key);
    // Quitar del historial la última ocurrencia de esta charla
    $cid_rev = intval($key);
    $hist_r = get_user_meta($uid, 'gw_charlas_historial', true);
    if (is_array($hist_r) && $hist_r) {
      $removed = false;
      $new_hist = [];
      foreach ($hist_r as $row) {
        if (!$removed && intval($row['charla_id'] ?? 0) === $cid_rev) { $removed = true; continue; }
        $new_hist[] = $row;
      }
      update_user_meta($uid, 'gw_charlas_historial', $new_hist);
      update_user_meta($uid, 'gw_charlas_historial_backup', $new_hist);
    }
    // Garantiza que vuelva a aparecer como pendiente en asignadas (si tu flujo así lo requiere)
    $asig_r = get_user_meta($uid, 'gw_charlas_asignadas', true);
    if (!is_array($asig_r)) $asig_r = [];
    $ids_asig = array_map('intval', $asig_r);
    if ($cid_rev > 0 && !in_array($cid_rev, $ids_asig, true)) {
      $ids_asig[] = $cid_rev;
      update_user_meta($uid, 'gw_charlas_asignadas', array_values(array_unique($ids_asig)));
    }
    wp_send_json_success(['done'=>1]);
  }

  // === Capacitación ===
  delete_user_meta($uid, 'gw_step7_completo');
  delete_user_meta($uid, 'gw_cap_asistio');

  $cap_ag = get_user_meta($uid, 'gw_capacitacion_agendada', true);
  $cap_id = 0;
  if (is_array($cap_ag) && !empty($cap_ag['cap_id'])) {
    $cap_id = intval($cap_ag['cap_id']);
  }
  if (!$cap_id) {
    $cap_id = intval(get_user_meta($uid, 'gw_capacitacion_id', true));
  }
  if ($cap_id) {
    delete_user_meta($uid, 'gw_cap_asistio_' . $cap_id);
  }

  wp_send_json_success(['done'=>1]);
}

// AJAX para borrar metas de paso 5 (charlas)
add_action('wp_ajax_gw_admin_reset_charlas', function() {
    $user = wp_get_current_user();
    // Borrar meta de paso actual y charla actual (ajustar las keys según tu implementación)
    delete_user_meta($user->ID, 'gw_step5');
    delete_user_meta($user->ID, 'gw_charla_actual');
    // También puedes borrar otras metas relacionadas si aplica
    wp_die();
// Remove this extra closing brace if it does not match any opening brace
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
    <div class="gw-progreso-table-wrap">

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
            $total_charlas = count($charlas_asignadas);
            
            // Contar charlas completadas
            $charlas_completadas = 0;
            foreach ($charlas_asignadas as $charla_key) {
                if (get_user_meta($v->ID, 'gw_' . $charla_key, true)) {
                    $charlas_completadas++;
                }
            }
          ?>
          <tr>
            <td><?php echo esc_html($v->display_name); ?></td>
            <td><?php echo esc_html($v->user_email); ?></td>
            <td>
              <?php if ($total_charlas > 0): ?>
                <button type="button" class="button gw-ver-charlas" style="font-size: 11px; padding: 2px 8px; height: auto;" data-user-id="<?php echo esc_attr($v->ID); ?>">
                  Ver charlas (<?php echo $charlas_completadas; ?>/<?php echo $total_charlas; ?>)
                </button>
              <?php else: ?>
                <span style="color: #999;">Sin charlas asignadas</span>
              <?php endif; ?>
            </td>
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

  </div>

  <!-- Modal principal -->
  <div id="gw-modal-overlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.35); z-index:99998;"></div>
  <div id="gw-modal" style="display:none; position:fixed; z-index:99999; left:50%; top:50%; transform:translate(-50%,-50%);
       width:760px; max-width:92vw; background:#fff; border-radius:12px; box-shadow:0 20px 60px rgba(0,0,0,.25); overflow:hidden;">
    <div style="padding:14px 16px; background:#f7fafd; border-bottom:1px solid #e3e3e3; display:flex; justify-content:space-between; align-items:center;">
      <strong id="gw-modal-title">Modal</strong>
      <button type="button" class="button button-small" id="gw-modal-close">Cerrar</button>
    </div>
    <div id="gw-modal-body" style="padding:14px; max-height:70vh; overflow:auto;"></div>
  </div>

  <script>
  jQuery(function($){
    // ajaxurl (frontend)
    if (typeof window.ajaxurl === 'undefined') {
      window.ajaxurl = "<?php echo esc_js( admin_url('admin-ajax.php') ); ?>";
    }

    function abrirModal(titulo = 'Modal'){
      $('#gw-modal-title').text(titulo);
      $('#gw-modal, #gw-modal-overlay').show();
    }
    
    function cerrarModal(){
      $('#gw-modal, #gw-modal-overlay').hide();
      $('#gw-modal-body').html('');
      $('#gw-doc-viewer').remove();
    }

    $(document).on('click', '#gw-modal-close, #gw-modal-overlay', cerrarModal);

    // Ver charlas del voluntario
    $(document).on('click', '.gw-ver-charlas', function(){
      var userId = $(this).data('user-id');
      var userName = $(this).closest('tr').find('td:first').text();
      
      abrirModal('Charlas de ' + userName);
      cargarCharlas(userId);
    });

    function cargarCharlas(userId){
      $('#gw-modal-body').html('<p>Cargando charlas…</p>');
      $.ajax({
        url: ajaxurl,
        method: 'POST',
        data: { action: 'gw_obtener_charlas_voluntario', user_id: userId },
        dataType: 'html'
      })
      .done(function(html){ $('#gw-modal-body').html(html); })
      .fail(function(xhr){
        var raw = (xhr && xhr.responseText) ? xhr.responseText.substring(0,500) : '';
        $('#gw-modal-body').html('<p>Error al cargar charlas.</p><pre style="white-space:pre-wrap">'+raw+'</pre>');
      });
    }

    // Abrir modal para documentos
    $(document).on('click', '.gw-revisar-docs', function(){
      var userId = $(this).data('user-id');
      var userName = $(this).closest('tr').find('td:first').text();
      
      abrirModal('Documentos de ' + userName);
      cargarDocs(userId);
    });

    function cargarDocs(userId){
      $('#gw-modal-body').html('<p>Cargando documentos…</p>');
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

    // Aprobar documentos
    $(document).on('click', '.gw-aprobar-doc', function(e){
      e.preventDefault();
      var docId  = $(this).data('doc-id');  // Cambio: usar doc-id
      var userId = $(this).data('user-id');
      var nonce  = $(this).data('nonce');

      if (!docId || !userId || !nonce) {
        alert('Error: faltan datos.');
        return;
      }

      var $btn = $(this).prop('disabled', true);

      $.ajax({
        url: ajaxurl,
        method: 'POST',
        dataType: 'json',
        data: { action: 'gw_aprobar_doc', doc_id: docId, user_id: userId, nonce: nonce }
      })
      .done(function(resp){
        if (resp && resp.success) {
          cargarDocs(userId);
        } else {
          alert('Error: ' + (resp && resp.data && resp.data.message ? resp.data.message : 'No se pudo actualizar'));
          $btn.prop('disabled', false);
        }
      })
      .fail(function(xhr){
        alert('Error: No se pudo actualizar');
        $btn.prop('disabled', false);
      });
    });

    // Rechazar documentos
    $(document).on('click', '.gw-rechazar-doc', function(e){
      e.preventDefault();
      var docId  = $(this).data('doc-id');  // Cambio: usar doc-id
      var userId = $(this).data('user-id');
      var nonce  = $(this).data('nonce');

      if (!docId || !userId || !nonce) {
        alert('Error: faltan datos.');
        return;
      }

      var $btn = $(this).prop('disabled', true);

      $.ajax({
        url: ajaxurl,
        method: 'POST',
        dataType: 'json',
        data: { action: 'gw_rechazar_doc', doc_id: docId, user_id: userId, nonce: nonce }
      })
      .done(function(resp){
        if (resp && resp.success) {
          cargarDocs(userId);
        } else {
          alert('Error: ' + (resp && resp.data && resp.data.message ? resp.data.message : 'No se pudo actualizar'));
          $btn.prop('disabled', false);
        }
      })
      .fail(function(xhr){
        alert('Error: No se pudo actualizar');
        $btn.prop('disabled', false);
      });
    });

  });
  </script>

  <style>/* Scroll horizontal SOLO para la tabla de Progreso */
#gw-admin-tab-progreso .gw-progreso-table-wrap{
  overflow-x: auto;           /* habilita scroll L⇄R */
  overflow-y: visible;        /* sin scroll vertical extra */
  -webkit-overflow-scrolling: touch;
}

/* Haz que la tabla sea más ancha que el contenedor
   para que aparezca la barra horizontal cuando sea necesario */
#gw-admin-tab-progreso .gw-progreso-table-wrap .widefat{
  min-width: 1200px;          /* ajusta el mínimo a tu gusto */
  table-layout: auto;
  border-collapse: collapse;
}

/* Evita que el contenido se parta: favorece el scroll */
#gw-admin-tab-progreso .gw-progreso-table-wrap .widefat th,
#gw-admin-tab-progreso .gw-progreso-table-wrap .widefat td{
  white-space: nowrap;
  vertical-align: middle;
}

/* Botón "Ver charlas" → azul */
#gw-admin-tab-progreso .button.gw-ver-charlas,
.gw-progreso-admin .button.gw-ver-charlas{
  background: #1e88e5 !important;
  border-color: #1e88e5 !important;
  color: #fff !important;
}

#gw-admin-tab-progreso .button.gw-ver-charlas:hover,
.gw-progreso-admin .button.gw-ver-charlas:hover{
  background: #1976d2 !important;
  border-color: #1976d2 !important;
}

#gw-admin-tab-progreso .button.gw-ver-charlas:active,
.gw-progreso-admin .button.gw-ver-charlas:active{
  background: #1565c0 !important;
  border-color: #1565c0 !important;
}

#gw-admin-tab-progreso .button.gw-ver-charlas:focus,
.gw-progreso-admin .button.gw-ver-charlas:focus{
  outline: none;
  box-shadow: 0 0 0 3px rgba(30,136,229,.35) !important;
}

</style>
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
// --- Helper: Recolectar capacitaciones de usuarios (para reportes, export, etc) ---
if (!function_exists('gw_reports_collect_capacitaciones')) {
function gw_reports_collect_capacitaciones($args) {
    // --- Filtros recibidos ---
    $pais_id      = intval($args['pais_id']      ?? 0);
    $proyecto_id  = intval($args['proyecto_id']  ?? 0);
    $cap_id_filt  = intval($args['cap_id']       ?? 0);
    $estado_filt  = sanitize_text_field($args['estado']     ?? 'todos');   // activos|inactivos|todos
    $asis_filt    = sanitize_text_field($args['asistencia'] ?? 'todas');   // asistio|no|todas|pendiente
    $desde        = sanitize_text_field($args['desde'] ?? '');
    $hasta        = sanitize_text_field($args['hasta'] ?? '');
    $desde_ts     = $desde ? strtotime($desde . ' 00:00:00') : 0;
    $hasta_ts     = $hasta ? strtotime($hasta . ' 23:59:59') : 0;

    // --- Armar filtros de usuarios (meta_query + roles) ---
    $mq = [];
    if ($pais_id) {
        $mq[] = ['key' => 'gw_pais_id', 'value' => $pais_id, 'compare' => '='];
    }
    if ($estado_filt === 'activos')   { $mq[] = ['key' => 'gw_active', 'value' => '1', 'compare' => '=']; }
    if ($estado_filt === 'inactivos') { $mq[] = ['key' => 'gw_active', 'value' => '0', 'compare' => '=']; }

    $user_args = [
        'fields'    => ['ID','display_name','user_email','roles'],
        'number'    => 9999,
        'role__in'  => ['voluntario','coach','coordinador_pais','administrator'],
    ];
    if (!empty($mq)) {
        $user_args['meta_query'] = $mq;
    }

    $users = get_users($user_args);
    if (!$users) return [];

    $rows = [];

    // --- Lista de posibles metakeys históricas para retrocompatibilidad ---
    $history_keys = [
        // arreglos/arrays de historiales
        'gw_caps_hist','gw_caps_history','gw_capacitaciones_historial',
        'gw_cap_logs','gw_caps_log','gw_caps_registros',
        'gw_capacitaciones','gw_cap_historial','gw_registros_capacitaciones',
    ];

    foreach ($users as $u) {
        $uid        = (int) $u->ID;
        $pais_title = get_the_title((int) get_user_meta($uid, 'gw_pais_id', true)) ?: '—';
        $estado_u   = get_user_meta($uid, 'gw_active', true); if ($estado_u === '') $estado_u = '1';

        // --- 1) Inscripción agendada actual (formato nuevo) ---
        $items = [];
        $ag = get_user_meta($uid, 'gw_capacitacion_agendada', true);
        if (is_array($ag) && !empty($ag['cap_id'])) {
            $asis = get_user_meta($uid, 'gw_step7_completo', true) ? '1' : '';
            $items[] = [
                'cap_id'  => (int) $ag['cap_id'],
                'fecha'   => isset($ag['fecha']) ? sanitize_text_field($ag['fecha']) : '',
                'hora'    => isset($ag['hora'])  ? sanitize_text_field($ag['hora'])  : '',
                'asistio' => $asis,
            ];
        }

        // --- 2) Historiales guardados en diferentes metakeys ---
        foreach ($history_keys as $k) {
            $arr = get_user_meta($uid, $k, true);
            if (is_array($arr) && $arr) {
                foreach ($arr as $it) {
                    if (!is_array($it)) continue;
                    // Normalizar: admite cap_id, capacitacion_id o id
                    $cid   = intval($it['cap_id'] ?? ($it['capacitacion_id'] ?? ($it['id'] ?? 0)));
                    if (!$cid) continue;
                    $fecha = isset($it['fecha']) ? sanitize_text_field($it['fecha']) : '';
                    $hora  = isset($it['hora'])  ? sanitize_text_field($it['hora'])  : '';
                    // alternativa: fecha_hora combinada
                    if (!$fecha && !empty($it['fecha_hora'])) {
                        $ts = strtotime($it['fecha_hora']);
                        if ($ts) { $fecha = date('Y-m-d', $ts); $hora = date('H:i', $ts); }
                    }
                    // asistencia flexible
                    $asis_raw = $it['asistio'] ?? ($it['asistencia'] ?? null);
                    $asis = ($asis_raw === true || $asis_raw === 1 || $asis_raw === '1' || strtolower((string)$asis_raw) === 'si' || strtolower((string)$asis_raw) === 'asistio') ? '1'
                            : (($asis_raw === null) ? '' : '0');
                    $items[] = ['cap_id'=>$cid, 'fecha'=>$fecha, 'hora'=>$hora, 'asistio'=>$asis];
                }
            }
        }

        // --- 3) Fallback simple: metakeys sueltas ---
        $single_cap = (int) get_user_meta($uid, 'gw_capacitacion_id', true);
        if ($single_cap) {
            $items[] = [
                'cap_id'  => $single_cap,
                'fecha'   => sanitize_text_field(get_user_meta($uid, 'gw_fecha', true)),
                'hora'    => sanitize_text_field(get_user_meta($uid, 'gw_hora', true)),
                'asistio' => (string) get_user_meta($uid, 'gw_asistio', true),
            ];
        }

        if (!$items) continue;

        // --- Deduplicar por cap_id|fecha|hora ---
        $uniq = [];
        foreach ($items as $it) {
            $key = ((int)$it['cap_id']) . '|' . ($it['fecha'] ?? '') . '|' . ($it['hora'] ?? '');
            $uniq[$key] = $it;
        }
        $items = array_values($uniq);

        // --- Aplicar filtros y armar filas ---
        foreach ($items as $it) {
            $cid = (int) $it['cap_id'];
            if (!$cid) continue;
            if ($cap_id_filt && $cid !== $cap_id_filt) continue;

            $proj_id  = (int) get_post_meta($cid, '_gw_proyecto_relacionado', true);
            if ($proyecto_id && $proj_id !== $proyecto_id) continue;

            $pais_cap = (int) get_post_meta($cid, '_gw_pais_relacionado', true);
            if ($pais_id && $pais_cap && $pais_cap !== $pais_id) continue;

            $fecha = $it['fecha']; $hora = $it['hora'];
            $ts = strtotime(trim($fecha . ' ' . $hora));
            if ($desde_ts && $ts && $ts < $desde_ts) continue;
            if ($hasta_ts && $ts && $ts > $hasta_ts) continue;

            $asis_bool = ($it['asistio'] === '1');
            if ($asis_filt === 'asistio' && !$asis_bool) continue;
            if ($asis_filt === 'no'       &&  $asis_bool) continue;
            if ($asis_filt === 'pendiente' && $it['asistio'] !== '') continue; // sólo sin dato

            $rows[] = [
                'nombre'     => $u->display_name ?: $u->user_email,
                'email'      => $u->user_email,
                'pais'       => $pais_title,
                'proyecto'   => $proj_id ? get_the_title($proj_id) : '—',
                'cap'        => get_the_title($cid),
                'fecha'      => $fecha ?: ($ts ? date_i18n('Y-m-d', $ts) : ''),
                'hora'       => $hora  ?: ($ts ? date_i18n('H:i', $ts)   : ''),
                'estado'     => ($estado_u === '1' ? 'Activo' : 'Inactivo'),
                'asistencia' => ($asis_bool ? 'Asistió' : 'No asistió'),
            ];
        }
    }

    return $rows;
}
}

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

// --- Tabs Ausencias: control de visibilidad entre Tab 7 (ajustes) y Tab 8 (detectadas)
add_action('wp_footer', function(){
  if ( ! is_user_logged_in() ) return;
  $req = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
  if (strpos($req, 'panel-administrativo') === false) return; // sólo en panel

  ?>
  <script>
  (function(){
    function applyVisibility(tab){
      // Formulario de ajustes
      var settings = document.getElementById('gw-abs-settings');
      // Listados de ausencias (pueden existir varias tablas con esta clase)
      var lists = document.querySelectorAll('.gw-abs-list');

      if (!settings && (!lists || !lists.length)) return;

      if (tab === 'ausencias'){ // Módulo 7: solo AJUSTES
        if (settings) settings.style.display = '';
        if (lists) lists.forEach(function(el){ el.style.display = 'none'; });
      } else if (tab === 'ausencias-detectadas'){ // Módulo 8: solo LISTADO
        if (settings) settings.style.display = 'none';
        if (lists) lists.forEach(function(el){ el.style.display = ''; });
      }
    }

    // Al cargar, intenta detectar el tab activo; si no, muestra ajustes (7)
    document.addEventListener('DOMContentLoaded', function(){
      var active = document.querySelector('.gw-step-item.gw-admin-tab-btn.active');
      var tab = active ? (active.getAttribute('data-tab') || 'ausencias') : 'ausencias';
      applyVisibility(tab);
    });

    // Cada click en un botón de tab aplica la visibilidad
    document.addEventListener('click', function(e){
      var btn = e.target.closest('.gw-step-item.gw-admin-tab-btn');
      if (!btn) return;
      var tab = btn.getAttribute('data-tab') || '';
      if (!tab) return;
      // si tu UI re-renderiza, un microdelay asegura que los nodos existan
      setTimeout(function(){ applyVisibility(tab); }, 0);
    });

    // Por si el DOM re-renderiza dinámicamente, re-aplicar
    var mo = new MutationObserver(function(){
      var active = document.querySelector('.gw-step-item.gw-admin-tab-btn.active');
      var tab = active ? (active.getAttribute('data-tab') || 'ausencias') : 'ausencias';
      applyVisibility(tab);
    });
    mo.observe(document.documentElement, {childList:true, subtree:true});
  })();
  </script>
  <?php
}, 109);

// === AJAX: Generar link/QR para País ===
if (!function_exists('gw_ajax_generar_link_qr_pais')) {
  function gw_ajax_generar_link_qr_pais(){
    if (!is_user_logged_in()) wp_send_json_error(['msg'=>'No logueado']);
    if (!( current_user_can('manage_options') || current_user_can('coordinador_pais') || current_user_can('coach') )) {
      wp_send_json_error(['msg'=>'No autorizado']);
    }
    $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
    if (!wp_verify_nonce($nonce, 'gw_paises_qr')) {
      wp_send_json_error(['msg'=>'Nonce inválido/expirado']);
    }
    $pais_id = intval($_POST['pais_id'] ?? 0);
    if (!$pais_id) wp_send_json_error(['msg'=>'ID de país requerido']);
    $p = get_post($pais_id);
    if (!$p || $p->post_type !== 'pais') wp_send_json_error(['msg'=>'País no válido']);

    // URL de destino: landing/login con preselección de país (usa la lógica existente que lee ?gw_pais=ID)
    $base   = site_url('/');
    $target = add_query_arg('gw_pais', $pais_id, $base);

    // QR usando Google Chart (simple y confiable)
    $qr_url = 'https://chart.googleapis.com/chart?chs=300x300&cht=qr&choe=UTF-8&chl=' . rawurlencode($target);

    wp_send_json_success([
      'url'  => $target,
      'qr'   => $qr_url,
      'pais' => get_the_title($pais_id),
    ]);
  }
}
add_action('wp_ajax_gw_generar_link_qr_pais', 'gw_ajax_generar_link_qr_pais');

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

    // Asegura jQuery en el panel (tabs, AJAX, exportaciones) Paso 8 
    wp_enqueue_script('jquery');
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
                <div class="gw-step-item gw-admin-tab-btn" data-tab="ausencias_detectadas">
                    <div class="gw-step-number">8</div>
                    <div class="gw-step-content">
                        <h3>Ausencias Detectadas</h3>
                        <p>Control de ausencias de voluntarios.</p>
                    </div>
                </div>

                <!-- Botón 9 -->
                <div class="gw-step-item gw-admin-tab-btn" data-tab="reportes">
                    <div class="gw-step-number">9</div>
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
                      echo '

                      
                      <div class="contenedor">';
                      foreach ($paises as $pais) {
                            $charlas_asociadas = get_post_meta($pais->ID, '_gw_charlas', true);
                            if (!is_array($charlas_asociadas)) $charlas_asociadas = [];
                            echo '<div style="border:1px solid #c8d6e5;padding:18px;border-radius:9px;margin-bottom:20px;background:#fafdff;" data-pais-id="' . $pais->ID . '">';
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

                    <!-- Modal QR -->
                    <div id="gw-qr-modal" style="display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;z-index:99999;background:rgba(30,40,50,0.5);">
                        <div style="background:#fff;max-width:450px;margin:5% auto;padding:20px;border-radius:15px;box-shadow:0 4px 40px rgba(0,0,0,0.3);position:relative;">
                            <button id="gw-qr-modal-cerrar" style="position:absolute;top:15px;right:20px;background:transparent;border:none;font-size:24px;cursor:pointer;">&times;</button>
                            <div style="text-align:center;">
                                <h3 id="gw-qr-modal-title" style="margin-bottom:20px;">QR de país</h3>
                                <div id="gw-qr-modal-qr" style="margin-bottom:15px;"></div>
                                <div style="margin:15px 0;">
                                    <label style="display:block;margin-bottom:5px;font-weight:bold;">Link:</label>
                                    <input id="gw-qr-modal-link" type="text" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;" readonly />
                                </div>
                                <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
                                    <button id="gw-qr-modal-copy" class="button button-primary">Copiar link</button>
                                    <a id="gw-qr-modal-open" class="button" target="_blank">Abrir link</a>
                                    <a id="gw-qr-modal-download" class="button" download>Descargar QR</a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <script>
                    // Variable global para evitar múltiples inicializaciones
                    if (typeof window.gwQRInitialized === 'undefined') {
                        window.gwQRInitialized = true;
                        
                        console.log('QR Script: Inicializando por única vez');
                        
                        // Función principal para manejar QR
                        function handleQRGeneration(button) {
                            const paisId = button.getAttribute('data-pais-id');
                            const paisNombre = button.getAttribute('data-pais-nombre');
                            
                            console.log('QR: Generando para país', paisId, paisNombre);
                            
                            if (!paisId) {
                                alert('Error: No se pudo obtener el ID del país');
                                return;
                            }

                            // Estado de carga
                            const originalText = button.textContent;
                            button.disabled = true;
                            button.textContent = 'Generando...';
                            button.style.opacity = '0.6';
                            
                            // Datos para la petición
                            const formData = new FormData();
                            formData.append('action', 'gw_generar_link_qr_pais');
                            formData.append('pais_id', paisId);
                            formData.append('nonce', '<?php echo wp_create_nonce('gw_paises_qr'); ?>');
                            
                            console.log('QR: Enviando petición AJAX...');
                            
                            // Petición AJAX
                            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                                method: 'POST',
                                credentials: 'same-origin',
                                body: formData
                            })
                            .then(response => {
                                console.log('QR: Status response:', response.status);
                                if (!response.ok) {
                                    throw new Error('HTTP ' + response.status);
                                }
                                return response.text();
                            })
                            .then(text => {
                                console.log('QR: Raw response:', text.substring(0, 200));
                                try {
                                    return JSON.parse(text);
                                } catch (e) {
                                    console.error('QR: Error parsing JSON:', e);
                                    throw new Error('Respuesta del servidor no válida');
                                }
                            })
                            .then(response => {
                                console.log('QR: Parsed response:', response);
                                
                                if (response.success && response.data) {
                                    const data = response.data;
                                    
                                    // Actualizar modal
                                    document.getElementById('gw-qr-modal-title').textContent = 'Link/QR para ' + paisNombre;
                                    document.getElementById('gw-qr-modal-qr').innerHTML = '<img src="' + data.qr + '" alt="QR Code" style="max-width:250px;border:1px solid #ddd;border-radius:8px;" onerror="this.src=\'' + (data.qr_alt || data.qr) + '\'">';
                                    document.getElementById('gw-qr-modal-link').value = data.url;
                                    document.getElementById('gw-qr-modal-open').href = data.url;
                                    document.getElementById('gw-qr-modal-download').href = data.qr;
                                    
                                    // Mostrar modal
                                    document.getElementById('gw-qr-modal').style.display = 'block';
                                    
                                    console.log('QR: Modal mostrado exitosamente');
                                } else {
                                    console.error('QR: Error en respuesta:', response);
                                    const errorMsg = response.data && response.data.msg ? response.data.msg : 'No se pudo generar el QR';
                                    alert('Error: ' + errorMsg);
                                }
                            })
                            .catch(error => {
                                console.error('QR: Error completo:', error);
                                alert('Error de conexión: ' + error.message);
                            })
                            .finally(() => {
                                // Restaurar botón siempre
                                button.disabled = false;
                                button.textContent = originalText;
                                button.style.opacity = '1';
                            });
                        }
                        
                        // Event delegation para botones QR (una sola vez)
                        document.addEventListener('click', function(e) {
                            // Solo procesar si es un botón QR
                            if (!e.target.matches('.gw-generar-qr-btn')) return;
                            
                            e.preventDefault();
                            e.stopImmediatePropagation();
                            
                            console.log('QR: Click detectado en botón');
                            handleQRGeneration(e.target);
                        }, true); // useCapture = true para mayor prioridad
                        
                        // Event listeners para modal (una sola vez)
                        document.addEventListener('click', function(e) {
                            // Cerrar modal
                            if (e.target.id === 'gw-qr-modal-cerrar' || e.target.id === 'gw-qr-modal') {
                                document.getElementById('gw-qr-modal').style.display = 'none';
                                return;
                            }
                            
                            // Copiar link
                            if (e.target.id === 'gw-qr-modal-copy') {
                                const linkInput = document.getElementById('gw-qr-modal-link');
                                linkInput.select();
                                linkInput.setSelectionRange(0, 99999);
                                
                                try {
                                    if (navigator.clipboard) {
                                        navigator.clipboard.writeText(linkInput.value).then(() => {
                                            e.target.textContent = '¡Copiado!';
                                            setTimeout(() => {
                                                e.target.textContent = 'Copiar link';
                                            }, 1500);
                                        });
                                    } else {
                                        document.execCommand('copy');
                                        e.target.textContent = '¡Copiado!';
                                        setTimeout(() => {
                                            e.target.textContent = 'Copiar link';
                                        }, 1500);
                                    }
                                } catch(err) {
                                    console.error('Error al copiar:', err);
                                    alert('No se pudo copiar el enlace');
                                }
                                return;
                            }
                        });
                    }
                    
                    // Handler para formularios de charlas (código original mantenido)
                    document.addEventListener('DOMContentLoaded', function() {
                        console.log('DOM: Inicializando formularios de charlas');
                        
                        document.querySelectorAll('.gw-form-charlas-pais').forEach(form => {
                            // Evitar múltiples listeners
                            if (form.dataset.initialized) return;
                            form.dataset.initialized = 'true';
                            
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
                    });
                    </script>

                    <style>
                    /* Botón "Generar link/QR" (solo en la pestaña de Países) */
                    #gw-admin-tab-paises .button.button-secondary.gw-generar-qr-btn{
                        background:#1e88e5 !important;
                        border-color: #1e88e5 !important;
                        color: #fff !important;
                        font-size: 12px;
                        padding: 4px 8px;
                        height: auto;
                        line-height: 1.2;
                        cursor: pointer;
                        transition: all 0.2s ease;
                    }

                    #gw-admin-tab-paises .button.button-secondary.gw-generar-qr-btn:hover{
                        background:#1976d2 !important;
                        border-color: #1976d2 !important;
                        transform: translateY(-1px);
                    }

                    #gw-admin-tab-paises .button.button-secondary.gw-generar-qr-btn:active{
                        background: #1565c0 !important;
                        border-color: #1565c0 !important;
                        transform: translateY(0);
                    }

                    #gw-admin-tab-paises .button.button-secondary.gw-generar-qr-btn:focus{
                        outline: none;
                        box-shadow: 0 0 0 3px rgba(30,136,229,.35) !important;
                    }

                    #gw-admin-tab-paises .button.button-secondary.gw-generar-qr-btn:disabled {
                        background: #999 !important;
                        border-color: #999 !important;
                        cursor: not-allowed;
                        transform: none;
                    }

                    /* Modal mejorado */
                    #gw-qr-modal {
                        backdrop-filter: blur(3px);
                    }
                    
                    #gw-qr-modal > div {
                        animation: modalSlideIn 0.3s ease-out;
                        bottom: -16rem;
                    }
                    
                    @keyframes modalSlideIn {
                        from {
                            opacity: 0;
                            transform: translateY(-50px);
                        }
                        to {
                            opacity: 1;
                            transform: translateY(0);
                        }
                    }

                    /* Botones del modal QR - azules */
                    #gw-qr-modal #gw-qr-modal-copy,
                    #gw-qr-modal #gw-qr-modal-open {
                        background: #1e88e5 !important;
                        border-color: #1e88e5 !important;
                        color: #fff !important;
                        text-decoration: none !important;
                        display: inline-block;
                        transition: all 0.2s ease;
                    }

                    #gw-qr-modal #gw-qr-modal-copy:hover,
                    #gw-qr-modal #gw-qr-modal-open:hover {
                        background: #1976d2 !important;
                        border-color: #1976d2 !important;
                        transform: translateY(-1px);
                    }

                    #gw-qr-modal #gw-qr-modal-download {
                        border-color: #28a745 !important;
                        color: #fff !important;
                        text-decoration: none !important;
                        display: inline-block;
                        transition: all 0.2s ease;
                    }

                    #gw-qr-modal #gw-qr-modal-download:hover {
                        border-color: #1e7e34 !important;
                        transform: translateY(-1px);
                    }

                    /* ========================================
                       MÓVIL RESPONSIVE - hasta 768px
                       ======================================== */
                    @media (max-width: 768px) {
                        /* Contenedor principal de países */
                        #gw-admin-tab-paises .gw-form-header h1 {
                            font-size: 20px !important;
                            margin-bottom: 4px !important;
                        }
                        
                        #gw-admin-tab-paises .gw-form-header p {
                            font-size: 12px !important;
                            margin-bottom: 16px !important;
                        }
                        
                        /* Contenedores de países */
                        #gw-admin-tab-paises > div[style*="max-width:700px"] {
                            max-width: 100% !important;
                            padding: 0 8px !important;
                        }
                        
                        /* Tarjetas de países - más compactas */
                        #gw-admin-tab-paises div[data-pais-id] {
                            padding: 10px !important;
                            margin-bottom: 10px !important;
                            border-radius: 8px !important;
                            border: 1px solid #e1e8ed !important;
                            background: #f8fafc !important;
                        }
                        
                        /* Títulos de países - una sola línea */
                        #gw-admin-tab-paises div[data-pais-id] h3 {
                            font-size: 15px !important;
                            margin-bottom: 10px !important;
                            display: flex !important;
                            align-items: center !important;
                            justify-content: space-between !important;
                            gap: 8px !important;
                            flex-wrap: nowrap !important;
                        }
                        
                        /* Botón "Generar link/QR" en móvil - más compacto */
                        #gw-admin-tab-paises .button.button-secondary.gw-generar-qr-btn {
                            font-size: 9px !important;
                            padding: 2px 4px !important;
                            border-radius: 4px !important;
                            min-height: auto !important;
                            height: auto !important;
                            line-height: 1.2 !important;
                            flex-shrink: 0 !important;
                            white-space: nowrap !important;
                        }
                        
                        /* Labels y checkboxes más compactos */
                        #gw-admin-tab-paises .gw-form-charlas-pais label {
                            font-size: 11px !important;
                            margin-bottom: 2px !important;
                            line-height: 1.2 !important;
                            display: flex !important;
                            align-items: center !important;
                            gap: 4px !important;
                        }
                        
                        #gw-admin-tab-paises .gw-form-charlas-pais label strong {
                            font-size: 12px !important;
                            margin-bottom: 4px !important;
                            display: block !important;
                        }
                        
                        /* Checkboxes más pequeños */
                        #gw-admin-tab-paises .gw-form-charlas-pais input[type="checkbox"] {
                            transform: scale(0.8) !important;
                            margin-right: 2px !important;
                        }
                        
                        /* Botón guardar y texto guardado en línea */
                        #gw-admin-tab-paises .gw-form-charlas-pais .button.button-primary {
                            font-size: 11px !important;
                            padding: 4px 8px !important;
                            margin-top: 6px !important;
                            margin-right: 8px !important;
                            display: inline-block !important;
                        }
                        
                        #gw-admin-tab-paises .gw-charlas-guardado {
                            font-size: 10px !important;
                            margin-left: 0 !important;
                            display: inline !important;
                        }
                        
                        /* Modal QR en móvil */
                        #gw-qr-modal > div {
                            max-width: 90% !important;
                            margin: 5% auto !important;
                            padding: 12px !important;
                            bottom: -16rem;
                        }
                        
                        #gw-qr-modal h3 {
                            font-size: 14px !important;
                            margin-bottom: 10px !important;
                        }
                        
                        #gw-qr-modal-qr img {
                            max-width: 150px !important;
                        }
                        
                        #gw-qr-modal input {
                            padding: 6px !important;
                            font-size: 11px !important;
                        }
                        
                        #gw-qr-modal .button {
                            font-size: 10px !important;
                            padding: 4px 6px !important;
                        }
                        
                        #gw-qr-modal > div > div[style*="display:flex"] {
                            gap: 4px !important;
                        }
                        
                        /* Espaciado mejor entre elementos */
                        #gw-admin-tab-paises .gw-form-charlas-pais {
                            margin-top: 6px !important;
                        }
                    }

                    /* ========================================
                       TABLET RESPONSIVE - 768px a 1024px
                       ======================================== */
                    @media (max-width: 1024px) and (min-width: 769px) {
                        /* Contenedor principal de países */
                        #gw-admin-tab-paises .gw-form-header h1 {
                            font-size: 28px;
                            margin-bottom: 8px;
                        }
                        
                        #gw-admin-tab-paises .gw-form-header p {
                            font-size: 14px;
                        }
                        
                        /* Contenedores de países */
                        #gw-admin-tab-paises > div[style*="max-width:700px"] {
                            max-width: 90% !important;
                        }
                        
                        /* Tarjetas de países */
                        #gw-admin-tab-paises div[data-pais-id] {
                            padding: 14px !important;
                            margin-bottom: 16px !important;
                            border-radius: 8px !important;
                        }
                        
                        /* Títulos de países */
                        #gw-admin-tab-paises div[data-pais-id] h3 {
                            font-size: 18px !important;
                            margin-bottom: 10px !important;
                            gap: 8px !important;
                        }
                        
                        /* Botón "Generar link/QR" en tablet */
                        #gw-admin-tab-paises .button.button-secondary.gw-generar-qr-btn {
                            font-size: 11px !important;
                            padding: 3px 6px !important;
                            border-radius: 4px;
                            min-height: auto;
                        }
                        
                        /* Labels y checkboxes */
                        #gw-admin-tab-paises .gw-form-charlas-pais label {
                            font-size: 13px !important;
                            margin-bottom: 4px !important;
                        }
                        
                        #gw-admin-tab-paises .gw-form-charlas-pais label strong {
                            font-size: 14px;
                        }
                        
                        /* Botón guardar */
                        #gw-admin-tab-paises .gw-form-charlas-pais .button.button-primary {
                            font-size: 13px !important;
                            padding: 6px 12px !important;
                            margin-top: 8px !important;
                        }
                        
                        /* Texto "Guardado" */
                        #gw-admin-tab-paises .gw-charlas-guardado {
                            font-size: 12px !important;
                            margin-left: 12px !important;
                        }
                        
                        /* Modal QR en tablet */
                        #gw-qr-modal > div {
                            max-width: 380px !important;
                            margin: 3% auto !important;
                            padding: 16px !important;
                            bottom: -16rem;
                            
                        }
                        
                        #gw-qr-modal h3 {
                            font-size: 18px !important;
                            margin-bottom: 16px !important;
                        }
                        
                        #gw-qr-modal-qr img {
                            max-width: 200px !important;
                        }
                        
                        #gw-qr-modal input {
                            padding: 6px !important;
                            font-size: 13px !important;
                        }
                        
                        #gw-qr-modal .button {
                            font-size: 12px !important;
                            padding: 6px 10px !important;
                        }
                        
                        #gw-qr-modal > div > div[style*="display:flex"] {
                            gap: 8px !important;
                        }
                    }

                    /* Tablet pequeño - 769px a 900px */
                    @media (max-width: 900px) and (min-width: 769px) {
                        #gw-admin-tab-paises .gw-form-header h1 {
                            font-size: 24px;
                        }
                        
                        #gw-admin-tab-paises div[data-pais-id] {
                            padding: 12px !important;
                        }
                        
                        #gw-admin-tab-paises div[data-pais-id] h3 {
                            font-size: 16px !important;
                            flex-direction: column !important;
                            align-items: flex-start !important;
                            gap: 6px !important;
                        }
                        
                        #gw-admin-tab-paises .button.button-secondary.gw-generar-qr-btn {
                            align-self: flex-start;
                            margin-top: 4px;
                        }
                    }

                    /* Modal responsive para móviles */
                    @media (max-width: 480px) {
                        #gw-qr-modal > div {
                            margin: 2% auto;
                            max-width: 95%;
                            padding: 15px;
                            bottom: -16rem;
                        }
                        
                        #gw-qr-modal-qr img {
                            max-width: 200px !important;
                        }
                    }
                    </style>
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

    /* Botones */
    #gw-admin-tab-usuarios .button.button-small.gw-user-toggle{background:#dc3545!important;border-color:#dc3545!important;color:#fff!important;}
    #gw-admin-tab-usuarios .button.button-small.gw-user-history{background:#1e88e5!important;border-color:#1e88e5!important;color:#fff!important;}

    /* ========================================
       MÓVIL RESPONSIVE - hasta 768px
       ======================================== */
    @media (max-width: 768px) {
        /* Header */
        #gw-admin-tab-usuarios .gw-form-header h1 {
            font-size: 20px !important;
            margin-bottom: 4px !important;
        }
        
        #gw-admin-tab-usuarios .gw-form-header p {
            font-size: 12px !important;
            margin-bottom: 16px !important;
        }
        
        /* Toolbar de filtros */
        .gw-users-toolbar {
            flex-direction: column !important;
            gap: 6px !important;
            margin: 8px 0 12px !important;
            align-items: stretch !important;
        }
        
        .gw-users-toolbar input[type="text"], 
        .gw-users-toolbar select {
            min-width: auto !important;
            width: 100% !important;
            padding: 8px 10px !important;
            font-size: 12px !important;
            border-radius: 6px !important;
        }
        
        /* Contenedor de tabla responsive */
        .gw-users-responsive {
            margin: 0 -8px !important;
            padding: 0 8px !important;
        }
        
        /* Tabla */
        table.gw-users {
            min-width: 600px !important;
            font-size: 11px !important;
        }
        
        table.gw-users th, 
        table.gw-users td {
            padding: 6px 4px !important;
        }
        
        table.gw-users th {
            font-size: 10px !important;
            background: #f0f4f8 !important;
        }
        
        /* Badges de estado */
        table.gw-users td span[style*="background:#e8f5e9"],
        table.gw-users td span[style*="background:#ffebee"] {
            padding: 1px 4px !important;
            font-size: 9px !important;
            border-radius: 8px !important;
        }
        
        /* Botones de acciones - mejor diseño */
        table.gw-users .button.button-small {
            font-size: 9px !important;
            padding: 3px 6px !important;
            margin: 1px 0 !important;
            border-radius: 4px !important;
            min-height: 20px !important;
            height: auto !important;
            line-height: 1.3 !important;
            display: inline-block !important;
            white-space: nowrap !important;
            vertical-align: middle !important;
        }
        
        /* Contenedor de botones para mejor organización */
        table.gw-users td:last-child {
            white-space: nowrap !important;
        }
        
        /* Ajustar específicamente cada botón */
        table.gw-users .gw-user-edit {
            background: #0073aa !important;
            border-color: #0073aa !important;
            color: #fff !important;
        }
        
        table.gw-users .gw-user-toggle {
            background: #dc3545 !important;
            border-color: #dc3545 !important;
            color: #fff !important;
        }
        
        table.gw-users .gw-user-history {
            background: #1e88e5 !important;
            border-color: #1e88e5 !important;
            color: #fff !important;
        }
        
        /* Ocultar algunas columnas en móvil muy pequeño */
        @media (max-width: 480px) {
            table.gw-users th:nth-child(4),
            table.gw-users td:nth-child(4) {
                display: none !important;
            }
        }
        
        /* Paginación */
        #gw-users-pagination {
            margin-top: 12px !important;
        }
        
        #gw-users-prev, #gw-users-next {
            font-size: 11px !important;
            padding: 6px 10px !important;
        }
        
        #gw-users-page-info {
            font-size: 11px !important;
            margin: 0 8px !important;
        }
        
        /* Modales */
        .gw-modal-box {
            max-width: 95% !important;
            margin: 2% auto !important;
            padding: 16px !important;
        }
        
        .gw-modal-close {
            font-size: 18px !important;
            right: 10px !important;
            top: 8px !important;
        }
        
        .gw-user-form label {
            font-size: 12px !important;
            margin: 6px 0 3px !important;
        }
        
        .gw-user-form input, 
        .gw-user-form select {
            padding: 6px !important;
            font-size: 12px !important;
        }
        
        .gw-user-form .actions {
            margin-top: 10px !important;
            gap: 6px !important;
            flex-direction: column !important;
        }
        
        .gw-user-form .actions .button {
            width: 100% !important;
            font-size: 12px !important;
            padding: 8px !important;
        }
        
        .gw-log-list {
            max-height: 200px !important;
            padding: 6px !important;
            font-size: 11px !important;
        }
        
        .gw-log-item {
            padding: 4px 2px !important;
        }
        
        .gw-log-item small {
            font-size: 9px !important;
        }
    }

    /* ========================================
       TABLET RESPONSIVE - 768px a 1024px
       ======================================== */
    @media (max-width: 1024px) and (min-width: 769px) {
        #gw-admin-tab-usuarios .gw-form-header h1 {
            font-size: 24px;
        }
        
        #gw-admin-tab-usuarios .gw-form-header p {
            font-size: 14px;
        }
        
        .gw-users-toolbar {
            flex-wrap: wrap;
            gap: 6px;
        }
        
        .gw-users-toolbar input[type="text"], 
        .gw-users-toolbar select {
            min-width: 150px;
            font-size: 13px;
        }
        
        table.gw-users {
            font-size: 13px;
        }
        
        table.gw-users th, 
        table.gw-users td {
            padding: 8px 6px;
        }
        
        table.gw-users .button.button-small {
            font-size: 10px;
            padding: 3px 6px;
        }
        
        .gw-modal-box {
            max-width: 500px;
            padding: 18px;
        }
    }
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

  <!-- Paginación -->
  <div id="gw-users-pagination" style="text-align:center;margin-top:15px;display:none;">
    <button type="button" id="gw-users-prev" class="button button-secondary">« Anterior</button>
    <span id="gw-users-page-info" style="margin:0 12px;font-weight:bold;"></span>
    <button type="button" id="gw-users-next" class="button button-secondary">Siguiente »</button>
  </div>

  <!-- Modal edición -->
  <div id="gw-user-modal">…</div>
  <!-- Modal historial -->
  <div id="gw-user-history-modal">…</div>

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
      initUsersPagination(); // reiniciar paginación al filtrar
    }
    search.addEventListener('input', applyFilters);
    roleFilter.addEventListener('change', applyFilters);
    statusFilter.addEventListener('change', applyFilters);

    // Paginación
    function initUsersPagination(){
      var rows = Array.from(document.querySelectorAll('#gw-users-table tbody tr')).filter(r=>r.style.display!=='none');
      var perPage = 10;
      var current = 1;
      var totalPages = Math.ceil(rows.length / perPage);

      function showPage(p){
        rows.forEach((r,i)=>{
          r.style.display = (i >= (p-1)*perPage && i < p*perPage) ? '' : 'none';
        });
        document.getElementById('gw-users-prev').disabled = (p===1);
        document.getElementById('gw-users-next').disabled = (p===totalPages);
        document.getElementById('gw-users-page-info').textContent = "Página "+p+" de "+totalPages;
        document.getElementById('gw-users-pagination').style.display = totalPages>1 ? '' : 'none';
      }

      if(rows.length){
        showPage(current);
        document.getElementById('gw-users-prev').onclick=function(){ if(current>1){current--;showPage(current);} };
        document.getElementById('gw-users-next').onclick=function(){ if(current<totalPages){current++;showPage(current);} };
      } else {
        document.getElementById('gw-users-pagination').style.display='none';
      }
    }

    initUsersPagination();

    // tus funciones gwUserEdit, gwUserToggle, gwUserHistory siguen igual…
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
                
                // Botón eliminar
                echo '<button type="button" class="gw-eliminar-charla" data-charla-id="'.$charla->ID.'" style="position:absolute;top:18px;right:18px;background:linear-gradient(135deg, #dc3545, #c82333);color:white;border:none;border-radius:8px;width:34px;height:34px;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 4px rgba(220,53,69,0.3);transition:all 0.2s ease;" title="Eliminar charla">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M3 6h18m-2 0v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2M10 11v6M14 11v6"/>
                    </svg>
                </button>';
                
                echo '<h3 style="margin:0 0 12px 0;padding-right:40px;">' . esc_html($charla->post_title) . '</h3>';
                
                // Mostrar tags
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
                
                foreach ($sesiones as $idx => $sesion) { ?>
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
                            <input type="text" name="sesion_lugar[]" value="<?php echo isset($sesion['lugar']) ? esc_attr($sesion['lugar']) : ''; ?>" <?php if(isset($sesion['modalidad']) && strtolower($sesion['modalidad'])=='virtual') echo 'disabled'; ?>>
                        </label>
                        <label class="gw-link-label-panel" style="margin-left:18px;<?php if(!isset($sesion['modalidad']) || strtolower($sesion['modalidad'])!='virtual') echo 'display:none;'; ?>">
                            Link:
                            <input type="url" name="sesion_link[]" value="<?php echo isset($sesion['link']) ? esc_attr($sesion['link']) : ''; ?>" <?php if(!isset($sesion['modalidad']) || strtolower($sesion['modalidad'])!='virtual') echo 'disabled'; ?>>
                        </label>
                        <button type="button" class="gw-remove-sesion-panel button button-small" style="margin-left:18px;">Eliminar</button>
                    </div>
                <?php }
                
                echo '</div>';
                echo '<button type="button" class="gw-add-sesion-panel button button-secondary">Agregar sesión</button>';
                echo '<button type="submit" class="button button-primary" style="margin-left:14px;">Guardar sesiones</button>';
                echo '<span class="gw-sesiones-guardado" style="margin-left:18px;color:#1e7e34;display:none;">Guardado</span>';
                echo '</form>';
                echo '</div>';
            }

            // Listado con paginación
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
                        $display_style = $index >= 4 ? 'style="display:none;"' : '';
                        echo '<div class="gw-charla-wrapper" '.$display_style.'>';
                        gw_render_charla_individual($charla);
                        echo '</div>';
                    }
                    
                    if (count($charlas) > 4) {
                        echo '<div id="gw-pagination-container" style="text-align:center;margin-top:20px;">';
                        echo '<button type="button" id="gw-prev-page" class="button button-secondary" disabled>« Anterior</button>';
                        echo '<span id="gw-pagination-info" style="margin:0 12px;font-weight:bold;"></span>';
                        echo '<button type="button" id="gw-next-page" class="button button-secondary">Siguiente »</button>';
                        echo '</div>';
                    }
                }
            }
            
            gw_render_listado_charlas_panel();
            ?>
        </div>
    </div>

    <script>
// Asegurar que ajaxurl esté definido
if (typeof ajaxurl === 'undefined') {
    window.ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
}

jQuery(document).ready(function($) {
    
    // =============================================
    // 1. AGREGAR NUEVA CHARLA
    // =============================================
    $('#gw-form-nueva-charla').on('submit', function(e) {
        e.preventDefault();
        
        var titulo = $('#gw-nueva-charla-title').val().trim();
        if (!titulo) {
            alert('Por favor ingresa un nombre para la charla');
            return;
        }
        
        var $btn = $(this).find('button[type="submit"]');
        var $guardado = $('#gw-charla-guardado');
        
        $btn.prop('disabled', true).text('Guardando...');
        
        $.post(ajaxurl, {
            action: 'gw_agregar_charla',
            titulo: titulo
        }, function(response) {
            if (response.success) {
                $('#gw-nueva-charla-title').val('');
                $guardado.show().delay(2000).fadeOut();
                
                // Actualizar listado
                $('#gw-listado-charlas').html(response.data.html);
                initPaginacionCharlas();
                initCharlasEventos();
                
                // Actualizar contador de eliminadas
                actualizarContadorEliminadas();
            } else {
                alert('Error: ' + (response.data.msg || 'No se pudo agregar la charla'));
            }
        }).fail(function() {
            alert('Error de conexión');
        }).always(function() {
            $btn.prop('disabled', false).text('Agregar charla');
        });
    });

    // =============================================
    // 2. ELIMINAR CHARLA
    // =============================================
    function eliminarCharla(charlaId) {
        if (!confirm('¿Estás seguro de que quieres eliminar esta charla?')) {
            return;
        }
        
        $.post(ajaxurl, {
            action: 'gw_eliminar_charla',
            charla_id: charlaId
        }, function(response) {
            if (response.success) {
                // Remover del DOM
                $('.gw-eliminar-charla[data-charla-id="' + charlaId + '"]').closest('.gw-charla-wrapper').fadeOut(function() {
                    $(this).remove();
                    initPaginacionCharlas();
                });
                
                // Actualizar contador
                actualizarContadorEliminadas();
            } else {
                alert('Error: ' + (response.data.msg || 'No se pudo eliminar la charla'));
            }
        }).fail(function() {
            alert('Error de conexión');
        });
    }

    // =============================================
    // 3. GUARDAR SESIONES DE CHARLA
    // =============================================
    function guardarSesiones($form) {
        var charlaId = $form.data('charla');
        var formData = $form.serialize() + '&action=gw_guardar_sesiones_charla&charla_id=' + charlaId;
        
        var $btn = $form.find('button[type="submit"]');
        var $guardado = $form.find('.gw-sesiones-guardado');
        
        $btn.prop('disabled', true).text('Guardando...');
        
        $.post(ajaxurl, formData, function(response) {
            if (response.success) {
                $guardado.show().delay(2000).fadeOut();
            } else {
                alert('Error: ' + (response.data.msg || 'No se pudieron guardar las sesiones'));
            }
        }).fail(function() {
            alert('Error de conexión');
        }).always(function() {
            $btn.prop('disabled', false).text('Guardar sesiones');
        });
    }

    // =============================================
    // 4. AGREGAR/ELIMINAR SESIONES
    // =============================================
    function agregarSesion($container) {
        var nuevaSesion = `
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
        $container.append(nuevaSesion);
    }

    // =============================================
    // 5. GESTIÓN DE CHARLAS ELIMINADAS
    // =============================================
    function actualizarContadorEliminadas() {
        $.post(ajaxurl, {
            action: 'gw_contar_eliminadas'
        }, function(response) {
            if (response.success) {
                $('#gw-count-eliminadas').text(response.data.count);
            }
        });
    }

    function mostrarCharlasEliminadas() {
        $.post(ajaxurl, {
            action: 'gw_obtener_eliminadas'
        }, function(response) {
            if (response.success) {
                $('#gw-listado-eliminadas').html(response.data.html);
                $('#gw-charlas-eliminadas-panel').show();
                initEliminadasEventos();
            }
        });
    }

    function restaurarCharla(charlaId) {
        $.post(ajaxurl, {
            action: 'gw_restaurar_charla',
            charla_id: charlaId
        }, function(response) {
            if (response.success) {
                // Actualizar vista
                mostrarCharlasEliminadas();
                actualizarContadorEliminadas();
                location.reload(); // Recargar para mostrar charla restaurada
            } else {
                alert('Error: ' + (response.data.msg || 'No se pudo restaurar la charla'));
            }
        });
    }

    function eliminarDefinitivo(charlaId) {
        if (!confirm('¿Estás seguro? Esta acción no se puede deshacer.')) {
            return;
        }
        
        $.post(ajaxurl, {
            action: 'gw_eliminar_definitivo',
            charla_id: charlaId
        }, function(response) {
            if (response.success) {
                mostrarCharlasEliminadas();
                actualizarContadorEliminadas();
            } else {
                alert('Error: ' + (response.data.msg || 'No se pudo eliminar la charla'));
            }
        });
    }

    // =============================================
    // 6. INICIALIZAR EVENTOS DE CHARLAS
    // =============================================
    function initCharlasEventos() {
        // Eliminar charla
        $(document).off('click', '.gw-eliminar-charla').on('click', '.gw-eliminar-charla', function() {
            var charlaId = $(this).data('charla-id');
            eliminarCharla(charlaId);
        });

        // Guardar sesiones
        $(document).off('submit', '.gw-form-sesiones-charla').on('submit', '.gw-form-sesiones-charla', function(e) {
            e.preventDefault();
            guardarSesiones($(this));
        });

        // Agregar sesión
        $(document).off('click', '.gw-add-sesion-panel').on('click', '.gw-add-sesion-panel', function() {
            var charlaId = $(this).closest('.gw-form-sesiones-charla').data('charla');
            var $container = $('#gw-sesiones-list-' + charlaId);
            agregarSesion($container);
        });

        // Eliminar sesión
        $(document).off('click', '.gw-remove-sesion-panel').on('click', '.gw-remove-sesion-panel', function() {
            if ($('.gw-sesion-block-panel').length > 1) {
                $(this).closest('.gw-sesion-block-panel').remove();
            } else {
                alert('Debe haber al menos una sesión');
            }
        });

        // Cambio de modalidad
        $(document).off('change', '.gw-sesion-modalidad-panel').on('change', '.gw-sesion-modalidad-panel', function() {
            var $sesion = $(this).closest('.gw-sesion-block-panel');
            var modalidad = $(this).val().toLowerCase();
            
            if (modalidad === 'virtual') {
                $sesion.find('.gw-lugar-label-panel').hide();
                $sesion.find('.gw-link-label-panel').show();
                $sesion.find('input[name="sesion_lugar[]"]').prop('disabled', true);
                $sesion.find('input[name="sesion_link[]"]').prop('disabled', false);
            } else {
                $sesion.find('.gw-lugar-label-panel').show();
                $sesion.find('.gw-link-label-panel').hide();
                $sesion.find('input[name="sesion_lugar[]"]').prop('disabled', false);
                $sesion.find('input[name="sesion_link[]"]').prop('disabled', true);
            }
        });
    }

    // =============================================
    // 7. EVENTOS DE CHARLAS ELIMINADAS
    // =============================================
    function initEliminadasEventos() {
        $(document).off('click', '.gw-restaurar-charla').on('click', '.gw-restaurar-charla', function() {
            var charlaId = $(this).data('charla-id');
            restaurarCharla(charlaId);
        });

        $(document).off('click', '.gw-eliminar-definitivo').on('click', '.gw-eliminar-definitivo', function() {
            var charlaId = $(this).data('charla-id');
            eliminarDefinitivo(charlaId);
        });
    }

    // =============================================
    // 8. FILTROS DE BÚSQUEDA
    // =============================================
    function aplicarFiltros() {
        var nombre = $('#gw-filtro-nombre').val().toLowerCase();
        var modalidad = $('#gw-filtro-modalidad').val();
        var lugar = $('#gw-filtro-lugar').val().toLowerCase();

        $('.gw-charla-wrapper').each(function() {
            var $charla = $(this);
            var $item = $charla.find('.gw-charla-item');
            
            var nombreCharla = $item.data('nombre') || '';
            var modalidades = ($item.data('modalidades') || '').toString();
            var lugares = ($item.data('lugares') || '').toString().toLowerCase();
            
            var mostrar = true;
            
            // Filtro por nombre
            if (nombre && nombreCharla.indexOf(nombre) === -1) {
                mostrar = false;
            }
            
            // Filtro por modalidad
            if (modalidad) {
                if (modalidad === 'Mixta') {
                    if (modalidades.indexOf('Presencial') === -1 || modalidades.indexOf('Virtual') === -1) {
                        mostrar = false;
                    }
                } else {
                    if (modalidades.indexOf(modalidad) === -1) {
                        mostrar = false;
                    }
                }
            }
            
            // Filtro por lugar
            if (lugar && lugares.indexOf(lugar) === -1) {
                mostrar = false;
            }
            
            $charla.toggle(mostrar);
        });
        
        // Reinicializar paginación después de filtrar
        initPaginacionCharlas();
    }

    // =============================================
    // 9. PAGINACIÓN (MEJORADA CON JQUERY)
    // =============================================
    function initPaginacionCharlas() {
        var $charlas = $('.gw-charla-wrapper:visible');
        if (!$charlas.length) return;
        
        var porPagina = 4;
        var paginaActual = 1;
        var totalPaginas = Math.ceil($charlas.length / porPagina);

        function mostrarPagina(pagina) {
            $charlas.each(function(i) {
                var mostrar = (i >= (pagina - 1) * porPagina && i < pagina * porPagina);
                $(this).toggle(mostrar);
            });

            $('#gw-prev-page').prop('disabled', pagina === 1);
            $('#gw-next-page').prop('disabled', pagina === totalPaginas);
            $('#gw-pagination-info').text("Página " + pagina + " de " + totalPaginas);
        }

        if ($('#gw-pagination-container').length) {
            mostrarPagina(paginaActual);

            $('#gw-prev-page').off('click').on('click', function() {
                if (paginaActual > 1) {
                    paginaActual--;
                    mostrarPagina(paginaActual);
                }
            });

            $('#gw-next-page').off('click').on('click', function() {
                if (paginaActual < totalPaginas) {
                    paginaActual++;
                    mostrarPagina(paginaActual);
                }
            });
        }
    }

    // =============================================
    // 10. EVENTOS PRINCIPALES
    // =============================================
    
    // Ver charlas eliminadas
    $('#gw-ver-eliminadas').on('click', function() {
        mostrarCharlasEliminadas();
    });

    // Cerrar panel de eliminadas
    $('#gw-cerrar-eliminadas').on('click', function() {
        $('#gw-charlas-eliminadas-panel').hide();
    });

    // Limpiar filtros
    $('#gw-limpiar-filtros').on('click', function() {
        $('#gw-filtro-nombre, #gw-filtro-lugar').val('');
        $('#gw-filtro-modalidad').val('');
        aplicarFiltros();
    });

    // Eventos de filtros
    $('#gw-filtro-nombre, #gw-filtro-lugar').on('input', aplicarFiltros);
    $('#gw-filtro-modalidad').on('change', aplicarFiltros);

    // =============================================
    // 11. INICIALIZACIÓN
    // =============================================
    
    // Inicializar eventos al cargar
    initCharlasEventos();
    initPaginacionCharlas();
    actualizarContadorEliminadas();
    
});
</script>
    
    <style>
    /* Botón primario "Guardar sesiones" en la pestaña de charlas: azul */
    #gw-admin-tab-charlas .button.button-primary{
      background: #1e88e5 !important;
      border-color: #1e88e5 !important;
      color: #fff !important;
    }
    #gw-admin-tab-charlas .button.button-primary:hover{
      background: #1e88e5 !important;
      border-color: #1976d2 !important;
    }
    #gw-admin-tab-charlas .button.button-primary:active{
      background: #1e88e5 !important;
      border-color: #1565c0 !important;
    }
    #gw-admin-tab-charlas .button.button-primary:focus{
      outline: none;
      box-shadow: 0 0 0 3px rgba(30,136,229,.35) !important;
    }

    /* Botón "Eliminar" dentro de cada sesión: rojo estilo danger */
    .gw-remove-sesion-panel.button {
      background: #dc3545 !important;
      border-color: #dc3545 !important;
      color: #fff !important;
    }
    .gw-remove-sesion-panel.button:hover {
      background: #c82333 !important;
      border-color: #bd2130 !important;
    }
    .gw-remove-sesion-panel.button:active {
      background: #a71e2a !important;
      border-color: #a71e2a !important;
    }
    .gw-remove-sesion-panel.button:focus {
      box-shadow: 0 0 0 3px rgba(220, 53, 69, 0.35) !important;
      outline: none;
    }

    .gw-sesion-block-panel label {font-weight:normal;}
    .gw-charla-item {transition: all 0.3s ease;}
    .gw-charla-item:hover {
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        transform: translateY(-1px);
    }
    .gw-eliminar-charla:hover {
        background: linear-gradient(135deg, #c82333, #a71e2a) !important;
        transform: scale(1.05);
        box-shadow: 0 4px 8px rgba(220,53,69,0.4) !important;
    }
    .gw-eliminar-charla:active {transform: scale(0.95);}
    .gw-eliminar-charla svg {
        stroke: currentColor; fill: none; stroke-width: 2;
        stroke-linecap: round; stroke-linejoin: round;
    }
    #gw-filtro-nombre, #gw-filtro-lugar {
        transition: all 0.2s ease; border: 1px solid #ddd; border-radius: 4px;
    }
    #gw-filtro-nombre:focus, #gw-filtro-lugar:focus {
        border-color: #007cba; outline: none;
        box-shadow: 0 0 0 3px rgba(0,124,186,0.1);
    }
    #gw-filtro-modalidad {
        border: 1px solid #ddd; border-radius: 4px; transition: all 0.2s ease;
    }
    #gw-filtro-modalidad:focus {
        border-color: #007cba; outline: none;
        box-shadow: 0 0 0 3px rgba(0,124,186,0.1);
    }
    .gw-charla-wrapper {transition: opacity 0.3s ease;}

    /* ========================================
       MÓVIL RESPONSIVE - hasta 768px
       ======================================== */
    @media (max-width: 768px) {
        /* Header */
        #gw-admin-tab-charlas .gw-form-header h1 {
            font-size: 20px !important;
            margin-bottom: 4px !important;
        }
        
        #gw-admin-tab-charlas .gw-form-header p {
            font-size: 12px !important;
            margin-bottom: 16px !important;
        }
        
        /* Contenedor principal */
        #gw-admin-tab-charlas > div[style*="max-width:900px"] {
            max-width: 100% !important;
            padding: 0 8px !important;
        }
        
        /* Panel de filtros */
        #gw-admin-tab-charlas > div > div[style*="margin-bottom:20px"] {
            padding: 12px !important;
            margin-bottom: 16px !important;
        }
        
        /* Formulario nueva charla */
        #gw-form-nueva-charla {
            flex-direction: column !important;
            align-items: stretch !important;
            gap: 8px !important;
        }
        
        #gw-nueva-charla-title {
            width: 100% !important;
            padding: 8px !important;
            font-size: 12px !important;
        }
        
        #gw-form-nueva-charla .button {
            font-size: 12px !important;
            padding: 8px 12px !important;
        }
        
        /* Filtros */
        #gw-admin-tab-charlas h4 {
            font-size: 13px !important;
            margin-bottom: 8px !important;
        }
        
        #gw-admin-tab-charlas > div > div > div > div[style*="display:flex"] {
            flex-direction: column !important;
            gap: 6px !important;
            align-items: stretch !important;
        }
        
        #gw-filtro-nombre, #gw-filtro-lugar {
            width: 100% !important;
            font-size: 11px !important;
            padding: 6px !important;
        }
        
        #gw-filtro-modalidad {
            width: 100% !important;
            font-size: 11px !important;
            padding: 6px !important;
        }
        
        #gw-limpiar-filtros, #gw-ver-eliminadas {
            font-size: 10px !important;
            padding: 6px 8px !important;
        }
        
        /* Tarjetas de charlas */
        .gw-charla-item {
            padding: 12px !important;
            margin-bottom: 12px !important;
            border-radius: 6px !important;
        }
        
        .gw-charla-item h3 {
            font-size: 15px !important;
            margin-bottom: 8px !important;
            padding-right: 35px !important;
        }
        
        /* Botón eliminar - mejor proporción */
        .gw-eliminar-charla {
            width: 24px !important;
            height: 24px !important;
            top: 10px !important;
            right: 10px !important;
            font-size: 12px !important;
            border-radius: 4px !important;
        }
        
        .gw-eliminar-charla svg {
            width: 10px !important;
            height: 10px !important;
        }
        
        /* Tags de modalidad */
        .gw-charla-item > div[style*="margin-bottom:8px"] span {
            font-size: 9px !important;
            padding: 1px 6px !important;
        }
        
        /* Bloques de sesión */
        .gw-sesion-block-panel {
            padding: 8px !important;
            margin-bottom: 8px !important;
            border-radius: 6px !important;
        }
        
        .gw-sesion-block-panel label {
            display: block !important;
            margin: 4px 0 !important;
            font-size: 11px !important;
        }
        
        .gw-sesion-block-panel select,
        .gw-sesion-block-panel input {
            width: 100% !important;
            padding: 4px !important;
            font-size: 11px !important;
            margin-top: 2px !important;
        }
        
        /* Botones de sesión */
        .gw-remove-sesion-panel {
            font-size: 9px !important;
            padding: 3px 6px !important;
            margin: 4px 0 0 0 !important;
        }
        
        .gw-add-sesion-panel,
        .gw-form-sesiones-charla .button.button-primary {
            font-size: 10px !important;
            padding: 6px 8px !important;
            margin: 6px 4px 0 0 !important;
        }
        
        .gw-sesiones-guardado {
            font-size: 9px !important;
            margin-left: 4px !important;
        }
        
        /* Panel eliminadas */
        #gw-charlas-eliminadas-panel {
            padding: 12px !important;
            margin-bottom: 16px !important;
        }
        
        #gw-charlas-eliminadas-panel h3 {
            font-size: 14px !important;
        }
        
        #gw-cerrar-eliminadas {
            font-size: 10px !important;
            padding: 4px 6px !important;
        }
        
        /* Paginación */
        #gw-pagination-container {
            margin-top: 12px !important;
        }
        
        #gw-prev-page, #gw-next-page {
            font-size: 10px !important;
            padding: 6px 8px !important;
        }
        
        #gw-pagination-info {
            font-size: 10px !important;
            margin: 0 6px !important;
        }
    }

    /* ========================================
       TABLET RESPONSIVE - 768px a 1024px
       ======================================== */
    @media (max-width: 1024px) and (min-width: 769px) {
        #gw-admin-tab-charlas .gw-form-header h1 {
            font-size: 24px;
        }
        
        #gw-admin-tab-charlas .gw-form-header p {
            font-size: 14px;
        }
        
        #gw-form-nueva-charla {
            flex-wrap: wrap;
        }
        
        #gw-nueva-charla-title {
            width: 200px;
            font-size: 13px;
        }
        
        .gw-charla-item {
            padding: 14px;
        }
        
        .gw-charla-item h3 {
            font-size: 17px;
        }
        
        .gw-sesion-block-panel {
            padding: 10px;
        }
        
        .gw-sesion-block-panel label {
            font-size: 12px;
        }
        
        .gw-sesion-block-panel select,
        .gw-sesion-block-panel input {
            font-size: 12px;
        }
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
/* Cards de proyectos modernas */
.gw-proyecto-item {
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 24px;
    margin-bottom: 20px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1), 0 1px 2px rgba(0, 0, 0, 0.06);
}

.gw-proyecto-item::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, #3b82f6, #1d4ed8, #7c3aed);
    opacity: 0;
    transition: opacity 0.3s ease;
}

.gw-proyecto-item:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1), 0 4px 12px rgba(0, 0, 0, 0.05);
    border-color: #cbd5e1;
}

.gw-proyecto-item:hover::before {
    opacity: 1;
}

/* Proyecto eliminado */
.gw-proyecto-eliminado {
    background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
    border-color: #fca5a5;
    position: relative;
}

.gw-proyecto-eliminado::before {
    background: linear-gradient(90deg, #ef4444, #dc2626);
    opacity: 1;
}

.gw-proyecto-eliminado::after {
    content: 'ELIMINADO';
    position: absolute;
    top: 12px;
    right: 12px;
    background: rgba(239, 68, 68, 0.1);
    color: #dc2626;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.5px;
}

/* Header del proyecto */
.gw-proyecto-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 16px;
    gap: 16px;
}

.gw-proyecto-title {
    font-size: 18px;
    font-weight: 600;
    color: #111827;
    line-height: 1.4;
    margin: 0;
    flex: 1;
}

.gw-proyecto-eliminado .gw-proyecto-title {
    color: #7f1d1d;
    text-decoration: line-through;
    opacity: 0.7;
}

/* Información del proyecto */
.gw-proyecto-info {
    margin-bottom: 20px;
}

.gw-proyecto-meta {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 12px;
    margin-bottom: 16px;
}

.gw-meta-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    background: rgba(59, 130, 246, 0.05);
    border-radius: 8px;
    font-size: 14px;
    color: #475569;
}

.gw-meta-icon {
    width: 16px;
    height: 16px;
    color: #3b82f6;
    flex-shrink: 0;
}

.gw-proyecto-descripcion {
    color: #64748b;
    font-size: 14px;
    line-height: 1.6;
    margin: 12px 0 0 0;
    padding: 12px;
    background: #f8fafc;
    border-radius: 8px;
    border-left: 3px solid #e2e8f0;
}

/* Acciones del proyecto */
.gw-proyecto-acciones {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    align-items: center;
    padding-top: 16px;
    border-top: 1px solid #f1f5f9;
}

.gw-proyecto-acciones button {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 500;
    white-space: nowrap;
    transition: all 0.2s ease;
    text-decoration: none;
    min-height: 36px;
}

.gw-proyecto-acciones button:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
}

/* Botones específicos */
.gw-btn-eliminar {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: white;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.gw-btn-eliminar:hover {
    background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
}

.gw-btn-restaurar {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.gw-btn-restaurar:hover {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
}

.gw-btn-historial {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: white;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.gw-btn-historial:hover {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
}

.gw-btn-editar {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: white;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.gw-btn-editar:hover {
    background: linear-gradient(135deg, #d97706 0%, #b45309 100%);
}

.gw-btn-wp {
    background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
    color: white;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.gw-btn-wp:hover {
    background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
}

/* Estado activo/destacado */
.gw-proyecto-item.destacado {
    border-color: #3b82f6;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15);
}

.gw-proyecto-item.destacado::before {
    opacity: 1;
}

/* ========================================
   MÓVIL RESPONSIVE - hasta 768px
   ======================================== */
@media (max-width: 768px) {
    /* Header */
    #gw-admin-tab-proyectos .gw-form-header h1 {
        font-size: 20px !important;
        margin-bottom: 4px !important;
    }
    
    #gw-admin-tab-proyectos .gw-form-header p {
        font-size: 12px !important;
        margin-bottom: 16px !important;
    }
    
    /* Panel de filtros */
    #gw-admin-tab-proyectos > div[style*="background:#f9f9f9"] {
        padding: 12px !important;
        margin-bottom: 16px !important;
    }
    
    #gw-admin-tab-proyectos > div > div[style*="display:flex"] {
        flex-direction: column !important;
        gap: 8px !important;
        align-items: stretch !important;
    }
    
    /* Elementos de filtro */
    #gw-admin-tab-proyectos div[style*="display:flex"] > div {
        display: flex !important;
        flex-direction: column !important;
        gap: 4px !important;
    }
    
    #gw-admin-tab-proyectos div[style*="display:flex"] > div label {
        font-size: 11px !important;
       margin-bottom: 2px !important;
   }
   
   #gw-admin-tab-proyectos div[style*="display:flex"] > div select,
   #gw-admin-tab-proyectos div[style*="display:flex"] > div input {
       width: 100% !important;
       padding: 8px !important;
       font-size: 14px !important;
       margin-left: 0 !important;
       border: 1px solid #ddd !important;
       border-radius: 4px !important;
   }
   
   #gw-admin-tab-proyectos #gw-limpiar-filtros {
       width: 100% !important;
       padding: 10px !important;
       font-size: 13px !important;
       margin-top: 4px !important;
   }
   
   /* Formulario nuevo proyecto */
   #gw-admin-tab-proyectos > div[style*="background:#fff"] {
       padding: 16px !important;
       margin-bottom: 20px !important;
   }
   
   #gw-admin-tab-proyectos form h3 {
       font-size: 16px !important;
       margin-bottom: 12px !important;
   }
   
   #gw-admin-tab-proyectos div[style*="display:grid;grid-template-columns:1fr 1fr"] {
       grid-template-columns: 1fr !important;
       gap: 12px !important;
       max-width: none !important;
   }
   
   #gw-admin-tab-proyectos form label {
       font-size: 12px !important;
       display: block !important;
       margin-bottom: 4px !important;
   }
   
   #gw-admin-tab-proyectos form input,
   #gw-admin-tab-proyectos form select,
   #gw-admin-tab-proyectos form textarea {
       width: 100% !important;
       padding: 10px !important;
       font-size: 14px !important;
       margin-top: 2px !important;
       border: 1px solid #ddd !important;
       border-radius: 4px !important;
       box-sizing: border-box !important;
   }
   
   #gw-admin-tab-proyectos form textarea {
       height: 80px !important;
   }
   
   #gw-admin-tab-proyectos form div[style*="margin-top:15px"] {
       margin-top: 12px !important;
   }
   
   #gw-admin-tab-proyectos form .button {
       width: 100% !important;
       padding: 12px !important;
       font-size: 14px !important;
       margin-bottom: 8px !important;
   }
   
   #gw-admin-tab-proyectos form span[id*="proyecto-"] {
       display: block !important;
       margin-left: 0 !important;
       margin-top: 8px !important;
       text-align: center !important;
       font-size: 12px !important;
   }
   
   /* Cards de proyectos móvil */
   .gw-proyecto-item {
       padding: 16px !important;
       margin-bottom: 16px !important;
       border-radius: 12px !important;
   }
   
   .gw-proyecto-eliminado::after {
       top: 8px !important;
       right: 8px !important;
       font-size: 9px !important;
       padding: 2px 8px !important;
   }
   
   /* Header del proyecto móvil */
   .gw-proyecto-header {
       flex-direction: column !important;
       gap: 8px !important;
       margin-bottom: 12px !important;
       align-items: stretch !important;
   }
   
   .gw-proyecto-title {
       font-size: 16px !important;
       line-height: 1.3 !important;
       margin-bottom: 4px !important;
   }
   
   /* Meta información móvil */
   .gw-proyecto-meta {
       grid-template-columns: 1fr !important;
       gap: 8px !important;
       margin-bottom: 12px !important;
   }
   
   .gw-meta-item {
       padding: 6px 10px !important;
       font-size: 12px !important;
       border-radius: 6px !important;
   }
   
   .gw-meta-icon {
       width: 14px !important;
       height: 14px !important;
   }
   
   .gw-proyecto-descripcion {
       font-size: 12px !important;
       padding: 8px !important;
       margin-top: 8px !important;
       border-radius: 6px !important;
       line-height: 1.4 !important;
   }
   
   /* Acciones móvil */
   .gw-proyecto-acciones {
       padding-top: 12px !important;
       gap: 6px !important;
       flex-direction: column !important;
   }
   
   .gw-proyecto-acciones button {
       width: 100% !important;
       padding: 10px 12px !important;
       font-size: 12px !important;
       min-height: 40px !important;
       border-radius: 6px !important;
       justify-content: center !important;
   }
   
   /* Modal de edición móvil */
   body div[style*="position:fixed"] > div {
       max-width: 95% !important;
       width: 95% !important;
       padding: 20px !important;
       margin: 10px !important;
       border-radius: 8px !important;
       max-height: 90vh !important;
       overflow-y: auto !important;
   }
   
   body div[style*="position:fixed"] h3 {
       font-size: 16px !important;
       margin-bottom: 12px !important;
   }
   
   body div[style*="position:fixed"] label {
       font-size: 12px !important;
       margin-bottom: 4px !important;
   }
   
   body div[style*="position:fixed"] input,
   body div[style*="position:fixed"] select,
   body div[style*="position:fixed"] textarea {
       padding: 10px !important;
       font-size: 14px !important;
       border: 1px solid #ddd !important;
       border-radius: 4px !important;
   }
   
   body div[style*="position:fixed"] div[style*="text-align:right"] {
       text-align: center !important;
       margin-top: 16px !important;
   }
   
   body div[style*="position:fixed"] .button {
       width: 48% !important;
       padding: 10px !important;
       font-size: 13px !important;
       margin: 2px !important;
   }
}

/* ========================================
  TABLET RESPONSIVE - 769px a 1024px
  ======================================== */
@media (min-width: 769px) and (max-width: 1024px) {
   /* Formulario más compacto en tablet */
   #gw-admin-tab-proyectos div[style*="display:grid;grid-template-columns:1fr 1fr"] {
       grid-template-columns: 1fr 1fr !important;
       gap: 12px !important;
   }
   
   /* Cards en tablet */
   .gw-proyecto-item {
       padding: 20px !important;
   }
   
   .gw-proyecto-meta {
       grid-template-columns: repeat(2, 1fr) !important;
       gap: 10px !important;
   }
   
   /* Acciones más compactas */
   .gw-proyecto-acciones {
       gap: 6px !important;
   }
   
   .gw-proyecto-acciones button {
       padding: 6px 12px !important;
       font-size: 12px !important;
   }
}

/* ========================================
  MEJORAS GENERALES DE ACCESIBILIDAD
  ======================================== */
/* Focus states mejorados */
#gw-admin-tab-proyectos input:focus,
#gw-admin-tab-proyectos select:focus,
#gw-admin-tab-proyectos textarea:focus,
#gw-admin-tab-proyectos button:focus {
   outline: 2px solid #3b82f6 !important;
   outline-offset: 2px !important;
   border-color: #3b82f6 !important;
}

/* Hover states para móvil */
@media (hover: hover) {
   .gw-proyecto-item:hover {
       transform: translateY(-2px);
   }
   
   .gw-proyecto-acciones button:hover {
       transform: translateY(-1px);
   }
}

/* Animaciones reducidas para usuarios que prefieren menos movimiento */
@media (prefers-reduced-motion: reduce) {
   .gw-proyecto-item,
   .gw-proyecto-acciones button {
       transition: none !important;
       transform: none !important;
   }
   
   .gw-proyecto-item:hover,
   .gw-proyecto-acciones button:hover {
       transform: none !important;
   }
}

/* Mejoras de contraste para mejor legibilidad */
.gw-proyecto-title {
   color: #111827 !important;
   font-weight: 600 !important;
}

.gw-meta-item {
   color: #374151 !important;
}

.gw-proyecto-descripcion {
   color: #4b5563 !important;
}

/* Estados de carga */
.gw-cargando {
   opacity: 0.6;
   pointer-events: none;
   position: relative;
}

.gw-cargando::after {
   content: 'Cargando...';
   position: absolute;
   top: 50%;
   left: 50%;
   transform: translate(-50%, -50%);
   background: rgba(255, 255, 255, 0.9);
   padding: 8px 16px;
   border-radius: 4px;
   font-size: 12px;
   color: #666;
}

/* Optimizaciones de rendimiento */
.gw-proyecto-item,
.gw-proyecto-acciones button {
   will-change: transform;
}

/* Scroll suave para modales largos */
body div[style*="position:fixed"] {
   scroll-behavior: smooth;
}
</style>


<!-- TAB CAPACITACIONES (COMPLETO CON NONCE Y CSS RESPONSIVE) -->
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
      .gw-wizard-sesion{border:1px solid #bfd9f7;border-radius:8px;padding:16px;margin-bottom:12px;display:grid;grid-template-columns:120px 140px 100px 1fr auto auto;gap:12px;align-items:center;background:#f8f9fa}
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

      /* ========================================
         CSS RESPONSIVE COMPLETO PARA CAPACITACIONES
         ======================================== */

      /* ========================================
         MÓVIL RESPONSIVE - hasta 768px
         ======================================== */
      @media (max-width: 768px) {
          /* Header principal */
          #gw-admin-tab-capacitaciones .gw-form-header h1 {
              font-size: 20px !important;
              margin-bottom: 8px !important;
          }
          
          #gw-admin-tab-capacitaciones .gw-form-header p {
              font-size: 14px !important;
              margin-bottom: 16px !important;
              color: #64748b !important;
          }

          /* Wizard Steps móvil */
          .gw-wizard-steps {
              flex-direction: column !important;
              gap: 8px !important;
              margin-bottom: 20px !important;
              margin-top: 8px !important;
          }
          
          .gw-wizard-step {
              margin: 0 !important;
              padding: 10px 8px !important;
              font-size: 12px !important;
              border-radius: 6px !important;
              flex: none !important;
              width: 100% !important;
          }

          /* Wizard Form móvil */
          .gw-wizard-form {
              padding: 16px 20px !important;
              margin: 0 8px 20px 8px !important;
              max-width: none !important;
              border-radius: 12px !important;
          }
          
          .gw-wizard-form label {
              font-size: 13px !important;
              margin-top: 12px !important;
              margin-bottom: 4px !important;
          }
          
          .gw-wizard-form input,
          .gw-wizard-form select {
              padding: 12px !important;
              font-size: 14px !important;
              margin-top: 2px !important;
              border-radius: 8px !important;
              width: 100% !important;
              box-sizing: border-box !important;
          }

          /* Botones de navegación del wizard móvil */
          .gw-wizard-form .next-step,
          .gw-wizard-form .prev-step,
          .gw-wizard-form .button-primary {
              width: 100% !important;
              padding: 12px 16px !important;
              margin-top: 12px !important;
              margin-bottom: 8px !important;
              font-size: 14px !important;
              border-radius: 8px !important;
              float: none !important;
              display: block !important;
          }
          
          .gw-wizard-form .prev-step {
              background: #6c757d !important;
              color: white !important;
              border: none !important;
          }

          /* Contenedor de botones del wizard */
          .gw-wizard-step-content.step-2 > button,
          .gw-wizard-step-content.step-3 > button,
          .gw-wizard-step-content.step-4 > div > button {
              width: 100% !important;
              float: none !important;
              margin: 8px 0 !important;
          }

          /* Contenedor final de botones paso 4 */
          .gw-wizard-step-content.step-4 > div:last-child {
              display: flex !important;
              flex-direction: column !important;
              gap: 8px !important;
              margin-top: 16px !important;
          }

          /* Botón agregar sesión móvil */
          .gw-wizard-form .add-sesion {
              width: 100% !important;
              padding: 12px 16px !important;
              font-size: 14px !important;
              border-radius: 8px !important;
              margin-top: 16px !important;
          }

          /* Sesiones en móvil */
          .gw-wizard-sesiones {
              margin-top: 16px !important;
          }
          
          .gw-wizard-sesion {
              display: flex !important;
              flex-direction: column !important;
              gap: 12px !important;
              padding: 16px !important;
              margin-bottom: 16px !important;
              border-radius: 12px !important;
              background: #f8fafc !important;
              border: 1px solid #e2e8f0 !important;
              grid-template-columns: none !important;
          }
          
          .gw-wizard-sesion input,
          .gw-wizard-sesion select {
              width: 100% !important;
              padding: 10px !important;
              font-size: 14px !important;
              border-radius: 6px !important;
              margin: 0 !important;
              grid-column: auto !important;
          }
          
          /* Botones de sesión móvil */
          .gw-wizard-sesion .remove-sesion,
          .gw-wizard-sesion .crear-meet {
              width: calc(50% - 4px) !important;
              padding: 10px 8px !important;
              font-size: 12px !important;
              border-radius: 6px !important;
              margin: 0 !important;
              display: inline-block !important;
          }
          
          .gw-wizard-sesion .remove-sesion {
              margin-right: 8px !important;
          }

          /* Lista de capacitaciones móvil */
          .gw-capacitacion-list {
              max-width: none !important;
              margin: 0 8px !important;
          }
          
          .gw-capacitacion-list h3 {
              font-size: 18px !important;
              margin-top: 24px !important;
              margin-bottom: 16px !important;
              text-align: center !important;
          }

          /* Filtros móvil */
          .gw-filtros-container {
              padding: 12px !important;
              margin: 0 0 16px 0 !important;
              border-radius: 12px !important;
          }
          
          .gw-filtros-container > div:first-child {
              display: flex !important;
              flex-direction: column !important;
              gap: 8px !important;
              margin-bottom: 12px !important;
              grid-template-columns: none !important;
          }
          
          .gw-filtros-container select,
          .gw-filtros-container input {
              width: 100% !important;
              padding: 10px !important;
              font-size: 14px !important;
              border-radius: 6px !important;
              border: 1px solid #ddd !important;
          }
          
          .gw-filtros-container > div:last-child {
              display: flex !important;
              flex-direction: column !important;
              gap: 8px !important;
          }
          
          .gw-filtros-container button {
              width: 100% !important;
              padding: 10px 16px !important;
              font-size: 14px !important;
              border-radius: 6px !important;
              margin: 0 !important;
          }

          /* Cards de capacitaciones móvil */
          .gw-capacitacion-card {
              padding: 16px !important;
              margin-bottom: 12px !important;
              border-radius: 12px !important;
          }
          
          .gw-capacitacion-card:hover {
              transform: none !important;
          }
          
          .gw-capacitacion-header {
              flex-direction: column !important;
              gap: 8px !important;
              margin-bottom: 12px !important;
              align-items: stretch !important;
          }
          
          .gw-capacitacion-title {
              font-size: 16px !important;
              margin-right: 0 !important;
              text-align: center !important;
              margin-bottom: 8px !important;
          }
          
          .gw-capacitacion-status {
              text-align: center !important;
          }
          
          .gw-status-badge {
              font-size: 11px !important;
              padding: 4px 8px !important;
          }

          /* Meta información móvil */
          .gw-capacitacion-meta {
              grid-template-columns: 1fr !important;
              gap: 8px !important;
              margin-bottom: 16px !important;
          }
          
          .gw-meta-item {
              background: #f8fafc !important;
              padding: 8px 10px !important;
              border-radius: 6px !important;
              font-size: 13px !important;
              border: 1px solid #e2e8f0 !important;
          }
          
          .gw-meta-icon {
              width: 14px !important;
              height: 14px !important;
          }

          /* Acciones móvil */
          .gw-capacitacion-actions {
              flex-direction: column !important;
              gap: 8px !important;
              padding-top: 12px !important;
          }
          
          .gw-btn-action {
              width: 100% !important;
              padding: 10px 12px !important;
              font-size: 13px !important;
              justify-content: center !important;
              border-radius: 6px !important;
          }

          /* Estado sin capacitaciones móvil */
          .gw-no-capacitaciones {
              padding: 40px 16px !important;
              margin: 0 !important;
              border-radius: 12px !important;
          }
          
          .gw-no-cap-icon {
              width: 60px !important;
              height: 60px !important;
              margin-bottom: 16px !important;
          }
          
          .gw-no-capacitaciones h3 {
              font-size: 18px !important;
              margin-bottom: 8px !important;
          }
          
          .gw-no-capacitaciones p {
              font-size: 14px !important;
          }

          /* Paginación móvil */
          .gw-pagination-info {
              flex-direction: column !important;
              gap: 4px !important;
              text-align: center !important;
              padding: 8px 12px !important;
              font-size: 12px !important;
              margin-bottom: 16px !important;
          }
          
          /* Botones de paginación móvil */
          #gw-admin-tab-capacitaciones form[method="post"] {
              display: block !important;
              width: 100% !important;
              margin: 4px 0 !important;
          }
          
          #gw-admin-tab-capacitaciones form[method="post"] button {
              width: 100% !important;
              padding: 12px 16px !important;
              font-size: 14px !important;
              border-radius: 8px !important;
              margin: 0 !important;
          }
          
          /* Container de paginación móvil */
          #gw-admin-tab-capacitaciones > div[style*="margin-top: 32px"] {
              margin-top: 20px !important;
              flex-direction: column !important;
              gap: 8px !important;
              align-items: stretch !important;
          }
          
          #gw-admin-tab-capacitaciones > div[style*="margin-top: 32px"] span {
              text-align: center !important;
              padding: 8px 12px !important;
              font-size: 13px !important;
              order: -1 !important;
          }
      }

      /* ========================================
         TABLET RESPONSIVE - 769px a 1024px
         ======================================== */
      @media (min-width: 769px) and (max-width: 1024px) {
          /* Wizard form tablet */
          .gw-wizard-form {
              max-width: 90% !important;
              padding: 20px 24px !important;
          }
          
          /* Wizard steps tablet */
          .gw-wizard-steps {
              margin-bottom: 24px !important;
          }
          
          .gw-wizard-step {
              padding: 10px 8px !important;
              font-size: 13px !important;
          }
          
          /* Sesiones tablet */
          .gw-wizard-sesion {
              grid-template-columns: 100px 120px 80px 1fr auto auto !important;
              gap: 10px !important;
          }
          
          .gw-wizard-sesion input[name="sesion_link[]"],
          .gw-wizard-sesion input[name="sesion_lugar[]"] {
              grid-column: span 2 !important;
          }
          
          /* Capacitaciones list tablet */
          .gw-capacitacion-list {
              max-width: 95% !important;
          }
          
          /* Filtros tablet */
          .gw-filtros-container > div:first-child {
              grid-template-columns: repeat(2, 1fr) !important;
              gap: 10px !important;
          }
          
          /* Cards tablet */
          .gw-capacitacion-meta {
              grid-template-columns: 1fr 1fr !important;
          }
          
          .gw-capacitacion-actions {
              flex-wrap: wrap !important;
              gap: 6px !important;
          }
          
          .gw-btn-action {
              flex: 1 !important;
              min-width: 120px !important;
          }
      }

      /* ========================================
         MEJORAS GENERALES Y ACCESIBILIDAD
         ======================================== */

      /* Focus states mejorados */
      #gw-admin-tab-capacitaciones input:focus,
      #gw-admin-tab-capacitaciones select:focus,
      #gw-admin-tab-capacitaciones button:focus {
          outline: 2px solid #31568d !important;
          outline-offset: 2px !important;
          border-color: #31568d !important;
      }

      /* Hover states para dispositivos que lo soportan */
      @media (hover: hover) {
          .gw-wizard-step:hover,
          .gw-capacitacion-card:hover {
              transform: translateY(-2px);
          }
          
          .gw-btn-action:hover {
              transform: translateY(-1px);
          }
      }

      /* Animaciones reducidas para usuarios que prefieren menos movimiento */
      @media (prefers-reduced-motion: reduce) {
          .gw-wizard-step,
          .gw-capacitacion-card,
          .gw-btn-action {
              transition: none !important;
              transform: none !important;
          }
          
          .gw-wizard-step:hover,
          .gw-capacitacion-card:hover,
          .gw-btn-action:hover {
              transform: none !important;
          }
      }

      /* Estados de carga */
      .gw-loading {
          opacity: 0.6;
          pointer-events: none;
          position: relative;
      }

      .gw-loading::after {
          content: 'Cargando...';
          position: absolute;
          top: 50%;
          left: 50%;
          transform: translate(-50%, -50%);
          background: rgba(255, 255, 255, 0.95);
          padding: 8px 16px;
          border-radius: 6px;
          font-size: 12px;
          color: #666;
          box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
      }

      /* Optimizaciones de rendimiento */
      .gw-wizard-step,
      .gw-capacitacion-card,
      .gw-btn-action {
          will-change: transform;
      }

      /* Scroll suave */
      html {
          scroll-behavior: smooth;
      }

      /* Correcciones específicas móvil pequeño */
      @media (max-width: 320px) {
          .gw-wizard-form {
              margin: 0 4px 16px 4px !important;
              padding: 12px 16px !important;
          }
          
          .gw-capacitacion-list {
              margin: 0 4px !important;
          }
      }

      /* Mejorar la experiencia en landscape móvil */
      @media (max-width: 768px) and (orientation: landscape) {
          .gw-wizard-steps {
              flex-direction: row !important;
              gap: 4px !important;
          }
          
          .gw-wizard-step {
              font-size: 11px !important;
              padding: 8px 4px !important;
          }
      }

      /* Print styles (opcional) */
      @media print {
          .gw-wizard-steps,
          .gw-filtros-container,
          .gw-capacitacion-actions {
              display: none !important;
          }
          
          .gw-capacitacion-card {
              break-inside: avoid !important;
              margin-bottom: 20px !important;
          }
      }
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
        <button type="button" class="next-step" style="margin-top:16px;">Siguiente →</button>
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
    // Configuración de paginación
    $items_per_page = 5;
    // CAMBIO AQUÍ: Aceptar tanto POST como GET
    $current_page = isset($_POST['cap_page']) ? max(1, intval($_POST['cap_page'])) : (isset($_GET['cap_page']) ? max(1, intval($_GET['cap_page'])) : 1);
    $offset = ($current_page - 1) * $items_per_page;
    
    // Obtener todas las capacitaciones
    $all_caps = get_posts([
      'post_type' => 'capacitacion',
      'numberposts' => -1,
      'orderby' => 'title',
      'order' => 'ASC'
    ]);
    
    $total_items = count($all_caps);
    $total_pages = ceil($total_items / $items_per_page);
    
    // Obtener solo las capacitaciones de la página actual
    $caps = array_slice($all_caps, $offset, $items_per_page);
    
    if(empty($all_caps)){
      echo '<div class="gw-no-capacitaciones">';
      echo '<div class="gw-no-cap-icon">';
      echo '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">';
      echo '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>';
      echo '<polyline points="14,2 14,8 20,8"></polyline>';
      echo '<line x1="16" y1="13" x2="8" y2="13"></line>';
      echo '<line x1="16" y1="17" x2="8" y2="17"></line>';
      echo '<polyline points="10,9 9,9 8,9"></polyline>';
      echo '</svg>';
      echo '</div>';
      echo '<h3>No hay capacitaciones registradas</h3>';
      echo '<p>Comienza creando tu primera capacitación para organizar las sesiones de entrenamiento.</p>';
      echo '</div>';
    } else {
      // Mostrar información de paginación
      echo '<div class="gw-pagination-info">';
      echo '<span>Mostrando ' . (($current_page - 1) * $items_per_page + 1) . '-' . min($current_page * $items_per_page, $total_items) . ' de ' . $total_items . ' capacitaciones</span>';
      echo '</div>';
      
      // Mostrar capacitaciones
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
        echo '<div class="gw-capacitacion-header">';
        echo '<div class="gw-capacitacion-title">'.esc_html($cap->post_title).'</div>';
        echo '<div class="gw-capacitacion-status">';
        echo '<span class="gw-status-badge gw-status-' . strtolower($modalidad) . '">' . $modalidad . '</span>';
        echo '</div>';
        echo '</div>';
        
        echo '<div class="gw-capacitacion-meta">';
        echo '<div class="gw-meta-item">';
        echo '<svg class="gw-meta-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>';
        echo '<span><strong>País:</strong> '.$pais_title.'</span>';
        echo '</div>';
        echo '<div class="gw-meta-item">';
        echo '<svg class="gw-meta-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><folder x="3" y="3" width="18" height="18" rx="2" ry="2"></folder><path d="M3 7h18"></path></svg>';
        echo '<span><strong>Proyecto:</strong> '.$proy_title.'</span>';
        echo '</div>';
        echo '<div class="gw-meta-item">';
        echo '<svg class="gw-meta-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>';
        echo '<span><strong>Coach:</strong> '.$coach_name.'</span>';
        echo '</div>';
        echo '<div class="gw-meta-item">';
        echo '<svg class="gw-meta-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><calendar x="3" y="4" width="18" height="18" rx="2" ry="2"></calendar><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>';
        echo '<span><strong>Sesiones:</strong> '.$num_sesiones.'</span>';
        echo '</div>';
        echo '</div>';
        
        echo '<div class="gw-capacitacion-actions">';
        echo '<button class="gw-cap-edit gw-btn-action gw-btn-edit" data-id="'.$cap->ID.'">';
        echo '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>';
        echo 'Editar';
        echo '</button>';
        echo '<button class="gw-cap-delete gw-btn-action gw-btn-delete" data-id="'.$cap->ID.'">';
        echo '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3,6 5,6 21,6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>';
        echo 'Eliminar';
        echo '</button>';
        echo '</div>';
        echo '</div>';
      }
      
      // PAGINACIÓN SIMPLE CON FORMULARIOS POST
      if($total_pages > 1) {
        echo '<div style="margin-top: 32px; display: flex; justify-content: center; align-items: center; gap: 16px;">';
        
        // Botón anterior
        if($current_page > 1) {
          echo '<form method="post" style="display: inline-block; margin: 0;">';
          echo '<input type="hidden" name="cap_page" value="'.($current_page - 1).'">';
          echo '<button type="submit" style="display: inline-block; padding: 12px 20px; background: #a4b444; color: white; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s ease;" onmouseover="this.style.background=\'#8a9b3a\';" onmouseout="this.style.background=\'#a4b444\';">';
          echo '« Anterior';
          echo '</button>';
          echo '</form>';
        } else {
          echo '<span style="display: inline-block; padding: 12px 20px; background: #d1d5db; color: #9ca3af; border-radius: 8px; font-size: 14px; font-weight: 600;">« Anterior</span>';
        }
        
        // Información de página
        echo '<span style="font-size: 16px; font-weight: 600; color: #374151; padding: 0 16px;">';
        echo 'Página ' . $current_page . ' de ' . $total_pages;
        echo '</span>';
        
        // Botón siguiente
        if($current_page < $total_pages) {
          echo '<form method="post" style="display: inline-block; margin: 0;">';
          echo '<input type="hidden" name="cap_page" value="'.($current_page + 1).'">';
          echo '<button type="submit" style="display: inline-block; padding: 12px 20px; background: #a4b444; color: white; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s ease;" onmouseover="this.style.background=\'#8a9b3a\';" onmouseout="this.style.background=\'#a4b444\';">';
          echo 'Siguiente »';
          echo '</button>';
          echo '</form>';
        } else {
          echo '<span style="display: inline-block; padding: 12px 20px; background: #d1d5db; color: #9ca3af; border-radius: 8px; font-size: 14px; font-weight: 600;">Siguiente »</span>';
        }
        
        echo '</div>';
        
        // Script para mantener la posición en la página
        echo '<script>
        document.addEventListener("DOMContentLoaded", function() {
          // Si hay parámetro cap_page (POST o GET), hacer scroll al listado
          if(window.location.href.indexOf("cap_page=") > -1 || document.querySelector("input[name=\'cap_page\']")) {
            setTimeout(function() {
              var listado = document.getElementById("gw-capacitaciones-listado");
              if(listado) {
                listado.scrollIntoView({ behavior: "smooth", block: "start" });
              }
            }, 100);
          }
        });
        </script>';
      }
    }
  ?>
</div>

<style>
/* Información de paginación */
.gw-pagination-info {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
  padding: 12px 16px;
  background: #f8fafc;
  border-radius: 8px;
  font-size: 14px;
  color: #64748b;
  border: 1px solid #e2e8f0;
}

/* Estado sin capacitaciones */
.gw-no-capacitaciones {
  text-align: center;
  padding: 60px 20px;
  background: #f8fafc;
  border-radius: 16px;
  border: 2px dashed #cbd5e1;
}

.gw-no-cap-icon {
  width: 80px;
  height: 80px;
  background: #e2e8f0;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto 20px auto;
  color: #94a3b8;
}

.gw-no-capacitaciones h3 {
  margin: 0 0 12px 0;
  font-size: 20px;
  font-weight: 600;
  color: #374151;
}

.gw-no-capacitaciones p {
  margin: 0;
  font-size: 16px;
  color: #64748b;
  line-height: 1.5;
}

/* Cards de capacitaciones mejoradas */
.gw-capacitacion-card {
  background: white;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  padding: 20px;
  margin-bottom: 16px;
  transition: all 0.2s ease;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.gw-capacitacion-card:hover {
  border-color: #cbd5e1;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
  transform: translateY(-1px);
}

.gw-capacitacion-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 16px;
}

.gw-capacitacion-title {
  font-size: 18px;
  font-weight: 600;
  color: #111827;
  line-height: 1.3;
  flex: 1;
  margin-right: 12px;
}

.gw-capacitacion-status {
  flex-shrink: 0;
}

.gw-status-badge {
  display: inline-block;
  padding: 4px 12px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.gw-status-presencial {
  background: #dbeafe;
  color: #1e40af;
}

.gw-status-virtual {
  background: #dcfce7;
  color: #166534;
}

.gw-status-mixta {
  background: #fef3c7;
  color: #92400e;
}

.gw-status-n\/a {
  background: #f3f4f6;
  color: #6b7280;
}

.gw-capacitacion-meta {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
  margin-bottom: 20px;
}

.gw-meta-item {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
  color: #64748b;
}

.gw-meta-icon {
  color: #94a3b8;
  flex-shrink: 0;
}

.gw-capacitacion-actions {
  display: flex;
  gap: 8px;
  justify-content: flex-end;
  padding-top: 16px;
  border-top: 1px solid #f1f5f9;
}

.gw-btn-action {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 8px 16px;
  border-radius: 8px;
  font-size: 14px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.2s ease;
  border: none;
  text-decoration: none;
}

.gw-btn-edit {
  background: #eff6ff;
  color: #1d4ed8;
  border: 1px solid #dbeafe;
}

.gw-btn-edit:hover {
  background: #dbeafe;
  border-color: #93c5fd;
}

.gw-btn-delete {
  background: #fef2f2;
  color: #dc2626;
  border: 1px solid #fecaca;
}

.gw-btn-delete:hover {
  background: #fecaca;
  border-color: #fca5a5;
}

/* Paginación */
.gw-pagination-wrapper {
  margin-top: 32px;
  display: flex;
  justify-content: center;
}

.gw-pagination {
  display: flex;
  align-items: center;
  gap: 8px;
  background: white;
  padding: 16px 20px;
  border-radius: 12px;
  border: 1px solid #e2e8f0;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.gw-pagination-btn {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 8px 12px;
  background: #f8fafc;
  color: #64748b;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  text-decoration: none;
  font-size: 14px;
  font-weight: 500;
  transition: all 0.2s ease;
}

.gw-pagination-btn:hover {
  background: #e2e8f0;
  color: #374151;
  border-color: #cbd5e1;
}

.gw-pagination-numbers {
  display: flex;
  align-items: center;
  gap: 4px;
  margin: 0 12px;
}

.gw-pagination-number {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border-radius: 8px;
  font-size: 14px;
  font-weight: 500;
  text-decoration: none;
  color: #64748b;
  transition: all 0.2s ease;
}

.gw-pagination-number:hover {
  background: #f1f5f9;
  color: #374151;
}

.gw-pagination-current {
  background: #3b82f6;
  color: white;
}

.gw-pagination-current:hover {
  background: #2563eb;
  color: white;
}

.gw-pagination-dots {
  color: #94a3b8;
  padding: 0 4px;
  font-weight: 500;
}

/* Responsive */
@media (max-width: 768px) {
  .gw-pagination-info {
    flex-direction: column;
    gap: 8px;
    text-align: center;
  }
  
  .gw-capacitacion-meta {
    grid-template-columns: 1fr;
    gap: 8px;
  }
  
  .gw-capacitacion-header {
    flex-direction: column;
    gap: 12px;
  }
  
  .gw-capacitacion-actions {
    flex-direction: column;
  }
  
  .gw-pagination {
    flex-direction: column;
    gap: 12px;
  }
  
  .gw-pagination-numbers {
    margin: 0;
  }
}

@media (max-width: 480px) {
  .gw-pagination-numbers {
    flex-wrap: wrap;
    justify-content: center;
  }
  
  .gw-btn-action {
    justify-content: center;
  }
}
</style>
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
    
    <div class="gw-prog-filters" id="gw-prog-filters" style="margin:8px 0 16px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
      <input id="gw-prog-search" type="search" placeholder="Buscar por nombre o email..." style="padding:8px;border:1px solid #ddd;border-radius:6px;min-width:260px;" />
      
      <select id="gw-prog-pais" style="padding:8px;border:1px solid #ddd;border-radius:6px;min-width:200px;">
        <option value="">Todos los países</option>
        <?php
          $gw_prog_paises = get_posts(['post_type' => 'pais', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC']);
          foreach ($gw_prog_paises as $p) {
            echo '<option value="'.(int)$p->ID.'" data-text="'.esc_attr($p->post_title).'">'.esc_html($p->post_title).'</option>';
          }
        ?>
      </select>
      
      <button type="button" class="button" id="gw-prog-clear">Limpiar</button>
    </div>
  </div>

  <?php
  // Mostrar el shortcode dentro de un contenedor para permitir filtrado en vivo
  echo '<div id="gw-progress-list">' . do_shortcode('[gw_progreso_voluntario]') . '</div>';
  ?>

  <script>
  (function(){
    var search = document.getElementById('gw-prog-search');
    var sel    = document.getElementById('gw-prog-pais');
    var clearB = document.getElementById('gw-prog-clear');
    var list   = document.getElementById('gw-progress-list');
    if(!list) return;

    function norm(s){ return (s||'').toString().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,''); }

    function filter(){
      var q = norm(search ? search.value : '');
      var paisId   = sel ? sel.value : '';
      var paisText = '';
      if (sel && sel.selectedIndex >= 0) {
        paisText = norm(sel.options[sel.selectedIndex].getAttribute('data-text') || sel.options[sel.selectedIndex].text);
      }
      // Detectar filas de tabla de la vista de progreso
      var rows = list.querySelectorAll('tbody tr');
      if (!rows.length) {
        // Fallback: por si el shortcode usa otro layout (cards)
        rows = list.querySelectorAll('.gw-vol-row');
      }
      rows.forEach(function(tr){
        var t = norm(tr.textContent);
        var okSearch = !q || t.indexOf(q) !== -1;
        var okPais   = true;
        if (paisId) {
          // Preferir data-pais-id si existe; sino, buscar por texto del país
          var pid = tr.getAttribute('data-pais-id') || (tr.dataset ? tr.dataset.paisId : '');
          if (pid) {
            okPais = String(pid) === String(paisId);
          } else {
            okPais = t.indexOf(paisText) !== -1;
          }
        }
        tr.style.display = (okSearch && okPais) ? '' : 'none';
      });
    }

    function debounce(fn,ms){ var to; return function(){ clearTimeout(to); var a=arguments; to=setTimeout(function(){ fn.apply(null,a); }, ms||180); }; }
    var doFilter = debounce(filter, 120);

    if (search) search.addEventListener('input', doFilter);
    if (sel)    sel.addEventListener('change', filter);
    if (clearB) clearB.addEventListener('click', function(){ if (search) search.value=''; if (sel) sel.value=''; filter(); });

    // Reaplicar filtro si el listado se re-renderiza dinámicamente
    var mo = new MutationObserver(function(){ filter(); });
    mo.observe(list, {childList:true, subtree:true});
  })();
  </script>
</div>

                <!-- TAB AUSENCIAS DETECTADAS (BOTÓN 8) -->
<div class="gw-admin-tab-content" id="gw-admin-tab-ausencias-detectadas" style="display:none;">                <div class="gw-form-header">
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
                    <div class="gw-abs-list" style="flex:1 1 520px;min-width:420px;">                    
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

                <style>
                  /* ===== AUSENCIAS: layout desktop ===== */
/* ===== AUSENCIAS (solo desktop): evitar corte a la derecha ===== */
@media (min-width: 1100px){
  /* wrapper flex de la sección */
  #gw-admin-tab-ausencias > div[style*="display:flex"],
  #gw-admin-tab-ausencias-detectadas > div[style*="display:flex"]{
    align-items: flex-start;
    gap: 24px;
  }
}

  /* panel de ajustes a la izquierda (ancho fijo razonable) */
  #gw-admin-tab-ausencias #gw-abs-settings
#gw-admin-tab-ausencias > div[style*="display:flex"] > div[style*="min-width:420px"]
#gw-admin-tab-ausencias .widefat ...
#gw-admin-tab-ausencias .button.button-small.gw-abs-...{
    flex: 0 0 520px;
    max-width: 560px;
  }

  /* contenedor de la lista (derecha): que pueda encoger y tenga scroll-x */
  #gw-admin-tab-ausencias > div[style*="display:flex"],
#gw-admin-tab-ausencias-detectadas > div[style*="display:flex"] > div[style*="min-width:420px"]{
    flex: 1 1 auto;
    min-width: 0 !important;          /* clave para que no se corte */
    overflow-x: auto;                  /* scroll solo si no cabe */
    -webkit-overflow-scrolling: touch;
  }

  /* la tabla puede necesitar ancho mínimo; así no rompe columnas */
  #gw-admin-tab-ausencias > div[style*="display:flex"],
#gw-admin-tab-ausencias-detectadas > div[style*="display:flex"] > div[style*="min-width:420px"] .widefat{
    width: 100%;
    min-width: 980px;                  /* ajusta si lo ves necesario */
    table-layout: auto;
  }

  /* reservar espacio para Acciones y evitar saltos de botones */
  #gw-admin-tab-ausencias .widefat th:last-child,
  #gw-admin-tab-ausencias .widefat td:last-child{
    width: 280px;                      /* sube/baja según botones */
    white-space: nowrap;
    padding-right: 16px;
  }

  /* permitir salto de línea en "Capacitación" si es largo */
  #gw-admin-tab-ausencias .widefat td:nth-child(2){
    white-space: normal;
  }

  #gw-admin-tab-ausencias .widefat td{ vertical-align: middle; }



  /* AUSENCIAS: colores de acciones */
#gw-admin-tab-ausencias .button.button-small.gw-abs-resolver{
  background: #1e88e5 !important;   /* azul */
  border-color: #1e88e5 !important;
  color: #fff !important;
}
#gw-admin-tab-ausencias .button.button-small.gw-abs-resolver:hover{
  background: #1976d2 !important;
  border-color: #1976d2 !important;
}
#gw-admin-tab-ausencias .button.button-small.gw-abs-resolver:active{
  background: #1565c0 !important;
  border-color: #1565c0 !important;
}
#gw-admin-tab-ausencias .button.button-small.gw-abs-resolver:focus{
  outline: none;
  box-shadow: 0 0 0 3px rgba(30,136,229,.35) !important;
}

/* Ocultar: rojo (peligro) */
#gw-admin-tab-ausencias .button.button-small.gw-abs-ocultar{
  background: #dc3545 !important;
  border-color: #dc3545 !important;
  color: #fff !important;
}
#gw-admin-tab-ausencias .button.button-small.gw-abs-ocultar:hover{
  background: #c82333 !important;
  border-color: #bd2130 !important;
}
#gw-admin-tab-ausencias .button.button-small.gw-abs-ocultar:active{
  background: #a71e2a !important;
  border-color: #a71e2a !important;
}
#gw-admin-tab-ausencias .button.button-small.gw-abs-ocultar:focus{
  outline: none;
  box-shadow: 0 0 0 3px rgba(220,53,69,.35) !important;
}

/* Reactivar usuario: lo dejo con tu verde actual */


                </style>
                </div>



                <!-- TAB 7: SEGUIMIENTO DE AUSENCIAS (AJUSTES) -->
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
  </div>
</div>

<!-- TAB 8: AUSENCIAS DETECTADAS (LISTADO) CON SEGMENTACIÓN -->
<div class="gw-admin-tab-content" id="gw-admin-tab-ausencias_detectadas" style="display:none;">
  <div class="gw-form-header">
    <h1>Ausencias detectadas</h1>
    <p>Control de ausencias de voluntarios.</p>
  </div>

  <div class="gw-ausencias-container">
    <div class="gw-abs-list">
      <h3 style="margin-top:0;">Ausencias detectadas</h3>
      
      <?php
      global $wpdb; 
      $current_user = wp_get_current_user();
      $user_role = '';
      $user_country = '';
      $user_projects = array();
      
      // Determinar el rol del usuario actual
      if (user_can($current_user, 'administrator')) {
        $user_role = 'administrator';
      } elseif (in_array('coordinador_pais', $current_user->roles)) {
        $user_role = 'coordinador_pais';
        $user_country = get_user_meta($current_user->ID, 'pais', true);
      } elseif (in_array('coach', $current_user->roles)) {
        $user_role = 'coach';
        // Obtener los proyectos asignados al coach
        $user_projects = get_user_meta($current_user->ID, 'proyectos_asignados', true);
        if (!is_array($user_projects)) {
          $user_projects = array();
        }
      }
      
      // Construir la consulta SQL según el rol
      $table = $wpdb->prefix.'gw_ausencias';
      $where_conditions = array("hidden=0");
      $join_clauses = "";
      
      if ($user_role == 'coordinador_pais' && !empty($user_country)) {
        // Coordinador de país: solo ausencias de usuarios de su país
        $join_clauses .= " LEFT JOIN {$wpdb->usermeta} um_pais ON a.user_id = um_pais.user_id AND um_pais.meta_key = 'pais'";
        $where_conditions[] = $wpdb->prepare("um_pais.meta_value = %s", $user_country);
        
      } elseif ($user_role == 'coach' && !empty($user_projects)) {
        // Coach: solo ausencias de capacitaciones de sus proyectos asignados
        $project_placeholders = implode(',', array_fill(0, count($user_projects), '%d'));
        $join_clauses .= " LEFT JOIN {$wpdb->postmeta} pm_proyecto ON a.cap_id = pm_proyecto.post_id AND pm_proyecto.meta_key = 'proyecto_id'";
        $where_conditions[] = $wpdb->prepare("pm_proyecto.meta_value IN ($project_placeholders)", $user_projects);
        
      } elseif ($user_role != 'administrator') {
        // Si no tiene ningún rol válido, no mostrar nada
        $where_conditions[] = "1=0";
      }
      
      $where_clause = implode(' AND ', $where_conditions);
      $query = "SELECT a.* FROM {$table} a {$join_clauses} WHERE {$where_clause} ORDER BY a.updated_at DESC LIMIT 300";
      
      $rows = $wpdb->get_results($query, ARRAY_A);
      
      if (!$rows) {
        echo '<div class="gw-no-ausencias">';
        echo '<div class="gw-no-ausencias-icon">';
        echo '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">';
        echo '<path d="M9 12l2 2 4-4"></path>';
        echo '<circle cx="12" cy="12" r="10"></circle>';
        echo '</svg>';
        echo '</div>';
        echo '<h3>No hay ausencias registradas</h3>';
        if ($user_role == 'coordinador_pais') {
          echo '<p>No se han detectado ausencias en tu país ('.esc_html($user_country).') o todas han sido resueltas.</p>';
        } elseif ($user_role == 'coach') {
          echo '<p>No se han detectado ausencias en tus proyectos asignados o todas han sido resueltas.</p>';
        } else {
          echo '<p>Todas las ausencias han sido resueltas o no se han detectado ausencias recientes.</p>';
        }
        echo '</div>';
      } else {
        // Mostrar información del filtro aplicado
        echo '<div class="gw-filter-info">';
        if ($user_role == 'coordinador_pais') {
          echo '<div class="gw-filter-badge gw-filter-coordinador">';
          echo '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">';
          echo '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>';
          echo '<circle cx="12" cy="10" r="3"></circle>';
          echo '</svg>';
          echo 'Mostrando ausencias de: <strong>'.esc_html($user_country).'</strong>';
          echo '</div>';
        } elseif ($user_role == 'coach') {
          $project_names = array();
          foreach ($user_projects as $project_id) {
            $project_name = get_the_title($project_id);
            if ($project_name) {
              $project_names[] = $project_name;
            }
          }
          echo '<div class="gw-filter-badge gw-filter-coach">';
          echo '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">';
          echo '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>';
          echo '<circle cx="9" cy="7" r="4"></circle>';
          echo '<path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>';
          echo '<path d="M16 3.13a4 4 0 0 1 0 7.75"></path>';
          echo '</svg>';
          echo 'Mostrando ausencias de tus proyectos: <strong>'.implode(', ', array_slice($project_names, 0, 3));
          if (count($project_names) > 3) {
            echo ' y '.(count($project_names) - 3).' más';
          }
          echo '</strong>';
          echo '</div>';
        } elseif ($user_role == 'administrator') {
          echo '<div class="gw-filter-badge gw-filter-admin">';
          echo '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">';
          echo '<path d="M12 1l3 6h6l-5 4 2 7-6-4-6 4 2-7-5-4h6z"></path>';
          echo '</svg>';
          echo 'Vista de <strong>Administrador</strong> - Todas las ausencias';
          echo '</div>';
        }
        echo '</div>';
        
        echo '<div class="gw-table-responsive">';
        echo '<table class="gw-ausencias-table">';
        echo '<thead>';
        echo '<tr>';
        echo '<th class="gw-col-usuario">Usuario</th>';
        if ($user_role == 'administrator' || $user_role == 'coordinador_pais') {
          echo '<th class="gw-col-pais">País</th>';
        }
        echo '<th class="gw-col-capacitacion">Capacitación</th>';
        if ($user_role == 'administrator' || $user_role == 'coach') {
          echo '<th class="gw-col-proyecto">Proyecto</th>';
        }
        echo '<th class="gw-col-fecha">Fecha/Hora</th>';
        echo '<th class="gw-col-estado">Estado</th>';
        echo '<th class="gw-col-recordatorios">Recordatorios</th>';
        echo '<th class="gw-col-acciones">Acciones</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        foreach ($rows as $r) {
          $u = get_user_by('id', intval($r['user_id']));
          $cap_title = get_the_title(intval($r['cap_id'])) ?: ('ID '.$r['cap_id']);
          
          // Obtener información adicional
          $user_country_display = get_user_meta($r['user_id'], 'pais', true) ?: 'No asignado';
          $proyecto_id = get_post_meta($r['cap_id'], 'proyecto_id', true);
          $proyecto_name = $proyecto_id ? get_the_title($proyecto_id) : 'No asignado';
          
          echo '<tr data-aid="'.intval($r['id']).'" class="gw-ausencia-row">';
          echo '<td class="gw-col-usuario">';
          echo '<div class="gw-usuario-info">';
          echo '<strong>'. esc_html($u ? ($u->display_name ?: $u->user_email) : ('#'.$r['user_id'])) .'</strong>';
          if ($u && $u->user_email) {
            echo '<br><small class="gw-usuario-email">'. esc_html($u->user_email) .'</small>';
          }
          echo '</div>';
          echo '</td>';
          
          // Mostrar columna país si corresponde
          if ($user_role == 'administrator' || $user_role == 'coordinador_pais') {
            echo '<td class="gw-col-pais">';
            echo '<span class="gw-pais-badge">'. esc_html($user_country_display) .'</span>';
            echo '</td>';
          }
          
          echo '<td class="gw-col-capacitacion">'. esc_html($cap_title) .'</td>';
          
          // Mostrar columna proyecto si corresponde
          if ($user_role == 'administrator' || $user_role == 'coach') {
            echo '<td class="gw-col-proyecto">';
            echo '<span class="gw-proyecto-badge">'. esc_html($proyecto_name) .'</span>';
            echo '</td>';
          }
          
          echo '<td class="gw-col-fecha">'. esc_html($r['fecha']) .'</td>';
          
          echo '<td class="gw-col-estado">';
          $estado_class = 'gw-estado-' . strtolower(str_replace(' ', '-', $r['status']));
          echo '<span class="gw-estado-badge '.$estado_class.'">'. esc_html($r['status']) .'</span>';
          echo '</td>';
          
          echo '<td class="gw-col-recordatorios">';
          echo '<span class="gw-recordatorios-count">'. intval($r['reminders_sent']) .'</span>';
          echo '</td>';
          
          echo '<td class="gw-col-acciones">';
          echo '<div class="gw-acciones-group">';
          echo '<button type="button" class="gw-btn gw-btn-resolver" data-id="'.intval($r['id']).'">';
          echo '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">';
          echo '<polyline points="20,6 9,17 4,12"></polyline>';
          echo '</svg>';
          echo 'Resolver';
          echo '</button>';
          
          echo '<button type="button" class="gw-btn gw-btn-reactivar" data-uid="'.intval($r['user_id']).'">';
          echo '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">';
          echo '<path d="M1 4v6h6"></path>';
          echo '<path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path>';
          echo '</svg>';
          echo 'Reactivar';
          echo '</button>';
          
          echo '<button type="button" class="gw-btn gw-btn-ocultar" data-id="'.intval($r['id']).'">';
          echo '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">';
          echo '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>';
          echo '<line x1="1" y1="1" x2="23" y2="23"></line>';
          echo '</svg>';
          echo 'Ocultar';
          echo '</button>';
          echo '</div>';
          echo '</td>';
          echo '</tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
        echo '</div>';
      }
      ?>
    </div>
  </div>
</div>

<script>
(function(){
  var ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
  var nonce   = '<?php echo esc_js($nonce_abs ?? wp_create_nonce('gw_ausencias')); ?>';

  // Acciones en filas
  document.addEventListener('click', function(ev){
    var el;
    
    // Resolver ausencia
    if (el = ev.target.closest('.gw-btn-resolver')) {
      var id = el.getAttribute('data-id');
      var row = el.closest('tr');
      
      el.disabled = true;
      el.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="gw-spin"><circle cx="12" cy="12" r="3"></circle></svg> Resolviendo...';
      
      var fd = new FormData(); 
      fd.append('action','gw_abs_mark_resuelto'); 
      fd.append('nonce',nonce); 
      fd.append('id',id);
      
      fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:fd})
        .then(r=>r.json())
        .then(function(res){ 
          if(res && res.success){ 
            row.style.opacity = '0.5';
            setTimeout(() => row.remove(), 300);
          } else {
            el.disabled = false;
            el.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20,6 9,17 4,12"></polyline></svg> Resolver';
            alert('Error al resolver la ausencia');
          }
        })
        .catch(() => {
          el.disabled = false;
          el.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20,6 9,17 4,12"></polyline></svg> Resolver';
          alert('Error de conexión');
        });
    }
    
    // Reactivar usuario
    if (el = ev.target.closest('.gw-btn-reactivar')) {
      var uid = el.getAttribute('data-uid');
      
      el.disabled = true;
      el.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="gw-spin"><circle cx="12" cy="12" r="3"></circle></svg> Reactivando...';
      
      var fd = new FormData(); 
      fd.append('action','gw_abs_reactivar_usuario'); 
      fd.append('nonce',nonce); 
      fd.append('user_id',uid);
      
      fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:fd})
        .then(r=>r.json())
        .then(function(res){
          if(res && res.success) {
            el.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20,6 9,17 4,12"></polyline></svg> Reactivado';
            el.classList.remove('gw-btn-reactivar');
            el.classList.add('gw-btn-success');
            setTimeout(() => {
              el.disabled = false;
              el.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 4v6h6"></path><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path></svg> Reactivar';
              el.classList.remove('gw-btn-success');
              el.classList.add('gw-btn-reactivar');
            }, 2000);
          } else {
            el.disabled = false;
            el.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 4v6h6"></path><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path></svg> Reactivar';
            alert('Error al reactivar usuario');
          }
        });
    }
    
    // Ocultar ausencia
    if (el = ev.target.closest('.gw-btn-ocultar')) {
      var id = el.getAttribute('data-id');
      var row = el.closest('tr');
      
      if (!confirm('¿Estás seguro de que quieres ocultar esta ausencia?')) {
        return;
      }
      
      el.disabled = true;
      el.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="gw-spin"><circle cx="12" cy="12" r="3"></circle></svg> Ocultando...';
      
      var fd = new FormData(); 
      fd.append('action','gw_abs_ocultar'); 
      fd.append('nonce',nonce); 
      fd.append('id',id);
      
      fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:fd})
        .then(r=>r.json())
        .then(function(res){ 
          if(res && res.success){ 
            row.style.opacity = '0.5';
            setTimeout(() => row.remove(), 300);
          } else {
            el.disabled = false;
            el.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg> Ocultar';
            alert('Error al ocultar la ausencia');
          }
        });
    }
  });
})();

// Fix definitivo - forzar display del contenido
(function(){
  let ausenciasContent = null;
  let isContentLoaded = false;
  
  // Función para forzar que se muestre el contenido
  function forzarMostrarContenido() {
    const tab = document.getElementById('gw-admin-tab-ausencias_detectadas');
    if (!tab) return;
    
    // Forzar que el tab principal se muestre
    if (tab.style.display === 'none') {
      tab.style.display = 'block';
    }
    
    // Forzar que el contenido interno se muestre
    const absList = tab.querySelector('.gw-abs-list');
    if (absList && absList.style.display === 'none') {
      absList.style.display = 'block';
      console.log('Forzando display de gw-abs-list a block');
    }
    
    // Forzar que el container se muestre
    const container = tab.querySelector('.gw-ausencias-container');
    if (container && container.style.display === 'none') {
      container.style.display = 'block';
    }
    
    // Verificar todos los elementos padre que puedan estar ocultos
    let parent = tab.parentElement;
    while (parent) {
      if (parent.style.display === 'none') {
        parent.style.display = 'block';
      }
      parent = parent.parentElement;
      if (parent === document.body) break;
    }
  }
  
  // Guardar contenido inicial
  function guardarContenidoInicial() {
    forzarMostrarContenido(); // Primero forzar que se muestre
    
    const container = document.querySelector('#gw-admin-tab-ausencias_detectadas .gw-ausencias-container');
    if (container && container.innerHTML.trim() && container.innerHTML.length > 100) {
      ausenciasContent = container.innerHTML;
      isContentLoaded = true;
      console.log('Contenido de ausencias guardado exitosamente');
    }
  }
  
  // Verificar y mostrar contenido
  function verificarYMostrar() {
    const tab = document.getElementById('gw-admin-tab-ausencias_detectadas');
    if (!tab) return;
    
    // Si el tab está "activo" pero no visible, forzar
    if (tab.classList.contains('active') || 
        window.location.hash.includes('ausencias') ||
        tab.style.display !== 'none') {
      
      forzarMostrarContenido();
      
      const container = tab.querySelector('.gw-ausencias-container');
      if (container) {
        const currentContent = container.innerHTML.trim();
        
        // Si no hay contenido, restaurar
        if (!currentContent || currentContent.length < 100) {
          if (ausenciasContent && isContentLoaded) {
            container.innerHTML = ausenciasContent;
            console.log('Contenido restaurado desde memoria');
          }
        }
      }
    }
  }
  
  // Interceptar TODOS los clicks para detectar cambios de pestaña
  document.addEventListener('click', function(e) {
    const target = e.target;
    
    // Guardar contenido antes de cualquier acción
    if (!isContentLoaded) {
      setTimeout(guardarContenidoInicial, 100);
    }
    
    // Si hace click en algo relacionado con ausencias
    if (target.textContent && 
        (target.textContent.toLowerCase().includes('ausencias') ||
         target.textContent.toLowerCase().includes('detectadas'))) {
      
      setTimeout(forzarMostrarContenido, 100);
      setTimeout(verificarYMostrar, 300);
      setTimeout(verificarYMostrar, 600);
      setTimeout(verificarYMostrar, 1000);
    }
    
    // También verificar si hace click en cualquier elemento con data-tab
    if (target.hasAttribute('data-tab') || target.closest('[data-tab]')) {
      const tabValue = target.getAttribute('data-tab') || target.closest('[data-tab]').getAttribute('data-tab');
      if (tabValue && tabValue.includes('ausencias')) {
        setTimeout(forzarMostrarContenido, 100);
        setTimeout(verificarYMostrar, 300);
      }
    }
  });
  
  // Verificación agresiva cada 2 segundos
  setInterval(function() {
    const tab = document.getElementById('gw-admin-tab-ausencias_detectadas');
    if (tab && (tab.classList.contains('active') || tab.style.display !== 'none')) {
      verificarYMostrar();
    }
  }, 2000);
  
  // Cargar contenido inicial
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => setTimeout(guardarContenidoInicial, 1000));
  } else {
    setTimeout(guardarContenidoInicial, 1000);
  }
  
  // Verificar cuando la ventana recibe focus
  window.addEventListener('focus', () => setTimeout(verificarYMostrar, 500));
  
  // API de debugging
  window.gwAusenciasDebug = {
    forzarMostrar: forzarMostrarContenido,
    verificar: verificarYMostrar,
    guardar: guardarContenidoInicial,
    contenido: () => ausenciasContent,
    cargado: () => isContentLoaded,
    estado: function() {
      const tab = document.getElementById('gw-admin-tab-ausencias_detectadas');
      const absList = tab ? tab.querySelector('.gw-abs-list') : null;
      return {
        tabExists: !!tab,
        tabDisplay: tab ? tab.style.display : 'N/A',
        absListExists: !!absList,
        absListDisplay: absList ? absList.style.display : 'N/A',
        hasContent: isContentLoaded
      };
    }
  };
  
  console.log('Sistema de forzado de display iniciado');
})();
</script>

<style>
/* Contenedor principal con scroll */
.gw-ausencias-container {
  background: white;
  border-radius: 12px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
  overflow: hidden;
  margin-top: 20px;
}

.gw-abs-list {
  padding: 24px;
}

/* NUEVO: Información del filtro aplicado */
.gw-filter-info {
  margin-bottom: 20px;
}

.gw-filter-badge {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 8px 16px;
  border-radius: 20px;
  font-size: 14px;
  font-weight: 600;
  border: 2px solid;
}

.gw-filter-admin {
  background: #fef3c7;
  color: #92400e;
  border-color: #f59e0b;
}

.gw-filter-coordinador {
  background: #dbeafe;
  color: #1e40af;
  border-color: #3b82f6;
}

.gw-filter-coach {
  background: #f0f9ff;
  color: #0c4a6e;
  border-color: #0ea5e9;
}

/* Tabla responsive CON SCROLL HORIZONTAL */
.gw-table-responsive {
  overflow-x: auto;
  overflow-y: visible;
  margin-top: 20px;
  border-radius: 8px;
  border: 1px solid #e2e8f0;
  scrollbar-width: thin;
  scrollbar-color: #cbd5e1 #f1f5f9;
}

.gw-table-responsive::-webkit-scrollbar {
  height: 8px;
}

.gw-table-responsive::-webkit-scrollbar-track {
  background: #f1f5f9;
}

.gw-table-responsive::-webkit-scrollbar-thumb {
  background: #cbd5e1;
  border-radius: 4px;
}

.gw-table-responsive::-webkit-scrollbar-thumb:hover {
  background: #94a3b8;
}

.gw-ausencias-table {
  width: 100%;
  min-width: 1400px; /* Aumentado por las nuevas columnas */
  border-collapse: collapse;
  font-size: 14px;
  background: white;
}

.gw-ausencias-table thead th {
  background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
  color: #374151;
  font-weight: 600;
  padding: 16px 12px;
  text-align: left;
  border-bottom: 2px solid #e5e7eb;
  white-space: nowrap;
  position: sticky;
  top: 0;
  z-index: 10;
}

.gw-ausencias-table tbody td {
  padding: 12px;
  border-bottom: 1px solid #f1f5f9;
  vertical-align: middle;
}

.gw-ausencias-table tbody tr:nth-child(even) {
  background: #f9fafb;
}

.gw-ausencias-table tbody tr:hover {
  background: #f0f9ff;
}

/* Columnas específicas - ANCHOS FIJOS */
.gw-col-usuario {
  min-width: 200px;
  width: 200px;
}

.gw-col-pais {
  min-width: 120px;
  width: 120px;
  text-align: center;
}

.gw-col-capacitacion {
  min-width: 150px;
  width: 150px;
  word-wrap: break-word;
}

.gw-col-proyecto {
  min-width: 130px;
  width: 130px;
  text-align: center;
}

.gw-col-fecha {
  min-width: 140px;
  width: 140px;
  text-align: center;
}

.gw-col-estado {
  min-width: 120px;
  width: 120px;
  text-align: center;
}

.gw-col-recordatorios {
  min-width: 100px;
  width: 100px;
  text-align: center;
}

.gw-col-acciones {
  min-width: 350px;
  width: 350px;
  white-space: nowrap;
}

/* Usuario info */
.gw-usuario-info strong {
  display: block;
  color: #1f2937;
}

.gw-usuario-email {
  color: #6b7280;
  font-size: 12px;
}

/* NUEVO: Badges para país y proyecto */
.gw-pais-badge,
.gw-proyecto-badge {
  display: inline-block;
  padding: 4px 8px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  background: #e0e7ff;
  color: #3730a3;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 100px;
}

.gw-proyecto-badge {
  background: #ecfdf5;
  color: #166534;
}

/* Estados */
.gw-estado-badge {
  display: inline-block;
  padding: 4px 8px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.gw-estado-pendiente {
  background: #fef3c7;
  color: #92400e;
}

.gw-estado-ausente {
  background: #fee2e2;
  color: #991b1b;
}

.gw-estado-resuelto {
  background: #dcfce7;
  color: #166534;
}

.gw-estado-inactivo {
  background: #fee2e2;
  color: #991b1b;
}

/* Recordatorios */
.gw-recordatorios-count {
  display: inline-block;
  background: #e0e7ff;
  color: #3730a3;
  padding: 4px 8px;
  border-radius: 8px;
  font-weight: 600;
  min-width: 24px;
  text-align: center;
}

/* Acciones */
.gw-acciones-group {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}

.gw-btn {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 6px 10px;
  border: none;
  border-radius: 6px;
  font-size: 11px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s ease;
  text-decoration: none;
  white-space: nowrap;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.gw-btn:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
}

.gw-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
  transform: none;
}

/* Botón RESOLVER - Verde */
.gw-ausencias-table .gw-btn-resolver {
  background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%) !important;
  color: white !important;
  border: 1px solid #16a34a !important;
}

.gw-ausencias-table .gw-btn-resolver:hover {
  background: linear-gradient(135deg, #16a34a 0%, #15803d 100%) !important;
  border-color: #15803d !important;
}

/* Botón REACTIVAR - Azul */
.gw-ausencias-table .gw-btn-reactivar {
  background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%) !important;
  color: white !important;
  border: 1px solid #2563eb !important;
}

.gw-ausencias-table .gw-btn-reactivar:hover {
  background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%) !important;
  border-color: #1d4ed8 !important;
}

/* Botón OCULTAR - Rojo */
.gw-ausencias-table .gw-btn-ocultar {
  background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%) !important;
  color: white !important;
  border: 1px solid #dc2626 !important;
}

.gw-ausencias-table .gw-btn-ocultar:hover {
  background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%) !important;
  border-color: #b91c1c !important;
}

/* Botón ASISTENCIAS - Púrpura */
.gw-ausencias-table .gw-btn-asistencias {
  background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%) !important;
  color: white !important;
  border: 1px solid #7c3aed !important;
}

.gw-ausencias-table .gw-btn-asistencias:hover {
  background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%) !important;
  border-color: #6d28d9 !important;
}

/* Botón SUCCESS - Verde claro */
.gw-btn-success {
  background: linear-gradient(135deg, #10b981 0%, #059669 100%);
  color: white;
  border: 1px solid #059669;
}

/* Estado sin ausencias */
.gw-no-ausencias {
  text-align: center;
  padding: 60px 20px;
  background: #f8fafc;
  border-radius: 16px;
  border: 2px dashed #cbd5e1;
}

.gw-no-ausencias-icon {
  width: 80px;
  height: 80px;
  background: #dcfce7;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto 20px auto;
  color: #16a34a;
}

.gw-no-ausencias h3 {
  margin: 0 0 12px 0;
  font-size: 20px;
  font-weight: 600;
  color: #374151;
}

.gw-no-ausencias p {
  margin: 0;
  font-size: 16px;
  color: #64748b;
  line-height: 1.5;
}

/* Indicador de scroll */
.gw-scroll-indicator {
  padding: 12px;
  background: #f8fafc;
  border-top: 1px solid #e2e8f0;
  text-align: center;
  font-size: 13px;
  color: #6b7280;
  border-radius: 0 0 8px 8px;
}

.gw-scroll-indicator::before {
  content: "👈 Desliza horizontalmente para ver más columnas 👉";
  font-size: 12px;
}

/* Animaciones */
@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}

.gw-spin {
  animation: spin 1s linear infinite;
}

.gw-ausencia-row {
  transition: opacity 0.3s ease;
}

/* Responsive */
@media (max-width: 768px) {
  .gw-abs-list {
    padding: 16px;
  }
  
  .gw-ausencias-table {
    min-width: 1000px; /* Ajustado para móvil */
  }
  
  .gw-acciones-group {
    flex-direction: column;
    gap: 4px;
  }
  
  .gw-btn {
    justify-content: center;
    font-size: 10px;
    padding: 5px 8px;
  }
  
  .gw-col-acciones {
    min-width: 280px;
    width: 280px;
  }
  
  .gw-filter-badge {
    font-size: 12px;
    padding: 6px 12px;
  }
  
  .gw-pais-badge,
  .gw-proyecto-badge {
    font-size: 10px;
    padding: 3px 6px;
    max-width: 80px;
  }
}
</style>

                
                <!-- TAB REPORTES -->
<div class="gw-admin-tab-content" id="gw-admin-tab-reportes" style="display:none;">
  <div class="gw-form-header">
    <h1>Reportes y listados</h1>
    <p>Genera reportes del sistema.</p>
    <div id="gw-reports-root">

      <?php
        // Fuentes para selects
        $gw_reports_paises    = get_posts(['post_type' => 'pais', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC']);
        $gw_reports_proyectos = get_posts(['post_type' => 'proyecto', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC']);
        $gw_reports_caps      = get_posts(['post_type' => 'capacitacion', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC']);
      ?>
      
      <div class="gw-reports-filters">
        <div class="gw-filters-grid">
          <label class="gw-filter-item">
            <span class="gw-filter-label">País</span>
            <select id="gw-r-pais" class="gw-filter-select">
              <option value="0">Todos</option>
              <?php foreach ($gw_reports_paises as $p): ?>
                <option value="<?php echo (int)$p->ID; ?>"><?php echo esc_html($p->post_title); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          
          <label class="gw-filter-item">
            <span class="gw-filter-label">Proyecto</span>
            <select id="gw-r-proy" class="gw-filter-select">
              <option value="0">Todos</option>
              <?php foreach ($gw_reports_proyectos as $p): ?>
                <option value="<?php echo (int)$p->ID; ?>"><?php echo esc_html($p->post_title); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          
          <label class="gw-filter-item">
            <span class="gw-filter-label">Capacitación</span>
            <select id="gw-r-cap" class="gw-filter-select">
              <option value="0">Todas</option>
              <?php foreach ($gw_reports_caps as $c): ?>
                <option value="<?php echo (int)$c->ID; ?>"><?php echo esc_html($c->post_title); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          
          <label class="gw-filter-item">
            <span class="gw-filter-label">Estado usuario</span>
            <select id="gw-r-estado" class="gw-filter-select">
              <option value="todos">Todos</option>
              <option value="activos">Activos</option>
              <option value="inactivos">Inactivos</option>
            </select>
          </label>
          
          <label class="gw-filter-item">
            <span class="gw-filter-label">Asistencia</span>
            <select id="gw-r-asistencia" class="gw-filter-select">
              <option value="todos">Todas</option>
              <option value="asistio">Asistió</option>
              <option value="pendiente">Pendiente</option>
            </select>
          </label>
          
          <label class="gw-filter-item">
            <span class="gw-filter-label">Desde</span>
            <input type="date" id="gw-r-desde" class="gw-filter-input">
          </label>
          
          <label class="gw-filter-item">
            <span class="gw-filter-label">Hasta</span>
            <input type="date" id="gw-r-hasta" class="gw-filter-input">
          </label>
        </div>
        
        <div class="gw-filter-actions">
          <button type="button" id="gw-r-generar" class="gw-btn gw-btn-primary">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
              <polyline points="3.27,6.96 12,12.01 20.73,6.96"></polyline>
              <line x1="12" y1="22.08" x2="12" y2="12"></line>
            </svg>
            Generar Reporte
          </button>
          
          <button type="button" id="gw-r-exportar" class="gw-btn gw-btn-secondary">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
              <polyline points="14,2 14,8 20,8"></polyline>
              <line x1="16" y1="13" x2="8" y2="13"></line>
              <line x1="16" y1="17" x2="8" y2="17"></line>
              <polyline points="10,9 9,9 8,9"></polyline>
            </svg>
            Exportar XLSX
          </button>
          
          <button type="button" id="gw-r-pdf" class="gw-btn gw-btn-danger">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <polyline points="6,9 6,2 18,2 18,9"></polyline>
              <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path>
              <rect x="6" y="14" width="12" height="8"></rect>
            </svg>
            Exportar PDF
          </button>
        </div>
      </div>

      <!-- Tabla con scroll horizontal -->
      <div id="gw-reports-result" class="gw-table-container">
        <div class="gw-table-scroll-wrapper">
          <table id="gw-reports-table" class="gw-reports-table">
            <thead>
              <tr>
                <th class="gw-col-nombre">Nombre</th>
                <th class="gw-col-email">Email</th>
                <th class="gw-col-pais">País</th>
                <th class="gw-col-proyecto">Proyecto</th>
                <th class="gw-col-capacitacion">Capacitación</th>
                <th class="gw-col-fecha">Fecha</th>
                <th class="gw-col-hora">Hora</th>
                <th class="gw-col-estado">Estado</th>
                <th class="gw-col-asistencia">Asistencia</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td colspan="9" class="gw-no-data">
                  <div class="gw-no-data-content">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                      <polyline points="14,2 14,8 20,8"></polyline>
                      <line x1="16" y1="13" x2="8" y2="13"></line>
                      <line x1="16" y1="17" x2="8" y2="17"></line>
                    </svg>
                    <h3>Genera tu primer reporte</h3>
                    <p>Selecciona los filtros y haz clic en "Generar Reporte"</p>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        
        <!-- Indicador de scroll -->
        <div class="gw-scroll-indicator">
          <span class="gw-scroll-hint">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <polyline points="15,18 9,12 15,6"></polyline>
            </svg>
            Desliza horizontalmente para ver más columnas
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <polyline points="9,18 15,12 9,6"></polyline>
            </svg>
          </span>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
jQuery(function($){
  if (typeof window.ajaxurl === 'undefined') {
    window.ajaxurl = "<?php echo esc_js( admin_url('admin-ajax.php') ); ?>";
  }

  function renderRows(rows){
    var $tb = $('#gw-reports-table tbody').empty();
    
    if (!rows || !rows.length) {
      $tb.append(`
        <tr>
          <td colspan="9" class="gw-no-data">
            <div class="gw-no-data-content">
              <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="11" cy="11" r="8"></circle>
                <path d="M21 21l-4.35-4.35"></path>
              </svg>
              <h3>Sin resultados</h3>
              <p>No se encontraron datos con los filtros seleccionados</p>
            </div>
          </td>
        </tr>
      `);
      $('.gw-scroll-indicator').hide();
      return;
    }
    
    $('.gw-scroll-indicator').show();
    
    rows.forEach(function(r){
      var estadoClass = '';
      var asistenciaClass = '';
      
      // Clases CSS para estados
      if (r.estado === 'Activo') estadoClass = 'gw-estado-activo';
      else if (r.estado === 'Inactivo') estadoClass = 'gw-estado-inactivo';
      
      if (r.asistencia === 'Asistió') asistenciaClass = 'gw-asistencia-si';
      else if (r.asistencia === 'No asistió') asistenciaClass = 'gw-asistencia-no';
      else asistenciaClass = 'gw-asistencia-pendiente';
      
      var tr = `<tr>
        <td class="gw-col-nombre"><strong>${r.nombre || ''}</strong></td>
        <td class="gw-col-email">${r.email || ''}</td>
        <td class="gw-col-pais">${r.pais || ''}</td>
        <td class="gw-col-proyecto">${r.proyecto || '—'}</td>
        <td class="gw-col-capacitacion">${r.capacitacion || ''}</td>
        <td class="gw-col-fecha">${r.fecha || ''}</td>
        <td class="gw-col-hora">${r.hora || ''}</td>
        <td class="gw-col-estado"><span class="gw-badge ${estadoClass}">${r.estado || ''}</span></td>
        <td class="gw-col-asistencia"><span class="gw-badge ${asistenciaClass}">${r.asistencia || ''}</span></td>
      </tr>`;
      $tb.append(tr);
    });
  }

  $('#gw-r-generar').on('click', function(){
    var $btn = $(this);
    var originalText = $btn.text();
    
    var data = {
      action: 'gw_reports_generate',
      pais: $('#gw-r-pais').val(),
      proyecto: $('#gw-r-proy').val(),
      cap: $('#gw-r-cap').val(),
      estado: $('#gw-r-estado').val(),
      asistencia: $('#gw-r-asistencia').val(),
      desde: $('#gw-r-desde').val(),
      hasta: $('#gw-r-hasta').val()
    };
    
    $btn.prop('disabled', true).html('<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="gw-spin"><circle cx="12" cy="12" r="3"></circle></svg> Generando…');
    
    $.post(ajaxurl, data)
      .done(function(resp){
        if (resp && resp.success) {
          renderRows(resp.data.rows || []);
        } else {
          renderRows([]);
        }
      })
      .fail(function(){ 
        renderRows([]);
      })
      .always(function() {
        $btn.prop('disabled', false).html(`<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27,6.96 12,12.01 20.73,6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg> Generar Reporte`);
      });
  });

  function exportCSV(){
    var rows = [];
    $('#gw-reports-table tr').each(function(){
      var cols = [];
      $(this).find('th,td').each(function(){
        var t = $(this).text().replace(/\n/g,' ').replace(/"/g,'""');
        cols.push('"' + t + '"');
      });
      rows.push(cols.join(','));
    });
    
    if (rows.length <= 1) {
      alert('No hay datos para exportar');
      return;
    }
    
    var csv = rows.join('\n');
    var blob = new Blob([csv], {type:'text/csv;charset=utf-8;'});
    var url  = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = 'reporte-voluntariado-' + new Date().toISOString().split('T')[0] + '.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  }

  $('#gw-r-exportar').on('click', exportCSV);

  $('#gw-r-pdf').on('click', function(){
    var tableContent = document.getElementById('gw-reports-result').innerHTML;
    var w = window.open('', '_blank');
    if (!w) {
      alert('Por favor permite ventanas emergentes para exportar PDF');
      return;
    }
    
    w.document.write(`
      <html>
        <head>
          <title>Reporte de Voluntariado</title>
          <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            th { background-color: #f2f2f2; font-weight: bold; }
            .gw-scroll-indicator { display: none; }
            .gw-no-data { text-align: center; }
          </style>
        </head>
        <body>
          <h1>Reporte de Voluntariado</h1>
          <p>Generado el: ${new Date().toLocaleDateString()}</p>
          ${tableContent}
        </body>
      </html>
    `);
    w.document.close();
    w.focus();
    w.print();
  });
});
</script>

<style>
/* Filtros modernos */
.gw-reports-filters {
  background: white;
  border-radius: 12px;
  padding: 24px;
  margin-bottom: 24px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
  border: 1px solid #e2e8f0;
}

.gw-filters-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 20px;
  margin-bottom: 24px;
}

.gw-filter-item {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.gw-filter-label {
  font-weight: 600;
  color: #374151;
  font-size: 14px;
}

.gw-filter-select,
.gw-filter-input {
  padding: 10px 12px;
  border: 2px solid #e5e7eb;
  border-radius: 8px;
  font-size: 14px;
  transition: all 0.2s ease;
  background: white;
}

.gw-filter-select:focus,
.gw-filter-input:focus {
  outline: none;
  border-color: #3b82f6;
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.gw-filter-actions {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  justify-content: center;
  padding-top: 20px;
  border-top: 1px solid #f1f5f9;
}

/* Botones modernos */
.gw-btn {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 12px 20px;
  border: none;
  border-radius: 8px;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s ease;
  text-decoration: none;
  min-height: 44px;
}

.gw-btn:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.gw-btn-primary {
  background: linear-gradient(135deg, #1e88e5 0%, #1976d2 100%);
  color: white;
}

.gw-btn-secondary {
  background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%);
  color: white;
}

.gw-btn-danger {
  background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
  color: white;
}

/* Contenedor de tabla responsive */
.gw-table-container {
  background: white;
  border-radius: 12px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
  overflow: hidden;
  border: 1px solid #e2e8f0;
}

.gw-table-scroll-wrapper {
  overflow-x: auto;
  overflow-y: visible;
  max-width: 100%;
  scrollbar-width: thin;
  scrollbar-color: #cbd5e1 #f1f5f9;
}

.gw-table-scroll-wrapper::-webkit-scrollbar {
  height: 8px;
}

.gw-table-scroll-wrapper::-webkit-scrollbar-track {
  background: #f1f5f9;
}

.gw-table-scroll-wrapper::-webkit-scrollbar-thumb {
  background: #cbd5e1;
  border-radius: 4px;
}

.gw-table-scroll-wrapper::-webkit-scrollbar-thumb:hover {
  background: #94a3b8;
}

/* Tabla moderna */
.gw-reports-table {
  width: 100%;
  min-width: 1000px;
  border-collapse: collapse;
  font-size: 14px;
  background: white;
}

.gw-reports-table thead th {
  background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
  color: #374151;
  font-weight: 600;
  padding: 16px 12px;
  text-align: left;
  border-bottom: 2px solid #e5e7eb;
  white-space: nowrap;
  position: sticky;
  top: 0;
  z-index: 10;
}

.gw-reports-table tbody td {
  padding: 12px;
  border-bottom: 1px solid #f1f5f9;
  vertical-align: middle;
  max-width: 200px;
  word-wrap: break-word;
}

.gw-reports-table tbody tr:nth-child(even) {
  background: #f9fafb;
}

.gw-reports-table tbody tr:hover {
  background: #f0f9ff;
}

/* Columnas específicas */
.gw-col-nombre {
  min-width: 150px;
}

.gw-col-email {
  min-width: 200px;
  font-family: monospace;
}

.gw-col-pais,
.gw-col-proyecto,
.gw-col-capacitacion {
  min-width: 120px;
}

.gw-col-fecha,
.gw-col-hora {
  min-width: 100px;
  text-align: center;
}

.gw-col-estado,
.gw-col-asistencia {
  min-width: 110px;
  text-align: center;
}

/* Badges */
.gw-badge {
  display: inline-block;
  padding: 4px 8px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.gw-estado-activo {
  background: #dcfce7;
  color: #166534;
}

.gw-estado-inactivo {
  background: #fee2e2;
  color: #991b1b;
}

.gw-asistencia-si {
  background: #dbeafe;
  color: #1e40af;
}

.gw-asistencia-no {
  background: #fee2e2;
  color: #991b1b;
}

.gw-asistencia-pendiente {
  background: #fef3c7;
  color: #92400e;
}

/* Estado sin datos */
.gw-no-data {
  text-align: center;
  padding: 60px 20px;
}

.gw-no-data-content {
  color: #6b7280;
}

.gw-no-data-content svg {
  color: #9ca3af;
  margin-bottom: 16px;
}

.gw-no-data-content h3 {
  margin: 0 0 8px 0;
  font-size: 18px;
  color: #374151;
}

.gw-no-data-content p {
  margin: 0;
  font-size: 14px;
}

/* Indicador de scroll */
.gw-scroll-indicator {
  padding: 12px;
  background: #f8fafc;
  border-top: 1px solid #e2e8f0;
  text-align: center;
}

.gw-scroll-hint {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  font-size: 13px;
  color: #6b7280;
  font-weight: 500;
}

/* Animación de carga */
@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}

.gw-spin {
  animation: spin 1s linear infinite;
}

/* Responsive */
@media (max-width: 768px) {
  .gw-filters-grid {
    grid-template-columns: 1fr;
  }
  
  .gw-filter-actions {
    flex-direction: column;
  }
  
  .gw-btn {
    justify-content: center;
  }
  
  .gw-scroll-hint {
    font-size: 12px;
  }
}
</style>
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






//CHARLAS DEFINITIVO
//CHARLAS DEFINITIVO
//CHARLAS DEFINITIVO


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
// --- SSO/QR: persistir gw_pais y normalizar metadatos al iniciar sesión ---
add_action('init', function () {
  // Si se llega con ?gw_pais=123 (por QR), guárdalo temporalmente para aplicarlo tras SSO
  if (isset($_GET['gw_pais'])) {
    $pid = intval($_GET['gw_pais']);
    if ($pid > 0) {
      setcookie('gw_pais_pending', (string)$pid, time() + 900, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
    }
  }
});

add_action('wp_login', function ($user_login, $user) {
  // Asegurar rol básico para usuarios creados por SSO (ajusta si usas 'voluntario')
  if (!in_array('voluntario', $user->roles, true) && !in_array('subscriber', $user->roles, true)) {
    $u = new WP_User($user->ID);
    $u->add_role('subscriber');
  }

  // Aplicar país capturado desde el QR previo al SSO
  if (!empty($_COOKIE['gw_pais_pending'])) {
    $pid = intval($_COOKIE['gw_pais_pending']);
    if ($pid > 0) {
      update_user_meta($user->ID, 'gw_pais_id', $pid);
      $t = get_the_title($pid);
      if ($t) update_user_meta($user->ID, 'pais', $t); // por compatibilidad con tu UI
    }
    setcookie('gw_pais_pending', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
  }

  // Inicializar contenedores como ARRAY (evita strings/JSON)
  foreach (['gw_charlas_asignadas', 'gw_capacitaciones_asignadas'] as $k) {
    $v = get_user_meta($user->ID, $k, true);
    if (!is_array($v)) {
      $tmp = json_decode((string)$v, true);
      $v = is_array($tmp) ? array_values($tmp) : [];
      update_user_meta($user->ID, $k, $v);
    }
  }
}, 10, 2);



// --- Helpers para asignación segura de charlas/capacitaciones ---
if (!function_exists('gw_assign_charla_to_user')) {
  function gw_assign_charla_to_user($user_id, $charla_id) {
    $arr = get_user_meta($user_id, 'gw_charlas_asignadas', true);
    if (!is_array($arr)) {
      $tmp = json_decode((string)$arr, true);
      $arr = is_array($tmp) ? $tmp : [];
    }
    $charla_id = (string)$charla_id;
    if (!in_array($charla_id, $arr, true)) {
      $arr[] = $charla_id;
      update_user_meta($user_id, 'gw_charlas_asignadas', $arr);
    }
  }
}






if (!function_exists('gw_assign_cap_to_user')) {
  function gw_assign_cap_to_user($user_id, $cap_id) {
    $arr = get_user_meta($user_id, 'gw_capacitaciones_asignadas', true);
    if (!is_array($arr)) {
      $tmp = json_decode((string)$arr, true);
      $arr = is_array($tmp) ? $tmp : [];
    }
    $cap_id = intval($cap_id);
    if (!in_array($cap_id, $arr, true)) {
      $arr[] = $cap_id;
      update_user_meta($user_id, 'gw_capacitaciones_asignadas', $arr);
    }
  }
}
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




// 1. Ver charlas del voluntario
add_action('wp_ajax_gw_obtener_charlas_voluntario', 'gw_obtener_charlas_voluntario_callback');
add_action('wp_ajax_nopriv_gw_obtener_charlas_voluntario', 'gw_obtener_charlas_voluntario_callback');

function gw_obtener_charlas_voluntario_callback() {
    if (!current_user_can('manage_options')) {
        wp_die('Sin permisos');
    }

    $user_id = intval($_POST['user_id']);
    if (!$user_id) {
        wp_die('ID de usuario inválido');
    }

    $user = get_userdata($user_id);
    if (!$user) {
        wp_die('Usuario no encontrado');
    }

    // Nueva lógica: normaliza y auto-fija metas si es necesario
    $charlas_asignadas = get_user_meta($user_id, 'gw_charlas_asignadas', true);
    if (!is_array($charlas_asignadas)) {
        $tmp = json_decode((string)$charlas_asignadas, true);
        if (is_array($tmp)) {
            $charlas_asignadas = array_values($tmp);
            // normaliza de una vez para futuros usos
            update_user_meta($user_id, 'gw_charlas_asignadas', $charlas_asignadas);
        } else {
            $charlas_asignadas = [];
        }
    }
    // Asegura que sean strings (tus claves de ejemplo son '96','93', etc.)
    $charlas_asignadas = array_map('strval', $charlas_asignadas);

    // Personaliza estos nombres según tus charlas reales
    $charlas_disponibles = array(
        '96' => 'Introducción al Voluntariado',
        '93' => 'Técnicas de Comunicación',
        '94' => 'Primeros Auxilios Básicos',
        '101' => 'Manejo de Situaciones Difíciles',
        '102' => 'Trabajo en Equipo',
        '103' => 'Ética y Valores',
    );

    ob_start(); ?>
    
    <?php if (empty($charlas_asignadas)): ?>
        <div style="text-align: center; padding: 40px;">
            <p style="color: #666; font-size: 16px;">Este voluntario no tiene charlas asignadas.</p>
        </div>
    <?php else: ?>
        <div style="margin-bottom: 20px;">
            <h4 style="margin: 0 0 15px 0;">Charlas asignadas a <?php echo esc_html($user->display_name); ?></h4>
        </div>
        
        <table class="widefat striped" style="margin: 0;">
            <thead>
                <tr>
                    <th style="width: 80px;">Código</th>
                    <th>Nombre de la Charla</th>
                    <th style="width: 100px; text-align: center;">Estado</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($charlas_asignadas as $charla_key): ?>
                    <?php
                        $completada = get_user_meta($user_id, 'gw_' . $charla_key, true);
                        $nombre_charla = isset($charlas_disponibles[$charla_key]) 
                            ? $charlas_disponibles[$charla_key] 
                            : 'Charla ' . $charla_key;
                    ?>
                    <tr>
                        <td style="font-weight: bold; color: #0073aa;">
                            <?php echo esc_html($charla_key); ?>
                        </td>
                        <td><?php echo esc_html($nombre_charla); ?></td>
                        <td style="text-align: center;">
                            <?php if ($completada): ?>
                                <span style="color: #46b450; font-size: 18px;" title="Completada">✅</span>
                            <?php else: ?>
                                <span style="color: #dc3232; font-size: 18px;" title="Pendiente">❌</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php
        $completadas = 0;
        foreach ($charlas_asignadas as $charla_key) {
            if (get_user_meta($user_id, 'gw_' . $charla_key, true)) {
                $completadas++;
            }
        }
        $total = count($charlas_asignadas);
        $porcentaje = $total > 0 ? round(($completadas / $total) * 100, 1) : 0;
        ?>

        <div style="margin-top: 20px; padding: 15px; background: #f7fafd; border-radius: 8px; border-left: 4px solid #0073aa;">
            <h4 style="margin: 0 0 10px 0; color: #0073aa;">Resumen del Progreso</h4>
            <p style="margin: 0; font-size: 16px;">
                <strong><?php echo $completadas; ?></strong> de <strong><?php echo $total; ?></strong> charlas completadas
                <span style="color: #666;">(<?php echo $porcentaje; ?>%)</span>
            </p>
            
            <div style="margin-top: 10px; background: #e0e0e0; border-radius: 10px; height: 10px; overflow: hidden;">
                <div style="background: linear-gradient(90deg, #46b450, #0073aa); height: 100%; width: <?php echo $porcentaje; ?>%; transition: width 0.3s ease;"></div>
            </div>
        </div>
    <?php endif; ?>

    <?php
    echo ob_get_clean();
    wp_die();
}

// 2. Ver documentos del voluntario - CORREGIDO PARA MANEJAR DOCUMENTOS INDEPENDIENTES
add_action('wp_ajax_gw_obtener_docs_voluntario', 'gw_obtener_docs_independientes_callback');

function gw_obtener_docs_independientes_callback() {
    if (!current_user_can('manage_options')) {
        echo '<p>Sin permisos.</p>';
        wp_die();
    }

    $user_id = intval($_POST['user_id']);
    if (!$user_id) {
        echo '<p>ID inválido.</p>';
        wp_die();
    }

    global $wpdb;
    $table = $wpdb->prefix . 'voluntario_docs';
    
    // Verificar si la tabla existe
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
    if (!$table_exists) {
        echo '<div style="text-align: center; padding: 40px;">
                <p style="color: #dc3232; font-size: 16px;">La tabla voluntario_docs no existe.</p>
              </div>';
        wp_die();
    }

    // Obtener el registro del usuario
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table WHERE user_id = %d ORDER BY fecha_subida DESC LIMIT 1",
        $user_id
    ));

    if (!$row) {
        echo '<p>El voluntario no ha subido documentos.</p>';
        wp_die();
    }

    // Función para forzar HTTPS si es necesario
    $to_https = function($url){
        if (!$url) return '';
        return esc_url(set_url_scheme(esc_url_raw($url), is_ssl() ? 'https' : 'http'));
    };

    $tipo = function($url){
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) return 'image';
        if ($ext === 'pdf') return 'pdf';
        return 'other';
    };

    $nonce = wp_create_nonce('gw_docs_review');

    // Crear array de documentos individuales
    $documentos = [];
    
    // Documento 1
    if (!empty($row->documento_1_url)) {
        $documentos[] = [
            'id' => 'doc1',
            'tipo' => 'documento_1', 
            'label' => 'Documento 1',
            'url' => $to_https($row->documento_1_url),
            'estado' => get_user_meta($user_id, 'gw_doc1_estado', true) ?: 'pendiente'
        ];
    }
    
    // Documento 2  
    if (!empty($row->documento_2_url)) {
        $documentos[] = [
            'id' => 'doc2',
            'tipo' => 'documento_2',
            'label' => 'Documento 2', 
            'url' => $to_https($row->documento_2_url),
            'estado' => get_user_meta($user_id, 'gw_doc2_estado', true) ?: 'pendiente'
        ];
    }

    ob_start(); ?>

    <table class="widefat striped">
        <thead>
            <tr>
                <th>Documento</th>
                <th>Archivo</th>
                <th>Estado</th>
                <th>Acción</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($documentos)): ?>
                <tr><td colspan="4">No hay documentos subidos para este voluntario.</td></tr>
            <?php else: ?>
                <?php foreach ($documentos as $doc): ?>
                    <?php $t = $tipo($doc['url']); ?>
                    <tr>
                        <td><?php echo esc_html($doc['label']); ?></td>
                        <td>
                            <button type="button" class="button gw-ver-archivo" 
                                    data-url="<?php echo esc_attr($doc['url']); ?>" 
                                    data-tipo="<?php echo esc_attr($t); ?>">
                                Ver archivo
                            </button>
                            <?php if ($t === 'image'): ?>
                                <div style="margin-top:8px;">
                                    <img src="<?php echo esc_url($doc['url']); ?>" alt="Documento" 
                                         style="max-width:120px;max-height:120px;border:1px solid #ccc;border-radius:6px;" 
                                         loading="lazy">
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $color = '';
                            $texto_estado = '';
                            switch ($doc['estado']) {
                                case 'aceptado':
                                    $color = '#46b450';
                                    $texto_estado = 'Aceptado';
                                    break;
                                case 'rechazado':
                                    $color = '#dc3232';
                                    $texto_estado = 'Rechazado';
                                    break;
                                default:
                                    $color = '#ffb900';
                                    $texto_estado = 'Pendiente';
                            }
                            ?>
                            <span style="color: <?php echo $color; ?>; font-weight: bold;">
                                <?php echo $texto_estado; ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($doc['estado'] !== 'aceptado'): ?>
                                <button type="button" class="button gw-aprobar-doc" 
                                        style="background: #46b450; border-color: #46b450; color: white; margin-right: 5px;"
                                        data-doc-id="<?php echo esc_attr($doc['id']); ?>" 
                                        data-user-id="<?php echo esc_attr($user_id); ?>" 
                                        data-nonce="<?php echo esc_attr($nonce); ?>">
                                    Aprobar
                                </button>
                            <?php endif; ?>
                            
                            <?php if ($doc['estado'] !== 'rechazado'): ?>
                                <button type="button" class="button gw-rechazar-doc" 
                                        style="background: #dc3232; border-color: #dc3232; color: white;"
                                        data-doc-id="<?php echo esc_attr($doc['id']); ?>" 
                                        data-user-id="<?php echo esc_attr($user_id); ?>" 
                                        data-nonce="<?php echo esc_attr($nonce); ?>">
                                    Rechazar
                                </button>
                            <?php endif; ?>
                            
                            <?php if ($doc['estado'] === 'aceptado'): ?>
                                <span style="color: #46b450;">✅ Aprobado</span>
                            <?php elseif ($doc['estado'] === 'rechazado'): ?>
                                <span style="color: #dc3232;">❌ Necesita nueva subida</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Estadísticas de documentos -->
    <?php 
    $aprobados = 0;
    $rechazados = 0; 
    $pendientes = 0;
    foreach ($documentos as $doc) {
        switch ($doc['estado']) {
            case 'aceptado': $aprobados++; break;
            case 'rechazado': $rechazados++; break;
            default: $pendientes++; break;
        }
    }
    ?>
    
    <div style="margin-top: 20px; padding: 15px; background: #f7fafd; border-radius: 8px;">
        <h4 style="margin: 0 0 10px 0;">Resumen de Documentos</h4>
        <p style="margin: 5px 0;">
            <span style="color: #46b450;">✅ Aprobados: <?php echo $aprobados; ?></span> | 
            <span style="color: #dc3232;">❌ Rechazados: <?php echo $rechazados; ?></span> | 
            <span style="color: #ffb900;">⏳ Pendientes: <?php echo $pendientes; ?></span>
        </p>
    </div>

    <?php
    echo ob_get_clean();
    wp_die();
}

// 3. Aprobar documento individual - CORREGIDO
add_action('wp_ajax_gw_aprobar_doc', 'gw_aprobar_doc_individual_callback');

function gw_aprobar_doc_individual_callback() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Sin permisos'], 403);
    }

    if (isset($_POST['nonce']) && !wp_verify_nonce($_POST['nonce'], 'gw_docs_review')) {
        wp_send_json_error(['message' => 'Nonce inválido'], 400);
    }

    $doc_id = sanitize_text_field($_POST['doc_id'] ?? '');
    $user_id = intval($_POST['user_id'] ?? 0);

    if (!$doc_id || !$user_id) {
        wp_send_json_error(['message' => 'Parámetros inválidos'], 400);
    }

    // Actualizar el estado individual del documento
    $meta_key = 'gw_' . $doc_id . '_estado';
    update_user_meta($user_id, $meta_key, 'aceptado');
    
    // Log de quien aprobó
    update_user_meta($user_id, 'gw_' . $doc_id . '_aprobado_por', get_current_user_id());
    update_user_meta($user_id, 'gw_' . $doc_id . '_fecha_aprobacion', current_time('mysql'));

    wp_send_json_success(['message' => 'Documento aprobado correctamente', 'status' => 'aceptado']);
}

// 4. Rechazar documento individual - CORREGIDO
add_action('wp_ajax_gw_rechazar_doc', 'gw_rechazar_doc_individual_callback');

function gw_rechazar_doc_individual_callback() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Sin permisos'], 403);
    }

    if (isset($_POST['nonce']) && !wp_verify_nonce($_POST['nonce'], 'gw_docs_review')) {
        wp_send_json_error(['message' => 'Nonce inválido'], 400);
    }

    $doc_id = sanitize_text_field($_POST['doc_id'] ?? '');
    $user_id = intval($_POST['user_id'] ?? 0);

    if (!$doc_id || !$user_id) {
        wp_send_json_error(['message' => 'Parámetros inválidos'], 400);
    }

    // Actualizar el estado individual del documento
    $meta_key = 'gw_' . $doc_id . '_estado';
    update_user_meta($user_id, $meta_key, 'rechazado');
    
    // Log de quien rechazó
    update_user_meta($user_id, 'gw_' . $doc_id . '_rechazado_por', get_current_user_id());
    update_user_meta($user_id, 'gw_' . $doc_id . '_fecha_rechazo', current_time('mysql'));

    // Limpiar el archivo específico en la base de datos para permitir nueva subida
    global $wpdb;
    $table = $wpdb->prefix . 'voluntario_docs';
    
    // Determinar qué campo limpiar según el doc_id
    $campo_a_limpiar = '';
    switch ($doc_id) {
        case 'doc1':
            $campo_a_limpiar = 'documento_1_url';
            break;
        case 'doc2':
            $campo_a_limpiar = 'documento_2_url';
            break;
        case 'doc3':
            $campo_a_limpiar = 'documento_3_url';
            break;
        case 'doc4':
            $campo_a_limpiar = 'documento_4_url';
            break;
    }

    if ($campo_a_limpiar) {
        $wpdb->update(
            $table,
            [$campo_a_limpiar => ''],
            ['user_id' => $user_id],
            ['%s'],
            ['%d']
        );
    }

    // Enviar correo específico para este documento
    $user = get_userdata($user_id);
    if ($user) {
        gw_enviar_correo_rechazo_documento($user, $doc_id);
    }

    wp_send_json_success(['message' => 'Documento rechazado y usuario notificado', 'status' => 'rechazado']);
}

// === AJAX: subir documento individual (compat layer) ===
add_action('wp_ajax_gw_doc_upload', 'gw_ajax_doc_upload'); // requiere login

function gw_ajax_doc_upload(){
  if (!is_user_logged_in()) wp_send_json_error(['msg'=>'No logueado']);
  check_ajax_referer('gw_doc_upload', 'nonce');

  $slot = isset($_POST['slot']) ? intval($_POST['slot']) : 0;
  if ($slot < 1 || $slot > 4) wp_send_json_error(['msg'=>'Slot inválido']);

  if (empty($_FILES['file'])) wp_send_json_error(['msg'=>'Archivo no recibido']);

  require_once ABSPATH.'wp-admin/includes/file.php';
  require_once ABSPATH.'wp-admin/includes/media.php';
  require_once ABSPATH.'wp-admin/includes/image.php';

  $user = wp_get_current_user();

  // Sube al Media Library
  $attachment_id = media_handle_upload('file', 0);
  if (is_wp_error($attachment_id)) {
    wp_send_json_error(['msg'=>$attachment_id->get_error_message()]);
  }
  $url = wp_get_attachment_url($attachment_id);

  // Guardar compat metas para lectura rápida
  update_user_meta($user->ID, "gw_doc{$slot}_attachment_id", $attachment_id);
  update_user_meta($user->ID, "gw_doc{$slot}_url", $url);

  // Mapa de últimos documentos (opcional)
  $map = (array) get_user_meta($user->ID, 'gw_docs_latest', true);
  $map[$slot] = ['id'=>$attachment_id, 'url'=>$url];
  update_user_meta($user->ID, 'gw_docs_latest', $map);

  wp_send_json_success(['slot'=>$slot, 'id'=>$attachment_id, 'url'=>$url]);
}

// 5. Función para enviar correo de rechazo específico por documento
function gw_enviar_correo_rechazo_documento($user, $doc_id) {
    $to = $user->user_email;
    
    // Determinar el nombre del documento rechazado
    $nombres_docs = [
        'doc1' => 'Documento de identidad (Foto 1)',
        'doc2' => 'Documento de identidad (Foto 2)',
        'doc3' => 'Documento adicional (Foto 3)',
        'doc4' => 'Documento adicional (Foto 4)'
    ];
    
    $nombre_documento = $nombres_docs[$doc_id] ?? 'Documento';
    
    $subject = 'Documento rechazado - ' . $nombre_documento;
    
    // URL del portal - personaliza según tu configuración
    $portal_url = home_url('/portal-voluntario/');

    $message = "
    <html>
    <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
        <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
            <div style='background: #dc3232; color: white; padding: 15px; border-radius: 5px 5px 0 0;'>
                <h2>Documento Rechazado</h2>
            </div>
            <div style='background: #f9f9f9; padding: 20px; border-radius: 0 0 5px 5px;'>
                <p>Estimado/a <strong>" . esc_html($user->display_name) . "</strong>,</p>
                
                <div style='background: #fff3cd; border-left: 4px solid #ffc107; padding: 10px; margin: 15px 0;'>
                    <p><strong>El documento \"$nombre_documento\" ha sido rechazado y requiere acción de su parte.</strong></p>
                </div>
                
                <p>Le informamos que el documento <strong>\"$nombre_documento\"</strong> que subió a nuestro sistema ha sido rechazado durante el proceso de revisión.</p>
                
                <div style='background: #e7f3ff; border-left: 4px solid #0073aa; padding: 10px; margin: 15px 0;'>
                    <p><strong>Importante:</strong> Solo este documento específico fue rechazado. Sus otros documentos mantienen su estado actual.</p>
                </div>
                
                <h3>¿Qué debe hacer ahora?</h3>
                <ul>
                    <li>Revisar los requisitos específicos para este documento</li>
                    <li>Preparar una nueva versión que cumpla con los estándares</li>
                    <li>Ingresar al portal para subir únicamente este documento corregido</li>
                </ul>
                
                <p>Para continuar con su proceso de voluntariado, debe ingresar al portal y subir una nueva versión del documento rechazado:</p>
                
                <a href='$portal_url' style='display: inline-block; background: #0073aa; color: white; padding: 12px 25px; text-decoration: none; border-radius: 5px; margin-top: 15px;'>Ingresar al Portal</a>
                
                <p style='margin-top: 20px;'><strong>Nota:</strong> Su proceso de aplicación continuará una vez que suba el documento corregido.</p>
                
                <p>Si tiene dudas sobre los requisitos específicos de este documento, puede contactarnos respondiendo a este correo.</p>
                
                <p>Saludos cordiales,<br><strong>Equipo de Administración</strong></p>
            </div>
        </div>
    </body>
    </html>";

    $headers = array(
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
    );

    wp_mail($to, $subject, $message, $headers);
}

// 6. Función para actualizar el formulario de subida (PASO 8) - CORREGIDA
function gw_actualizar_paso8_para_rechazos() {
    // Esta función debe ir en tu archivo del PASO 8
    // Modifica la lógica de mostrar documentos para verificar estados individuales
    
    // En lugar de verificar solo $status, ahora verificar cada documento:
    /*
    $doc1_estado = get_user_meta($user_id, 'gw_doc1_estado', true) ?: 'pendiente';
    $doc2_estado = get_user_meta($user_id, 'gw_doc2_estado', true) ?: 'pendiente';
    
    // Mostrar cada documento con su estado específico
    // Permitir subida individual de documentos rechazados
    */
}

// 7. Cargar scripts necesarios
add_action('wp_enqueue_scripts', function () {
    if (is_page('panel-administrativo')) {
        wp_enqueue_script('jquery');
        wp_add_inline_script('jquery', 'window.ajaxurl = "'. esc_js( admin_url('admin-ajax.php') ) .'";', 'after');
        $js = "(function($){
          function gwRepFiltros(){
            return {
              action: 'gw_reports_fetch',
              nonce:  '".wp_create_nonce('gw_reports')."',
              tipo:   $('#gwRepTipo').val() || 'capacitacion',
              pais_id: $('#gwRepPais').val() || '',
              proyecto_id: $('#gwRepProyecto').val() || '',
              cap_id: $('#gwRepCap').val() || '',
              estado: ($('#gwRepEstado').val() || '').toLowerCase(),
              asistencia: ($('#gwRepAsistencia').val() || 'todas').toLowerCase(),
              desde:  $('#gwRepDesde').val() || '',
              hasta:  $('#gwRepHasta').val() || ''
            };
          }
        
          function gwRepGenerar(){
            var data = gwRepFiltros();
            $.post(ajaxurl, data, function(html){
              $('#gwRepResultados').html(html);
            }).fail(function(xhr){
              console.error('AJAX FAIL', xhr && xhr.responseText);
            });
          }
        
          $(document).on('click','#gwRepGenerar',function(e){ e.preventDefault(); gwRepGenerar(); });
        
          $(document).on('click','#gwRepExportCSV',function(e){
            e.preventDefault();
            var q = gwRepFiltros(); q.action = 'gw_reports_export';
            window.location = ajaxurl + '?' + $.param(q);
          });
        
          $(document).on('click','#gwRepExportPDF',function(e){
            e.preventDefault();
            var q = gwRepFiltros(); q.action = 'gw_reports_export'; q.format = 'pdf';
            window.location = ajaxurl + '?' + $.param(q);
          });
        
          $(document).on('click','.gwRepPag',function(e){
            e.preventDefault();
            var q = gwRepFiltros(); q.page = $(this).data('p') || 1;
            $.post(ajaxurl, q, function(html){ $('#gwRepResultados').html(html); });
          });
        })(jQuery);";
        wp_add_inline_script('jquery', $js, 'after');
    }
});

// 8. Función auxiliar para verificar configuración de correo
function gw_verificar_configuracion_correo() {
    // Test de envío de correo
    $admin_email = get_option('admin_email');
    $test_sent = wp_mail($admin_email, 'Test - Sistema de documentos', 'Este es un correo de prueba del sistema de documentos de voluntarios.');
    
    if ($test_sent) {
        return "✅ Configuración de correo funcionando";
    } else {
        return "❌ Error en configuración de correo. Revisar plugins SMTP o configuración del servidor.";
    }
}

// 9. Shortcode para probar correos (opcional - solo para debug)
add_shortcode('test_correo_docs', function() {
    if (!current_user_can('manage_options')) return 'Sin permisos';
    
    if (isset($_GET['test_mail'])) {
        $resultado = gw_verificar_configuracion_correo();
        return '<div style="padding: 10px; background: #f0f0f0; margin: 10px 0;">' . $resultado . '</div>';
    }
    
    return '<a href="?test_mail=1" class="button">Probar configuración de correo</a>';
});

/**
 * Sube un documento a /wp-content/uploads/documentos-voluntarios usando la API de WordPress.
 * @param array  $file     La entrada de $_FILES['...'].
 * @param int    $user_id  ID del usuario.
 * @param string $doc_tipo Etiqueta del doc (ej: documento_1).
 * @return string|false    URL final del archivo o false en error.
 */
function gw_subir_documento_personalizado( $file, $user_id, $doc_tipo ) {
    if ( empty($file) || !isset($file['tmp_name']) ) return false;
    if ( $file['error'] !== UPLOAD_ERR_OK ) return false;
    if ( ! file_exists($file['tmp_name']) ) return false;

    // Extensiones permitidas
    $allowed_mimes = array(
        'jpg|jpeg' => 'image/jpeg',
        'png'      => 'image/png',
        'gif'      => 'image/gif',
        'webp'     => 'image/webp',
    );

    // Base de uploads
    $uploads = wp_upload_dir();
    if ( ! empty($uploads['error']) ) return false;

    // 📌 Crear carpeta personalizada con año/mes (igual que WP)
    $time       = current_time('mysql');
    $subdir     = '/documentos-voluntarios/' . date('Y/m', strtotime($time));
    $custom_dir = $uploads['basedir'] . $subdir;
    $custom_url = $uploads['baseurl'] . $subdir;

    // 👉 Crear directorio recursivamente si no existe
    if ( ! wp_mkdir_p( $custom_dir ) ) {
        error_log('GW Upload Error: no se pudo crear carpeta ' . $custom_dir);
        return false;
    }

    // Filtro upload_dir para forzar esta ruta
    $filter = function( $dirs ) use ( $custom_dir, $custom_url, $subdir ) {
        $dirs['path']   = $custom_dir;
        $dirs['url']    = $custom_url;
        $dirs['subdir'] = $subdir;
        return $dirs;
    };
    add_filter( 'upload_dir', $filter );

    // Nombre de archivo seguro
    $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
    $safe_base  = sanitize_file_name( $user_id . '_' . $doc_tipo . '_' . time() );
    $file['name'] = $safe_base . '.' . $ext;

    // Subir
    $result = wp_handle_upload( $file, array(
        'test_form' => false,
        'mimes'     => $allowed_mimes,
    ));

    remove_filter( 'upload_dir', $filter );

    if ( isset($result['error']) ) {
        error_log('GW Upload Error: ' . $result['error']);
        return false;
    }

    // Seguridad: index.php
    $index_file = $custom_dir . '/index.php';
    if ( ! file_exists($index_file) ) {
        file_put_contents($index_file, "<?php\n// Silence is golden\n");
    }

    return esc_url_raw($result['url']);
}

// Función auxiliar para verificar configuración de uploads
function gw_verificar_configuracion_uploads() {
    $upload_info = wp_upload_dir();
    $results = [];
    
    $results['wp_upload_dir_error'] = $upload_info['error'];
    $results['upload_dir'] = $upload_info['basedir'];
    $results['upload_url'] = $upload_info['baseurl'];
    $results['dir_exists'] = file_exists($upload_info['basedir']);
    $results['dir_writable'] = is_writable($upload_info['basedir']);
    
    $docs_dir = $upload_info['basedir'] . '/documentos-voluntarios/';
    $results['docs_dir_exists'] = file_exists($docs_dir);
    $results['docs_dir_writable'] = file_exists($docs_dir) ? is_writable($docs_dir) : 'N/A';
    
    $results['max_upload_size'] = wp_max_upload_size();
    $results['php_max_filesize'] = ini_get('upload_max_filesize');
    $results['php_max_post_size'] = ini_get('post_max_size');
    
    return $results;
}

// Shortcode para diagnóstico (temporal, solo para admin)
function gw_diagnostico_uploads_shortcode() {
    if (!current_user_can('manage_options')) {
        return 'Sin permisos para ver diagnóstico.';
    }
    
    $config = gw_verificar_configuracion_uploads();
    
    ob_start();
    ?>
    <div style="background: #f9f9f9; padding: 15px; border-radius: 8px; margin: 10px 0;">
        <h3>Diagnóstico de Configuración de Uploads</h3>
        <ul>
            <li><strong>Error WP Upload Dir:</strong> <?php echo $config['wp_upload_dir_error'] ?: 'Ninguno'; ?></li>
            <li><strong>Directorio base:</strong> <?php echo esc_html($config['upload_dir']); ?></li>
            <li><strong>URL base:</strong> <?php echo esc_html($config['upload_url']); ?></li>
            <li><strong>Directorio existe:</strong> <?php echo $config['dir_exists'] ? '✅ Sí' : '❌ No'; ?></li>
            <li><strong>Directorio escribible:</strong> <?php echo $config['dir_writable'] ? '✅ Sí' : '❌ No'; ?></li>
            <li><strong>Dir documentos existe:</strong> <?php echo $config['docs_dir_exists'] ? '✅ Sí' : '❌ No'; ?></li>
            <li><strong>Dir documentos escribible:</strong> <?php echo $config['docs_dir_writable'] === 'N/A' ? 'N/A' : ($config['docs_dir_writable'] ? '✅ Sí' : '❌ No'); ?></li>
            <li><strong>Tamaño máximo WP:</strong> <?php echo size_format($config['max_upload_size']); ?></li>
            <li><strong>PHP max filesize:</strong> <?php echo $config['php_max_filesize']; ?></li>
            <li><strong>PHP max post size:</strong> <?php echo $config['php_max_post_size']; ?></li>
        </ul>
        
        <?php if (isset($_GET['test_create_dir'])): ?>
            <?php
            $test_dir = $config['upload_dir'] . '/test-gw-' . time() . '/';
            $create_result = wp_mkdir_p($test_dir);
            ?>
            <div style="margin-top: 10px; padding: 10px; background: <?php echo $create_result ? '#d4edda' : '#f8d7da'; ?>;">
                <strong>Test de creación de directorio:</strong>
                <?php echo $create_result ? '✅ Exitoso' : '❌ Falló'; ?>
                <?php if ($create_result): ?>
                    <?php 
                    // Limpiar directorio de prueba
                    rmdir($test_dir);
                    ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <p style="margin-top: 15px;">
            <a href="?test_create_dir=1" class="button">Probar creación de directorio</a>
        </p>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('gw_diagnostico_uploads', 'gw_diagnostico_uploads_shortcode');

// Función para obtener mensaje de error de upload
function gw_obtener_mensaje_error_upload($error_code) {
    switch ($error_code) {
        case UPLOAD_ERR_OK:
            return 'Sin errores';
        case UPLOAD_ERR_INI_SIZE:
            return 'El archivo excede upload_max_filesize del servidor (' . ini_get('upload_max_filesize') . ')';
        case UPLOAD_ERR_FORM_SIZE:
            return 'El archivo excede MAX_FILE_SIZE del formulario';
        case UPLOAD_ERR_PARTIAL:
            return 'El archivo se subió parcialmente';
        case UPLOAD_ERR_NO_FILE:
            return 'No se seleccionó archivo';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'Falta directorio temporal del servidor';
        case UPLOAD_ERR_CANT_WRITE:
            return 'Error de escritura en disco del servidor';
        case UPLOAD_ERR_EXTENSION:
            return 'Una extensión PHP bloqueó la subida';
        default:
            return 'Error desconocido (' . $error_code . ')';
    }
}

// === Paso 8 (Documentos y escuela): previews + botón "Agregar otra foto" ===
add_action('wp_footer', function(){
  if (!is_user_logged_in()) return;
  $u = wp_get_current_user();
  if (!in_array('voluntario', (array)$u->roles, true)) return; // sólo voluntarios
  $req = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
  // Heurística: solo en portal de voluntario / documentos / paso 8
  if (strpos($req,'portal-voluntario') === false && strpos($req,'paso-8') === false && strpos($req,'documentos') === false) return;
  ?>
  <script>
  (function(){
    // 1) PREVIEW INSTANTÁNEO (FileReader) — no depende del servidor
    function isDocInput(el){
      if (!el || el.type !== 'file') return false;
      var n = (el.getAttribute('name') || '').toLowerCase();
      return n.indexOf('gw_doc') === 0 || el.hasAttribute('data-gw-doc');
    }
    function ensureThumb(input){
      var wrap = input.closest('.gw-doc-slot') || input.parentNode;
      var img = wrap.querySelector('.gw-doc-thumb');
      if (!img){
        img = document.createElement('img');
        img.className = 'gw-doc-thumb';
        img.alt = 'Vista previa';
        img.style.maxWidth = '180px';
        img.style.borderRadius = '10px';
        img.style.display = 'block';
        img.style.margin = '8px 0';
        wrap.insertBefore(img, input);
      }
      return img;
    }
    function handleChange(e){
      var input = e.target; if (!isDocInput(input) || !input.files || !input.files[0]) return;
      try{
        var reader = new FileReader();
        var img = ensureThumb(input);
        reader.onload = function(ev){ img.src = ev.target.result; img.style.display = 'block'; };
        reader.readAsDataURL(input.files[0]);
      }catch(err){}
    }
    document.addEventListener('change', function(e){
      if (e.target && e.target.matches('input[type="file"]')) handleChange(e);
    }, true);

    // 2) BOTÓN “+ Agregar otra foto” — crea/revela slots 3 y 4 de forma segura
    function getDocsContainer(){
      return document.querySelector('[data-gw-docs-container]') || document.getElementById('gw-docs-form') || document.querySelector('.gw-docs');
    }
    function createSlot(n){
      var c = getDocsContainer(); if (!c) return null;
      var slot = document.createElement('div');
      slot.className = 'gw-doc-slot';
      slot.setAttribute('data-slot', String(n));
      slot.innerHTML = '<label style="display:block;font-weight:600;margin:8px 0">Documento de identidad (Foto '+n+')</label>'
                     + '<input type="file" accept="image/*" name="gw_doc'+n+'" data-gw-doc="'+n+'">';
      c.appendChild(slot);
      return slot;
    }
    function ensureExtraSlots(){
      var c = getDocsContainer(); if (!c) return;
      var count = c.querySelectorAll('input[type="file"][name^="gw_doc"]').length;
      if (count < 3) createSlot(3);
      if (count < 4) createSlot(4);
    }
    document.addEventListener('click', function(e){
      var t = e.target;
      var text = (t.textContent || '').trim().toLowerCase();
      // Soporta diferentes labels/ids sin depender del HTML exacto
      if (t.id === 'gw-add-photo' || t.getAttribute('data-gw-add-doc') === '1' || text === '+ agregar otra foto' || text === 'agregar otra foto'){
        e.preventDefault();
        ensureExtraSlots();
      }
    }, true);
  })();
  </script>
  <?php
});

// === Paso 8 (Documentos y escuela): auto-upload 2.2 ===
add_action('wp_footer', function(){
  if (!is_user_logged_in()) return;
  $u = wp_get_current_user();
  if (!in_array('voluntario', (array)$u->roles, true)) return; // solo voluntarios

  // Ejecutar solo en el portal/paso 8/documentos
  $req = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
  if (strpos($req,'portal-voluntario') === false && strpos($req,'paso-8') === false && strpos($req,'documentos') === false) return;
  ?>
  <script>
  (function(){
    var AUTO_UPLOAD = false; // Pon en true si quieres subir automáticamente al elegir archivo
    if (!AUTO_UPLOAD) return;

    var nonce = "<?php echo esc_js( wp_create_nonce('gw_doc_upload') ); ?>";
    function isDocInput(el){
      if (!el || el.type !== 'file') return false;
      var n = (el.getAttribute('name') || '').toLowerCase();
      return n.indexOf('gw_doc') === 0 || el.hasAttribute('data-gw-doc');
    }
    function getSlot(el){
      var n = (el.getAttribute('name') || '').match(/gw_doc(\\d)/i);
      if (n && n[1]) return parseInt(n[1],10);
      var ds = el.getAttribute('data-gw-doc'); if (ds) return parseInt(ds,10);
      return 0;
    }

    document.addEventListener('change', function(e){
      var input = e.target;
      if (!isDocInput(input) || !input.files || !input.files[0]) return;
      var slot = getSlot(input); if (!slot) return;

      var fd = new FormData();
      fd.append('action', 'gw_doc_upload');
      fd.append('nonce', nonce);
      fd.append('slot', slot);
      fd.append('file', input.files[0]);

      fetch('<?php echo esc_js( admin_url('admin-ajax.php') ); ?>', { method:'POST', credentials:'same-origin', body:fd })
        .then(r=>r.json())
        .then(function(resp){
          if (resp && resp.success && resp.data && resp.data.url){
            // Si ya existe un <img> de preview, actualiza a la URL definitiva del adjunto
            var wrap = input.closest('.gw-doc-slot') || input.parentNode;
            var img = wrap && wrap.querySelector('.gw-doc-thumb');
            if (img) img.src = resp.data.url;
          } else {
            console.warn('Upload documentos (compat) sin éxito', resp);
          }
        })
        .catch(function(err){ console.error('Upload documentos (compat) error', err); });
    }, true);
  })();
  </script>
  <?php
});

// Crear el index.php al activar el plugin o cargar el sistema
// Crea (si no existe) el directorio /documentos-voluntarios y un index.php de seguridad
if (!function_exists('gw_crear_index_seguridad_documentos')) {
  function gw_crear_index_seguridad_documentos() {
    $upload_info = wp_upload_dir();
    $docs_dir  = trailingslashit($upload_info['basedir']) . 'documentos-voluntarios/';

    // Asegurar que el directorio exista
    if (!file_exists($docs_dir)) {
      wp_mkdir_p($docs_dir);
    }

    // Crear index.php para evitar listado de directorio
    $index_file = $docs_dir . 'index.php';
    if (file_exists($docs_dir) && !file_exists($index_file)) {
      $index_content = "<?php\n// Silence is golden\n";
      @file_put_contents($index_file, $index_content);
    }
  }
}
// Crear el index.php al activar el plugin o cargar el sistema
add_action('init', 'gw_crear_index_seguridad_documentos');

// Función para verificar permisos del directorio y corregirlos si es necesario
function gw_verificar_permisos_directorio_documentos() {
    $upload_info = wp_upload_dir();
    $docs_dir = $upload_info['basedir'] . '/documentos-voluntarios/';
    
    if (file_exists($docs_dir)) {
        $permisos_actuales = substr(sprintf('%o', fileperms($docs_dir)), -4);
        
        // Si los permisos no son adecuados, intentar corregirlos
        if ($permisos_actuales !== '0755' && $permisos_actuales !== '0775') {
            $resultado = chmod($docs_dir, 0755);
            error_log("GW Permisos - Cambio de permisos en $docs_dir: " . ($resultado ? 'exitoso' : 'falló'));
        }
        
        return [
            'exists' => true,
            'writable' => is_writable($docs_dir),
            'permisos' => $permisos_actuales
        ];
    }
    
    return [
        'exists' => false,
        'writable' => false,
        'permisos' => null
    ];
}

// Hook para verificar permisos periódicamente (solo en admin)
if (is_admin()) {
    add_action('admin_init', function() {
        // Solo verificar una vez por sesión
        if (!get_transient('gw_permisos_verificados')) {
            $estado = gw_verificar_permisos_directorio_documentos();
            
            if (!$estado['writable']) {
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-warning"><p><strong>Glasswing:</strong> El directorio de documentos no tiene permisos de escritura. Los uploads pueden fallar.</p></div>';
                });
            }
            
            // Marcar como verificado por 1 hora
            set_transient('gw_permisos_verificados', true, HOUR_IN_SECONDS);
        }
    });

    // === Paso 8 (Voluntario) - Guardar preguntas por AJAX ===
add_action('wp_ajax_gw_step8_save_answers', 'gw_step8_save_answers');
function gw_step8_save_answers(){
  if ( !is_user_logged_in() ) {
    wp_send_json_error(['msg'=>'No logueado'], 401);
  }

  $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
  if ( ! wp_verify_nonce($nonce, 'gw_step8') ) {
    wp_send_json_error(['msg'=>'Nonce inválido/expirado'], 400);
  }

  $uid = get_current_user_id();
  // answers es un objeto {pregunta_1: "...", pregunta_2: "...", ...}
  $answers = isset($_POST['answers']) && is_array($_POST['answers']) ? $_POST['answers'] : [];

  // Sanitizar todo
  $clean = [];
  foreach ($answers as $k => $v) {
    $key = sanitize_key($k);
    // acepta texto multirenglón
    $clean[$key] = wp_kses_post( (string)$v );
  }

  update_user_meta($uid, 'gw_step8_respuestas', $clean);

  wp_send_json_success(['msg'=>'Respuestas guardadas']);
}

}













// ================================
// MÓDULO: Reportes y Listados
// Visible para Administrador y Coordinador de país
// ================================

// Menú propio en el Panel Administrativo
add_action('admin_menu', function(){
    // Usamos la capability personalizada 'coordinador_pais' que ya está añadida al rol administrador
    add_menu_page(
        'Reportes y Listados',
        'Reportes',
        'coordinador_pais',
        'gw_reportes',
        'gw_reportes_render',
        'dashicons-chart-bar',
        58
    );
});

// ---------- Helpers de datos ----------
if (!function_exists('gw_rep_get_paises')) {
    function gw_rep_get_paises(){
        return get_posts(['post_type'=>'pais','numberposts'=>-1,'orderby'=>'title','order'=>'ASC']);
    }
}
if (!function_exists('gw_rep_get_proyectos')) {
    function gw_rep_get_proyectos(){
        return get_posts(['post_type'=>'proyecto','numberposts'=>-1,'orderby'=>'title','order'=>'ASC']);
    }
}
if (!function_exists('gw_rep_get_caps')) {
    function gw_rep_get_caps(){
        return get_posts(['post_type'=>'capacitacion','numberposts'=>-1,'orderby'=>'title','order'=>'ASC']);
    }
}
if (!function_exists('gw_rep_get_coaches')) {
    function gw_rep_get_coaches(){
        return get_users(['role'=>'coach','fields'=>['ID','display_name','user_email']]);
    }
}

// Cargar sesiones de una capacitación (AJAX para el select dinámico)
add_action('wp_ajax_gw_reportes_get_sesiones', function(){
    if (!current_user_can('coordinador_pais')) wp_send_json_error(['msg'=>'No autorizado']);
    $cap_id = isset($_POST['cap_id']) ? intval($_POST['cap_id']) : 0;
    if (!$cap_id) wp_send_json_success(['options' => '<option value="">Todas</option>']);

    $sesiones = get_post_meta($cap_id, '_gw_sesiones', true);
    if (!is_array($sesiones)) $sesiones = [];
    $html = '<option value="">Todas</option>';
    foreach ($sesiones as $idx => $s) {
        $mod = isset($s['modalidad']) ? $s['modalidad'] : '';
        $f   = isset($s['fecha']) ? $s['fecha'] : '';
        $h   = isset($s['hora']) ? $s['hora'] : '';
        $label = 'Opción '.($idx+1).' — '.esc_html($f.' '.$h).' ('.esc_html($mod).')';
        $html .= '<option value="'.esc_attr($idx).'">'.$label.'</option>';
    }
    wp_send_json_success(['options'=>$html]);
});

// Función central: generar filas según filtros
if (!function_exists('gw_reportes_query_rows')) {
function gw_reportes_query_rows($filtros){
    $rows = [];

    // Usuarios objetivo: voluntarios del país (si se selecciona)
    $user_args = [
        'role'   => 'voluntario',
        'number' => -1,
        'fields' => ['ID','display_name','user_email']
    ];
    if (!empty($filtros['pais_id'])) {
        $user_args['meta_key']   = 'gw_pais_id';
        $user_args['meta_value'] = intval($filtros['pais_id']);
    }
    $users = get_users($user_args);

    $now_ts = current_time('timestamp');

    foreach ($users as $u) {
        $uid = $u->ID;
        $activo = get_user_meta($uid, 'gw_active', true);
        if ($activo === '') $activo = '1';

        // Obtener capacitación agendada (nueva clave preferida)
        $ag = get_user_meta($uid, 'gw_capacitacion_agendada', true);
        $cap_id = 0; $fecha = ''; $hora = ''; $sesion_idx = null;

        if (is_array($ag) && !empty($ag['cap_id'])) {
            $cap_id = intval($ag['cap_id']);
            $sesion_idx = isset($ag['idx']) ? intval($ag['idx']) : null;
            if (!empty($ag['fecha'])) $fecha = sanitize_text_field($ag['fecha']);
            if (!empty($ag['hora']))  $hora  = sanitize_text_field($ag['hora']);
        } else {
            // Compatibilidad con metadatos antiguos
            $cap_id = intval(get_user_meta($uid, 'gw_capacitacion_id', true));
            $fecha  = sanitize_text_field(get_user_meta($uid, 'gw_fecha', true));
            $hora   = sanitize_text_field(get_user_meta($uid, 'gw_hora', true));
            // Intentar derivar idx comparando con sesiones guardadas
            if ($cap_id && $fecha && $hora) {
                $sesiones_tmp = get_post_meta($cap_id, '_gw_sesiones', true);
                if (is_array($sesiones_tmp)) {
                    foreach ($sesiones_tmp as $i => $s) {
                        if ((isset($s['fecha']) && $s['fecha'] === $fecha) && (isset($s['hora']) && $s['hora'] === $hora)) {
                            $sesion_idx = $i; break;
                        }
                    }
                }
            }
        }

        // Filtros por proyecto/capacitacion/sesion/coach
        if (!empty($filtros['cap_id']) && intval($filtros['cap_id']) !== $cap_id) continue;

        // Si hay proyecto, la capacitación del usuario debe pertenecer a ese proyecto
        if (!empty($filtros['proyecto_id']) && $cap_id) {
            $proy_cap = intval(get_post_meta($cap_id, '_gw_proyecto_relacionado', true));
            if ($proy_cap !== intval($filtros['proyecto_id'])) continue;
        }

        // Filtro coach (responsable)
        if (!empty($filtros['coach_id']) && $cap_id) {
            $coach_cap = intval(get_post_meta($cap_id, '_gw_coach_asignado', true));
            if ($coach_cap !== intval($filtros['coach_id'])) continue;
        }

        // Filtro sesión
        if ($filtros['sesion_idx'] !== '' && $filtros['sesion_idx'] !== null) {
            if ($sesion_idx === null || intval($filtros['sesion_idx']) !== intval($sesion_idx)) continue;
        }

        $pais_id = get_user_meta($uid, 'gw_pais_id', true);
        $pais_titulo = $pais_id ? get_the_title($pais_id) : '—';

        $cap_title = $cap_id ? get_the_title($cap_id) : '—';
        $proy_title = $cap_id ? (get_the_title(intval(get_post_meta($cap_id, '_gw_proyecto_relacionado', true))) ?: '—') : '—';
        $coach_id = $cap_id ? intval(get_post_meta($cap_id, '_gw_coach_asignado', true)) : 0;
        $coach_name = $coach_id ? (get_user_by('id',$coach_id)->display_name ?: '—') : '—';

        // Estado calculado
        $estado = '—';
        if ($activo === '0') {
            $estado = 'Inactivo';
        } else if ($cap_id && $fecha && $hora) {
            $ts = strtotime($fecha.' '.$hora);
            $step7_done = get_user_meta($uid, 'gw_step7_completo', true);
            if ($ts && $ts <= $now_ts) {
                $estado = $step7_done ? 'Asistió' : 'Ausente';
            } else {
                $estado = 'Inscrito';
            }
        }

        // Filtro por estado
        if (!empty($filtros['estado'])) {
            if (strtolower($filtros['estado']) !== strtolower($estado)) continue;
        }

        // Sesión label
        $ses_label = '—';
        if ($cap_id) {
            $ses = get_post_meta($cap_id, '_gw_sesiones', true);
            if (is_array($ses) && $sesion_idx !== null && isset($ses[$sesion_idx])) {
                $sx = $ses[$sesion_idx];
                $mod = isset($sx['modalidad']) ? $sx['modalidad'] : '';
                $ff  = isset($sx['fecha']) ? $sx['fecha'] : $fecha;
                $hh  = isset($sx['hora']) ? $sx['hora'] : $hora;
                $extra = (strtolower($mod)==='virtual') ? 'Virtual/Google Meet' : 'Presencial';
                $ses_label = 'Opción '.($sesion_idx+1).' — '.$ff.' '.$hh.' ('.$extra.')';
            } elseif ($fecha || $hora) {
                $ses_label = trim($fecha.' '.$hora);
            }
        }

        $rows[] = [
            'user_id'   => $uid,
            'nombre'    => $u->display_name ?: $u->user_login,
            'email'     => $u->user_email,
            'pais'      => $pais_titulo,
            'proyecto'  => $proy_title,
            'cap'       => $cap_title,
            'sesion'    => $ses_label,
            'estado'    => $estado,
            'fecha'     => $fecha ?: '—',
            'hora'      => $hora ?: '—',
            'coach'     => $coach_name,
        ];
    }

    return $rows;
}
}

// ---------- Render de página ----------
if (!function_exists('gw_reportes_render')) {
function gw_reportes_render(){
    if (!current_user_can('coordinador_pais')) {
        wp_die('No autorizado.');
    }

    $paises    = gw_rep_get_paises();
    $proyectos = gw_rep_get_proyectos();
    $caps      = gw_rep_get_caps();
    $coaches   = gw_rep_get_coaches();

    $f = [
        'pais_id'     => isset($_GET['pais_id']) ? intval($_GET['pais_id']) : 0,
        'proyecto_id' => isset($_GET['proyecto_id']) ? intval($_GET['proyecto_id']) : 0,
        'cap_id'      => isset($_GET['cap_id']) ? intval($_GET['cap_id']) : 0,
        'sesion_idx'  => isset($_GET['sesion_idx']) ? (($_GET['sesion_idx'] === '' ? '' : intval($_GET['sesion_idx']))) : '',
        'coach_id'    => isset($_GET['coach_id']) ? intval($_GET['coach_id']) : 0,
        'estado'      => isset($_GET['estado']) ? sanitize_text_field($_GET['estado']) : '',
    ];

    $rows = [];
    $generated = isset($_GET['generar']) ? 1 : 0;
    if ($generated) {
        $rows = gw_reportes_query_rows($f);
    }

    $nonce = wp_create_nonce('gw_reportes_export');
    ?>
    <div class="wrap">
      <h1 class="wp-heading-inline">Reportes y Listados</h1>
      <hr class="wp-header-end">

      <form method="get" action="">
        <input type="hidden" name="page" value="gw_reportes">
        <table class="form-table">
          <tbody>
            <tr>
              <th scope="row"><label for="pais_id">País</label></th>
              <td>
                <select id="pais_id" name="pais_id">
                  <option value="">Todos</option>
                  <?php foreach ($paises as $p): ?>
                    <option value="<?php echo (int)$p->ID; ?>" <?php selected($f['pais_id'],$p->ID); ?> ><?php echo esc_html($p->post_title); ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
            </tr>
            <tr>
              <th scope="row"><label for="proyecto_id">Programa/Proyecto</label></th>
              <td>
                <select id="proyecto_id" name="proyecto_id">
                  <option value="">Todos</option>
                  <?php foreach ($proyectos as $pr): ?>
                    <option value="<?php echo (int)$pr->ID; ?>" <?php selected($f['proyecto_id'],$pr->ID); ?> ><?php echo esc_html($pr->post_title); ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
            </tr>
            <tr>
              <th scope="row"><label for="cap_id">Capacitación</label></th>
              <td>
                <select id="cap_id" name="cap_id">
                  <option value="">Todas</option>
                  <?php foreach ($caps as $c): ?>
                    <option value="<?php echo (int)$c->ID; ?>" <?php selected($f['cap_id'],$c->ID); ?> ><?php echo esc_html($c->post_title); ?></option>
                  <?php endforeach; ?>
                </select>
                &nbsp;&nbsp;
                <label for="sesion_idx"><strong>Sesión</strong></label>
                <select id="sesion_idx" name="sesion_idx">
                  <option value="">Todas</option>
                </select>
              </td>
            </tr>
            <tr>
              <th scope="row"><label for="coach_id">Responsable (Coach)</label></th>
              <td>
                <select id="coach_id" name="coach_id">
                  <option value="">Todos</option>
                  <?php foreach ($coaches as $ch): ?>
                    <option value="<?php echo (int)$ch->ID; ?>" <?php selected($f['coach_id'],$ch->ID); ?> ><?php echo esc_html($ch->display_name); ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
            </tr>
            <tr>
              <th scope="row"><label for="estado">Estado</label></th>
              <td>
                <select id="estado" name="estado">
                  <option value="">Todos</option>
                  <option value="Inscrito" <?php selected($f['estado'],'Inscrito'); ?>>Inscrito</option>
                  <option value="Asistió" <?php selected($f['estado'],'Asistió'); ?>>Asistió</option>
                  <option value="Ausente" <?php selected($f['estado'],'Ausente'); ?>>Ausente</option>
                  <option value="Inactivo" <?php selected($f['estado'],'Inactivo'); ?>>Inactivo</option>
                </select>
              </td>
            </tr>
          </tbody>
        </table>
        <p class="submit">
          <button type="submit" name="generar" value="1" class="button button-primary">Generar</button>
        </p>
      </form>

      <?php if ($generated): ?>
        <hr>
        <h2>Resultados</h2>

        <div style="overflow-x:auto; max-width:100%;">
        <table class="widefat striped">
          <thead>
            <tr>
              <th>Nombre</th>
              <th>Correo</th>
              <th>País</th>
              <th>Proyecto</th>
              <th>Capacitación</th>
              <th>Sesión</th>
              <th>Estado</th>
              <th>Fecha</th>
              <th>Hora</th>
              <th>Coach</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($rows)): ?>
            <tr><td colspan="10">No se encontraron registros con los filtros seleccionados.</td></tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
            <tr>
              <td><?php echo esc_html($r['nombre']); ?></td>
              <td><?php echo esc_html($r['email']); ?></td>
              <td><?php echo esc_html($r['pais']); ?></td>
              <td><?php echo esc_html($r['proyecto']); ?></td>
              <td><?php echo esc_html($r['cap']); ?></td>
              <td><?php echo esc_html($r['sesion']); ?></td>
              <td><?php echo esc_html($r['estado']); ?></td>
              <td><?php echo esc_html($r['fecha']); ?></td>
              <td><?php echo esc_html($r['hora']); ?></td>
              <td><?php echo esc_html($r['coach']); ?></td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
        </div>

        <?php if (!empty($rows)): ?>
        <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="margin-top:14px; display:flex; gap:8px; flex-wrap:wrap;">
          <?php foreach ($f as $k => $v): ?>
            <input type="hidden" name="<?php echo esc_attr($k); ?>" value="<?php echo esc_attr($v); ?>" />
          <?php endforeach; ?>
          <input type="hidden" name="action" value="gw_reportes_export" />
          <input type="hidden" name="nonce" value="<?php echo esc_attr($nonce); ?>" />
          <button type="submit" name="format" value="csv" class="button">Exportar CSV</button>
          <button type="submit" name="format" value="xls" class="button">Exportar Excel</button>
          <button type="submit" name="format" value="pdf" class="button">Exportar PDF</button>
        </form>
        <?php endif; ?>

      <?php endif; ?>
    </div>

    <script>
    (function(){
      // Cargar sesiones cuando cambia la capacitación
      var capSel = document.getElementById('cap_id');
      var sesSel = document.getElementById('sesion_idx');
      function cargarSesiones(){
        sesSel.innerHTML = '<option value="">Cargando…</option>';
        var data = new FormData();
        data.append('action','gw_reportes_get_sesiones');
        data.append('cap_id', capSel.value || '');
        fetch(ajaxurl, { method:'POST', credentials:'same-origin', body:data })
        .then(r => r.json())
        .then(j => {
          if (j && j.success) {
            sesSel.innerHTML = j.data.options;
            // Reseleccionar si venía valor en la URL
            var preset = '<?php echo isset($_GET['sesion_idx']) ? esc_js($_GET['sesion_idx']) : ''; ?>';
            if (preset !== '') { sesSel.value = preset; }
          } else {
            sesSel.innerHTML = '<option value="">Todas</option>';
          }
        })
        .catch(() => { sesSel.innerHTML = '<option value="">Todas</option>'; });
      }
      if (capSel) {
        capSel.addEventListener('change', cargarSesiones);
        // Inicial
        cargarSesiones();
      }
    })();
    </script>
    <?php
}
}

// === REPORTES Y LISTADOS PASO 8: handler AJAX ===
add_action('wp_ajax_gw_reports_generate', 'gw_reports_generate_handler');
function gw_reports_generate_handler(){
  if ( !is_user_logged_in() || !( current_user_can('manage_options') || current_user_can('coordinador_pais') ) ) {
    wp_send_json_error(['msg' => 'No autorizado']);
  }

  $rows = gw_reports_collect_capacitaciones([
    'pais_id'     => intval($_POST['pais'] ?? 0),
    'proyecto_id' => intval($_POST['proyecto'] ?? 0),
    'cap_id'      => intval($_POST['cap'] ?? 0),
    'estado'      => sanitize_text_field($_POST['estado'] ?? 'todos'),     // activos|inactivos|todos
    'asistencia'  => sanitize_text_field($_POST['asistencia'] ?? 'todas'), // asistio|no|todas|pendiente
    'desde'       => sanitize_text_field($_POST['desde'] ?? ''),
    'hasta'       => sanitize_text_field($_POST['hasta'] ?? ''),
  ]);

  wp_send_json_success(['rows' => $rows]);
}

// ===== Helpers para Reportes y Listados =====
if (!function_exists('gw_reports_parse_date')) {
  function gw_reports_parse_date($s){
    $s = trim((string)$s);
    if ($s === '') return 0;
    // dd/mm/YYYY
    if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $s)) {
      list($d,$m,$y) = array_map('intval', explode('/', $s));
      return mktime(0,0,0,$m,$d,$y);
    }
    // YYYY-mm-dd
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
      list($y,$m,$d) = array_map('intval', explode('-', $s));
      return mktime(0,0,0,$m,$d,$y);
    }
    $ts = strtotime($s);
    return $ts ? $ts : 0;
  }
}

// ---- Helper: normaliza un item de historial ----
if (!function_exists('gw_reports_normalize_hist_item')) {
  function gw_reports_normalize_hist_item($it) {
    $cap_id = intval($it['cap_id'] ?? ($it['capacitacion_id'] ?? ($it['id'] ?? 0)));
    $fecha  = isset($it['fecha']) ? sanitize_text_field($it['fecha']) : '';
    $hora   = isset($it['hora'])  ? sanitize_text_field($it['hora'])  : '';
    if (!$fecha && !empty($it['fecha_hora'])) {
      $ts = strtotime($it['fecha_hora']);
      if ($ts) { $fecha = date('Y-m-d', $ts); $hora = date('H:i', $ts); }
    }
    // true/1/"si"/"asistio" => '1' | false/0/"no" => '0' | sin dato => ''
    $asis = null;
    if (isset($it['asistio']))        { $asis = $it['asistio']; }
    elseif (isset($it['asistencia'])) { $asis = $it['asistencia']; }
    $asis = ($asis === true || $asis === 1 || $asis === '1' || strtolower((string)$asis) === 'si' || strtolower((string)$asis) === 'asistio') ? '1'
          : (($asis === null) ? '' : '0');
    return ['cap_id'=>$cap_id, 'fecha'=>$fecha, 'hora'=>$hora, 'asistio'=>$asis];
  }
}

// ---- Recolector de filas para CAPACITACIONES (usado por Generar y Exportar) ----
if (!function_exists('gw_reports_collect_capacitaciones')) {
  function gw_reports_collect_capacitaciones($args) {
    $pais_id      = intval($args['pais_id']      ?? 0);
    $proyecto_id  = intval($args['proyecto_id']  ?? 0);
    $cap_id_filt  = intval($args['cap_id']       ?? 0);
    $estado_filt  = sanitize_text_field($args['estado']     ?? 'todos');   // activos|inactivos|todos
    $asis_filt    = sanitize_text_field($args['asistencia'] ?? 'todas');   // asistio|no|todas|pendiente
    $desde        = sanitize_text_field($args['desde'] ?? '');
    $hasta        = sanitize_text_field($args['hasta'] ?? '');
    $desde_ts     = $desde ? strtotime($desde.' 00:00:00') : 0;
    $hasta_ts     = $hasta ? strtotime($hasta.' 23:59:59') : 0;

    // Query base de usuarios (principalmente voluntarios; puedes ampliar si quieres coach/coordinador)
    $mq = [];
    if ($pais_id)                   $mq[] = ['key'=>'gw_pais_id','value'=>$pais_id,'compare'=>'='];
    if ($estado_filt==='activos')   $mq[] = ['key'=>'gw_active','value'=>'1','compare'=>'='];
    if ($estado_filt==='inactivos') $mq[] = ['key'=>'gw_active','value'=>'0','compare'=>'='];

    $users = get_users([
      'fields'     => ['ID','display_name','user_email','roles'],
      'meta_query' => $mq,
      // incluimos voluntarios y demás (admin queda por si haces pruebas)
      'role__in'   => ['voluntario','coach','coordinador_pais','administrator'],
    ]);

    $rows = [];
    foreach ($users as $u) {
      $uid        = $u->ID;
      $pais_title = get_the_title((int) get_user_meta($uid,'gw_pais_id',true)) ?: '—';
      $estado_u   = get_user_meta($uid,'gw_active',true); if ($estado_u==='') $estado_u='1';

      // 1) historial (claves posibles)
      $items = [];
      // 0) Inscripción actual agendada (si existe)
        $ag = get_user_meta($uid, 'gw_capacitacion_agendada', true);
          if (is_array($ag) && !empty($ag['cap_id'])) {
            $items[] = gw_reports_normalize_hist_item([
            'cap_id'  => $ag['cap_id'],
            'fecha'   => $ag['fecha'] ?? '',
            'hora'    => $ag['hora']  ?? '',
            // si completó el paso 7 lo tomamos como que asistió
            'asistio' => get_user_meta($uid, 'gw_step7_completo', true) ? '1' : '',
          ]);     
        } 
      foreach (['gw_caps_historial','gw_capacitaciones','gw_capacitaciones_historial','gw_caps_registros'] as $k) {
        $arr = get_user_meta($uid, $k, true);
        if (is_array($arr) && $arr) {
          foreach ($arr as $it) {
            $norm = gw_reports_normalize_hist_item($it);
            if ($norm['cap_id']) $items[] = $norm;
          }
        }
      }
      // 2) fallback “simple”
      $single_cap = (int) get_user_meta($uid,'gw_capacitacion_id',true);
      if ($single_cap) {
        $items[] = [
          'cap_id'  => $single_cap,
          'fecha'   => sanitize_text_field(get_user_meta($uid,'gw_fecha',true)),
          'hora'    => sanitize_text_field(get_user_meta($uid,'gw_hora',true)),
          'asistio' => (string) get_user_meta($uid,'gw_asistio',true),
        ];
      }
      if (!$items) continue;

      foreach ($items as $it) {
        $cid = (int) $it['cap_id'];
        if (!$cid) continue;
        if ($cap_id_filt && $cid !== $cap_id_filt) continue;

        $proj_id  = (int) get_post_meta($cid,'_gw_proyecto_relacionado',true);
        if ($proyecto_id && $proj_id !== $proyecto_id) continue;

        $pais_cap = (int) get_post_meta($cid,'_gw_pais_relacionado',true);
        if ($pais_id && $pais_cap && $pais_cap !== $pais_id) continue;

        $fecha = $it['fecha']; $hora = $it['hora'];
        $ts = strtotime(trim($fecha.' '.$hora));
        if ($desde_ts && $ts && $ts < $desde_ts) continue;
        if ($hasta_ts && $ts && $ts > $hasta_ts) continue;

        // Filtro asistencia: acepta 'asistio', 'no', 'pendiente', 'todas'
        // (si no hay dato, tratamos como pendiente)
        $asis_bool = ($it['asistio']==='1');
        if ($asis_filt==='asistio' && !$asis_bool) continue;
        if (($asis_filt==='no' || $asis_filt==='no_asistio' || $asis_filt==='pendiente') && $asis_bool) continue;

        $rows[] = [
          'nombre'     => $u->display_name ?: $u->user_email,
          'email'      => $u->user_email,
          'pais'       => $pais_title,
          'proyecto'   => $proj_id ? get_the_title($proj_id) : '—',
          'cap'        => get_the_title($cid),
          'fecha'      => $fecha ?: ($ts ? date_i18n('Y-m-d',$ts) : ''),
          'hora'       => $hora  ?: ($ts ? date_i18n('H:i',$ts)   : ''),
          'estado'     => ($estado_u==='1' ? 'Activo' : 'Inactivo'),
          'asistencia' => ($asis_bool ? 'Asistió' : 'No asistió'),
        ];
      }
    }
    return $rows;
  }
}

if (!function_exists('gw_reports_collect_cap_records')) {
  /**
   * Devuelve un arreglo de registros de capacitaciones de un usuario.
   * Intenta varios metakeys posibles y hace fallback a la inscripción actual.
   * Cada item: ['cap_id'=>int,'fecha'=>'Y-m-d'| 'd/m/Y','hora'=>'HH:MM','asistio'=>0/1]
   */
  function gw_reports_collect_cap_records($uid){
    $out = [];

    // 1) Nuevo formato: inscripción agendada actual
    $ag = get_user_meta($uid, 'gw_capacitacion_agendada', true);
    if (is_array($ag) && !empty($ag['cap_id'])) {
        $out[] = [
            'cap_id'  => intval($ag['cap_id']),
            'fecha'   => isset($ag['fecha']) ? (string)$ag['fecha'] : '',
            'hora'    => isset($ag['hora'])  ? (string)$ag['hora']  : '',
            'asistio' => get_user_meta($uid, 'gw_step7_completo', true) ? 1 : 0,
        ];

    // 2) Históricos conocidos (retrocompatibilidad)
    $candidates = [
      'gw_caps_hist','gw_caps_history','gw_capacitaciones_historial',
      'gw_cap_logs','gw_caps_log','gw_caps_registros',
      // otros nombres frecuentes en instalaciones previas
      'gw_capacitaciones','gw_cap_historial','gw_registros_capacitaciones'
    ];
    foreach ($candidates as $k) {
      $val = get_user_meta($uid, $k, true);
      // Deduplicar items por cap_id|fecha|hora (dentro del usuario)
        if ($items) {
            $uniq = [];
        foreach ($items as $it) {
                $key = intval($it['cap_id']).'|'.($it['fecha'] ?? '').'|'.($it['hora'] ?? '');
                $uniq[$key] = $it;
              }
                $items = array_values($uniq);
        } 
      if (is_array($val)) {
        foreach ($val as $row) {
          if (!is_array($row)) continue;
          if (!isset($row['cap_id']) && !isset($row['capacitacion_id'])) continue;
          $out[] = [
            'cap_id'  => intval($row['cap_id'] ?? $row['capacitacion_id']),
            'fecha'   => isset($row['fecha']) ? (string)$row['fecha'] : '',
            'hora'    => isset($row['hora'])  ? (string)$row['hora']  : '',
            'asistio' => isset($row['asistio']) ? intval($row['asistio']) : -1,
          ];
        }
      }
    }

    // 3) Fallback antiguo simple (metakeys sueltas)
    $cap_id = get_user_meta($uid, 'gw_capacitacion_id', true);
    $fecha  = get_user_meta($uid, 'gw_fecha', true);
    $hora   = get_user_meta($uid, 'gw_hora', true);
    if ($cap_id && $fecha) {
      $out[] = [
        'cap_id'  => intval($cap_id),
        'fecha'   => (string)$fecha,
        'hora'    => (string)$hora,
        'asistio' => get_user_meta($uid, 'gw_step7_completo', true) ? 1 : 0,
      ];
    }

    // 4) Deduplicar por cap_id|fecha|hora
    if ($out) {
      $uniq = [];
      foreach ($out as $r) {
        $key = intval($r['cap_id']).'|'.($r['fecha'] ?? '').'|'.($r['hora'] ?? '');
        $uniq[$key] = $r;
      }
      $out = array_values($uniq);
    }

    return $out;
}
}}

// ===== AJAX: generar tabla HTML de Reportes =====
add_action('wp_ajax_gw_reports_fetch', 'gw_reports_fetch');
add_action('wp_ajax_nopriv_gw_reports_fetch', 'gw_reports_fetch');
function gw_reports_fetch(){
  if ( !isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'gw_reports') ) {
    wp_die('Nonce inválido');
  }
  if (!current_user_can('manage_options') && !current_user_can('coordinador_pais')) {
    wp_send_json_error('No autorizado');
  }

  $tipo     = isset($_POST['tipo'])     ? sanitize_text_field($_POST['tipo']) : 'capacitacion';
  $pais_id  = isset($_POST['pais_id'])  ? intval($_POST['pais_id']) : 0;
  $estado   = isset($_POST['estado'])   ? sanitize_text_field($_POST['estado']) : 'todos';
  $asis_flt = isset($_POST['asistencia']) ? sanitize_text_field($_POST['asistencia']) : 'todas';
  $cap_id_f = isset($_POST['cap_id'])   ? intval($_POST['cap_id']) : 0; // 0 = todas
  $proj_id  = isset($_POST['proyecto_id']) ? intval($_POST['proyecto_id']) : 0;
  $desde    = isset($_POST['desde'])    ? sanitize_text_field($_POST['desde']) : '';
  $hasta    = isset($_POST['hasta'])    ? sanitize_text_field($_POST['hasta']) : '';

  $from_ts = gw_reports_parse_date($desde);
  $to_ts   = gw_reports_parse_date($hasta);
  if ($to_ts) $to_ts += DAY_IN_SECONDS - 1; // inclusivo

  // Filtra capacitaciones por país/proyecto si aplica
  $cap_ids_scope = [];
  if ($tipo === 'capacitacion') {
    $args = [
      'post_type'   => 'capacitacion',
      'post_status' => 'publish',
      'numberposts' => -1,
    ];
    $meta_query = [];
    if ($pais_id)  $meta_query[] = ['key' => '_gw_pais_relacionado',  'value' => $pais_id, 'compare' => '='];
    if ($proj_id)  $meta_query[] = ['key' => '_gw_proyecto_relacionado','value' => $proj_id, 'compare' => '='];
    if (!empty($meta_query)) $args['meta_query'] = $meta_query;

    $caps = get_posts($args);
    foreach ($caps as $c) $cap_ids_scope[] = intval($c->ID);

    // Si el filtro de "cap_id" viene explícito, limitar a ese
    if ($cap_id_f) {
      if (!in_array($cap_id_f, $cap_ids_scope, true)) {
        // el cap no pertenece al país/proyecto -> no habrá resultados
        $cap_ids_scope = [$cap_id_f];
      } else {
        $cap_ids_scope = [$cap_id_f];
      }
    }
  }

  // Usuarios a considerar
  $user_args = [
    'role__in' => ['voluntario','coach','coordinador_pais','administrator'], // por si quieres incluir staff en reportes
    'fields'   => ['ID','user_email','display_name']
  ];
  if ($pais_id) {
    $user_args['meta_key']   = 'gw_pais_id';
    $user_args['meta_value'] = $pais_id;
  }
  $users = get_users($user_args);

  ob_start();
  ?>
  <table class="widefat striped">
    <thead>
      <tr>
        <th>Nombre</th>
        <th>Email</th>
        <th>País</th>
        <th>Proyecto</th>
        <th>Capacitación</th>
        <th>Fecha</th>
        <th>Hora</th>
        <th>Estado</th>
        <th>Asistencia</th>
      </tr>
    </thead>
    <tbody>
  <?php

  $row_count = 0;
  foreach ($users as $u) {
    // Estado del usuario
    $active = get_user_meta($u->ID, 'gw_active', true);
    if ($active === '') $active = '1';
    if ($estado === 'activos'   && $active !== '1') continue;
    if ($estado === 'inactivos' && $active !== '0') continue;

    $pais_titulo = '—';
    $upais = get_user_meta($u->ID, 'gw_pais_id', true);
    if ($upais) $pais_titulo = get_the_title($upais);

    // Registros de capacitaciones
    $records = gw_reports_collect_cap_records($u->ID);
    if (!$records) continue;

    foreach ($records as $rec) {
      $cap_id = intval($rec['cap_id']);
      if ($tipo === 'capacitacion') {
        if (!empty($cap_ids_scope) && $cap_id && !in_array($cap_id, $cap_ids_scope, true)) {
          continue; // fuera del alcance país/proyecto/capacitación
        }
      }

      $cap_title = $cap_id ? get_the_title($cap_id) : '—';
      $proj_t    = '—';
      if ($cap_id) {
        $pid = get_post_meta($cap_id, '_gw_proyecto_relacionado', true);
        if ($proj_id && intval($pid) !== $proj_id) continue; // seguridad extra
        $proj_t = $pid ? get_the_title($pid) : '—';
      }

      // Fecha/hora
      $fh_ts = 0;
      $f_raw = isset($rec['fecha']) ? $rec['fecha'] : '';
      $h_raw = isset($rec['hora'])  ? $rec['hora']  : '';
      if ($f_raw) {
        // 'YYYY-mm-dd' o 'dd/mm/YYYY'
        $base = gw_reports_parse_date($f_raw);
        if ($base) {
          if (preg_match('/^\d{1,2}:\d{2}/', $h_raw)) {
            list($hh,$mm) = array_pad(explode(':', $h_raw), 2, 0);
            $fh_ts = $base + (intval($hh)*3600 + intval($mm)*60);
          } else {
            $fh_ts = $base;
          }
        } else {
          // Intento directo
          $fh_ts = strtotime(trim($f_raw.' '.$h_raw));
        }
      }
      if ($from_ts && $fh_ts && $fh_ts < $from_ts) continue;
      if ($to_ts   && $fh_ts && $fh_ts > $to_ts) continue;

      // Filtro asistencia
      $asis = isset($rec['asistio']) ? intval($rec['asistio']) : -1; // -1 = desconocido
      if     ($asis_flt === 'asistio'    && $asis !== 1)  continue;
      elseif ($asis_flt === 'no_asistio' && $asis === 1) continue;

      $row_count++;
      ?>
      <tr>
        <td><?php echo esc_html($u->display_name ?: $u->user_email); ?></td>
        <td><?php echo esc_html($u->user_email); ?></td>
        <td><?php echo esc_html($pais_titulo); ?></td>
        <td><?php echo esc_html($proj_t); ?></td>
        <td><?php echo esc_html($cap_title); ?></td>
        <td><?php echo $fh_ts ? esc_html(date_i18n('d/m/Y', $fh_ts)) : '—'; ?></td>
        <td><?php echo $fh_ts ? esc_html(date_i18n('H:i', $fh_ts)) : '—'; ?></td>
        <td><?php echo $active === '1' ? 'Activo' : 'Inactivo'; ?></td>
        <td>
          <?php
            if ($asis === 1) echo 'Asistió';
            elseif ($asis === 0) echo 'No asistió';
            else echo '—';
          ?>
        </td>
      </tr>
      <?php
    }
  }

  if ($row_count === 0) {
    echo '<tr><td colspan="9">Sin resultados con los filtros seleccionados.</td></tr>';
  }
  ?>
    </tbody>
  </table>
  <?php
  echo ob_get_clean();
  wp_die();
}

// ===== AJAX: exportar CSV =====
add_action('wp_ajax_gw_reports_export', 'gw_reports_export');
function gw_reports_export(){
  if (!current_user_can('manage_options') && !current_user_can('coordinador_pais')) {
    wp_die('No autorizado');
  }

  // Reusar el mismo fetch pero produciendo CSV
  // Para simplificar, llamamos a gw_reports_fetch() capturando su salida HTML y lo convertimos a CSV básico.
  // (Si ya tienes una lógica nativa para CSV, puedes sustituir esta parte.)
  ob_start();
  $_POST['page'] = 1;
  gw_reports_fetch();
  $html = ob_get_clean();

  // Extraer filas del HTML
  $dom = new DOMDocument();
  libxml_use_internal_errors(true);
  $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
  libxml_clear_errors();

  $rows = $dom->getElementsByTagName('tr');

  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=reportes.csv');

  $out = fopen('php://output', 'w');
  foreach ($rows as $r) {
    $cols = $r->getElementsByTagName('th');
    if ($cols->length === 0) $cols = $r->getElementsByTagName('td');
    $row = [];
    foreach ($cols as $c) {
      $row[] = trim($c->textContent);
    }
    if (!empty($row)) fputcsv($out, $row);
  }
  fclose($out);
  exit;
}

// ====== MÓDULO REPORTES Y LISTADOS: AJAX PASO 8 (Capacitaciones + Charlas) ======
if (!function_exists('gw_reports_fetch')) {
    add_action('wp_ajax_gw_reports_fetch', 'gw_reports_fetch');
    function gw_reports_fetch(){
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'gw_reports')) { wp_die('Nonce inválido'); }
        if (!current_user_can('manage_options') && !current_user_can('coordinador_pais')) { wp_die('No autorizado'); }
    
        $tipo   = sanitize_text_field($_POST['tipo'] ?? 'capacitacion');
        $paisId = intval($_POST['pais_id'] ?? 0);
        $estado = sanitize_text_field($_POST['estado'] ?? ''); // activo|inactivo|''
        $page   = max(1, intval($_POST['page'] ?? 1));
        $per    = 50; $offset = ($page-1)*$per;
    
        $mq = [];
        if ($paisId) { $mq[] = ['key' => 'gw_pais_id', 'value' => $paisId, 'compare' => '=']; }
        if ($estado === 'activo' || $estado === 'inactivo') {
            $mq[] = ['key' => 'gw_active', 'value' => ($estado==='activo' ? '1' : '0'), 'compare' => '='];
        }
    
        $args = [
          'role'       => 'voluntario',
          'number'     => $per,
          'offset'     => $offset,
          'fields'     => ['ID','user_email','display_name'],
          'meta_query' => $mq
        ];
        $users = get_users($args);
        $total = count_users()['avail_roles']['voluntario'] ?? 0; // aprox para paginación
    
        ob_start();
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>Nombre</th><th>Email</th><th>País</th><th>Estado</th>';
        if ($tipo === 'charla' || $tipo === 'charlas') {
            echo '<th>Charlas asignadas</th>';
        } else {
            echo '<th>Capacitación</th><th>Fecha</th><th>Hora</th>';
        }
        echo '</tr></thead><tbody>';
    
        foreach ($users as $u) {
            $pais_id = get_user_meta($u->ID, 'gw_pais_id', true);
            $pais_t  = $pais_id ? get_the_title($pais_id) : '—';
            $act     = get_user_meta($u->ID, 'gw_active', true); if ($act==='') $act='1';
            $badge   = $act==='1' ? 'Activo' : 'Inactivo';
    
            echo '<tr>';
            echo '<td>'.esc_html($u->display_name ?: $u->user_email).'</td>';
            echo '<td>'.esc_html($u->user_email).'</td>';
            echo '<td>'.esc_html($pais_t).'</td>';
            echo '<td>'.esc_html($badge).'</td>';
    
            if ($tipo === 'charla' || $tipo === 'charlas') {
                $asig = get_user_meta($u->ID, 'gw_charlas_asignadas', true);
                if (!is_array($asig)) { $asig = []; }
                $out = [];
                foreach ($asig as $key) {
                    $done = get_user_meta($u->ID, 'gw_'.$key, true) ? '✅' : '❌';
                    $out[] = esc_html(ucfirst($key)).' '.$done;
                }
                echo '<td>'.($out ? implode('<br>', $out) : '—').'</td>';
            } else {
                $cap_id = get_user_meta($u->ID, 'gw_capacitacion_id', true);
                $cap_t  = $cap_id ? get_the_title($cap_id) : '—';
                $fecha  = get_user_meta($u->ID, 'gw_fecha', true) ?: '—';
                $hora   = get_user_meta($u->ID, 'gw_hora', true) ?: '—';
                echo '<td>'.esc_html($cap_t).'</td><td>'.esc_html($fecha).'</td><td>'.esc_html($hora).'</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
    
        // Paginación simple
        $prev = max(1, $page-1); $next = $page+1;
        echo '<p style="margin-top:12px">';
        if ($page>1) echo '<a href="#" class="gwRepPag button" data-p="'.$prev.'">Anterior</a> ';
        if ($offset + $per < $total) echo '<a href="#" class="gwRepPag button" data-p="'.$next.'">Siguiente</a>';
        echo '</p>';
    
        echo ob_get_clean();
        wp_die();
    }
    }
    
    if (!function_exists('gw_reports_export')) {
    add_action('wp_ajax_gw_reports_export', 'gw_reports_export');
    function gw_reports_export(){
        if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'gw_reports')) { wp_die('Nonce inválido'); }
        if (!current_user_can('manage_options') && !current_user_can('coordinador_pais')) { wp_die('No autorizado'); }
    
        $tipo   = sanitize_text_field($_GET['tipo'] ?? 'capacitacion');
        $paisId = intval($_GET['pais_id'] ?? 0);
        $estado = sanitize_text_field($_GET['estado'] ?? '');
    
        $mq = [];
        if ($paisId) { $mq[] = ['key' => 'gw_pais_id', 'value' => $paisId, 'compare' => '=']; }
        if ($estado === 'activo' || $estado === 'inactivo') {
            $mq[] = ['key' => 'gw_active', 'value' => ($estado==='activo' ? '1' : '0'), 'compare' => '='];
        }
    
        $users = get_users([
          'role'       => 'voluntario',
          'number'     => -1,
          'fields'     => ['ID','user_email','display_name'],
          'meta_query' => $mq
        ]);
    
        // CSV
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=reporte_'.($tipo==='charla'?'charlas':'capacitaciones').'_'.date('Ymd_His').'.csv');
        $out = fopen('php://output', 'w');
    
        if ($tipo === 'charla' || $tipo === 'charlas') {
            fputcsv($out, ['Nombre','Email','País','Estado','Charlas']);
            foreach ($users as $u) {
                $pais_id = get_user_meta($u->ID, 'gw_pais_id', true); $pais_t = $pais_id ? get_the_title($pais_id) : '—';
                $act = get_user_meta($u->ID, 'gw_active', true); if ($act==='') $act='1'; $badge = $act==='1' ? 'Activo' : 'Inactivo';
                $asig = get_user_meta($u->ID, 'gw_charlas_asignadas', true); if (!is_array($asig)) $asig = [];
                $lista = [];
                foreach ($asig as $key) { $done = get_user_meta($u->ID, 'gw_'.$key, true) ? 'SI' : 'NO'; $lista[] = $key.':'.$done; }
                fputcsv($out, [ $u->display_name ?: $u->user_email, $u->user_email, $pais_t, $badge, implode(' | ', $lista) ]);
            }
        } else {
            fputcsv($out, ['Nombre','Email','País','Estado','Capacitación','Fecha','Hora']);
            foreach ($users as $u) {
                $pais_id = get_user_meta($u->ID, 'gw_pais_id', true); $pais_t = $pais_id ? get_the_title($pais_id) : '—';
                $act = get_user_meta($u->ID, 'gw_active', true); if ($act==='') $act='1'; $badge = $act==='1' ? 'Activo' : 'Inactivo';
                $cap_id = get_user_meta($u->ID, 'gw_capacitacion_id', true); $cap_t = $cap_id ? get_the_title($cap_id) : '—';
                $fecha  = get_user_meta($u->ID, 'gw_fecha', true) ?: '—';
                $hora   = get_user_meta($u->ID, 'gw_hora', true) ?: '—';
                fputcsv($out, [ $u->display_name ?: $u->user_email, $u->user_email, $pais_t, $badge, $cap_t, $fecha, $hora ]);
            }
        }
        fclose($out);
        exit;
    }
    }
    // ====== FIN MÓDULO REPORTES Y LISTADOS: AJAX ======

// ---------- Exportadores ----------
add_action('admin_post_gw_reportes_export', function(){
    if (!current_user_can('coordinador_pais')) wp_die('No autorizado.');
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'gw_reportes_export')) wp_die('Nonce inválido');

    $f = [
        'pais_id'     => isset($_POST['pais_id']) ? intval($_POST['pais_id']) : 0,
        'proyecto_id' => isset($_POST['proyecto_id']) ? intval($_POST['proyecto_id']) : 0,
        'cap_id'      => isset($_POST['cap_id']) ? intval($_POST['cap_id']) : 0,
        'sesion_idx'  => (isset($_POST['sesion_idx']) && $_POST['sesion_idx'] !== '') ? intval($_POST['sesion_idx']) : '',
        'coach_id'    => isset($_POST['coach_id']) ? intval($_POST['coach_id']) : 0,
        'estado'      => isset($_POST['estado']) ? sanitize_text_field($_POST['estado']) : '',
    ];
    $rows = gw_reportes_query_rows($f);
    $fmt = isset($_POST['format']) ? sanitize_text_field($_POST['format']) : 'csv';
    $filename = 'reporte_'.date('Ymd_His');

    if ($fmt === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename='.$filename.'.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Nombre','Correo','País','Proyecto','Capacitación','Sesión','Estado','Fecha','Hora','Coach']);
        foreach ($rows as $r) {
            fputcsv($out, [$r['nombre'],$r['email'],$r['pais'],$r['proyecto'],$r['cap'],$r['sesion'],$r['estado'],$r['fecha'],$r['hora'],$r['coach']]);
        }
        fclose($out);
        exit;
    }
    if ($fmt === 'xls') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename='.$filename.'.xls');
        echo "<table border='1'><tr><th>Nombre</th><th>Correo</th><th>País</th><th>Proyecto</th><th>Capacitación</th><th>Sesión</th><th>Estado</th><th>Fecha</th><th>Hora</th><th>Coach</th></tr>";
        foreach ($rows as $r) {
            echo '<tr>'
               .'<td>'.esc_html($r['nombre']).'</td>'
               .'<td>'.esc_html($r['email']).'</td>'
               .'<td>'.esc_html($r['pais']).'</td>'
               .'<td>'.esc_html($r['proyecto']).'</td>'
               .'<td>'.esc_html($r['cap']).'</td>'
               .'<td>'.esc_html($r['sesion']).'</td>'
               .'<td>'.esc_html($r['estado']).'</td>'
               .'<td>'.esc_html($r['fecha']).'</td>'
               .'<td>'.esc_html($r['hora']).'</td>'
               .'<td>'.esc_html($r['coach']).'</td>'
               .'</tr>';
        }
        echo '</table>';
        exit;
    }
    if ($fmt === 'pdf') {
        // Requiere Dompdf. Si no está disponible, mostramos mensaje
        if (!class_exists('\Dompdf\Dompdf')) {
            wp_die('Exportar a PDF requiere Dompdf. Instálalo vía Composer o plugin y vuelve a intentar.');
        }
        $html  = '<h3 style="text-align:center">Reporte de Voluntariado</h3>';
        $html .= '<table width="100%" border="1" cellspacing="0" cellpadding="4"><thead><tr>'
               . '<th>Nombre</th><th>Correo</th><th>País</th><th>Proyecto</th><th>Capacitación</th><th>Sesión</th><th>Estado</th><th>Fecha</th><th>Hora</th><th>Coach</th>'
               . '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $html .= '<tr>'
                  .  '<td>'.esc_html($r['nombre']).'</td>'
                  .  '<td>'.esc_html($r['email']).'</td>'
                  .  '<td>'.esc_html($r['pais']).'</td>'
                  .  '<td>'.esc_html($r['proyecto']).'</td>'
                  .  '<td>'.esc_html($r['cap']).'</td>'
                  .  '<td>'.esc_html($r['sesion']).'</td>'
                  .  '<td>'.esc_html($r['estado']).'</td>'
                  .  '<td>'.esc_html($r['fecha']).'</td>'
                  .  '<td>'.esc_html($r['hora']).'</td>'
                  .  '<td>'.esc_html($r['coach']).'</td>'
                  .  '</tr>';
        }
        $html .= '</tbody></table>';

        if ( ! class_exists('\Dompdf\Dompdf') ) {
            wp_die('Exportar a PDF requiere Dompdf. Instálalo vía Composer o plugin y vuelve a intentar.');
        }

        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        $dompdf->stream($filename . '.pdf', ['Attachment' => true]);
        exit;
    } // fin if ($fmt === 'pdf')
});

// Agregar este código a tu functions.php o archivo de plugin
// Remover handlers duplicados
remove_all_actions('wp_ajax_gw_generar_link_qr_pais');

// Handler único y limpio
function gw_ajax_generar_link_qr_pais_final() {
    error_log('QR Generation: Handler llamado');
    
    if (!is_user_logged_in()) {
        wp_send_json_error(['msg' => 'Usuario no autenticado']);
    }
    
    if (!current_user_can('manage_options') && !current_user_can('coordinador_pais') && !current_user_can('coach')) {
        wp_send_json_error(['msg' => 'No tienes permisos']);
    }

    $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
    if (!wp_verify_nonce($nonce, 'gw_paises_qr')) {
        error_log('QR Generation: Nonce inválido - Recibido: ' . $nonce);
        wp_send_json_error(['msg' => 'Token de seguridad inválido']);
    }

    $pais_id = isset($_POST['pais_id']) ? intval($_POST['pais_id']) : 0;
    if (!$pais_id) {
        wp_send_json_error(['msg' => 'ID de país requerido']);
    }

    $pais = get_post($pais_id);
    if (!$pais || $pais->post_type !== 'pais') {
        wp_send_json_error(['msg' => 'País no válido']);
    }

    $target_url = add_query_arg('gw_pais', $pais_id, home_url('/'));
    $qr_primary = 'https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=' . rawurlencode($target_url);
    $qr_fallback = 'https://chart.googleapis.com/chart?chs=250x250&cht=qr&choe=UTF-8&chl=' . rawurlencode($target_url);

    error_log('QR Generation: Éxito para país ID: ' . $pais_id);
    
    wp_send_json_success([
        'url' => $target_url,
        'qr' => $qr_primary,
        'qr_alt' => $qr_fallback,
        'pais' => get_the_title($pais_id),
    ]);
}

add_action('wp_ajax_gw_generar_link_qr_pais', 'gw_ajax_generar_link_qr_pais_final');



/**
 * Crea o sincroniza el WP_User desde el perfil de Google
 * $g = ['sub','email','name','given_name','family_name','picture', ...]
 * Retorna el $user_id listo y loguea.
 */
function gw_upsert_user_from_google(array $g){
  $email = sanitize_email($g['email'] ?? '');
  if (!$email) wp_die('Email requerido por Google');

  // 1) Buscar usuario por email
  $user = get_user_by('email', $email);
  $default_role = 'voluntario';

  if (!$user) {
    // user_login único a partir del email
    $login = sanitize_user(current(explode('@', $email)));
    if (username_exists($login)) $login .= '_' . wp_generate_password(4, false, false);

    $user_id = wp_insert_user([
      'user_login'   => $login,
      'user_pass'    => wp_generate_password(24),
      'user_email'   => $email,
      'first_name'   => $g['given_name'] ?? '',
      'last_name'    => $g['family_name'] ?? '',
      'display_name' => !empty($g['name']) ? $g['name'] : trim(($g['given_name'] ?? '') . ' ' . ($g['family_name'] ?? '')),
      'role'         => $default_role,
    ]);

    if (is_wp_error($user_id)) wp_die('No se pudo crear el usuario.');
  } else {
    $user_id = $user->ID;

    // Mantén admin/coordinador/coach; si no tiene ninguno de tus roles, asigna voluntario
    $u = new WP_User($user_id);
    $app_roles = ['administrator','coordinador_pais','coach','voluntario'];
    if (!array_intersect($app_roles, $u->roles)) {
      $u->set_role($default_role);
    }

    // Completa nombres si faltan
    if (empty($user->first_name) && !empty($g['given_name'])) update_user_meta($user_id, 'first_name', $g['given_name']);
    if (empty($user->last_name)  && !empty($g['family_name'])) update_user_meta($user_id, 'last_name',  $g['family_name']);
    if (empty($user->display_name) && !empty($g['name'])) wp_update_user(['ID'=>$user_id,'display_name'=>$g['name']]);
  }

  // 2) Metas mínimas que tu panel usa (clave para que “aparezcan”)
  if (get_user_meta($user_id, 'gw_active', true)   === '') update_user_meta($user_id, 'gw_active', '1');
  if (get_user_meta($user_id, 'gw_pais_id', true)  === '') update_user_meta($user_id, 'gw_pais_id', 0); // o un país por defecto
  update_user_meta($user_id, 'gw_auth_provider', 'google');
  if (!empty($g['sub'])) update_user_meta($user_id, 'gw_google_sub', $g['sub']);

  // 3) Login y redirección
  wp_set_current_user($user_id);
  wp_set_auth_cookie($user_id, true);
  wp_safe_redirect(home_url('/index.php/portal-voluntario/')); // cambia a donde quieras
  exit;
}