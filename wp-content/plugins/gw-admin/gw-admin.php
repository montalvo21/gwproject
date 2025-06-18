<?php
/**
 * Plugin Name: Glasswing Admin Manager
 * Description: Módulos avanzados de administración y gestión para ONG Glasswing.
 * Version: 1.0
 * Author: Carlos Montalvo
 */

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
        'show_in_menu' => false, // Solo visible desde el panel visual
        'show_in_rest' => true
    ]);
});

// Registrar Custom Post Type "Programa"
add_action('init', function () {
    register_post_type('programa', [
        'labels' => [
            'name' => 'Programas',
            'singular_name' => 'Programa',
            'add_new' => 'Agregar Nuevo Programa',
            'add_new_item' => 'Agregar Nuevo Programa',
            'edit_item' => 'Editar Programa',
            'new_item' => 'Nuevo Programa',
            'view_item' => 'Ver Programa',
            'search_items' => 'Buscar Programa',
            'not_found' => 'No se encontraron programas',
            'not_found_in_trash' => 'No se encontraron programas en la papelera',
            'all_items' => 'Todos los Programas',
            'menu_name' => 'Programas',
        ],
        'public' => true,
        'has_archive' => true,
        'menu_icon' => 'dashicons-welcome-learn-more',
        'supports' => ['title'],
        'show_in_menu' => true,
        'show_in_rest' => true // para Gutenberg y REST API
    ]);
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
.    <style>
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
                
                    // Guardar asociaciones (coordinadores y programas)
                    if (isset($_POST['gw_save_asoc']) && is_numeric($_POST['pais_id'])) {
                        $pid = intval($_POST['pais_id']);
                        $coordinadores = array_map('intval', $_POST['gw_coordinadores'] ?? []);
                        $programas = array_map('sanitize_text_field', $_POST['gw_programas'] ?? []);
                        update_post_meta($pid, '_gw_coordinadores', $coordinadores);
                        update_post_meta($pid, '_gw_programas', $programas);
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
                        echo '<table style="width:100%;background:#fff;border-radius:8px;box-shadow:0 0 8px #e3e3e3;"><tr><th style="padding:8px;">Nombre</th><th>Coordinadores</th><th>Programas</th><th>Acciones</th></tr>';
                        foreach ($paises as $pais) {
                            $coors = get_post_meta($pais->ID, '_gw_coordinadores', true) ?: [];
                            $progs = get_post_meta($pais->ID, '_gw_programas', true) ?: [];
                            $editando = (isset($_GET['edit_pais']) && intval($_GET['edit_pais']) === $pais->ID);
                
                            // Si se está editando este país, mostrar formulario
                            if ($editando) {
                                // Usuarios con rol coordinador_pais
                                $usuarios = get_users(['role' => 'coordinador_pais']);
                                // Lista de programas (simples por ahora, puedes adaptar a tu estructura)
                                $programas = get_posts(['post_type'=>'programa','numberposts'=>-1]);
                                echo '<tr><form method="post"><td style="padding:8px;">'.esc_html($pais->post_title).'</td>';
                                // Coordinadores
                                echo '<td><select name="gw_coordinadores[]" multiple style="min-width:130px">';
                                foreach($usuarios as $u) {
                                    $sel = in_array($u->ID, $coors) ? 'selected' : '';
                                    echo '<option value="'.$u->ID.'" '.$sel.'>'.$u->display_name.'</option>';
                                }
                                echo '</select></td>';
                                // Programas
                                echo '<td><select name="gw_programas[]" multiple style="min-width:130px">';
                                foreach($programas as $p) {
                                    $sel = in_array($p->ID, $progs) ? 'selected' : '';
                                    echo '<option value="'.$p->ID.'" '.$sel.'>'.$p->post_title.'</option>';
                                }
                                echo '</select></td>';
                                echo '<td>
                                    <input type="hidden" name="pais_id" value="'.$pais->ID.'">
                                    <button type="submit" name="gw_save_asoc" style="background:#388e3c;color:#fff;padding:6px 18px;border:none;border-radius:4px;">Guardar</button>
                                    <a href="?gw_section=paises" style="margin-left:8px;">Cancelar</a>
                                    </td></form></tr>';
                            } else {
                                // Mostrar valores actuales
                                $usuarios = array_map(function($id){ $u=get_userdata($id); return $u?$u->display_name:''; }, $coors);
                                $programas = array_map(function($id){ $p=get_post($id); return $p?$p->post_title:''; }, $progs);
                                echo '<tr>
                                    <td style="padding:8px;">'.esc_html($pais->post_title).'</td>
                                    <td>'.implode(', ', array_filter($usuarios)).'</td>
                                    <td>'.implode(', ', array_filter($programas)).'</td>
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
                                    echo '<tr><form method="post">
        <td style="padding:8px;">
            <input type="text" name="gw_user_nombre" value="'.esc_attr($u->display_name).'" required style="width:98%;">
        </td>
        <td>
            <input type="email" name="gw_user_email" value="'.esc_attr($u->user_email).'" required style="width:98%;">
        </td>
        <td>
            <select name="gw_user_role">';
    foreach($roles as $k=>$v) {
        $sel = ($k==$rol_actual)?'selected':'';
        echo '<option value="'.$k.'" '.$sel.'>'.$v.'</option>';
    }
    echo '</select>
        </td>
        <td>
            <select name="gw_user_pais">
                <option value="">Ninguno</option>';
    foreach($paises as $p) {
        $sel = ($p->ID==$pais_id)?'selected':'';
        echo '<option value="'.$p->ID.'" '.$sel.'>'.$p->post_title.'</option>';
    }
    echo '</select>
        </td>
        <td><input type="checkbox" name="gw_user_activo" value="1" '.($activo?'checked':'').'></td>
        <td>
            <input type="hidden" name="usuario_id" value="'.$u->ID.'">
            <button type="submit" name="gw_guardar_usuario" style="background:#388e3c;color:#fff;padding:5px 16px;border:none;border-radius:4px;">Guardar</button>
            <a href="?gw_section=usuarios" style="margin-left:7px;">Cancelar</a>
        </td>
    </form></tr>';
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
        $programa = intval($_POST['gw_programa']);
        $responsable = intval($_POST['gw_responsable']);
        $fecha = sanitize_text_field($_POST['gw_fecha']);
        $hora = sanitize_text_field($_POST['gw_hora']);
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
        update_post_meta($cap_id, '_gw_programa', $programa);
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
    $programas = get_posts(['post_type'=>'programa','numberposts'=>-1]);
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
    echo '<select name="gw_programa" required><option value="">Programa</option>';
    foreach($programas as $pr) {
        $sel = ($cap_data['programa']==$pr->ID)?'selected':'';
        echo '<option value="'.$pr->ID.'" '.$sel.'>'.$pr->post_title.'</option>';
    }
    echo '</select> ';
    echo '<select name="gw_responsable" required><option value="">Responsable</option>';
    foreach($usuarios as $u) {
        $sel = ($cap_data['responsable']==$u->ID)?'selected':'';
        echo '<option value="'.$u->ID.'" '.$sel.'>'.$u->display_name.' ('.$u->user_email.')</option>';
    }
    echo '</select><br><br>';
    echo 'Fecha: <input type="date" name="gw_fecha" required value="'.esc_attr($cap_data['fecha']).'"> ';
    echo 'Hora: <input type="time" name="gw_hora" required value="'.esc_attr($cap_data['hora']).'"><br><br>';
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
                <th>Programa</th>
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
            $programa = get_post_meta($c->ID, '_gw_programa', true);
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
                <td>'.($programa?get_the_title($programa):'').'</td>
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
                case 'progreso':
                    echo '<h2>Progreso del Voluntario</h2>';
                    // Conseguimos todos los voluntarios
                    $voluntarios = get_users(['role' => 'voluntario']);
                    $paises = get_posts(['post_type' => 'pais', 'numberposts' => -1]);
                    $programas = get_posts(['post_type' => 'programa', 'numberposts' => -1]);
                    $caps_generales = get_posts(['post_type'=>'capacitacion','numberposts'=>-1,'meta_query'=>[['key'=>'_gw_tipo','value'=>'general']]]);
                    $caps_especiales = get_posts(['post_type'=>'capacitacion','numberposts'=>-1,'meta_query'=>[['key'=>'_gw_tipo','value'=>'especial']]]);

                    echo '<div style="overflow-x:auto;"><table style="width:100%;background:#fff;border-radius:8px;box-shadow:0 0 8px #e3e3e3;font-size:0.98rem;">';
                    echo '<tr><th>Voluntario</th><th>País</th><th>Programas</th><th>Charlas generales</th><th>Especializadas</th><th>Estado</th></tr>';

                    foreach ($voluntarios as $v) {
                        $pais = get_user_meta($v->ID, 'gw_pais_id', true);
                        $pais_nombre = $pais ? get_the_title($pais) : '';
                        $progs = get_user_meta($v->ID, 'gw_programas', true) ?: [];
                        if (!is_array($progs)) $progs = [];
                        $progs_nombres = [];
                        foreach ($progs as $pid) {
                            $pr = get_post($pid);
                            if ($pr) $progs_nombres[] = $pr->post_title;
                        }
                        // Charlas generales completadas
                        $gen_total = count($caps_generales);
                        $gen_asist = 0;
                        foreach ($caps_generales as $cap) {
                            $asis = get_post_meta($cap->ID, '_gw_asistencias', true);
                            if (is_array($asis) && isset($asis[$v->ID])) $gen_asist++;
                        }
                        // Especializadas completadas (sumadas)
                        $esp_total = 0; $esp_asist = 0;
                        foreach ($caps_especiales as $cap) {
                            $prog_cap = get_post_meta($cap->ID, '_gw_programa', true);
                            if (in_array($prog_cap, $progs)) {
                                $esp_total++;
                                $asis = get_post_meta($cap->ID, '_gw_asistencias', true);
                                if (is_array($asis) && isset($asis[$v->ID])) $esp_asist++;
                            }
                        }
                        $estado = get_user_meta($v->ID, 'gw_activo', true) ? 'Activo' : 'Inactivo';
                        echo '<tr>
                                <td>'.$v->display_name.'</td>
                                <td>'.$pais_nombre.'</td>
                                <td>'.implode(', ', $progs_nombres).'</td>
                                <td>'.$gen_asist.' / '.$gen_total.'</td>
                                <td>'.$esp_asist.' / '.$esp_total.'</td>
                                <td>'.$estado.'</td>
                            </tr>';
                    }
                    echo '</table></div>';
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
// Handler de asistencia automática al entrar al link
add_action('init', function() {
    if (!is_user_logged_in()) return;
    if (!isset($_GET['gw_asistir'])) return;

    $cap_id = intval($_GET['gw_asistir']);
    $user_id = get_current_user_id();

    if (get_post_type($cap_id) !== 'capacitacion') return;

    // Guardar/actualizar asistencia en el meta de la capacitación
    $asistencias = get_post_meta($cap_id, '_gw_asistencias', true);
    if (!is_array($asistencias)) $asistencias = [];
    $asistencias[$user_id] = time(); // timestamp actual como marca de asistencia
    update_post_meta($cap_id, '_gw_asistencias', $asistencias);

    // Redirigir al enlace real de Google Meet
    $link = get_post_meta($cap_id, '_gw_link', true);
    if ($link) {
        wp_redirect($link);
        exit;
    }
});