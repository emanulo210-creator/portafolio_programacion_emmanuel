<?php
session_start();
require_once "conexion.php";

// Cerrar sesión
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header("Location: pagina.php");
    exit;
}

// Disponible en toda la página para saber si el usuario inició sesión
$logueado = isset($_SESSION['usuario_id']);
$nombre_usuario = $logueado ? $_SESSION['usuario_nombre'] : "";
$inicial_usuario = $logueado ? strtoupper(mb_substr($nombre_usuario, 0, 1)) : "";

// Datos del perfil para el panel de estadísticas (visitas, fecha de registro, etc.)
$perfil = null;
$mensaje_perfil = "";

if (isset($_SESSION['flash_perfil'])) {
    $mensaje_perfil = $_SESSION['flash_perfil'];
    unset($_SESSION['flash_perfil']);
}

if ($logueado) {
    try {
        // Sumamos 1 visita cada vez que el usuario carga la página
        $pdo->prepare("UPDATE registro SET visitas = visitas + 1 WHERE id = ?")
            ->execute([$_SESSION['usuario_id']]);
    } catch (PDOException $e) {
        // Si la columna 'visitas' todavía no existe, lo dejamos pasar sin romper la página
        error_log("Aviso: no se pudo actualizar 'visitas': " . $e->getMessage());
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM registro WHERE id = ?");
        $stmt->execute([$_SESSION['usuario_id']]);
        $perfil = $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Aviso: no se pudo cargar el perfil: " . $e->getMessage());
    }
}

// Un usuario es administrador si su fila en `registro` tiene es_admin = 1.
// Se calcula en cada carga de página (no se guarda en sesión) para que un
// cambio de permisos en la base de datos se refleje de inmediato.
$es_admin = $perfil && isset($perfil['es_admin']) && (int) $perfil['es_admin'] === 1;

// El usuario puede borrar una partida de SU propio historial (nunca de otro,
// por eso el WHERE siempre incluye usuario_id = sesión actual).
if ($logueado && isset($_GET['eliminar_juego'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM juegos_jugados WHERE id = ? AND usuario_id = ?");
        $stmt->execute([(int) $_GET['eliminar_juego'], $_SESSION['usuario_id']]);
    } catch (PDOException $e) {
        error_log("Aviso: no se pudo borrar la partida: " . $e->getMessage());
    }
    header("Location: pagina.php?panel=perfil");
    exit;
}

// Historial de juegos del usuario logueado, para mostrarlo en su panel
$mis_juegos = [];
if ($logueado) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM juegos_jugados WHERE usuario_id = ? ORDER BY fecha_juego DESC LIMIT 20");
        $stmt->execute([$_SESSION['usuario_id']]);
        $mis_juegos = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Aviso: no se pudo cargar el historial de juegos: " . $e->getMessage());
    }
}

// =====================================================================
// PANEL DE ADMINISTRACIÓN (integrado en esta misma página, pestaña #admin)
// Todo lo de aquí abajo solo se ejecuta si $es_admin es true, comprobado
// contra la base de datos justo arriba. Nunca confiamos en datos del
// cliente (GET/POST) para decidir si alguien es administrador.
// =====================================================================
$mensaje_admin = "";
if (isset($_SESSION['flash_admin'])) {
    $mensaje_admin = $_SESSION['flash_admin'];
    unset($_SESSION['flash_admin']);
}
$seccion_admin = $_GET['seccion_admin'] ?? 'usuarios';
$usuario_editar_admin = null;
$usuarios_admin = [];
$todos_los_juegos_admin = [];

if ($es_admin) {

    // --- CREAR usuario (desde el panel de admin) ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion_crear_usuario_admin'])) {
        $nombre = htmlspecialchars(trim($_POST['nombre']));
        $correo = htmlspecialchars(trim(strtolower($_POST['correo'])));
        $documento = htmlspecialchars(trim($_POST['documento']));
        $password = $_POST['password'];

        if ($nombre === '' || $correo === '' || $documento === '' || strlen($password) < 6) {
            $_SESSION['flash_admin'] = "<p class='msg error'>Todos los campos son obligatorios y la contraseña debe tener al menos 6 caracteres.</p>";
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id FROM registro WHERE correo = ?");
                $stmt->execute([$correo]);
                if ($stmt->fetch()) {
                    $_SESSION['flash_admin'] = "<p class='msg error'>Ya existe un usuario con ese correo.</p>";
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("INSERT INTO registro (nombre, correo, documento, password) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$nombre, $correo, $documento, $hash]);
                    $_SESSION['flash_admin'] = "<p class='msg exito'>Usuario creado correctamente.</p>";
                }
            } catch (PDOException $e) {
                error_log("Error al crear usuario (admin): " . $e->getMessage());
                $_SESSION['flash_admin'] = "<p class='msg error'>Ocurrió un error al crear el usuario.</p>";
            }
        }
        header("Location: pagina.php?seccion_admin=usuarios#admin");
        exit;
    }

    // --- ACTUALIZAR usuario ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion_actualizar_usuario_admin'])) {
        $id = (int) $_POST['id'];
        $nombre = htmlspecialchars(trim($_POST['nombre']));
        $correo = htmlspecialchars(trim(strtolower($_POST['correo'])));
        $documento = htmlspecialchars(trim($_POST['documento']));
        $password_nueva = $_POST['password'];

        try {
            $stmt = $pdo->prepare("SELECT id FROM registro WHERE correo = ? AND id != ?");
            $stmt->execute([$correo, $id]);
            if ($stmt->fetch()) {
                $_SESSION['flash_admin'] = "<p class='msg error'>Ese correo ya lo usa otro usuario.</p>";
            } else {
                if (strlen($password_nueva) >= 6) {
                    $hash = password_hash($password_nueva, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE registro SET nombre=?, correo=?, documento=?, password=? WHERE id=?");
                    $stmt->execute([$nombre, $correo, $documento, $hash, $id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE registro SET nombre=?, correo=?, documento=? WHERE id=?");
                    $stmt->execute([$nombre, $correo, $documento, $id]);
                }
                $_SESSION['flash_admin'] = "<p class='msg exito'>Usuario actualizado correctamente.</p>";
            }
        } catch (PDOException $e) {
            error_log("Error al actualizar usuario (admin): " . $e->getMessage());
            $_SESSION['flash_admin'] = "<p class='msg error'>Ocurrió un error al actualizar el usuario.</p>";
        }
        header("Location: pagina.php?seccion_admin=usuarios#admin");
        exit;
    }

    // --- ELIMINAR usuario ---
    if (isset($_GET['eliminar_usuario_admin'])) {
        $id = (int) $_GET['eliminar_usuario_admin'];
        if ($id === (int) $_SESSION['usuario_id']) {
            $_SESSION['flash_admin'] = "<p class='msg error'>No puedes eliminar tu propia cuenta desde aquí.</p>";
        } else {
            try {
                $stmt = $pdo->prepare("DELETE FROM registro WHERE id = ?");
                $stmt->execute([$id]);
                $_SESSION['flash_admin'] = "<p class='msg exito'>Usuario eliminado correctamente.</p>";
            } catch (PDOException $e) {
                error_log("Error al eliminar usuario (admin): " . $e->getMessage());
                $_SESSION['flash_admin'] = "<p class='msg error'>No se pudo eliminar el usuario.</p>";
            }
        }
        header("Location: pagina.php?seccion_admin=usuarios#admin");
        exit;
    }

    // --- ALTERNAR ROL DE ADMINISTRADOR ---
    if (isset($_GET['toggle_admin'])) {
        $id = (int) $_GET['toggle_admin'];
        if ($id === (int) $_SESSION['usuario_id']) {
            $_SESSION['flash_admin'] = "<p class='msg error'>No puedes quitarte el rol de administrador a ti mismo.</p>";
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE registro SET es_admin = 1 - es_admin WHERE id = ?");
                $stmt->execute([$id]);
                $_SESSION['flash_admin'] = "<p class='msg exito'>Rol de administrador actualizado.</p>";
            } catch (PDOException $e) {
                error_log("Error al alternar rol de admin: " . $e->getMessage());
                $_SESSION['flash_admin'] = "<p class='msg error'>No se pudo actualizar el rol.</p>";
            }
        }
        header("Location: pagina.php?seccion_admin=usuarios#admin");
        exit;
    }

    // --- ELIMINAR UNA PARTIDA (de cualquier usuario) ---
    if (isset($_GET['eliminar_juego_admin'])) {
        try {
            $stmt = $pdo->prepare("DELETE FROM juegos_jugados WHERE id = ?");
            $stmt->execute([(int) $_GET['eliminar_juego_admin']]);
            $_SESSION['flash_admin'] = "<p class='msg exito'>Partida eliminada correctamente.</p>";
        } catch (PDOException $e) {
            error_log("Error al eliminar partida (admin): " . $e->getMessage());
            $_SESSION['flash_admin'] = "<p class='msg error'>No se pudo eliminar la partida.</p>";
        }
        header("Location: pagina.php?seccion_admin=juegos#admin");
        exit;
    }

    // --- Usuario a editar (si corresponde) ---
    if (($_GET['admin_accion'] ?? '') === 'editar' && isset($_GET['id'])) {
        $stmt = $pdo->prepare("SELECT * FROM registro WHERE id = ?");
        $stmt->execute([(int) $_GET['id']]);
        $usuario_editar_admin = $stmt->fetch();
    }

    // --- Listado de usuarios ---
    $usuarios_admin = $pdo->query("SELECT * FROM registro ORDER BY id DESC")->fetchAll();

    // --- Listado de todas las partidas, con nombre y correo del jugador ---
    $todos_los_juegos_admin = $pdo->query(
        "SELECT j.*, r.nombre AS nombre_usuario, r.correo AS correo_usuario
         FROM juegos_jugados j
         JOIN registro r ON j.usuario_id = r.id
         ORDER BY j.fecha_juego DESC"
    )->fetchAll();
}


$mensaje_registro = "";
$mensaje_login = "";

// Recuperamos mensajes "flash" guardados en sesión (de un redirect anterior)
// y los borramos para que no vuelvan a aparecer si se recarga la página.
if (isset($_SESSION['flash_registro'])) {
    $mensaje_registro = $_SESSION['flash_registro'];
    unset($_SESSION['flash_registro']);
}
if (isset($_SESSION['flash_login'])) {
    $mensaje_login = $_SESSION['flash_login'];
    unset($_SESSION['flash_login']);
}

// Dominios de correo permitidos para registrarse.
// Ya no es obligatorio usar @ie.edu.co: también se acepta @gmail.com.
$dominios_permitidos = ["@gmail.com", "@ie.edu.co"];

function correo_tiene_dominio_valido($correo, $dominios) {
    foreach ($dominios as $dominio) {
        if (str_ends_with(strtolower($correo), $dominio)) {
            return true;
        }
    }
    return false;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // ---------------- REGISTRO ----------------
    if (isset($_POST['accion_registro'])) {
        $nombre = htmlspecialchars(trim($_POST['nombre']));
        $correo = htmlspecialchars(trim(strtolower($_POST['correo'])));
        $documento = htmlspecialchars(trim($_POST['documento']));
        $password = $_POST['password'];

        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_registro'] = "<p style='color: red; text-align: center;'>Error: El correo ingresado no es válido.</p>";
        } elseif (!correo_tiene_dominio_valido($correo, $dominios_permitidos)) {
            $_SESSION['flash_registro'] = "<p style='color: red; text-align: center;'>Error: Usa un correo @gmail.com o institucional (@ie.edu.co).</p>";
        } elseif (strlen($password) < 6) {
            $_SESSION['flash_registro'] = "<p style='color: red; text-align: center;'>Error: La contraseña debe tener al menos 6 caracteres.</p>";
        } else {
            try {
                // Verificamos que el correo no esté ya registrado
                $stmt = $pdo->prepare("SELECT id FROM registro WHERE LOWER(correo) = LOWER(?)");
                $stmt->execute([$correo]);

                if ($stmt->fetch()) {
                    $_SESSION['flash_registro'] = "<p style='color: red; text-align: center;'>Error: Ese correo ya está registrado. Intenta iniciar sesión.</p>";
                } else {
                    $password_hash = password_hash($password, PASSWORD_DEFAULT);

                    $stmt = $pdo->prepare(
                        "INSERT INTO registro (nombre, correo, documento, password) VALUES (?, ?, ?, ?)"
                    );
                    $stmt->execute([$nombre, $correo, $documento, $password_hash]);

                    $_SESSION['flash_registro'] = "<p style='color: green; text-align: center;'>¡Registro exitoso para $nombre! Ya puedes iniciar sesión.</p>";
                }
            } catch (PDOException $e) {
                error_log("Error al registrar usuario: " . $e->getMessage());
                $_SESSION['flash_registro'] = "<p style='color: red; text-align: center;'>Ocurrió un error al registrar. Intenta de nuevo.</p>";
            }
        }

        // Redirigimos siempre a la sección "registro": esto evita que al
        // refrescar la página el navegador reenvíe el formulario y vuelva
        // a intentar crear el mismo usuario (lo que causaba el falso
        // "ya registrado").
        header("Location: pagina.php#registro");
        exit;
    }

    // ---------------- LOGIN ----------------
    // El login se valida contra la misma tabla "registro": es decir,
    // solo puede iniciar sesión quien ya se registró con ese correo y contraseña.
    if (isset($_POST['accion_login'])) {
        $correo_login = htmlspecialchars(trim(strtolower($_POST['correo_login'])));
        $password_login = $_POST['password_login'];

        try {
            $stmt = $pdo->prepare("SELECT * FROM registro WHERE LOWER(correo) = LOWER(?)");
            $stmt->execute([$correo_login]);
            $usuario = $stmt->fetch();

            if ($usuario && password_verify($password_login, $usuario['password'])) {
                // Guardamos la sesión del usuario (esto es lo único indispensable)
                $_SESSION['usuario_id'] = $usuario['id'];
                $_SESSION['usuario_nombre'] = $usuario['nombre'];
                $_SESSION['usuario_correo'] = $usuario['correo'];

                // Registramos el inicio de sesión en la tabla inicioseccion.
                // Esto es solo un registro histórico: si falla (por ejemplo,
                // porque esa tabla tiene columnas distintas), no debe impedir
                // que el usuario inicie sesión.
                try {
                    $stmt = $pdo->prepare(
                        "INSERT INTO inicioseccion (registro_id, correo) VALUES (?, ?)"
                    );
                    $stmt->execute([$usuario['id'], $usuario['correo']]);
                } catch (PDOException $e) {
                    error_log("Aviso: no se pudo registrar en inicioseccion: " . $e->getMessage());
                }

                $_SESSION['flash_login'] = "<p style='color: green; text-align: center;'>Sesión iniciada correctamente como: " . htmlspecialchars($usuario['nombre']) . "</p>";

                // Tras iniciar sesión, mandamos al usuario al inicio
                header("Location: pagina.php#inicio");
                exit;
            } else {
                $_SESSION['flash_login'] = "<p style='color: red; text-align: center;'>Correo o contraseña incorrectos. Verifica tus datos o regístrate primero.</p>";
                header("Location: pagina.php#login");
                exit;
            }
        } catch (PDOException $e) {
            error_log("Error al iniciar sesión: " . $e->getMessage());
            $_SESSION['flash_login'] = "<p style='color: red; text-align: center;'>Ocurrió un error al iniciar sesión. Intenta de nuevo.</p>";
            header("Location: pagina.php#login");
            exit;
        }
    }

    // ---------------- CONFIGURACIÓN DE PERFIL ----------------
    if (isset($_POST['accion_actualizar_perfil']) && $logueado) {
        $nuevo_nombre = htmlspecialchars(trim($_POST['nombre_perfil']));
        $nuevo_correo = htmlspecialchars(trim(strtolower($_POST['correo_perfil'])));
        $nuevo_documento = htmlspecialchars(trim($_POST['documento_perfil']));
        $nueva_password = $_POST['password_perfil'];

        if ($nuevo_nombre === '' || $nuevo_documento === '') {
            $_SESSION['flash_perfil'] = "<p style='color: red;'>El nombre y el documento no pueden estar vacíos.</p>";
            header("Location: pagina.php?panel=perfil");
            exit;
        }

        if (!filter_var($nuevo_correo, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_perfil'] = "<p style='color: red;'>El correo ingresado no es válido.</p>";
            header("Location: pagina.php?panel=perfil");
            exit;
        }

        if (!correo_tiene_dominio_valido($nuevo_correo, $dominios_permitidos)) {
            $_SESSION['flash_perfil'] = "<p style='color: red;'>Usa un correo @gmail.com o institucional (@ie.edu.co).</p>";
            header("Location: pagina.php?panel=perfil");
            exit;
        }

        if ($nueva_password !== '' && strlen($nueva_password) < 6) {
            $_SESSION['flash_perfil'] = "<p style='color: red;'>La nueva contraseña debe tener al menos 6 caracteres. No se guardó ningún cambio.</p>";
            header("Location: pagina.php?panel=perfil");
            exit;
        }

        try {
            // El correo debe seguir siendo único: verificamos que no lo use otro usuario
            $stmt = $pdo->prepare("SELECT id FROM registro WHERE LOWER(correo) = LOWER(?) AND id != ?");
            $stmt->execute([$nuevo_correo, $_SESSION['usuario_id']]);

            if ($stmt->fetch()) {
                $_SESSION['flash_perfil'] = "<p style='color: red;'>Ese correo ya lo está usando otra cuenta.</p>";
            } else {
                if (strlen($nueva_password) >= 6) {
                    $hash = password_hash($nueva_password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE registro SET nombre = ?, correo = ?, documento = ?, password = ? WHERE id = ?");
                    $stmt->execute([$nuevo_nombre, $nuevo_correo, $nuevo_documento, $hash, $_SESSION['usuario_id']]);
                } else {
                    $stmt = $pdo->prepare("UPDATE registro SET nombre = ?, correo = ?, documento = ? WHERE id = ?");
                    $stmt->execute([$nuevo_nombre, $nuevo_correo, $nuevo_documento, $_SESSION['usuario_id']]);
                }

                $_SESSION['usuario_nombre'] = $nuevo_nombre;
                $_SESSION['usuario_correo'] = $nuevo_correo;
                $_SESSION['flash_perfil'] = "<p style='color: green;'>Configuración guardada correctamente.</p>";
            }
        } catch (PDOException $e) {
            error_log("Error al actualizar perfil: " . $e->getMessage());
            $_SESSION['flash_perfil'] = "<p style='color: red;'>Ocurrió un error al guardar los cambios.</p>";
        }


        header("Location: pagina.php?panel=perfil");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Llenos de Amor - Restaurante Escolar</title>
    <link href="https://fonts.googleapis.com/css2?family=Josefin+Sans:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="pagina.css?v=<?php echo filemtime(__DIR__ . '/pagina.css'); ?>">
   <style>
        /* Estilos de comportamiento de pestañas */
        .tab-section { display: none; }
        .tab-section.active { display: block; }
        
        /* Pestaña seleccionada: Fondo dorado intenso y letras oscuras para máximo contraste vivo */
        nav ul li a.active { 
            color: #1B3818 !important; 
            background-color: #E2A000 !important; 
            font-weight: 800;
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(226, 160, 0, 0.4) !important;
            border-bottom: none !important;
            text-decoration: none !important;
        }
        
        .info-nutricional { background: #f9f9f9; padding: 15px; border-radius: 8px; margin-top: 10px; }
        .alerta { padding: 10px; border-radius: 5px; margin-bottom: 15px; text-align: center; font-weight: bold; }

        /* Avatar del usuario que inició sesión, en la barra de navegación */
        .usuario-nav {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 14px !important;
            white-space: nowrap;
        }
        .avatar-usuario {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #E2A000;
            color: #1B3818;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 1em;
            flex-shrink: 0;
        }
        .nombre-usuario-nav {
            font-weight: 600;
            max-width: 120px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .cerrar-sesion-link {
            font-size: 0.8em;
            opacity: 0.85;
            text-decoration: underline !important;
            white-space: nowrap;
        }

        /* Panel desplegable de perfil (estadísticas + configuración) */
        .panel-perfil {
            display: none;
            position: absolute;
            top: 70px;
            right: 20px;
            width: 300px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.18);
            padding: 20px;
            z-index: 999;
            color: #1B3818;
        }
        .panel-perfil.abierto { display: block; }
        .panel-perfil-header {
            display: flex;
            align-items: center;
            gap: 12px;
            position: relative;
            margin-bottom: 14px;
        }
        .avatar-grande { width: 46px; height: 46px; font-size: 1.3em; }
        .panel-perfil-header h4 { margin: 0; }
        .correo-panel { margin: 2px 0 0; font-size: 0.8em; color: #666; }
        .cerrar-panel {
            position: absolute;
            top: -6px; right: -6px;
            background: none;
            border: none;
            font-size: 1.4em;
            cursor: pointer;
            color: #888;
        }
        .panel-mensaje { font-size: 0.85em; margin-bottom: 10px; text-align: center; }
        .panel-estadisticas {
            display: flex;
            gap: 10px;
            margin-bottom: 14px;
        }
        .stat-card {
            flex: 1;
            background: #f4f6f4;
            border-radius: 8px;
            padding: 12px 8px;
            text-align: center;
        }
        .stat-numero { display: block; font-weight: 800; font-size: 1.3em; color: #386134; }
        .stat-label { font-size: 0.75em; color: #666; }
        .panel-configuracion summary {
            cursor: pointer;
            font-weight: 600;
            margin-bottom: 8px;
        }
        .panel-juegos summary {
            cursor: pointer;
            font-weight: 600;
            margin-bottom: 8px;
        }
        .lista-juegos {
            max-height: 180px;
            overflow-y: auto;
            margin-top: 8px;
        }
        .juego-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 6px;
            padding: 6px 0;
            border-bottom: 1px solid #eee;
            font-size: 0.8em;
        }
        .juego-item:last-child { border-bottom: none; }
        .juego-info { flex: 1; min-width: 0; }
        .juego-nombre { font-weight: 700; text-transform: capitalize; }
        .juego-detalle { color: #666; font-size: 0.9em; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .juego-borrar {
            color: #c0392b;
            text-decoration: none;
            font-weight: 700;
            flex-shrink: 0;
        }
        .sin-juegos { font-size: 0.85em; color: #777; text-align: center; padding: 10px 0; }

        /* ---------- Menú hamburguesa (3 rayitas) ---------- */
        .mobile-menu-btn {
            display: none;
            flex-direction: column;
            justify-content: space-between;
            width: 30px;
            height: 22px;
            background: none;
            border: none;
            cursor: pointer;
            padding: 0;
            z-index: 20;
        }
        .mobile-menu-btn .barra {
            display: block;
            width: 100%;
            height: 3px;
            border-radius: 3px;
            background: #1B3818;
            transition: transform 0.3s ease, opacity 0.3s ease;
        }
        .mobile-menu-btn.open .barra:nth-child(1) { transform: translateY(9.5px) rotate(45deg); }
        .mobile-menu-btn.open .barra:nth-child(2) { opacity: 0; }
        .mobile-menu-btn.open .barra:nth-child(3) { transform: translateY(-9.5px) rotate(-45deg); }

        @media (max-width: 1150px) {
            .mobile-menu-btn { display: flex; }

            nav#main-navigation {
                position: absolute;
                top: 100%;
                left: 0;
                right: 0;
                background: white;
                max-height: 0;
                overflow: hidden;
                transition: max-height 0.35s ease;
                box-shadow: 0 10px 25px rgba(0,0,0,0.15);
                border-radius: 0 0 12px 12px;
                z-index: 15;
            }
            nav#main-navigation.open { max-height: 80vh; overflow-y: auto; }

            nav#main-navigation ul {
                flex-direction: column;
                width: 100%;
                flex-wrap: nowrap;
                padding: 6px 0;
                gap: 0;
            }
            nav#main-navigation ul li { flex-shrink: 1; width: 100%; }
            nav#main-navigation ul li a {
                display: block;
                width: 100%;
                border-radius: 0;
                padding: 14px 20px;
                white-space: normal;
                background: white;
            }
            nav#main-navigation ul li a:hover { transform: none; box-shadow: none; }
            nav#main-navigation .usuario-nav { padding: 14px 20px !important; }
        }

        /* ---------- Panel de administración (sección #admin) ---------- */
        .admin-tabs { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
        .admin-tab-link { padding: 10px 18px; border-radius: 8px; text-decoration: none; font-weight: 700; background: white; color: #386134; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .admin-tab-link.activa { background: #386134; color: white; }
        .admin-tarjeta { background: white; border-radius: 10px; padding: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.06); margin-bottom: 25px; color: #1B3818; }
        .admin-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 15px; }
        .admin-form-grid input { padding: 10px; border-radius: 6px; border: 1px solid #ccc; }
        .admin-form-grid button, .admin-form-grid a { grid-column: span 2; text-align: center; }
        .admin-tabla-wrap { overflow-x: auto; }
        .admin-tabla { width: 100%; border-collapse: collapse; color: #1B3818; }
        .admin-tabla th, .admin-tabla td { padding: 10px; text-align: left; border-bottom: 1px solid #eee; font-size: 0.92em; white-space: nowrap; }
        .admin-tabla th { background: #f0f4ef; }
        .admin-badge { background: #E2A000; color: #1B3818; padding: 2px 8px; border-radius: 10px; font-size: 0.75em; font-weight: 700; }
        .admin-acciones a { margin-right: 10px; text-decoration: none; font-weight: 600; }
        .admin-editar { color: #E2A000; }
        .admin-eliminar { color: #c0392b; }
        .admin-toggle { color: #386134; }
        .form-perfil { display: flex; flex-direction: column; gap: 8px; margin-top: 10px; }
        .form-perfil label { font-size: 0.8em; font-weight: 600; }
        .form-perfil input {
            padding: 8px;
            border-radius: 6px;
            border: 1px solid #ccc;
        }
        .form-perfil button { margin-top: 6px; }
        .cerrar-sesion-panel {
            display: block;
            text-align: center;
            margin-top: 16px;
            color: #c0392b;
            font-weight: 600;
            text-decoration: none;
        }

        /* Bloque que se muestra cuando el contenido está restringido */
        .contenido-bloqueado {
            text-align: center;
            padding: 50px 20px;
            background: #f9f9f9;
            border-radius: 12px;
            max-width: 500px;
            margin: 30px auto;
        }
        .contenido-bloqueado .candado-icono { font-size: 3em; margin-bottom: 10px; }
        .contenido-bloqueado h3 { margin-bottom: 8px; }
        .contenido-bloqueado p { color: #555; margin-bottom: 20px; }
        .contenido-bloqueado .botones-bloqueo {
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap;
        }
    </style>
</head>
<body>

    <div class="top-search">
    <input type="text" id="input-buscar" placeholder="Buscar contenido...">
    <div class="search-icon" id="btn-buscar">🔍</div>
</div>

<header>
        <div class="food-bg img-banana-happy"></div>
        <div class="food-bg img-basket"></div>
        <div class="food-bg img-apple-happy"></div>
        <div class="food-bg img-broccoli-happy"></div>
        <div class="food-bg img-carrot-happy"></div>
        <div class="food-bg img-chef-happy"></div>
        <div class="food-bg img-tomato-happy"></div>
        <div class="food-bg img-orange-happy"></div>

        <div class="logo">
            <img src="logo.jpeg" alt="Llenos de Amor Logo">
            <h1>Llenos de Amor</h1>
        </div>
        <button type="button" id="mobile-menu-btn" class="mobile-menu-btn" aria-label="Abrir menú" aria-expanded="false">
            <span class="barra"></span>
            <span class="barra"></span>
            <span class="barra"></span>
        </button>
        <nav id="main-navigation">
            <ul>
                <li><a href="#inicio" class="nav-link active">Inicio</a></li>
                <li><a href="#menu" class="nav-link">Menú</a></li>
                <li><a href="#nutricion" class="nav-link">Nutrición</a></li>
                <li><a href="#noticias" class="nav-link">Noticias</a></li>
                <li><a href="#juegos" class="nav-link">Juegos</a></li>
                <?php if ($es_admin): ?>
                    <li><a href="#admin" class="nav-link">🛠️ Admin</a></li>
                <?php endif; ?>
                <?php if ($logueado): ?>
                    <li>
                        <a href="javascript:void(0)" class="nav-link usuario-nav" title="Ver mi perfil" onclick="togglePerfil()">
                            <span class="avatar-usuario"><?php echo $inicial_usuario; ?></span>
                            <span class="nombre-usuario-nav"><?php echo htmlspecialchars($nombre_usuario); ?></span>
                        </a>
                    </li>
                    <li><a href="?logout=1" class="nav-link cerrar-sesion-link">Cerrar sesión</a></li>
                <?php else: ?>
                    <li><a href="#registro" class="nav-link">Registro</a></li>
                    <li><a href="#login" class="nav-link">Iniciar Sesión</a></li>
                <?php endif; ?>
            </ul>
        </nav>

        <?php if ($logueado): ?>
        <div id="panel-perfil" class="panel-perfil">
            <div class="panel-perfil-header">
                <span class="avatar-usuario avatar-grande"><?php echo $inicial_usuario; ?></span>
                <div>
                    <h4><?php echo htmlspecialchars($nombre_usuario); ?></h4>
                    <p class="correo-panel"><?php echo htmlspecialchars($_SESSION['usuario_correo']); ?></p>
                </div>
                <button type="button" class="cerrar-panel" onclick="togglePerfil()">×</button>
            </div>

            <?php if ($mensaje_perfil): ?>
                <div class="panel-mensaje"><?php echo $mensaje_perfil; ?></div>
            <?php endif; ?>

            <div class="panel-estadisticas">
                <div class="stat-card">
                    <span class="stat-numero"><?php echo $perfil ? (int) $perfil['visitas'] : 0; ?></span>
                    <span class="stat-label">Visitas</span>
                </div>
                <div class="stat-card">
                    <span class="stat-numero"><?php echo $perfil ? date("d/m/Y", strtotime($perfil['fecha_registro'])) : "-"; ?></span>
                    <span class="stat-label">Miembro desde</span>
                </div>
            </div>

            <details class="panel-juegos">
                <summary>🎮 Mis juegos jugados</summary>
                <div class="lista-juegos">
                    <?php if (empty($mis_juegos)): ?>
                        <p class="sin-juegos">Todavía no has jugado ninguna partida.</p>
                    <?php else: ?>
                        <?php foreach ($mis_juegos as $juego): ?>
                            <div class="juego-item">
                                <div class="juego-info">
                                    <div class="juego-nombre"><?php echo htmlspecialchars($juego['juego']); ?> — <?php echo (int) $juego['puntaje']; ?> pts</div>
                                    <div class="juego-detalle">
                                        <?php echo htmlspecialchars($juego['resultado'] ?? ''); ?> ·
                                        <?php echo date("d/m/Y H:i", strtotime($juego['fecha_juego'])); ?>
                                    </div>
                                </div>
                                <a class="juego-borrar" href="?eliminar_juego=<?php echo $juego['id']; ?>&panel=perfil" title="Borrar esta partida" onclick="return confirm('¿Borrar esta partida de tu historial?');">✕</a>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </details>

            <details class="panel-configuracion">
                <summary>⚙️ Configuración de mi cuenta</summary>
                <form method="POST" class="form-perfil">
                    <label>Nombre</label>
                    <input type="text" name="nombre_perfil" value="<?php echo htmlspecialchars($nombre_usuario); ?>" required>

                    <label>Correo (@gmail.com o @ie.edu.co)</label>
                    <input type="email" name="correo_perfil" value="<?php echo htmlspecialchars($_SESSION['usuario_correo']); ?>" required>

                    <label>Documento</label>
                    <input type="text" name="documento_perfil" value="<?php echo $perfil ? htmlspecialchars($perfil['documento']) : ''; ?>" required>

                    <label>Nueva contraseña (opcional)</label>
                    <input type="password" name="password_perfil" placeholder="Déjalo vacío para no cambiarla">

                    <button type="submit" name="accion_actualizar_perfil" class="button primary">Guardar cambios</button>
                </form>
            </details>

            <?php if ($es_admin): ?>
                <a href="#admin" class="button primary nav-link" style="display:block; text-align:center; margin-top:14px; text-decoration:none;" onclick="togglePerfil()">🛠️ Panel de administración</a>
            <?php endif; ?>

            <a href="?logout=1" class="cerrar-sesion-panel">Cerrar sesión</a>
        </div>
        <?php endif; ?>
    </header>
    <main>
        <section id="inicio" class="tab-section active">
    <div id="inicio-content">
        <div class="hero">
            <div class="hero-carousel">
                <img src="https://www.consumer.es/app/uploads/fly-images/444456/comedor-escolar-saludable-1200x550-cc.jpg" alt="Slide 1">
                <img src="https://ontrack.global/wp-content/uploads/2025/11/2be48b0b71b274b1f8789546e5f5f335ec9555a8.webp" alt="Slide 2">
                <img src="https://png.pngtree.com/thumb_back/fw800/background/20250918/pngtree-school-cafeteria-image_19440351.webp" alt="Slide 3">
                <img src="https://www.colegioelpinar.com/wp-content/uploads/2018/11/Comedor-Escolar-Malaga-El-Pinar-2.jpg" alt="Slide 4">
                <img src="https://casaintur.com/wp-content/uploads/2025/05/comedor-escolar-min-scaled.jpg" alt="Slide 5">
            </div>
            
            <div class="hero-content">
                <h2>Orden y cariño serán la constancia del amor</h2>
                <p>Descubre un mundo de alimentación saludable y educación en nuestro restaurante escolar.</p>
                <div class="hero-buttons">
                    <a href="#registro" class="button primary btn-nav-internal">Comenzar Ahora</a>
                    <a href="#menu" class="button secondary btn-nav-internal">Ver Menú Semanal</a>
                </div>
            </div>
        </div>
        
        </div>
       <div class="container">
                    <h3>¿Qué ofrecemos?</h3>
                    <div class="features">
                        <div class="flip-card feature-flip" tabindex="0" role="button" aria-label="Menú, toca para ver más" onclick="toggleFlip(this)" onkeypress="if(event.key==='Enter')toggleFlip(this)">
                            <div class="flip-card-inner">
                                <div class="flip-card-front">
                                    <img class="feature-item-img" src="https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcSL7jDh9PX9PyTS3A6tWgdz0oHzEeecp3mOArvbfqb3WQ&s=10" alt="Menú">
                                    <h4>Menu</h4>
                                    <span class="flip-hint"> Toca para saber más</span>
                                </div>
                                <div class="flip-card-back">
                                    <h4>Menu</h4>
                                    <p>Podras vigilar el menu que consumes a diario.</p>
                                    <span class="flip-hint">Toca para volver</span>
                                </div>
                            </div>
                        </div>
                        <div class="flip-card feature-flip" tabindex="0" role="button" aria-label="Educación Nutricional, toca para ver más" onclick="toggleFlip(this)" onkeypress="if(event.key==='Enter')toggleFlip(this)">
                            <div class="flip-card-inner">
                                <div class="flip-card-front">
                                    <img class="feature-item-img" src="https://mendozacolectividades.com/wp-content/uploads/2021/05/WhatsApp-Image-2021-05-14-at-1.48.03-PM.jpeg" alt="Educación Nutricional">
                                    <h4>Educación Nutricional</h4>
                                    <span class="flip-hint"> Toca para saber más</span>
                                </div>
                                <div class="flip-card-back">
                                    <h4>Educación Nutricional</h4>
                                    <p>Programas y talleres para aprender sobre hábitos alimenticios conscientes.</p>
                                    <span class="flip-hint"> Toca para volver</span>
                                </div>
                            </div>
                        </div>
                        <div class="flip-card feature-flip" tabindex="0" role="button" aria-label="Ambiente Amigable, toca para ver más" onclick="toggleFlip(this)" onkeypress="if(event.key==='Enter')toggleFlip(this)">
                            <div class="flip-card-inner">
                                <div class="flip-card-front">
                                    <img class="feature-item-img" src="https://prensalibrecasanare.com/uploads/posts/2015-06/1433483888_restaurante-escolar-cocineras-11.jpg" alt="Ambiente Amigable">
                                    <h4>Ambiente Amigable</h4>
                                    <span class="flip-hint"> Toca para saber más</span>
                                </div>
                                <div class="flip-card-back">
                                    <h4>Ambiente Amigable</h4>
                                    <p>Un espacio seguro,divertido para compartir y aprender cada día.</p>
                                    <span class="flip-hint"> Toca para volver</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
</section>
        <!-- Sección Menú Dividida -->
<section id="menu" class="tab-section">
<?php if ($logueado): ?>
    <div class="container">
        <h3>Menú Escolar PAE</h3>

        <?php
        // Menú escolar PAE: 4 semanas, 5 días (Lunes a Viernes) por semana.
        // Cada día trae un único menú completo (bebida + plato + acompañamiento).
        $menu_semanal_pae = [
            [
                'titulo' => 'Semana 1',
                'dias' => [
                    ['dia' => 'Lunes', 'icono' => '🥪', 'preview' => 'Sánduche de queso', 'bebida' => 'Chocolate con leche', 'plato' => 'Sánduche de queso', 'acompanamiento' => 'Torta de zanahoria y fruta entera (Grupo N°1)'],
                    ['dia' => 'Martes', 'icono' => '🐖', 'preview' => 'Carne de cerdo guisada', 'bebida' => 'Agua de panela con leche', 'plato' => 'Carne de cerdo guisada', 'acompanamiento' => 'Puré de yuca y arroz con espinaca'],
                    ['dia' => 'Miércoles', 'icono' => '🍳', 'preview' => 'Huevo revuelto', 'bebida' => 'Bebida chocolatada', 'plato' => 'Huevo revuelto', 'acompanamiento' => 'Arroz blanco con cebolla de rama y tajada de maduro con queso'],
                    ['dia' => 'Jueves', 'icono' => '🥩', 'preview' => 'Carne de res desmechada', 'bebida' => 'Avena fría con leche', 'plato' => 'Carne de res desmechada', 'acompanamiento' => 'Arepa asada, galletas de soda y fruta entera (Grupo N°3)'],
                    ['dia' => 'Viernes', 'icono' => '🍔', 'preview' => 'Torta de lentejas (hamburguesa)', 'bebida' => 'Sorbete de fruta', 'plato' => 'Torta de lentejas para hamburguesa', 'acompanamiento' => 'Pan de hamburguesa, complementos y papas a la francesa'],
                ],
            ],
            [
                'titulo' => 'Semana 2',
                'dias' => [
                    ['dia' => 'Lunes', 'icono' => '🌱', 'preview' => 'Guiso de proteína vegetal', 'bebida' => 'Sorbete de fruta', 'plato' => 'Guiso de proteína vegetal para el arroz', 'acompanamiento' => 'Arroz con guiso de proteína vegetal de soya y vegetales, papas criollas fritas y fruta entera (Grupo N°1)'],
                    ['dia' => 'Martes', 'icono' => '🍳', 'preview' => 'Huevo revuelto con cebolla', 'bebida' => 'Agua de panela con leche', 'plato' => 'Huevo revuelto con cebolla', 'acompanamiento' => 'Arroz blanco, tajadas de plátano maduro y calendadito PAE'],
                    ['dia' => 'Miércoles', 'icono' => '🐖', 'preview' => 'Carne de cerdo en julianas', 'bebida' => 'Bebida fría con avena y leche', 'plato' => 'Carne de cerdo en julianas con cebolla caramelizada', 'acompanamiento' => 'Arroz con cúrcuma y cilantro y puré de papa capira'],
                    ['dia' => 'Jueves', 'icono' => '🥩', 'preview' => 'Carne de res guisada con habichuelas', 'bebida' => 'Sorbete de fruta', 'plato' => 'Carne de res guisada con habichuelas', 'acompanamiento' => 'Pastas tornillo con tomate y albahaca, y croquetas de plátano maduro'],
                    ['dia' => 'Viernes', 'icono' => '🐖', 'preview' => 'Carne de cerdo en salsa criolla', 'bebida' => 'Bebida chocolatada', 'plato' => 'Carne de cerdo en salsa criolla', 'acompanamiento' => 'Papas a la francesa y galletas de soda'],
                ],
            ],
            [
                'titulo' => 'Semana 3',
                'dias' => [
                    ['dia' => 'Lunes', 'icono' => '🐖', 'preview' => 'Carne de cerdo con vegetales', 'bebida' => 'Bebida fría con avena en leche', 'plato' => 'Carne de cerdo con vegetales', 'acompanamiento' => 'Arepa asada y fruta entera (Grupo N°2)'],
                    ['dia' => 'Martes', 'icono' => '🥩', 'preview' => 'Carne de res estofada', 'bebida' => 'Sorbete de fruta', 'plato' => 'Carne de res estofada', 'acompanamiento' => 'Arroz con cabello de ángel'],
                    ['dia' => 'Miércoles', 'icono' => '🍳', 'preview' => 'Huevo frito', 'bebida' => 'Bebida chocolatada', 'plato' => 'Huevo frito', 'acompanamiento' => 'Lentejas guisadas y arroz blanco'],
                    ['dia' => 'Jueves', 'icono' => '🍝', 'preview' => 'Carne de res en salsa napolitana', 'bebida' => 'Agua de panela con leche', 'plato' => 'Carne de res desmechada en salsa napolitana casera', 'acompanamiento' => 'Pasta corta y tajadas de plátano maduro'],
                    ['dia' => 'Viernes', 'icono' => '🍳', 'preview' => 'Huevo revuelto con cebolla', 'bebida' => 'Sorbete de fruta', 'plato' => 'Huevo revuelto con cebolla', 'acompanamiento' => 'Arroz rojo, papas capira con romero y fruta entera (Grupo N°1)'],
                ],
            ],
            [
                'titulo' => 'Semana 4',
                'dias' => [
                    ['dia' => 'Lunes', 'icono' => '🥔', 'preview' => 'Goulash de cerdo y papa', 'bebida' => 'Agua de panela con leche', 'plato' => 'Goulash de carne de cerdo y papa', 'acompanamiento' => 'Arroz con cebolla y cúrcuma'],
                    ['dia' => 'Martes', 'icono' => '🍳', 'preview' => 'Huevo con plátano maduro', 'bebida' => 'Bebida chocolatada', 'plato' => 'Huevo revuelto con plátano maduro', 'acompanamiento' => 'Arroz blanco con cebolla cabezona y fruta entera (Grupo N°1)'],
                    ['dia' => 'Miércoles', 'icono' => '🧀', 'preview' => 'Carne de res desmechada con queso', 'bebida' => 'Bebida fría con avena en leche', 'plato' => 'Carne de res desmechada con queso', 'acompanamiento' => 'Patacones'],
                    ['dia' => 'Jueves', 'icono' => '🐖', 'preview' => 'Carne de cerdo salteada', 'bebida' => 'Sorbete de fruta', 'plato' => 'Carne de cerdo salteada', 'acompanamiento' => 'Arroz con zanahoria y papas con hogao'],
                    ['dia' => 'Viernes', 'icono' => '🍳', 'preview' => 'Huevo con guiso', 'bebida' => 'Agua de panela con leche', 'plato' => 'Huevo con guiso', 'acompanamiento' => 'Arroz blanco, frijoles guisados y tajada de maduro'],
                ],
            ],
        ];
        ?>

        <?php foreach ($menu_semanal_pae as $indice_semana => $semana): ?>
            <details class="menu-semana-bloque" <?php echo $indice_semana === 0 ? 'open' : ''; ?>>
                <summary class="menu-semana-titulo">
                    <span><?php echo htmlspecialchars($semana['titulo']); ?></span>
                    <span class="menu-semana-flecha">▾</span>
                </summary>
                <div class="menu-semana-grid">
                    <?php foreach ($semana['dias'] as $dia): ?>
                        <div class="flip-card menu-flip-card" tabindex="0" role="button" aria-label="<?php echo htmlspecialchars($dia['dia']); ?>, toca para ver el menú" onclick="toggleFlip(this)" onkeypress="if(event.key==='Enter')toggleFlip(this)">
                            <div class="flip-card-inner">
                                <div class="flip-card-front">
                                    <div class="dia-info"><span class="dia-foto"><?php echo $dia['icono']; ?></span><span class="dia-nombre"><?php echo htmlspecialchars($dia['dia']); ?></span></div>
                                    <span class="plato-principal-preview"><?php echo htmlspecialchars($dia['preview']); ?></span>
                                    <span class="flip-hint">👆 Toca para ver el menú</span>
                                </div>
                                <div class="flip-card-back">
                                    <div class="menu-detalles-bloque">
                                        <p><strong>Bebida:</strong> <?php echo htmlspecialchars($dia['bebida']); ?></p>
                                        <p><strong>Comida:</strong> <?php echo htmlspecialchars($dia['plato'] . ', ' . $dia['acompanamiento']); ?></p>
                                    </div>
                                    <span class="flip-hint">↩️ Toca para volver</span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="contenido-bloqueado">
        <div class="candado-icono">🔒</div>
        <h3>Este contenido es solo para usuarios registrados</h3>
        <p>Inicia sesión o crea una cuenta gratis para ver el menú semanal completo.</p>
        <div class="botones-bloqueo">
            <a href="#login" class="button primary btn-nav-internal">Iniciar Sesión</a>
            <a href="#registro" class="button secondary btn-nav-internal">Registrarme</a>
        </div>
    </div>
<?php endif; ?>
</section>

<section id="nutricion" class="tab-section">
<?php if ($logueado): ?>
    <div class="container">
        <h3>Educación Nutricional</h3>
        
        <div class="nutricion-container" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 30px;">
            <?php
            $cards = [
                ["Distribución de Macronutrientes", "50% Hortalizas, 25% Almidones complejos, 25% Proteínas.", "La combinación ideal para mantener niveles de glucosa estables.","https://cdn.aarp.net/content/dam/aarp/food/healthy-eating/2019/05/1140-counting-macros-promo-esp.jpg"],
                ["Hidratación Neuronal", "Mantener el equilibrio osmótico es clave para la memoria.", "El cerebro depende de una correcta hidratación.","https://gsinapsis.com/wp-content/uploads/2026/03/hidratacion-y-cerebro.webp"],
                ["El Papel del Hierro", "Vital para el transporte de oxígeno hacia el cerebro.", "Su carencia provoca fatiga escolar.","https://www.mnsa.es/wp-content/uploads/hierro-imgp.jpg"],
                ["Azúcar vs. Energía", "Los picos de glucosa dañan tu concentración.", "Evita los azúcares refinados.","https://www.fisiologiadelejercicio.com/wp-content/uploads/2023/06/azucar-scaled.jpg"],
                ["Beneficios de la Fibra", "Regula la absorción de nutrientes.", "La fibra ralentiza la digestión.","https://colbritanico.edu.co/wp-content/uploads/sites/27/2025/01/fibra-coetica-1.jpg"],
                ["El rol de los Omega-3", "Fundamentales para las membranas neuronales.", "Mejoran la velocidad de aprendizaje.","https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcSyjoLV7OqARDYBHMXMGDSCxHGkKnjSGpZ3Aw&s"],
                ["Vitaminas del grupo B", "Cruciales para convertir alimentos en energía.", "Actúan como coenzimas.","https://institutodyn.com/wp-content/uploads/vitaminas-del-grupo-b.jpg"],
                ["Calcio y Salud Ósea", "Indispensable durante la etapa de crecimiento.", "Asegura una densidad ósea adecuada.","https://cdn.static.aptavs.com/imagenes/alimentos-ricos-en-calcio_905x603.jpg"]
            ];

          // Dentro de tu ciclo foreach en pagina.php, ajusta el bloque echo:
foreach ($cards as $index => $c) {
    // Verificamos si existe la URL de la imagen en el arreglo (índice 3)
    $urlImagen = isset($c[3]) ? $c[3] : 'ruta/imagen-por-defecto.jpg';
    
    echo '
    <div class="flip-card nutri-flip-card" tabindex="0" role="button" aria-label="' . $c[0] . ', toca para ver más" onclick="toggleFlip(this)" onkeypress="if(event.key===\'Enter\')toggleFlip(this)">
        <div class="flip-card-inner">
            <div class="flip-card-front">
                <img src="' . $urlImagen . '" class="card-img-top" alt="' . $c[0] . '">
                <div class="card-body">
                    <h5 class="card-title">' . $c[0] . '</h5>
                    <p class="card-text">' . $c[1] . '</p>
                    <span class="flip-hint">👆 Toca para saber más</span>
                </div>
            </div>
            <div class="flip-card-back">
                <div class="card-body">
                    <h5 class="card-title">' . $c[0] . '</h5>
                    <p class="card-text">' . $c[2] . '</p>
                    <span class="flip-hint">↩️ Toca para volver</span>
                </div>
            </div>
        </div>
    </div>';
}
            ?>
        </div>
    </div>
</div>
       
       <div class="nutri-card">
                    <h4>Clasificación de Alimentos por Densidad Nutricional</h4>
                    <p>Selecciona una categoría para analizar el impacto que tienen los distintos tipos de alimentos en el cuerpo:</p>
                    
                    <div class="semaforo-botones">
                        <button onclick="mostrarSemaforo('verde')" style="background: #2ecc71; color: white;">Verde: Base Alimentaria</button>
                        <button onclick="mostrarSemaforo('amarillo')" style="background: #f1c40f; color: #2c3e50;">Amarillo: Combustible Esencial</button>
                        <button onclick="mostrarSemaforo('rojo')" style="background: #e74c3c; color: white;">Rojo: Restricción Crítica</button>
                    </div>

                    <div id="panel-semaforo"></div>
                </div>

                <div class="nutri-card">
                    <h4>Calculadora de Estado y Requerimiento Energético</h4>
                    <p>Introduce tus datos para conocer tu Índice de Masa Corporal (IMC) y estimar tu Tasa Metabólica Basal (TMB) según las fórmulas estandarizadas de la OMS para jóvenes en desarrollo:</p>
                    
                    <div class="imc-inputs">
                        <input type="number" id="peso" placeholder="Peso en kg (Ej: 56)">
                        <input type="number" id="estatura" placeholder="Estatura en metros (Ej: 1.65)" step="0.01">
                        <select id="genero">
                            <option value="">Selecciona tu género</option>
                            <option value="masculino">Masculino</option>
                            <option value="femenino">Femenino</option>
                        </select>
                        <button type="button" onclick="calcularCalculadoraAvanzada()" class="button primary">Calcular Parámetros</button>
                    </div>

                    <div class="barra-imc" id="barra-imc-contenedor">
                        <div class="barra-imc-flex">
                            <div class="zona-bajo">Bajo</div>
                            <div class="zona-normal">Normal</div>
                            <div class="zona-sobre">Sobrepeso</div>
                            <div class="zona-obesidad">Obesidad</div>
                        </div>
                        <div id="indicador-imc"></div>
                    </div>
                    
                    <div id="resultado-imc-nuevo"></div>
                </div>

                <div class="nutri-card">
                    <h4>Desafío de Conocimiento Nutricional</h4>
                    <p>Responde las preguntas de base científica para poner a prueba tus conceptos biológicos alimentarios:</p>
                    
                    <div class="quiz-container">
                        <div id="bloque-quiz">
                            <span id="quiz-progreso" style="font-size: 0.85em; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;">Pregunta 1 de 3</span>
                            <p id="quiz-pregunta" style="font-size: 1.15em; font-weight: 600; margin-top: 5px; color: #1e293b;"></p>
                            
                            <div class="opciones-quiz" id="quiz-opciones"></div>
                            <div id="explicacion-quiz"></div>
                            
                            <button id="btn-siguiente-quiz" class="button primary" style="display: none; margin-top: 15px;" onclick="cargarSiguientePregunta()">Siguiente Pregunta</button>
                        </div>
                        
                        <div id="resultado-final-quiz" style="display: none; text-align: center; padding: 20px;">
                            <h5 style="font-size: 1.4em; margin: 0 0 10px 0; color: #386134;">¡Evaluación Concluida!</h5>
                            <p id="puntuacion-quiz-texto" style="font-size: 1.1em; color: #334155;"></p>
                            <button class="button secondary" style="margin-top: 15px;" onclick="reiniciarQuiz()">Volver a Intentarlo</button>
                        </div>
                    </div>
                </div>

                <div class="nutri-card" style="border-left: 5px solid #c0392b;">
                    <h4 style="color: #c0392b;">Riesgos de los Alimentos Ultraprocesados</h4>
                    <p style="color: #4a5568; line-height: 1.6; margin-bottom: 0;">Las formulaciones industriales de consumo masivo (aperitivos empacados, bebidas azucaradas gasificadas) pasan por procesos de refinamiento extremos que eliminan los componentes matriciales de los alimentos. Su alta concentración de sodio, ácidos grasos trans y jarabe de maíz de alta fructosa altera los ejes neuroendocrinos de la saciedad, induciendo a un consumo crónico inconsciente que desregula la microbiota e incrementa los marcadores inflamatorios celulares.</p>
                </div>
            </div>
<?php else: ?>
    <div class="contenido-bloqueado">
        <div class="candado-icono">🔒</div>
        <h3>Contenido exclusivo para usuarios registrados</h3>
        <p>Inicia sesión o crea una cuenta gratis para ver toda la educación nutricional.</p>
        <div class="botones-bloqueo">
            <a href="#login" class="button primary btn-nav-internal">Iniciar Sesión</a>
            <a href="#registro" class="button secondary btn-nav-internal">Registrarme</a>
        </div>
    </div>
<?php endif; ?>
        </section>
                

        <section id="registro" class="tab-section">
            <div class="container">
                <h3>Formulario de Registro</h3>
                <div class="form-container">
                    <?php echo $mensaje_registro; ?>
                    <form action="#registro" method="POST" onsubmit="return validarRegistro()">
                        <input type="text" name="nombre" id="reg-nombre" placeholder="Nombre completo" required>
                        <input type="email" name="correo" id="reg-correo" placeholder="Correo (@gmail.com)" required>
                        <input type="number" name="documento" placeholder="Documento de identidad" required>
                        <input type="password" name="password" id="reg-pass" placeholder="Crear contraseña" required>
                        <button type="submit" name="accion_registro" class="button primary" style="width: 100%; border-radius: 8px;">Registrarse</button>
                    </form>
                </div>
            </div>
        </section>

        <section id="login" class="tab-section">
            <div class="container">
                <h3>Acceso al Sistema</h3>
                <div class="form-container">
                    <?php echo $mensaje_login; ?>
                    <form action="#login" method="POST">
                        <input type="email" name="correo_login" placeholder="Correo electrónico" required>
                        <input type="password" name="password_login" placeholder="Contraseña" required>
                        <button type="submit" name="accion_login" class="button primary" style="width: 100%; border-radius: 8px;">Entrar</button>
                    </form>
                    <p style="text-align: center; margin-top: 20px; font-size: 0.9em;">
                        <a href="#registro" class="btn-nav-internal" style="color: #386134; text-decoration: none; font-weight: 600;">¿Olvidaste tu contraseña?</a>
                    </p>
                </div>
            </div>
           <div class="chatbot-container" id="chat-container">
        <button class="chatbot-toggle" id="chat-toggle" onclick="toggleChat()">
            💬 <span style="font-size: 0.8em; margin-left: 5px;">Nutribot</span>
        </button>

        <div class="chatbot-box" id="chat-box" style="display: none;">
            <div class="chatbot-header">
                <h4>🍎 Nutribot</h4>
                <button onclick="toggleChat()" style="background:none; border:none; color:white; font-size:1.2em; cursor:pointer;">×</button>
            </div>
            <div class="chatbot-messages" id="chat-messages">
                <div class="msg-bot">¡Hola! Soy Nutribot 🤖, tu asistente del restaurante escolar "Llenos de Amor". ¿En qué te puedo ayudar hoy?</div>
            </div>
            <div class="chatbot-input-area">
                <input type="text" id="chat-input" placeholder="Pregúntame sobre el menú o nutrición..." onkeypress="evaluarEnterChat(event)">
                <button onclick="enviarMensajeChat()">Enviar</button>
            </div>
        </div>
    </div>
        </section>
    <!-- ===== SECCIÓN NOTICIAS / EVENTOS ===== -->
    <section id="noticias" class="tab-section">
        <div class="container">
            <h3>📰 Noticias y Eventos</h3>

            <!-- Ticker de noticias rápidas -->
            <div class="ticker-wrapper">
                <span class="ticker-label">🔔 HOY:</span>
                <div class="ticker-track">
                    <span>🥗 Semana de la Alimentación Saludable — del 16 al 20 de junio &nbsp;|&nbsp; 🏆 Concurso de recetas escolares: inscríbete hasta el viernes &nbsp;|&nbsp; 🍎 Nuevo menú de frutas tropicales disponible en la tienda escolar &nbsp;|&nbsp; 📅 Charla de nutricionista: jueves 19 de junio, 10:00 a.m. &nbsp;|&nbsp; 🎨 Taller de manualidades con vegetales — aula 204, este sábado</span>
                </div>
            </div>

            <!-- Tarjetas de eventos próximos -->
            <div class="noticias-grid">

                <div class="noticia-card destacada">
                    <div class="noticia-badge">📌 Destacado</div>
                    <div class="noticia-fecha">Lunes 16 · Junio 2026</div>
                    <h4>Semana de la Alimentación Saludable</h4>
                    <p>Durante toda la semana realizaremos actividades pedagógicas, degustaciones y talleres para fomentar hábitos alimenticios sanos en toda la comunidad escolar.</p>
                    <div class="noticia-meta">🕘 Todo el día &nbsp;|&nbsp; 📍 Instalaciones del colegio</div>
                </div>

                <div class="noticia-card">
                    <div class="noticia-fecha">Miércoles 18 · Junio 2026</div>
                    <h4>🏆 Concurso de Recetas Saludables</h4>
                    <p>Presenta tu receta más creativa y nutritiva. Los tres mejores platillos serán incluidos en el menú oficial del restaurante. ¡Inscríbete con tu profesor!</p>
                    <div class="noticia-meta">🕙 10:00 a.m. &nbsp;|&nbsp; 📍 Aula de Tecnología</div>
                </div>

                <div class="noticia-card">
                    <div class="noticia-fecha">Jueves 19 · Junio 2026</div>
                    <h4>🎙️ Charla: Nutrición en la Adolescencia</h4>
                    <p>La nutricionista Dra. Marcela Ospina nos hablará sobre los requerimientos energéticos en la etapa escolar y cómo cubrir las necesidades con la alimentación del restaurante.</p>
                    <div class="noticia-meta">🕙 10:00 a.m. &nbsp;|&nbsp; 📍 Auditorio principal</div>
                </div>

                <div class="noticia-card">
                    <div class="noticia-fecha">Viernes 20 · Junio 2026</div>
                    <h4>🎨 Taller: Arte con Alimentos</h4>
                    <p>Aprende a hacer figuras decorativas con frutas y verduras. Una actividad lúdica para promover el amor por los alimentos naturales en los estudiantes de primaria.</p>
                    <div class="noticia-meta">🕑 2:00 p.m. &nbsp;|&nbsp; 📍 Aula 204</div>
                </div>

                <div class="noticia-card">
                    <div class="noticia-fecha">Sábado 21 · Junio 2026</div>
                    <h4>🌱 Inauguración Huerta Escolar</h4>
                    <p>¡Inauguramos nuestra nueva huerta comunitaria! Los estudiantes podrán sembrar, cuidar y cosechar sus propios vegetales para la cocina del restaurante escolar.</p>
                    <div class="noticia-meta">🕙 9:00 a.m. &nbsp;|&nbsp; 📍 Zona Verde</div>
                </div>

                <div class="noticia-card logro">
                    <div class="noticia-badge logro-badge">🏅 Logro</div>
                    <div class="noticia-fecha">Mayo 2026</div>
                    <h4>Premio Departamental al Restaurante Escolar</h4>
                    <p>¡Recibimos el reconocimiento de Alimentación Escolar Ejemplar de Antioquia 2026! Un orgullo para toda nuestra comunidad educativa.</p>
                    <div class="noticia-meta">🎉 Reconocimiento oficial de la Gobernación de Antioquia</div>
                </div>

            </div>

            <!-- Contador regresivo al próximo evento -->
            <div class="evento-contador">
                <h4>⏳ Próximo evento en:</h4>
                <div class="countdown-display">
                    <div class="cd-bloque"><span id="cd-dias">--</span><small>Días</small></div>
                    <div class="cd-sep">:</div>
                    <div class="cd-bloque"><span id="cd-horas">--</span><small>Horas</small></div>
                    <div class="cd-sep">:</div>
                    <div class="cd-bloque"><span id="cd-mins">--</span><small>Minutos</small></div>
                    <div class="cd-sep">:</div>
                    <div class="cd-bloque"><span id="cd-segs">--</span><small>Segundos</small></div>
                </div>
                <p style="color:#666; margin-top:8px; font-size:0.9em;">Semana de la Alimentación Saludable — Lunes 16 de junio</p>
            </div>

        </div>
    </section>

    <!-- ===== SECCIÓN JUEGOS ===== -->
    <section id="juegos" class="tab-section">
<?php if ($logueado): ?>
        <div class="container">
            <h3>🎮 Juegos Educativos</h3>
            <p style="text-align:center; color:#666; margin-top:-25px; margin-bottom:40px;">¡Aprende jugando sobre nutrición y alimentación saludable!</p>

            <div class="juegos-grid">

                <!-- JUEGO 1: Ordena el Plato -->
                <div class="juego-card">
                    <div class="juego-icono">🍽️</div>
                    <h4>Arma tu Plato Saludable</h4>
                    <p>Arrastra los alimentos al plato y verifica si tu combinación es equilibrada según el plato saludable de la OMS.</p>
                    <div id="juego-plato">
                        <div class="alimentos-disponibles" id="alimentos-pool">
                            <span class="alimento-drag" draggable="true" data-tipo="verdura" data-nombre="Brócoli">🥦</span>
                            <span class="alimento-drag" draggable="true" data-tipo="proteina" data-nombre="Pollo">🍗</span>
                            <span class="alimento-drag" draggable="true" data-tipo="carbohidrato" data-nombre="Arroz">🍚</span>
                            <span class="alimento-drag" draggable="true" data-tipo="fruta" data-nombre="Manzana">🍎</span>
                            <span class="alimento-drag" draggable="true" data-tipo="verdura" data-nombre="Zanahoria">🥕</span>
                            <span class="alimento-drag" draggable="true" data-tipo="proteina" data-nombre="Huevo">🥚</span>
                            <span class="alimento-drag" draggable="true" data-tipo="carbohidrato" data-nombre="Pan integral">🍞</span>
                            <span class="alimento-drag" draggable="true" data-tipo="malo" data-nombre="Gaseosa">🥤</span>
                            <span class="alimento-drag" draggable="true" data-tipo="malo" data-nombre="Papas fritas">🍟</span>
                            <span class="alimento-drag" draggable="true" data-tipo="fruta" data-nombre="Banano">🍌</span>
                        </div>
                        <div class="plato-drop-area" id="plato-drop" ondragover="event.preventDefault()" ondrop="soltarEnPlato(event)">
                            <div class="plato-circulo">
                                <div id="plato-items-list"></div>
                                <p id="plato-hint" style="color:#aaa; font-size:0.8em; margin:0;">Suelta aquí tus alimentos</p>
                            </div>
                        </div>
                    </div>
                    <div style="display:flex; gap:10px; justify-content:center; margin-top:12px; flex-wrap:wrap;">
                        <button class="button primary" onclick="evaluarPlato()">Evaluar mi plato 🔍</button>
                        <button class="button secondary" style="color:#386134; border-color:#386134;" onclick="limpiarPlato()">Limpiar 🗑️</button>
                    </div>
                    <div id="resultado-plato" style="margin-top:12px; font-weight:600; text-align:center; min-height:24px;"></div>
                </div>

                <!-- JUEGO 2: Adivina el Alimento -->
                <div class="juego-card">
                    <div class="juego-icono">🔍</div>
                    <h4>Adivina el Alimento</h4>
                    <p>Lee la pista y escribe el nombre del alimento correcto. ¡Tienes 3 intentos por pregunta!</p>
                    <div id="juego-adivina">
                        <div class="adivina-pista" id="adivina-pista-box">
                            <span id="adivina-pista-texto">Cargando pista...</span>
                        </div>
                        <div style="display:flex; gap:8px; margin-top:12px; flex-wrap:wrap; justify-content:center;">
                            <input type="text" id="adivina-input" placeholder="Escribe el alimento..." style="flex:1; min-width:150px; padding:10px 14px; border:2px solid #ddd; border-radius:10px; font-size:1em;" onkeypress="if(event.key==='Enter') verificarAdivina()">
                            <button class="button primary" onclick="verificarAdivina()">Verificar ✅</button>
                        </div>
                        <div id="adivina-feedback" style="margin-top:10px; font-weight:600; text-align:center; min-height:22px;"></div>
                        <div style="display:flex; justify-content:space-between; margin-top:10px; font-size:0.85em; color:#666;">
                            <span>Intentos restantes: <strong id="adivina-intentos">3</strong></span>
                            <span>Puntos: <strong id="adivina-puntos">0</strong></span>
                        </div>
                        <button class="button secondary" style="color:#386134; border-color:#386134; margin-top:10px; width:100%;" onclick="siguientePistaAdivina()">Siguiente pista ➡️</button>
                    </div>
                </div>

                <!-- JUEGO 3: Memoria Nutricional -->
                <div class="juego-card">
                    <div class="juego-icono">🧠</div>
                    <h4>Memoria Nutricional</h4>
                    <p>Encuentra los pares de alimentos y sus grupos nutricionales. ¡Entrena tu memoria y tus conocimientos!</p>
                    <div id="tablero-memoria" style="display:grid; grid-template-columns: repeat(4,1fr); gap:8px; margin-top:12px;"></div>
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:12px;">
                        <span style="font-size:0.85em; color:#666;">Pares encontrados: <strong id="mem-pares">0</strong>/6</span>
                        <button class="button primary" style="padding:8px 18px; font-size:0.85em;" onclick="iniciarMemoria()">Reiniciar 🔄</button>
                    </div>
                    <div id="mem-resultado" style="text-align:center; font-weight:700; color:#386134; margin-top:8px; min-height:22px;"></div>
                </div>

                <!-- JUEGO 4: Reacciones rápidas -->
                <div class="juego-card">
                    <div class="juego-icono">⚡</div>
                    <h4>¡Atrapa lo Saludable!</h4>
                    <p>Haz clic en los alimentos saludables que aparecen. ¡Evita los ultraprocesados! Tienes 20 segundos.</p>
                    <div id="juego-atrapa-area" style="position:relative; height:200px; background:#f0faf0; border-radius:12px; overflow:hidden; border:2px dashed #a8d5a2; margin-top:12px;">
                        <div id="atrapa-inicio-msg" style="position:absolute; inset:0; display:flex; align-items:center; justify-content:center; flex-direction:column; gap:8px;">
                            <span style="font-size:2em;">🥦</span>
                            <button class="button primary" onclick="iniciarAtrapa()">¡Jugar!</button>
                        </div>
                    </div>
                    <div style="display:flex; justify-content:space-between; margin-top:10px; font-size:0.9em;">
                        <span>⏱️ Tiempo: <strong id="atrapa-timer">20</strong>s</span>
                        <span>✅ Saludables: <strong id="atrapa-puntos">0</strong></span>
                        <span>❌ Malos: <strong id="atrapa-penalizacion">0</strong></span>
                    </div>
                    <div id="atrapa-fin" style="display:none; text-align:center; font-weight:700; color:#386134; margin-top:8px;"></div>
                </div>

            </div>
        </div>
<?php else: ?>
    <div class="contenido-bloqueado">
        <div class="candado-icono">🔒</div>
        <h3>Los juegos son solo para usuarios registrados</h3>
        <p>Inicia sesión o crea una cuenta gratis para jugar y aprender sobre nutrición.</p>
        <div class="botones-bloqueo">
            <a href="#login" class="button primary btn-nav-internal">Iniciar Sesión</a>
            <a href="#registro" class="button secondary btn-nav-internal">Registrarme</a>
        </div>
    </div>
<?php endif; ?>
    </section>

    <?php if ($es_admin): ?>
    <section id="admin" class="tab-section panel-admin-seccion">
      <div class="container">
        <h3>🛠️ Panel de administración</h3>

        <div class="admin-tabs">
            <a href="?seccion_admin=usuarios#admin" class="admin-tab-link <?php echo $seccion_admin === 'usuarios' ? 'activa' : ''; ?>">👤 Usuarios</a>
            <a href="?seccion_admin=juegos#admin" class="admin-tab-link <?php echo $seccion_admin === 'juegos' ? 'activa' : ''; ?>">🎮 Juegos jugados</a>
        </div>

        <?php if ($mensaje_admin): ?>
            <div class="admin-tarjeta"><?php echo $mensaje_admin; ?></div>
        <?php endif; ?>

        <?php if ($seccion_admin === 'usuarios'): ?>
            <div class="admin-tarjeta">
                <?php if ($usuario_editar_admin): ?>
                    <h3>Editar usuario</h3>
                    <form class="admin-form-grid" method="POST" action="pagina.php">
                        <input type="hidden" name="id" value="<?php echo $usuario_editar_admin['id']; ?>">
                        <input type="text" name="nombre" placeholder="Nombre completo" value="<?php echo htmlspecialchars($usuario_editar_admin['nombre']); ?>" required>
                        <input type="email" name="correo" placeholder="Correo" value="<?php echo htmlspecialchars($usuario_editar_admin['correo']); ?>" required>
                        <input type="text" name="documento" placeholder="Documento" value="<?php echo htmlspecialchars($usuario_editar_admin['documento']); ?>" required>
                        <input type="password" name="password" placeholder="Nueva contraseña (déjalo vacío para no cambiarla)">
                        <button type="submit" name="accion_actualizar_usuario_admin" class="button primary">Guardar cambios</button>
                        <a href="?seccion_admin=usuarios#admin" class="button secondary" style="text-align:center;">Cancelar</a>
                    </form>
                <?php else: ?>
                    <h3>Crear nuevo usuario</h3>
                    <form class="admin-form-grid" method="POST" action="pagina.php">
                        <input type="text" name="nombre" placeholder="Nombre completo" required>
                        <input type="email" name="correo" placeholder="Correo (@gmail.com o @ie.edu.co)" required>
                        <input type="text" name="documento" placeholder="Documento" required>
                        <input type="password" name="password" placeholder="Contraseña (mín. 6 caracteres)" required>
                        <button type="submit" name="accion_crear_usuario_admin" class="button primary">Crear usuario</button>
                    </form>
                <?php endif; ?>
            </div>

            <div class="admin-tarjeta admin-tabla-wrap">
                <h3>Usuarios registrados (<?php echo count($usuarios_admin); ?>)</h3>
                <table class="admin-tabla">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nombre</th>
                            <th>Correo</th>
                            <th>Documento</th>
                            <th>Registro</th>
                            <th>Rol</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($usuarios_admin) === 0): ?>
                            <tr><td colspan="7" style="text-align:center;">Aún no hay usuarios registrados.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($usuarios_admin as $u): ?>
                            <tr>
                                <td><?php echo $u['id']; ?></td>
                                <td><?php echo htmlspecialchars($u['nombre']); ?></td>
                                <td><?php echo htmlspecialchars($u['correo']); ?></td>
                                <td><?php echo htmlspecialchars($u['documento']); ?></td>
                                <td><?php echo htmlspecialchars($u['fecha_registro']); ?></td>
                                <td><?php echo ((int) $u['es_admin'] === 1) ? '<span class="admin-badge">Admin</span>' : 'Usuario'; ?></td>
                                <td class="admin-acciones">
                                    <a class="admin-editar" href="?admin_accion=editar&id=<?php echo $u['id']; ?>#admin">Editar</a>
                                    <a class="admin-toggle" href="?toggle_admin=<?php echo $u['id']; ?>#admin" onclick="return confirm('¿Cambiar el rol de administrador de este usuario?');">
                                        <?php echo ((int) $u['es_admin'] === 1) ? 'Quitar admin' : 'Hacer admin'; ?>
                                    </a>
                                    <a class="admin-eliminar" href="?eliminar_usuario_admin=<?php echo $u['id']; ?>#admin" onclick="return confirm('¿Seguro que quieres eliminar este usuario?');">Eliminar</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($seccion_admin === 'juegos'): ?>
            <div class="admin-tarjeta admin-tabla-wrap">
                <h3>Partidas registradas (<?php echo count($todos_los_juegos_admin); ?>)</h3>
                <table class="admin-tabla">
                    <thead>
                        <tr>
                            <th>Usuario</th>
                            <th>Correo</th>
                            <th>Juego</th>
                            <th>Puntaje</th>
                            <th>Resultado</th>
                            <th>Fecha</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($todos_los_juegos_admin) === 0): ?>
                            <tr><td colspan="7" style="text-align:center;">Todavía no hay partidas registradas.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($todos_los_juegos_admin as $j): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($j['nombre_usuario']); ?></td>
                                <td><?php echo htmlspecialchars($j['correo_usuario']); ?></td>
                                <td style="text-transform:capitalize;"><?php echo htmlspecialchars($j['juego']); ?></td>
                                <td><?php echo (int) $j['puntaje']; ?></td>
                                <td><?php echo htmlspecialchars($j['resultado'] ?? ''); ?></td>
                                <td><?php echo date("d/m/Y H:i", strtotime($j['fecha_juego'])); ?></td>
                                <td class="admin-acciones">
                                    <a class="admin-eliminar" href="?seccion_admin=juegos&eliminar_juego_admin=<?php echo $j['id']; ?>#admin" onclick="return confirm('¿Eliminar esta partida del historial?');">Eliminar</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
      </div>
    </section>
    <?php endif; ?>

    </main>

    <footer>
        <div class="container" style="padding: 0;">
            <p><strong>Llenos de Amor</strong></p>
            <p>I.E Presbitero Montoya Giraldo, Antioquia &copy; 2026</p>
        </div>
    </footer>

    <script>
        // LÓGICA DE NAVEGACIÓN Y MENÚ ACORDEÓN
        const links = document.querySelectorAll('.nav-link, .btn-nav-internal');
        const sections = document.querySelectorAll('.tab-section');

        function cambiarPestana(targetId) {
            sections.forEach(section => {
                section.classList.remove('active');
                if(section.id === targetId) {
                    section.classList.add('active');
                }
            });

            document.querySelectorAll('.nav-link').forEach(link => {
                link.classList.remove('active');
                if(link.getAttribute('href') === `#${targetId}`) {
                    link.classList.add('active');
                }
            });
        }

        links.forEach(link => {
            link.addEventListener('click', function(e) {
                const targetId = this.getAttribute('href').substring(1);
                if(document.getElementById(targetId)) {
                    e.preventDefault();
                    cambiarPestana(targetId);
                    window.location.hash = targetId;
                }
            });
        });

        window.addEventListener('load', () => {
            const hash = window.location.hash.substring(1);
            if (hash && document.getElementById(hash)) {
                cambiarPestana(hash);
            }
            cargarPreguntaQuiz(); 
        });

        // Función única para todas las flipcards del sitio (inicio, menú y nutrición)
        function toggleFlip(tarjeta) {
            tarjeta.classList.toggle('flipped');
        }

        function validarRegistro() {
            const correo = document.getElementById('reg-correo').value.toLowerCase();
            const password = document.getElementById('reg-pass').value;

            if (!correo.endsWith('@gmail.com') && !correo.endsWith('@ie.edu.co')) {
                alert('¡Atención! Usa un correo @gmail.com o tu correo institucional (@ie.edu.co).');
                return false;
            }

            if (password.length < 6) {
                alert('La contraseña debe tener al menos 6 caracteres por seguridad.');
                return false;
            }
            return true;
        }

        function mostrarSemaforo(color) {
            const panel = document.getElementById('panel-semaforo');
            panel.style.display = 'block';
            
            if (color === 'verde') {
                panel.style.borderColor = '#2ecc71';
                panel.innerHTML = `<h5 style="margin:0 0 5px 0; color:#2ecc71; font-size:1.1em;">Alimentos Verdes (Consumo Regular Obligatorio)</h5>
                                   <p style="margin:0; color:#555;">Representados por hortalizas foliares, verduras crudas y frutos enteros. El consumo de estos alimentos aporta fibra dietética insoluble que previene la respuesta glucémica elevada y protege las vellosidades intestinales encargadas de la absorción eficiente de nutrientes.</p>`;
            } else if (color === 'amarillo') {
                panel.style.borderColor = '#f1c40f';
                panel.innerHTML = `<h5 style="margin:0 0 5px 0; color:#f1c40f; font-size:1.1em;">Alimentos Amarillos (Consumo Dosificado)</h5>
                                   <p style="margin:0; color:#555;">Incluye proteínas animales magras, tubérculos, legumbres y cereales como el arroz. Proveen la densidad proteica y calórica indispensable para sostener el crecimiento de los tejidos y reponer el glucógeno muscular degradado en las jornadas escolares.</p>`;
            } else if (color === 'rojo') {
                panel.style.borderColor = '#e74c3c';
                panel.innerHTML = `<h5 style="margin:0 0 5px 0; color:#e74c3c; font-size:1.1em;">Alimentos Rojos (Consumo Fuertemente Restringido)</h5>
                                   <p style="margin:0; color:#555;">Involucra azúcares refinados, grasas saturadas e hidrogenadas, y ultraprocesados. Provocan desbalances hemodinámicos y picos insulínicos elevados seguidos de fatiga extrema, mermando los procesos de memorización e incrementando el riesgo metabólico a largo plazo.</p>`;
            }
        }

        function calcularCalculadoraAvanzada() {
            const peso = parseFloat(document.getElementById('peso').value);
            const estatura = parseFloat(document.getElementById('estatura').value);
            const genero = document.getElementById('genero').value;
            const resultadoDiv = document.getElementById('resultado-imc-nuevo');
            const barraContenedor = document.getElementById('barra-imc-contenedor');
            const indicador = document.getElementById('indicador-imc');

            if (!peso || !estatura || estatura <= 0 || !genero) {
                resultadoDiv.innerHTML = "<span style='color:#e74c3c; font-weight:600;'>Por favor, introduce peso, estatura y selecciona un género válido para ejecutar los algoritmos.</span>";
                return;
            }

            const imc = peso / (estatura * estatura);
            barraContenedor.style.display = 'block';

            let clasificacion = "";
            let colorHex = "";
            let porcentajePosicion = 50;

            if (imc < 18.5) {
                clasificacion = "Bajo peso por debajo de los estándares ponderales.";
                colorHex = "#f1c40f";
                porcentajePosicion = 10;
            } else if (imc >= 18.5 && imc < 25) {
                clasificacion = "Rango normopeso o composición corporal saludable.";
                colorHex = "#2ecc71";
                porcentajePosicion = 38;
            } else if (imc >= 25 && imc < 30) {
                clasificacion = "Sobrepeso con acumulación lipídica moderada.";
                colorHex = "#e67e22";
                porcentajePosicion = 62;
            } else {
                clasificacion = "Obesidad con requerimiento de intervención médica.";
                colorHex = "#e74c3c";
                porcentajePosicion = 88;
            }

            indicador.style.left = porcentajePosicion + "%";

            let tmb = 0;
            if (genero === "masculino") {
                tmb = (17.5 * peso) + 651;
            } else {
                tmb = (12.2 * peso) + 746;
            }

            resultadoDiv.innerHTML = `
                <div style="border-left: 4px solid ${colorHex}; padding-left: 15px;">
                    <p style="margin: 0 0 8px 0;">Tu Índice de Masa Corporal es de <strong>${imc.toFixed(1)} kg/m²</strong>.</p>
                    <p style="margin: 0 0 12px 0; color: ${colorHex}; font-weight: 600; font-size: 1.1em;">Estado: ${clasificacion}</p>
                    <p style="margin: 0; color: #475569; font-size: 0.95em;">
                        Tu Gasto Energético Basal estimado es de <strong>${Math.round(tmb)} calorías diarias</strong>. Esto representa la energía neta mínima que tu organismo requiere para mantener funciones celulares vitales, sin contar las calorías adicionales que gastas al caminar o entrenar.
                    </p>
                </div>
            `;
        }

        const bancoPreguntas = [
            {
                pregunta: "¿Por qué los jugos de fruta licuados no reemplazan el valor nutricional de la fruta entera?",
                opciones: [
                    "Porque las cuchillas de metal destruyen las vitaminas por magnetismo.",
                    "Porque al romperse la matriz de fibra, la fructosa pasa a actuar como azúcar libre de rápida absorción.",
                    "Porque el jugo pierde el agua líquida original de la fruta."
                ],
                correcta: 1,
                explicacion: "Al procesar mecánicamente la fruta se elimina la acción de la fibra dietética. El hígado absorbe la fructosa líquida de forma inmediata, generando picos de insulina idénticos a los de una bebida procesada, mientras que comer la fruta entera ralentiza la absorción."
            },
            {
                pregunta: "¿Qué efecto directo produce el consumo de ultraprocesados en el rendimiento del estudiante?",
                opciones: [
                    "Aumenta la retención memorística gracias a los carbohidratos refinados.",
                    "Produce hipoglucemia reactiva, causando caídas drásticas de atención y fatiga en las clases.",
                    "No genera ningún impacto biológico medible en el sistema nervioso."
                ],
                correcta: 1,
                explicacion: "Los ultraprocesados se absorben tan rápido que causan una elevación masiva de glucosa. El páncreas responde liberando insulina en exceso, vaciando la sangre de azúcar rápidamente, lo que induce somnolencia, cansancio mental y falta de concentración."
            },
            {
                pregunta: "¿Cuál es la función de los carbohidratos complejos en el almuerzo escolar?",
                opciones: [
                    "Suministrar cadenas complejas de almidón que liberan glucosa de forma progresiva al cerebro.",
                    "Reparar directamente las microrroturas musculares tras el ejercicio.",
                    "Aportar vitaminas liposolubles que no se disuelven en agua."
                ],
                correcta: 0,
                explicacion: "Los carbohidratos complejos (como el arroz o las legumbres) son polisacáridos. El cuerpo tarda horas en hidrolizarlos, asegurando que el torrente sanguíneo reciba un goteo constante de glucosa, manteniendo las neuronas estables y enfocadas."
            }
        ];

        let preguntaActualIndice = 0;
        let respuestasCorrectasContador = 0;

        function cargarPreguntaQuiz() {
            const quiz = bancoPreguntas[preguntaActualIndice];
            document.getElementById('quiz-progreso').innerText = `Pregunta ${preguntaActualIndice + 1} de ${bancoPreguntas.length}`;
            document.getElementById('quiz-pregunta').innerText = quiz.pregunta;
            
            const contenedorOpciones = document.getElementById('quiz-opciones');
            contenedorOpciones.innerHTML = "";
            
            const explicacionDiv = document.getElementById('explicacion-quiz');
            explicacionDiv.style.display = "none";
            document.getElementById('btn-siguiente-quiz').style.display = "none";

            quiz.opciones.forEach((opcion, indice) => {
                const boton = document.createElement('button');
                boton.className = "btn-opcion";
                boton.innerText = opcion;
                boton.onclick = () => verificarRespuestaQuiz(indice, boton);
                contenedorOpciones.appendChild(boton);
            });
        }

        function verificarRespuestaQuiz(indiceSeleccionado, botonSeleccionado) {
            const quiz = bancoPreguntas[preguntaActualIndice];
            const botones = document.querySelectorAll('.opciones-quiz .btn-opcion');
            const explicacionDiv = document.getElementById('explicacion-quiz');

            botones.forEach(btn => btn.disabled = true);

            if (indiceSeleccionado === quiz.correcta) {
                botonSeleccionado.style.background = "#2ecc71";
                botonSeleccionado.style.color = "white";
                respuestasCorrectasContador++;
            } else {
                botonSeleccionado.style.background = "#e74c3c";
                botonSeleccionado.style.color = "white";
                botones[quiz.correcta].style.background = "#2ecc71";
                botones[quiz.correcta].style.color = "white";
            }

            explicacionDiv.style.display = "block";
            explicacionDiv.style.marginTop = "15px";
            explicacionDiv.innerHTML = `<strong>Explicación Científica:</strong> ${quiz.explicacion}`;
            document.getElementById('btn-siguiente-quiz').style.display = "inline-block";
        }

        function cargarSiguientePregunta() {
            preguntaActualIndice++;
            if (preguntaActualIndice < bancoPreguntas.length) {
                cargarPreguntaQuiz();
            } else {
                document.getElementById('bloque-quiz').style.display = "none";
                const resultadoFinal = document.getElementById('resultado-final-quiz');
                resultadoFinal.style.display = "block";
                document.getElementById('puntuacion-quiz-texto').innerText = `Lograste responder correctamente ${respuestasCorrectasContador} de ${bancoPreguntas.length} evaluaciones teóricas.`;
            }
        }

        function reiniciarQuiz() {
            preguntaActualIndice = 0;
            respuestasCorrectasContador = 0;
            document.getElementById('bloque-quiz').style.display = "block";
            document.getElementById('resultado-final-quiz').style.display = "none";
            cargarPreguntaQuiz();
        }
        // ===== CONTADOR REGRESIVO =====
        function actualizarContador() {
            const objetivo = new Date('2026-06-16T07:00:00');
            const ahora = new Date();
            const diff = objetivo - ahora;
            if (diff <= 0) {
                document.getElementById('cd-dias').textContent = '0';
                document.getElementById('cd-horas').textContent = '0';
                document.getElementById('cd-mins').textContent = '0';
                document.getElementById('cd-segs').textContent = '0';
                return;
            }
            const d = Math.floor(diff / 86400000);
            const h = Math.floor((diff % 86400000) / 3600000);
            const m = Math.floor((diff % 3600000) / 60000);
            const s = Math.floor((diff % 60000) / 1000);
            document.getElementById('cd-dias').textContent = String(d).padStart(2,'0');
            document.getElementById('cd-horas').textContent = String(h).padStart(2,'0');
            document.getElementById('cd-mins').textContent = String(m).padStart(2,'0');
            document.getElementById('cd-segs').textContent = String(s).padStart(2,'0');
        }
        setInterval(actualizarContador, 1000);
        actualizarContador();

        // ===== JUEGO 1: ARMA TU PLATO =====
        let platoItems = [];
        document.querySelectorAll('.alimento-drag').forEach(el => {
            el.addEventListener('dragstart', e => {
                e.dataTransfer.setData('tipo', el.dataset.tipo);
                e.dataTransfer.setData('nombre', el.dataset.nombre);
                e.dataTransfer.setData('emoji', el.textContent);
            });
        });
        function soltarEnPlato(e) {
            e.preventDefault();
            const tipo = e.dataTransfer.getData('tipo');
            const nombre = e.dataTransfer.getData('nombre');
            const emoji = e.dataTransfer.getData('emoji');
            if (platoItems.length >= 6) { document.getElementById('resultado-plato').textContent = '⚠️ El plato ya está lleno (máx. 6 alimentos)'; return; }
            if (platoItems.find(i => i.nombre === nombre)) return;
            platoItems.push({tipo, nombre, emoji});
            renderizarPlato();
        }
        function renderizarPlato() {
            const list = document.getElementById('plato-items-list');
            const hint = document.getElementById('plato-hint');
            list.innerHTML = platoItems.map(i => `<span title="${i.nombre}" style="font-size:1.8em;">${i.emoji}</span>`).join(' ');
            hint.style.display = platoItems.length ? 'none' : 'block';
        }
        function limpiarPlato() {
            platoItems = [];
            renderizarPlato();
            document.getElementById('resultado-plato').textContent = '';
        }
        // Envía el resultado de una partida al servidor para guardarlo en juegos_jugados
        function registrarJuego(juego, puntaje, resultado) {
            fetch('guardar_juego.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ juego, puntaje, resultado })
            }).catch(err => console.error('No se pudo registrar el juego:', err));
        }

        function evaluarPlato() {
            if (platoItems.length < 3) { document.getElementById('resultado-plato').innerHTML = '<span style="color:#e74c3c">Agrega al menos 3 alimentos para evaluar.</span>'; return; }
            const conteo = { verdura:0, proteina:0, carbohidrato:0, fruta:0, malo:0 };
            platoItems.forEach(i => { if(conteo[i.tipo] !== undefined) conteo[i.tipo]++; });
            const res = document.getElementById('resultado-plato');
            if (conteo.malo > 0) {
                res.innerHTML = `<span style="color:#e74c3c">❌ Tu plato tiene alimentos no recomendados. Retira los ultraprocesados.</span>`;
            } else if (conteo.verdura >= 1 && conteo.proteina >= 1 && conteo.carbohidrato >= 1) {
                res.innerHTML = `<span style="color:#2ecc71">✅ ¡Excelente! Tu plato es equilibrado. Tiene verduras, proteínas y carbohidratos. 🎉</span>`;
                registrarJuego('plato', 10, 'Plato equilibrado');
            } else {
                const falta = [];
                if (!conteo.verdura) falta.push('verduras 🥦');
                if (!conteo.proteina) falta.push('proteínas 🍗');
                if (!conteo.carbohidrato) falta.push('carbohidratos 🍚');
                res.innerHTML = `<span style="color:#E2A000">⚠️ Falta: ${falta.join(', ')}. ¡Completa tu plato!</span>`;
            }
        }

        // ===== JUEGO 2: ADIVINA EL ALIMENTO =====
        const pistasAdivina = [
            { pista: "Soy rojo, redondo y me encuentro en ensaladas. Soy una fruta aunque te parezca verdura.", respuesta: "tomate" },
            { pista: "Soy amarillo, curvo y los monos me adoran. Me dan a los atletas por mi potasio.", respuesta: "banano" },
            { pista: "Soy verde, parezco un árbol en miniatura y soy famoso por mis propiedades anticancerígenas.", respuesta: "brocoli" },
            { pista: "Soy naranja, alargada y crujiente. Mejoro tu visión gracias al betacaroteno.", respuesta: "zanahoria" },
            { pista: "Vengo del mar, soy muy proteico, bajo en grasa y en muchos colegios me sirven los viernes.", respuesta: "pescado" },
            { pista: "Soy blanco y líquido. Los niños me beben para tener huesos fuertes. Tengo calcio.", respuesta: "leche" },
            { pista: "Soy pequeño, ovalado y de gallina. Me como revuelto, tibio o frito.", respuesta: "huevo" },
        ];
        let pistaActual = 0, intentosAdivina = 3, puntosAdivina = 0;
        function cargarPistaAdivina() {
            const p = pistasAdivina[pistaActual % pistasAdivina.length];
            document.getElementById('adivina-pista-texto').textContent = p.pista;
            document.getElementById('adivina-feedback').textContent = '';
            document.getElementById('adivina-input').value = '';
            document.getElementById('adivina-intentos').textContent = intentosAdivina;
        }
        function verificarAdivina() {
            const input = document.getElementById('adivina-input').value.trim().toLowerCase()
                .normalize('NFD').replace(/[\u0300-\u036f]/g,'');
            const correcto = pistasAdivina[pistaActual % pistasAdivina.length].respuesta
                .normalize('NFD').replace(/[\u0300-\u036f]/g,'');
            const fb = document.getElementById('adivina-feedback');
            if (input === correcto) {
                puntosAdivina += intentosAdivina * 10;
                document.getElementById('adivina-puntos').textContent = puntosAdivina;
                fb.innerHTML = `<span style="color:#2ecc71">✅ ¡Correcto! Era <strong>${pistasAdivina[pistaActual % pistasAdivina.length].respuesta}</strong>. +${intentosAdivina*10} pts</span>`;
                registrarJuego('adivina', puntosAdivina, 'Acertó: ' + pistasAdivina[pistaActual % pistasAdivina.length].respuesta);
                intentosAdivina = 3;
            } else {
                intentosAdivina--;
                document.getElementById('adivina-intentos').textContent = intentosAdivina;
                if (intentosAdivina <= 0) {
                    fb.innerHTML = `<span style="color:#e74c3c">❌ Era: <strong>${pistasAdivina[pistaActual % pistasAdivina.length].respuesta}</strong>. ¡Sigue intentando!</span>`;
                    intentosAdivina = 3;
                } else {
                    fb.innerHTML = `<span style="color:#E2A000">⚠️ Incorrecto. Te quedan ${intentosAdivina} intentos.</span>`;
                    return;
                }
            }
        }
        function siguientePistaAdivina() {
            pistaActual++;
            intentosAdivina = 3;
            cargarPistaAdivina();
        }
        window.addEventListener('load', () => { cargarPistaAdivina(); });

        // ===== JUEGO 3: MEMORIA =====
        const memoriaItems = [
            { emoji:'🥦', grupo:'Verdura' }, { emoji:'🍗', grupo:'Proteína' },
            { emoji:'🍚', grupo:'Carbohidrato' }, { emoji:'🥛', grupo:'Lácteo' },
            { emoji:'🍎', grupo:'Fruta' }, { emoji:'🥚', grupo:'Proteína' }
        ];
        let memoriaTablero = [], memPrimero = null, memBloqueado = false, memParesEncontrados = 0;
        function iniciarMemoria() {
            const dobles = [...memoriaItems, ...memoriaItems].map((item, i) => ({...item, id: i}));
            memoriaTablero = dobles.sort(() => Math.random() - 0.5);
            memPrimero = null; memBloqueado = false; memParesEncontrados = 0;
            document.getElementById('mem-pares').textContent = '0';
            document.getElementById('mem-resultado').textContent = '';
            const tablero = document.getElementById('tablero-memoria');
            tablero.innerHTML = memoriaTablero.map((item, i) =>
                `<div class="mem-carta" id="mc-${i}" onclick="voltearCarta(${i})" style="background:#e8f5e9; border-radius:8px; height:55px; display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:1.6em; user-select:none; transition:all 0.3s;">❓</div>`
            ).join('');
        }
        function voltearCarta(i) {
            if (memBloqueado) return;
            const carta = document.getElementById(`mc-${i}`);
            if (carta.dataset.volteada === '1') return;
            carta.textContent = memoriaTablero[i].emoji;
            carta.dataset.volteada = '1';
            carta.style.background = '#fff9c4';
            if (!memPrimero) { memPrimero = i; return; }
            const segundo = i;
            memBloqueado = true;
            if (memoriaTablero[memPrimero].emoji === memoriaTablero[segundo].emoji && memPrimero !== segundo) {
                document.getElementById(`mc-${memPrimero}`).style.background = '#c8f7c5';
                carta.style.background = '#c8f7c5';
                memParesEncontrados++;
                document.getElementById('mem-pares').textContent = memParesEncontrados;
                memPrimero = null; memBloqueado = false;
                if (memParesEncontrados === 6) {
                    document.getElementById('mem-resultado').textContent = '🎉 ¡Ganaste! Encontraste todos los pares.';
                    registrarJuego('memoria', memParesEncontrados * 10, 'Encontró todos los pares');
                }
            } else {
                setTimeout(() => {
                    document.getElementById(`mc-${memPrimero}`).textContent = '❓';
                    document.getElementById(`mc-${memPrimero}`).style.background = '#e8f5e9';
                    document.getElementById(`mc-${memPrimero}`).dataset.volteada = '0';
                    carta.textContent = '❓';
                    carta.style.background = '#e8f5e9';
                    carta.dataset.volteada = '0';
                    memPrimero = null; memBloqueado = false;
                }, 900);
            }
        }
        window.addEventListener('load', () => { iniciarMemoria(); });

        // ===== JUEGO 4: ATRAPA LO SALUDABLE =====
        const alimentosAtrapa = [
          {e:'🧀',s:true},{e:'🥦',s:true},{e:'🍗',s:true},{e:'🍎',s:true},{e:'🥕',s:true},{e:'🥚',s:true},{e:'🍌',s:true},
            {e:'🍟',s:false},{e:'🥤',s:false},{e:'🍫',s:false},{e:'🍕',s:false}
        ];
        let atrapaTimer = null, atrapaSegs = 20, atrapaPuntos = 0, atrapaMalos = 0, atrapaActivo = false;
        function iniciarAtrapa() {
            document.getElementById('atrapa-inicio-msg').style.display = 'none';
            document.getElementById('atrapa-fin').style.display = 'none';
            atrapaSegs = 20; atrapaPuntos = 0; atrapaMalos = 0; atrapaActivo = true;
            document.getElementById('atrapa-timer').textContent = atrapaSegs;
            document.getElementById('atrapa-puntos').textContent = 0;
            document.getElementById('atrapa-penalizacion').textContent = 0;
            const area = document.getElementById('juego-atrapa-area');
            area.querySelectorAll('.atrapa-item').forEach(e => e.remove());
            clearInterval(atrapaTimer);
            atrapaTimer = setInterval(() => {
                atrapaSegs--;
                document.getElementById('atrapa-timer').textContent = atrapaSegs;
                spawnAlimento();
                if (atrapaSegs <= 0) { clearInterval(atrapaTimer); atrapaActivo = false; mostrarFinAtrapa(); }
            }, 1000);
        }
        function spawnAlimento() {
            const area = document.getElementById('juego-atrapa-area');
            const item = alimentosAtrapa[Math.floor(Math.random() * alimentosAtrapa.length)];
            const div = document.createElement('div');
            div.className = 'atrapa-item';
            div.textContent = item.e;
            div.style.cssText = `position:absolute; font-size:2em; cursor:pointer; user-select:none; top:${Math.random()*140}px; left:${Math.random()*85}%; animation:fadeIn 0.3s ease;`;
            div.onclick = () => {
                if (!atrapaActivo) return;
                if (item.s) { atrapaPuntos++; document.getElementById('atrapa-puntos').textContent = atrapaPuntos; }
                else { atrapaMalos++; document.getElementById('atrapa-penalizacion').textContent = atrapaMalos; }
                div.remove();
            };
            area.appendChild(div);
            setTimeout(() => div.remove(), 2000);
        }
        function mostrarFinAtrapa() {
            const fin = document.getElementById('atrapa-fin');
            fin.style.display = 'block';
            const neto = atrapaPuntos - atrapaMalos;
            let resultadoAtrapa;
            if (neto >= 8) { fin.innerHTML = `🏆 ¡Increíble! Atrapaste ${atrapaPuntos} saludables. ¡Eres un experto!`; resultadoAtrapa = 'Experto'; }
            else if (neto >= 4) { fin.innerHTML = `👍 Bien hecho. ${atrapaPuntos} saludables, ${atrapaMalos} errores.`; resultadoAtrapa = 'Bien hecho'; }
            else { fin.innerHTML = `😅 Sigue practicando. ${atrapaPuntos} saludables vs ${atrapaMalos} no saludables.`; resultadoAtrapa = 'Sigue practicando'; }
            registrarJuego('atrapa', atrapaPuntos, resultadoAtrapa);
            document.getElementById('atrapa-inicio-msg').style.display = 'flex';
            document.getElementById('atrapa-inicio-msg').querySelector('span').textContent = '🔄';
            document.getElementById('atrapa-inicio-msg').querySelector('button').textContent = 'Jugar de nuevo';
        }

        // Lógica para el control del menú móvil desplegable
const mobileMenuBtn = document.getElementById('mobile-menu-btn');
const mainNavigation = document.getElementById('main-navigation');
const navLinks = document.querySelectorAll('.nav-link, .btn-nav-internal');

if (mobileMenuBtn && mainNavigation) {
    mobileMenuBtn.addEventListener('click', () => {
        mobileMenuBtn.classList.toggle('open');
        mainNavigation.classList.toggle('open');
    });
}

// Asegurar que el menú se cierre al hacer clic en cualquier enlace (en móviles)
navLinks.forEach(link => {
    link.addEventListener('click', () => {
        if (mainNavigation.classList.contains('open')) {
            mobileMenuBtn.classList.remove('open');
            mainNavigation.classList.remove('open');
        }
    });
});

    </script>
    <script>
        // Abre/cierra el panel de perfil (estadísticas y configuración)
        function togglePerfil() {
            const panel = document.getElementById('panel-perfil');
            if (panel) {
                panel.classList.toggle('abierto');
            }
        }

        // Si venimos de guardar la configuración (?panel=perfil), abrimos el panel automáticamente
        if (window.location.search.includes('panel=perfil')) {
            document.addEventListener('DOMContentLoaded', function () {
                const panel = document.getElementById('panel-perfil');
                if (panel) panel.classList.add('abierto');
            });
        }

        // Cierra el panel si el usuario hace clic fuera de él
        document.addEventListener('click', function (e) {
            const panel = document.getElementById('panel-perfil');
            if (!panel) return;
            const boton = e.target.closest('.usuario-nav');
            if (!panel.contains(e.target) && !boton) {
                panel.classList.remove('abierto');
            }
        });

        // Historial en memoria de los mensajes para mantener el contexto con la IA
        let historialMensajes = [];

        // Función para abrir y cerrar la ventana flotante
        function toggleChat() {
            const chatBox = document.getElementById('chat-box');
            if (chatBox.style.display === 'none' || chatBox.style.display === '') {
                chatBox.style.display = 'flex';
            } else {
                chatBox.style.display = 'none';
            }
        }

        // Detectar si el usuario presiona la tecla Enter en el input del chat
        function evaluarEnterChat(e) {
            if (e.key === 'Enter') {
                enviarMensajeChat();
            }
        }

        // Función principal para enviar el mensaje y consultar al servidor
        async function enviarMensajeChat() {
            const inputField = document.getElementById('chat-input');
            const contenedorMensajes = document.getElementById('chat-messages');
            const textoUsuario = inputField.value.trim();

            if (textoUsuario === "") return;

            // 1. Renderizar el mensaje del usuario en la pantalla
            const divUsuario = document.createElement('div');
            divUsuario.className = 'msg-user';
            divUsuario.textContent = textoUsuario;
            contenedorMensajes.appendChild(divUsuario);
            
            // Limpiar el campo de texto y hacer scroll automático al fondo
            inputField.value = "";
            contenedorMensajes.scrollTop = contenedorMensajes.scrollHeight;

            // Guardar en el historial en el formato que espera el chatbot
            historialMensajes.push({ role: 'user', content: textoUsuario });

            // 2. Colocar indicador visual de que el bot está "escribiendo"
            const divPensando = document.createElement('div');
            divPensando.className = 'msg-bot';
            divPensando.id = 'msg-pensando';
            divPensando.textContent = 'Escribiendo... ⏳';
            contenedorMensajes.appendChild(divPensando);
            contenedorMensajes.scrollTop = contenedorMensajes.scrollHeight;

            try {
                // 3. Conexión asíncrona mediante FETCH hacia tu archivo chatbot.php
                const respuesta = await fetch('procesar_chat.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ messages: historialMensajes })
                });

                // Remover indicador de carga
                document.getElementById('msg-pensando').remove();

                if (!respuesta.ok) {
                    throw new Error('Error en la respuesta del servidor.');
                }

                const datosJson = await respuesta.json();
                
                // Extraer el texto de la respuesta del formato recibido
                let textoBot = "Lo siento, tuve un problema al procesar tu solicitud.";
                if (datosJson.content && datosJson.content[0] && datosJson.content[0].text) {
                    textoBot = datosJson.content[0].text;
                }

                // 4. Renderizar la respuesta de la IA en la pantalla
                const divBot = document.createElement('div');
                divBot.className = 'msg-bot';
                divBot.textContent = textoBot;
                contenedorMensajes.appendChild(divBot);

                // Guardar la respuesta del asistente en el historial para no perder el hilo
                historialMensajes.push({ role: 'assistant', content: textoBot });

            } catch (error) {
                console.error(error);
                if(document.getElementById('msg-pensando')) {
                    document.getElementById('msg-pensando').remove();
                }
                const divError = document.createElement('div');
                divError.className = 'msg-bot';
                divError.style.color = 'red';
                divError.textContent = '⚠️ Hubo un error de conexión con Nutribot. Inténtalo de nuevo más tarde.';
                contenedorMensajes.appendChild(divError);
            }

            // Scroll final
            contenedorMensajes.scrollTop = contenedorMensajes.scrollHeight;
        }
    </script>
</body>
</html>