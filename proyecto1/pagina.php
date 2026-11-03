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
    // Solo sumamos 1 visita la PRIMERA vez que carga la página después de
    // iniciar sesión (con la bandera 'visita_contada' en la sesión). Así,
    // refrescar la página o navegar entre secciones ya no infla el contador;
    // solo un nuevo inicio de sesión genera una visita nueva.
    if (!isset($_SESSION['visita_contada'])) {
        try {
            $pdo->prepare("UPDATE registro SET visitas = visitas + 1 WHERE id = ?")
                ->execute([$_SESSION['usuario_id']]);
            $_SESSION['visita_contada'] = true;
        } catch (PDOException $e) {
            // Si la columna 'visitas' todavía no existe, lo dejamos pasar sin romper la página
            error_log("Aviso: no se pudo actualizar 'visitas': " . $e->getMessage());
        }
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
            font-size: 0.87em;
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
            width: 380px;
            max-width: calc(100vw - 24px);
            max-height: calc(100vh - 100px);
            overflow-y: auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 14px 40px rgba(0,0,0,0.2);
            padding: 26px;
            z-index: 999;
            color: #1B3818;
        }
        .panel-perfil.abierto { display: block; animation: panelAparece 0.25s ease both; }
        @keyframes panelAparece {
            0% { opacity: 0; transform: translateY(-8px); }
            100% { opacity: 1; transform: translateY(0); }
        }
        .panel-perfil-header {
            display: flex;
            align-items: center;
            gap: 16px;
            position: relative;
            margin-bottom: 20px;
            padding-bottom: 18px;
            border-bottom: 2px solid #f0f4ef;
        }
        .avatar-grande {
            width: 64px;
            height: 64px;
            font-size: 1.6em;
            background: linear-gradient(145deg, #447B3E, #1B3818);
            box-shadow: 0 4px 10px rgba(27, 56, 24, 0.25);
        }
        .panel-perfil-header h4 { margin: 0; font-size: 1.25em; }
        .correo-panel { margin: 4px 0 0; font-size: 0.95em; color: #666; }
        .cerrar-panel {
            position: absolute;
            top: -10px; right: -10px;
            background: #f0f4ef;
            border: none;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            font-size: 1.3em;
            line-height: 1;
            cursor: pointer;
            color: #666;
            transition: background 0.2s ease, color 0.2s ease;
        }
        .cerrar-panel:hover { background: #e2e8e2; color: #1B3818; }
        .panel-mensaje { font-size: 1em; margin-bottom: 14px; text-align: center; }
        .panel-estadisticas {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
        }
        .stat-card {
            flex: 1;
            background: linear-gradient(160deg, #f4f6f4, #eaf1e8);
            border-radius: 14px;
            padding: 16px 10px;
            text-align: center;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 14px rgba(27, 56, 24, 0.12);
        }
        .stat-icono { display: block; font-size: 1.5em; margin-bottom: 4px; }
        .stat-numero { display: block; font-weight: 800; font-size: 1.45em; color: #386134; }
        .stat-label { font-size: 0.88em; color: #666; }

        /* --- Secciones plegables (juegos jugados, configuración) más grandes y divertidas --- */
        .panel-acordeon {
            background: #f9faf8;
            border: 1px solid #edf1ec;
            border-radius: 14px;
            padding: 14px 16px;
            margin-bottom: 14px;
            transition: background 0.2s ease;
        }
        .panel-acordeon[open] {
            background: #ffffff;
            border-color: #dbe6da;
            box-shadow: 0 4px 14px rgba(27, 56, 24, 0.08);
        }
        .panel-acordeon summary {
            cursor: pointer;
            font-weight: 700;
            font-size: 1.08em;
            list-style: none;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 4px 0;
        }
        .panel-acordeon summary::-webkit-details-marker { display: none; }
        .panel-acordeon summary::after {
            content: "▾";
            font-size: 0.9em;
            color: #888;
            transition: transform 0.25s ease;
        }
        .panel-acordeon[open] summary::after { transform: rotate(180deg); }
        .panel-configuracion, .panel-juegos { /* alias históricos, ahora usan .panel-acordeon */ }
        .lista-juegos {
            max-height: 220px;
            overflow-y: auto;
            margin-top: 14px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .juego-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            padding: 12px 14px;
            background: #f4f6f4;
            border-radius: 10px;
            font-size: 0.95em;
            border-bottom: none;
        }
        .juego-info { flex: 1; min-width: 0; }
        .juego-nombre { font-weight: 700; text-transform: capitalize; font-size: 1.02em; }
        .juego-detalle { color: #666; font-size: 0.9em; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; margin-top: 2px; }
        .juego-borrar {
            color: #c0392b;
            text-decoration: none;
            font-weight: 700;
            flex-shrink: 0;
            font-size: 1.1em;
            padding: 4px 6px;
            border-radius: 6px;
            transition: background 0.2s ease;
        }
        .juego-borrar:hover { background: rgba(192, 57, 43, 0.12); }
        .sin-juegos { font-size: 0.98em; color: #777; text-align: center; padding: 16px 0; }

        /* ---------- Inicio más creativo: kicker, badges, ola y animación de entrada ---------- */
        .hero-content { animation: heroAparece 0.9s ease both; }
        @keyframes heroAparece {
            0% { opacity: 0; transform: translateY(24px); }
            100% { opacity: 1; transform: translateY(0); }
        }
        .hero-kicker {
            display: inline-block;
            background: rgba(255,255,255,0.18);
            border: 1px solid rgba(255,255,255,0.4);
            backdrop-filter: blur(4px);
            padding: 6px 16px;
            border-radius: 30px;
            font-size: 0.87em;
            font-weight: 700;
            letter-spacing: 0.5px;
            margin-bottom: 16px;
        }
        .hero-badges {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 28px;
        }
        .hero-badge {
            background: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.35);
            backdrop-filter: blur(6px);
            padding: 10px 18px;
            border-radius: 30px;
            font-weight: 700;
            font-size: 0.96em;
            color: white;
            transition: transform 0.2s ease, background 0.2s ease;
        }
        .hero-badge:hover {
            transform: translateY(-4px);
            background: rgba(255,255,255,0.28);
        }
        .hero-ola {
            position: absolute;
            left: 0;
            right: 0;
            bottom: -2px;
            z-index: 2;
            line-height: 0;
        }
        .hero-ola svg {
            width: 100%;
            height: 70px;
            display: block;
        }
        .hero-ola path {
            fill: #F4F7F5;
        }
        .ofrecemos-subtitulo {
            text-align: center;
            color: #667;
            margin-top: -8px;
            margin-bottom: 10px;
        }
        .feature-flip {
            height: 380px;
        }
        .feature-flip .flip-card-front,
        .feature-flip .flip-card-back {
            padding: 28px;
        }
        .feature-icono {
            font-size: 3.2em;
            width: 90px;
            height: 90px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: #eaf3e8;
            margin: 0 auto 16px;
        }
        .feature-flip .flip-card-back p {
            font-size: 1em;
        }
        .feature-cta {
            margin-top: 14px;
            padding: 8px 18px !important;
            font-size: 0.92em;
        }

        /* ---------- Pestañas de semana del menú escolar ---------- */
        .menu-semanas-tabs {
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
            margin: 20px 0 10px;
        }
        .menu-semana-btn {
            padding: 10px 18px;
            border: 2px solid #386134;
            border-radius: 20px;
            background: white;
            color: #386134;
            font-weight: 700;
            cursor: pointer;
        }
        .menu-semana-btn.activa {
            background: #386134;
            color: white;
        }

        /* ---------- Header sticky (se queda visible al hacer scroll) ---------- */
        header {
            position: sticky;
            top: 0;
            z-index: 1000;
            background: #FFFFFF;
            transition: box-shadow 0.25s ease;
            overflow: visible; /* si no, el header recorta el menú desplegable */
        }
        header::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 6px;
            background: linear-gradient(90deg, #386134, #E2A000, #386134);
            background-size: 200% 100%;
            animation: brilloHeader 6s linear infinite;
        }
        @keyframes brilloHeader {
            0% { background-position: 0% 0; }
            100% { background-position: 200% 0; }
        }
        .logo h1 {
            background: linear-gradient(90deg, #386134, #4f8b49, #E2A000, #386134);
            background-size: 300% auto;
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
            animation: brilloTexto 8s linear infinite;
        }
        @keyframes brilloTexto {
            0% { background-position: 0% 0; }
            100% { background-position: 300% 0; }
        }

        /* Para que el contenido no quede tapado por el header fijo al saltar a una sección */
        .tab-section {
            scroll-margin-top: 130px;
        }
        .header-decoracion {
            position: absolute;
            inset: 0;
            overflow: hidden;
            pointer-events: none;
            z-index: 0;
        }

        /* ---------- Menú hamburguesa (3 rayitas) — siempre activo, en cualquier tamaño de pantalla ---------- */
        .mobile-menu-btn {
            display: flex;
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

        nav#main-navigation {
            position: absolute;
            top: 100%;
            right: 0;
            min-width: 240px;
            background: white;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.35s ease;
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
            border-radius: 0 0 14px 14px;
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
        nav#main-navigation ul li {
            flex-shrink: 1;
            width: 100%;
            list-style: none;
        }
        /* Se usa !important porque compite con la regla ".active" del nav de
           escritorio (que también tiene !important) — si no, el menú se ve
           como tarjetitas sueltas en vez de una lista limpia. */
        nav#main-navigation ul li a,
        nav#main-navigation ul li a.active {
            display: flex !important;
            align-items: center;
            gap: 10px;
            width: 100% !important;
            box-sizing: border-box;
            border-radius: 0 !important;
            background: white !important;
            color: #1B3818 !important;
            box-shadow: none !important;
            transform: none !important;
            padding: 15px 22px !important;
            font-weight: 600 !important;
            font-size: 1.02em;
            white-space: normal;
            border-bottom: 1px solid #eef1ed !important;
        }
        nav#main-navigation ul li:last-child a { border-bottom: none !important; }
        nav#main-navigation ul li a:hover {
            background: #eef6ec !important;
        }
        nav#main-navigation ul li a.active {
            background: #386134 !important;
            color: white !important;
            font-weight: 800 !important;
        }
        nav#main-navigation .usuario-nav {
            background: #f7f9f6 !important;
            border-top: 2px solid #eef1ed !important;
            margin-top: 4px;
        }
        nav#main-navigation .cerrar-sesion-link {
            color: #c0392b !important;
            font-weight: 700 !important;
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
        .admin-tabla th, .admin-tabla td { padding: 10px; text-align: left; border-bottom: 1px solid #eee; font-size: 0.97em; white-space: nowrap; }
        .admin-tabla th { background: #f0f4ef; }
        .admin-badge { background: #E2A000; color: #1B3818; padding: 2px 8px; border-radius: 10px; font-size: 0.82em; font-weight: 700; }
        .admin-acciones a { margin-right: 10px; text-decoration: none; font-weight: 600; }
        .admin-editar { color: #E2A000; }
        .admin-eliminar { color: #c0392b; }
        .admin-toggle { color: #386134; }
        .form-perfil { display: flex; flex-direction: column; gap: 12px; margin-top: 14px; }
        .form-perfil label { font-size: 0.95em; font-weight: 700; color: #386134; }
        .form-perfil input {
            padding: 12px 14px;
            border-radius: 10px;
            border: 2px solid #e2e8e2;
            font-size: 1em;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .form-perfil input:focus {
            outline: none;
            border-color: #447B3E;
            box-shadow: 0 0 0 3px rgba(68, 123, 62, 0.15);
        }
        .form-perfil button { margin-top: 10px; padding: 12px; font-size: 1.02em; }
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
        <div class="header-decoracion">
            <div class="food-bg img-banana-happy"></div>
            <div class="food-bg img-basket"></div>
            <div class="food-bg img-apple-happy"></div>
            <div class="food-bg img-broccoli-happy"></div>
            <div class="food-bg img-carrot-happy"></div>
            <div class="food-bg img-chef-happy"></div>
            <div class="food-bg img-tomato-happy"></div>
            <div class="food-bg img-orange-happy"></div>
        </div>

        <div class="logo">
            <img src="logo.jpeg" alt="Llenos de Amor Logo">
            <h1>Llenos de Amor</h1>
        </div>
        <div class="nav-area">
            <nav id="main-navigation">
                <ul>
                    <li><a href="#inicio" class="nav-link active">🏠 Inicio</a></li>
                    <li><a href="#menu" class="nav-link">🍽️ Menú</a></li>
                    <li><a href="#nutricion" class="nav-link">🥗 Nutrición</a></li>
                    <li><a href="#noticias" class="nav-link">📰 Noticias</a></li>
                    <li><a href="#juegos" class="nav-link">🎮 Juegos</a></li>
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
                        <li><a href="?logout=1" class="nav-link cerrar-sesion-link">🚪 Cerrar sesión</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
            <?php if (!$logueado): ?>
            <div class="auth-buttons">
                <a href="#login" class="nav-link auth-btn auth-btn-login"><span class="auth-btn-icono">🔑</span> Iniciar Sesión</a>
                <a href="#registro" class="nav-link auth-btn auth-btn-registro"><span class="auth-btn-icono">📝</span> Registro</a>
            </div>
            <?php endif; ?>
        </div>
        <button type="button" id="mobile-menu-btn" class="mobile-menu-btn" aria-label="Abrir menú" aria-expanded="false">
            <span class="barra"></span>
            <span class="barra"></span>
            <span class="barra"></span>
        </button>

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
                    <span class="stat-icono">🎯</span>
                    <span class="stat-numero"><?php echo $perfil ? (int) $perfil['visitas'] : 0; ?></span>
                    <span class="stat-label">Visitas</span>
                </div>
                <div class="stat-card">
                    <span class="stat-icono">🌱</span>
                    <span class="stat-numero"><?php echo $perfil ? date("d/m/Y", strtotime($perfil['fecha_registro'])) : "-"; ?></span>
                    <span class="stat-label">Miembro desde</span>
                </div>
            </div>

            <details class="panel-acordeon panel-juegos">
                <summary>🎮 Mis juegos jugados</summary>
                <div class="lista-juegos" id="lista-mis-juegos">
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

            <details class="panel-acordeon panel-configuracion">
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
                <img src="resta.jpg" alt="Slide 1">
                <img src="papa.jpg" alt="Slide 2">
                <img src="comer.jpg" alt="Slide 3">
                <img src="comiditas.jpg" alt="Slide 4">
                <img src="res.jpg" alt="Slide 5">
                <img src="comida.jpeg" alt="Slide 6 - Plato de nuestro restaurante escolar">
                <img src="mesas.jpeg" alt="Slide 7 - Comedor de nuestra institución">
            </div>
            
            <div class="hero-content">
                <span class="hero-kicker">🍽️ Institución Educativa Presbítero Montoya Giraldo</span>
                <h2>Orden y cariño serán la constancia del amor</h2>
                <p>Descubre un mundo de alimentación saludable y educación en nuestro restaurante escolar.</p>
                <div class="hero-buttons">
                    <?php if (!$logueado): ?>
                        <a href="#registro" class="button primary btn-nav-internal">Comenzar Ahora</a>
                    <?php endif; ?>
                    <a href="#menu" class="button secondary btn-nav-internal">Ver Menú Semanal</a>
                </div>
                <div class="hero-badges">
                    <span class="hero-badge">📅 Ciclo de 4 semanas</span>
                    <span class="hero-badge">🥗 20 menús balanceados</span>
                    <span class="hero-badge">❤️ Hecho con cariño</span>
                </div>
            </div>

            <div class="hero-ola">
                <svg viewBox="0 0 1200 120" preserveAspectRatio="none">
                    <path d="M0,40 C300,120 900,0 1200,60 L1200,120 L0,120 Z"></path>
                </svg>
            </div>
        </div>
        
        </div>
       <div class="container">
                    <h3>¿Qué ofrecemos?</h3>
                    <p class="ofrecemos-subtitulo">Todo lo que necesitas para crecer sano, feliz y bien alimentado ✨</p>
                    <div class="features">
                        <div class="flip-card feature-flip" tabindex="0" role="group" aria-label="Menú Escolar">
                            <div class="flip-card-inner">
                                <div class="flip-card-front">
                                    <div class="feature-icono">🍽️</div>
                                    <h4>Menú Escolar</h4>
                                </div>
                                <div class="flip-card-back">
                                    <h4>Menú Escolar</h4>
                                    <p>Consulta el ciclo completo de 4 semanas del PAE: bebida, plato principal y acompañamiento de cada día, sin sorpresas.</p>
                                    <a href="#menu" class="button secondary btn-nav-internal feature-cta">Ver el menú</a>
                                </div>
                            </div>
                        </div>
                        <div class="flip-card feature-flip" tabindex="0" role="group" aria-label="Educación Nutricional">
                            <div class="flip-card-inner">
                                <div class="flip-card-front">
                                    <div class="feature-icono">🥦</div>
                                    <h4>Educación Nutricional</h4>
                                </div>
                                <div class="flip-card-back">
                                    <h4>Educación Nutricional</h4>
                                    <p>Aprende con datos de macronutrientes, hidratación, fibra, hierro y más. Además, calcula tu IMC y pon a prueba lo aprendido con un quiz interactivo.</p>
                                    <a href="#nutricion" class="button secondary btn-nav-internal feature-cta">Explorar Nutrición</a>
                                </div>
                            </div>
                        </div>
                        <div class="flip-card feature-flip" tabindex="0" role="group" aria-label="Ambiente Amigable">
                            <div class="flip-card-inner">
                                <div class="flip-card-front">
                                    <div class="feature-icono">🤝</div>
                                    <h4>Ambiente Amigable</h4>
                                </div>
                                <div class="flip-card-back">
                                    <h4>Ambiente Amigable</h4>
                                    <p>Un espacio seguro y divertido donde compartir con tus compañeros, aprender buenos hábitos y disfrutar cada comida en confianza.</p>
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
        <h3>Menú Escolar (PAE) — Ciclo de 4 semanas</h3>
        <p style="text-align:center; color:#555; margin-top:-10px;">Toca cada día para ver el menú completo. El ciclo se repite cada 4 semanas.</p>
        <div class="menu-semanas-tabs">
            <button type="button" class="menu-semana-btn activa" data-semana="1" onclick="mostrarSemanaMenu(1)">Semana 1</button>
            <button type="button" class="menu-semana-btn " data-semana="2" onclick="mostrarSemanaMenu(2)">Semana 2</button>
            <button type="button" class="menu-semana-btn " data-semana="3" onclick="mostrarSemanaMenu(3)">Semana 3</button>
            <button type="button" class="menu-semana-btn " data-semana="4" onclick="mostrarSemanaMenu(4)">Semana 4</button>
        </div>
        <div class="menu-semana-contenido" data-semana="1">
            <div class="menu-acordeon">
                    <div class="flip-card menu-flip-card" tabindex="0" role="button" aria-label="Lunes, toca para ver el menú" onclick="toggleFlip(this)" onkeypress="if(event.key==='Enter')toggleFlip(this)">
                        <div class="flip-card-inner">
                            <div class="flip-card-front">
                                <div class="dia-info"><span class="dia-foto">🥪</span><span class="dia-nombre">Lunes</span></div>
                                <span class="plato-principal-preview">Sánduche de queso</span>
                                <span class="flip-hint">👆 Toca para ver el menú completo</span>
                            </div>
                            <div class="flip-card-back">
                                <div class="menu-detalles-bloque">
                                    <p><strong>Bebida:</strong> Chocolate con leche</p>
                                    <p><strong>Plato principal:</strong> Sánduche de queso</p>
                                    <p><strong>Acompañamiento:</strong> Torta de zanahoria, Fruta entera</p>
                                </div>
                                <span class="flip-hint">↩️ Toca para volver</span>
                            </div>
                        </div>
                    </div>
                    <div class="flip-card menu-flip-card" tabindex="0" role="button" aria-label="Martes, toca para ver el menú" onclick="toggleFlip(this)" onkeypress="if(event.key==='Enter')toggleFlip(this)">
                        <div class="flip-card-inner">
                            <div class="flip-card-front">
                                <div class="dia-info"><span class="dia-foto">🍖</span><span class="dia-nombre">Martes</span></div>
                                <span class="plato-principal-preview">Carne de cerdo guisada</span>
                                <span class="flip-hint">👆 Toca para ver el menú completo</span>
                            </div>
                            <div class="flip-card-back">
                                <div class="menu-detalles-bloque">
                                    <p><strong>Bebida:</strong> Agua de panela con leche</p>
                                    <p><strong>Plato principal:</strong> Carne de cerdo guisada</p>
                                    <p><strong>Acompañamiento:</strong> Puré de yuca, Arroz con espinaca</p>
                                </div>
                                <span class="flip-hint">↩️ Toca para volver</span>
                            </div>
                        </div>
                    </div>
                    <div class="flip-card menu-flip-card" tabindex="0" role="button" aria-label="Miércoles, toca para ver el menú" onclick="toggleFlip(this)" onkeypress="if(event.key==='Enter')toggleFlip(this)">
                        <div class="flip-card-inner">
                            <div class="flip-card-front">
                                <div class="dia-info"><span class="dia-foto">🍳</span><span class="dia-nombre">Miércoles</span></div>
                                <span class="plato-principal-preview">Huevo revuelto</span>
                                <span class="flip-hint">👆 Toca para ver el menú completo</span>
                            </div>
                            <div class="flip-card-back">
                                <div class="menu-detalles-bloque">
                                    <p><strong>Bebida:</strong> Bebida chocolatada</p>
                                    <p><strong>Plato principal:</strong> Huevo revuelto</p>
                                    <p><strong>Acompañamiento:</strong> Arroz blanco con cebolla de rama, Tajada de maduro con queso</p>
                                </div>
                                <span class="flip-hint">↩️ Toca para volver</span>
                            </div>
                        </div>
                    </div>
                    <div class="flip-card menu-flip-card" tabindex="0" role="button" aria-label="Jueves, toca para ver el menú" onclick="toggleFlip(this)" onkeypress="if(event.key==='Enter')toggleFlip(this)">
                        <div class="flip-card-inner">
                            <div class="flip-card-front">
                                <div class="dia-info"><span class="dia-foto">🥩</span><span class="dia-nombre">Jueves</span></div>
                                <span class="plato-principal-preview">Carne de res desmechada</span>
                                <span class="flip-hint">👆 Toca para ver el menú completo</span>
                            </div>
                            <div class="flip-card-back">
                                <div class="menu-detalles-bloque">
                                    <p><strong>Bebida:</strong> Avena fría con leche</p>
                                    <p><strong>Plato principal:</strong> Carne de res desmechada</p>
                                    <p><strong>Acompañamiento:</strong> Arepa asada, Galletas de soda, Fruta entera</p>
                                </div>
                                <span class="flip-hint">↩️ Toca para volver</span>
                            </div>
                        </div>
                    </div>
                    <div class="flip-card menu-flip-card" tabindex="0" role="button" aria-label="Viernes, toca para ver el menú" onclick="toggleFlip(this)" onkeypress="if(event.key==='Enter')toggleFlip(this)">
                        <div class="flip-card-inner">
                            <div class="flip-card-front">
                                <div class="dia-info"><span class="dia-foto">🍔</span><span class="dia-nombre">Viernes</span></div>
                                <span class="plato-principal-preview">Torta de lentejas para hamburguesa</span>
                                <span class="flip-hint">👆 Toca para ver el menú completo</span>
                            </div>
                            <div class="flip-card-back">
                                <div class="menu-detalles-bloque">
                                    <p><strong>Bebida:</strong> Sorbete de fruta</p>
                                    <p><strong>Plato principal:</strong> Torta de lentejas para hamburguesa</p>
                                    <p><strong>Acompañamiento:</strong> Pan de hamburguesa y complementos, Papas a la francesa</p>
                                </div>
                                <span class="flip-hint">↩️ Toca para volver</span>
                            </div>
                        </div>
                    </div>
            </div>
        </div>
        <div class="menu-semana-contenido" data-semana="2" style="display:none;">
            <div class="menu-acordeon">
                    <div class="flip-card menu-flip-card" tabindex="0" role="button" aria-label="Lunes, toca para ver el menú" onclick="toggleFlip(this)" onkeypress="if(event.key==='Enter')toggleFlip(this)">
                        <div class="flip-card-inner">
                            <div class="flip-card-front">
                                <div class="dia-info"><span class="dia-foto">🌱</span><span class="dia-nombre">Lunes</span></div>
                                <span class="plato-principal-preview">Guiso de proteína vegetal de soya con arroz y vegetales</span>
                                <span class="flip-hint">👆 Toca para ver el menú completo</span>
                            </div>
                            <div class="flip-card-back">
                                <div class="menu-detalles-bloque">
                                    <p><strong>Bebida:</strong> Sorbete de fruta</p>
                                    <p><strong>Plato principal:</strong> Guiso de proteína vegetal de soya con arroz y vegetales</p>
                                    <p><strong>Acompañamiento:</strong> Papas criollas fritas, Fruta entera</p>
                                </div>
                                <span class="flip-hint">↩️ Toca para volver</span>
                            </div>
                        </div>
                    </div>
                    <div class="flip-card menu-flip-card" tabindex="0" role="button" aria-label="Martes, toca para ver el menú" onclick="toggleFlip(this)" onkeypress="if(event.key==='Enter')toggleFlip(this)">
                        <div class="flip-card-inner">
                            <div class="flip-card-front">
                                <div class="dia-info"><span class="dia-foto">🍳</span><span class="dia-nombre">Martes</span></div>
                                <span class="plato-principal-preview">Huevo revuelto con cebolla</span>
                                <span class="flip-hint">👆 Toca para ver el menú completo</span>
                            </div>
                            <div class="flip-card-back">
                                <div class="menu-detalles-bloque">
                                    <p><strong>Bebida:</strong> Agua de panela con leche</p>
                                    <p><strong>Plato principal:</strong> Huevo revuelto con cebolla</p>
                                    <p><strong>Acompañamiento:</strong> Arroz blanco, Tajadas de plátano maduro, Calentadito PAE</p>
                                </div>
                                <span class="flip-hint">↩️ Toca para volver</span>
                            </div>
                        </div>
                    </div>
                    <div class="flip-card menu-flip-card" tabindex="0" role="button" aria-label="Miércoles, toca para ver el menú" onclick="toggleFlip(this)" onkeypress="if(event.key==='Enter')toggleFlip(this)">
                        <div class="flip-card-inner">
                            <div class="flip-card-front">
                                <div class="dia-info"><span class="dia-foto">🍖</span><span class="dia-nombre">Miércoles</span></div>
                                <span class="plato-principal-preview">Carne de cerdo en julianas con cebolla caramelizada</span>
                                <span class="flip-hint">👆 Toca para ver el menú completo</span>
                            </div>
                            <div class="flip-card-back">
                                <div class="menu-detalles-bloque">
                                    <p><strong>Bebida:</strong> Bebida fría con avena y leche</p>
                                    <p><strong>Plato principal:</strong> Carne de cerdo en julianas con cebolla caramelizada</p>
                                    <p><strong>Acompañamiento:</strong> Arroz con cúrcuma y cilantro, Puré de papa capira</p>
                                </div>
                                <span class="flip-hint">↩️ Toca para volver</span>
                            </div>
                        </div>
                    </div>
                    <div class="flip-card menu-flip-card" tabindex="0" role="button" aria-label="Jueves, toca para ver el menú" onclick="toggleFlip(this)" onkeypress="if(event.key==='Enter')toggleFlip(this)">
                        <div class="flip-card-inner">
                            <div class="flip-card-front">
                                <div class="dia-info"><span class="dia-foto">🍲</span><span class="dia-nombre">Jueves</span></div>
                                <span class="plato-principal-preview">Carne de res guisada con habichuelas</span>
                                <span class="flip-hint">👆 Toca para ver el menú completo</span>
                            </div>
                            <div class="flip-card-back">
                                <div class="menu-detalles-bloque">
                                    <p><strong>Bebida:</strong> Sorbete de fruta</p>
                                    <p><strong>Plato principal:</strong> Carne de res guisada con habichuelas</p>
                                    <p><strong>Acompañamiento:</strong> Pastas tornillo con tomate y albahaca, Croquetas de plátano maduro</p>
                                </div>
                                <span class="flip-hint">↩️ Toca para volver</span>
                            </div>
                        </div>
                    </div>
                    <div class="flip-card menu-flip-card" tabindex="0" role="button" aria-label="Viernes, toca para ver el menú" onclick="toggleFlip(this)" onkeypress="if(event.key==='Enter')toggleFlip(this)">
                        <div class="flip-card-inner">
                            <div class="flip-card-front">
                                <div class="dia-info"><span class="dia-foto">🍖</span><span class="dia-nombre">Viernes</span></div>
                                <span class="plato-principal-preview">Carne de cerdo en salsa criolla</span>
                                <span class="flip-hint">👆 Toca para ver el menú completo</span>
                            </div>
                            <div class="flip-card-back">
                                <div class="menu-detalles-bloque">
                                    <p><strong>Bebida:</strong> Bebida chocolatada</p>
                                    <p><strong>Plato principal:</strong> Carne de cerdo en salsa criolla</p>
                                    <p><strong>Acompañamiento:</strong> Papas a la francesa, Galletas de soda</p>
                                </div>
                                <span class="flip-hint">↩️ Toca para volver</span>
                            </div>
                        </div>
                    </div>
            </div>
        </div>
        <div class="menu-semana-contenido" data-semana="3" style="display:none;">
            <div class="menu-acordeon">
                    <div class="flip-card menu-flip-card" tabindex="0" role="button" aria-label="Lunes, toca para ver el menú" onclick="toggleFlip(this)" onkeypress="if(event.key==='Enter')toggleFlip(this)">
                        <div class="flip-card-inner">
                            <div class="flip-card-front">
                                <div class="dia-info"><span class="dia-foto">🍖</span><span class="dia-nombre">Lunes</span></div>
                                <span class="plato-principal-preview">Carne de cerdo con vegetales</span>
                                <span class="flip-hint">👆 Toca para ver el menú completo</span>
                            </div>
                            <div class="flip-card-back">
                                <div class="menu-detalles-bloque">
                                    <p><strong>Bebida:</strong> Bebida fría con avena en leche</p>
                                    <p><strong>Plato principal:</strong> Carne de cerdo con vegetales</p>
                                    <p><strong>Acompañamiento:</strong> Arepa asada, Fruta entera</p>
                                </div>
                                <span class="flip-hint">↩️ Toca para volver</span>
                            </div>
                        </div>
                    </div>
                    <div class="flip-card menu-flip-card" tabindex="0" role="button" aria-label="Martes, toca para ver el menú" onclick="toggleFlip(this)" onkeypress="if(event.key==='Enter')toggleFlip(this)">
                        <div class="flip-card-inner">
                            <div class="flip-card-front">
                                <div class="dia-info"><span class="dia-foto">🥩</span><span class="dia-nombre">Martes</span></div>
                                <span class="plato-principal-preview">Carne de res estofada</span>
                                <span class="flip-hint">👆 Toca para ver el menú completo</span>
                            </div>
                            <div class="flip-card-back">
                                <div class="menu-detalles-bloque">
                                    <p><strong>Bebida:</strong> Sorbete de fruta</p>
                                    <p><strong>Plato principal:</strong> Carne de res estofada</p>
                                    <p><strong>Acompañamiento:</strong> Arroz con cabello de ángel</p>
                                </div>
                                <span class="flip-hint">↩️ Toca para volver</span>
                            </div>
                        </div>
                    </div>
                    <div class="flip-card menu-flip-card" tabindex="0" role="button" aria-label="Miércoles, toca para ver el menú" onclick="toggleFlip(this)" onkeypress="if(event.key==='Enter')toggleFlip(this)">
                        <div class="flip-card-inner">
                            <div class="flip-card-front">
                                <div class="dia-info"><span class="dia-foto">🍳</span><span class="dia-nombre">Miércoles</span></div>
                                <span class="plato-principal-preview">Huevo frito con lentejas guisadas</span>
                                <span class="flip-hint">👆 Toca para ver el menú completo</span>
                            </div>
                            <div class="flip-card-back">
                                <div class="menu-detalles-bloque">
                                    <p><strong>Bebida:</strong> Bebida chocolatada</p>
                                    <p><strong>Plato principal:</strong> Huevo frito con lentejas guisadas</p>
                                    <p><strong>Acompañamiento:</strong> Arroz blanco</p>
                                </div>
                                <span class="flip-hint">↩️ Toca para volver</span>
                            </div>
                        </div>
                    </div>
                    <div class="flip-card menu-flip-card" tabindex="0" role="button" aria-label="Jueves, toca para ver el menú" onclick="toggleFlip(this)" onkeypress="if(event.key==='Enter')toggleFlip(this)">
                        <div class="flip-card-inner">
                            <div class="flip-card-front">
                                <div class="dia-info"><span class="dia-foto">🍝</span><span class="dia-nombre">Jueves</span></div>
                                <span class="plato-principal-preview">Carne de res desmechada en salsa napolitana casera</span>
                                <span class="flip-hint">👆 Toca para ver el menú completo</span>
                            </div>
                            <div class="flip-card-back">
                                <div class="menu-detalles-bloque">
                                    <p><strong>Bebida:</strong> Agua de panela con leche</p>
                                    <p><strong>Plato principal:</strong> Carne de res desmechada en salsa napolitana casera</p>
                                    <p><strong>Acompañamiento:</strong> Pasta corta, Tajadas de plátano maduro</p>
                                </div>
                                <span class="flip-hint">↩️ Toca para volver</span>
                            </div>
                        </div>
                    </div>
                    <div class="flip-card menu-flip-card" tabindex="0" role="button" aria-label="Viernes, toca para ver el menú" onclick="toggleFlip(this)" onkeypress="if(event.key==='Enter')toggleFlip(this)">
                        <div class="flip-card-inner">
                            <div class="flip-card-front">
                                <div class="dia-info"><span class="dia-foto">🍳</span><span class="dia-nombre">Viernes</span></div>
                                <span class="plato-principal-preview">Huevo revuelto con cebolla</span>
                                <span class="flip-hint">👆 Toca para ver el menú completo</span>
                            </div>
                            <div class="flip-card-back">
                                <div class="menu-detalles-bloque">
                                    <p><strong>Bebida:</strong> Sorbete de fruta</p>
                                    <p><strong>Plato principal:</strong> Huevo revuelto con cebolla</p>
                                    <p><strong>Acompañamiento:</strong> Arroz rojo, Papas capira con romero, Fruta entera</p>
                                </div>
                                <span class="flip-hint">↩️ Toca para volver</span>
                            </div>
                        </div>
                    </div>
            </div>
        </div>
        <div class="menu-semana-contenido" data-semana="4" style="display:none;">
            <div class="menu-acordeon">
                    <div class="flip-card menu-flip-card" tabindex="0" role="button" aria-label="Lunes, toca para ver el menú" onclick="toggleFlip(this)" onkeypress="if(event.key==='Enter')toggleFlip(this)">
                        <div class="flip-card-inner">
                            <div class="flip-card-front">
                                <div class="dia-info"><span class="dia-foto">🍲</span><span class="dia-nombre">Lunes</span></div>
                                <span class="plato-principal-preview">Goulash de carne de cerdo y papa</span>
                                <span class="flip-hint">👆 Toca para ver el menú completo</span>
                            </div>
                            <div class="flip-card-back">
                                <div class="menu-detalles-bloque">
                                    <p><strong>Bebida:</strong> Agua de panela con leche</p>
                                    <p><strong>Plato principal:</strong> Goulash de carne de cerdo y papa</p>
                                    <p><strong>Acompañamiento:</strong> Arroz con cebolla y cúrcuma</p>
                                </div>
                                <span class="flip-hint">↩️ Toca para volver</span>
                            </div>
                        </div>
                    </div>
                    <div class="flip-card menu-flip-card" tabindex="0" role="button" aria-label="Martes, toca para ver el menú" onclick="toggleFlip(this)" onkeypress="if(event.key==='Enter')toggleFlip(this)">
                        <div class="flip-card-inner">
                            <div class="flip-card-front">
                                <div class="dia-info"><span class="dia-foto">🍳</span><span class="dia-nombre">Martes</span></div>
                                <span class="plato-principal-preview">Huevo revuelto con plátano maduro</span>
                                <span class="flip-hint">👆 Toca para ver el menú completo</span>
                            </div>
                            <div class="flip-card-back">
                                <div class="menu-detalles-bloque">
                                    <p><strong>Bebida:</strong> Bebida chocolatada</p>
                                    <p><strong>Plato principal:</strong> Huevo revuelto con plátano maduro</p>
                                    <p><strong>Acompañamiento:</strong> Arroz blanco con cebolla cabezona, Fruta entera</p>
                                </div>
                                <span class="flip-hint">↩️ Toca para volver</span>
                            </div>
                        </div>
                    </div>
                    <div class="flip-card menu-flip-card" tabindex="0" role="button" aria-label="Miércoles, toca para ver el menú" onclick="toggleFlip(this)" onkeypress="if(event.key==='Enter')toggleFlip(this)">
                        <div class="flip-card-inner">
                            <div class="flip-card-front">
                                <div class="dia-info"><span class="dia-foto">🥩</span><span class="dia-nombre">Miércoles</span></div>
                                <span class="plato-principal-preview">Carne de res desmechada con queso</span>
                                <span class="flip-hint">👆 Toca para ver el menú completo</span>
                            </div>
                            <div class="flip-card-back">
                                <div class="menu-detalles-bloque">
                                    <p><strong>Bebida:</strong> Bebida fría con avena en leche</p>
                                    <p><strong>Plato principal:</strong> Carne de res desmechada con queso</p>
                                    <p><strong>Acompañamiento:</strong> Patacones</p>
                                </div>
                                <span class="flip-hint">↩️ Toca para volver</span>
                            </div>
                        </div>
                    </div>
                    <div class="flip-card menu-flip-card" tabindex="0" role="button" aria-label="Jueves, toca para ver el menú" onclick="toggleFlip(this)" onkeypress="if(event.key==='Enter')toggleFlip(this)">
                        <div class="flip-card-inner">
                            <div class="flip-card-front">
                                <div class="dia-info"><span class="dia-foto">🍖</span><span class="dia-nombre">Jueves</span></div>
                                <span class="plato-principal-preview">Carne de cerdo salteada</span>
                                <span class="flip-hint">👆 Toca para ver el menú completo</span>
                            </div>
                            <div class="flip-card-back">
                                <div class="menu-detalles-bloque">
                                    <p><strong>Bebida:</strong> Sorbete de fruta</p>
                                    <p><strong>Plato principal:</strong> Carne de cerdo salteada</p>
                                    <p><strong>Acompañamiento:</strong> Arroz con zanahoria, Papas con hogao</p>
                                </div>
                                <span class="flip-hint">↩️ Toca para volver</span>
                            </div>
                        </div>
                    </div>
                    <div class="flip-card menu-flip-card" tabindex="0" role="button" aria-label="Viernes, toca para ver el menú" onclick="toggleFlip(this)" onkeypress="if(event.key==='Enter')toggleFlip(this)">
                        <div class="flip-card-inner">
                            <div class="flip-card-front">
                                <div class="dia-info"><span class="dia-foto">🍳</span><span class="dia-nombre">Viernes</span></div>
                                <span class="plato-principal-preview">Huevo con guiso</span>
                                <span class="flip-hint">👆 Toca para ver el menú completo</span>
                            </div>
                            <div class="flip-card-back">
                                <div class="menu-detalles-bloque">
                                    <p><strong>Bebida:</strong> Agua de panela con leche</p>
                                    <p><strong>Plato principal:</strong> Huevo con guiso</p>
                                    <p><strong>Acompañamiento:</strong> Arroz blanco, Frijoles guisados, Tajada de maduro</p>
                                </div>
                                <span class="flip-hint">↩️ Toca para volver</span>
                            </div>
                        </div>
                    </div>
            </div>
        </div>
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
        <p class="nutriInfo-intro">Cada tarjeta esconde el porqué científico detrás de un buen hábito alimenticio. Toca cualquiera para descubrir puntos clave, alimentos recomendados y datos que quizás no sabías.</p>

        <div class="nutriInfo-grid">
            <?php
            $cards = [
                [
                    "icono" => "🍽️",
                    "categoria" => "Plato Saludable",
                    "color" => "#2ecc71",
                    "titulo" => "Distribución de Macronutrientes",
                    "imagen" => "https://cdn.aarp.net/content/dam/aarp/food/healthy-eating/2019/05/1140-counting-macros-promo-esp.jpg",
                    "gancho" => "La proporción perfecta en tu bandeja de almuerzo.",
                    "dato_clave" => "50% verduras · 25% proteína · 25% carbohidratos",
                    "explicacion" => "Un plato balanceado no depende de contar calorías, sino de una proporción visual sencilla: la mitad de hortalizas y frutas, un cuarto de proteína magra y un cuarto de carbohidratos complejos.",
                    "puntos" => ["Evita picos y bajones de energía entre clases", "Facilita la concentración en las últimas horas de estudio", "Es la base que usan nutricionistas escolares en todo el mundo"],
                    "alimentos" => ["Espinaca", "Pechuga de pollo", "Arroz integral"],
                ],
                [
                    "icono" => "💧",
                    "categoria" => "Cerebro y Memoria",
                    "color" => "#3498db",
                    "titulo" => "Hidratación Neuronal",
                    "imagen" => "https://gsinapsis.com/wp-content/uploads/2026/03/hidratacion-y-cerebro.webp",
                    "gancho" => "Tu cerebro es casi 75% agua, cuídalo.",
                    "dato_clave" => "Con solo 2% de deshidratación baja tu concentración",
                    "explicacion" => "El cerebro depende del equilibrio de agua y electrolitos para transmitir señales entre neuronas. Perder apenas un 2% del agua corporal ya provoca fallas de memoria a corto plazo y dolor de cabeza.",
                    "puntos" => ["Bebe agua antes de sentir sed, no cuando ya la sientes", "Las frutas con alto contenido de agua también hidratan", "Evita reemplazar el agua por bebidas azucaradas"],
                    "alimentos" => ["Agua", "Sandía", "Pepino"],
                ],
                [
                    "icono" => "🩸",
                    "categoria" => "Energía y Oxígeno",
                    "color" => "#c0392b",
                    "titulo" => "El Papel del Hierro",
                    "imagen" => "https://www.mnsa.es/wp-content/uploads/hierro-imgp.jpg",
                    "gancho" => "El transportador de oxígeno de tu cuerpo.",
                    "dato_clave" => "Su carencia es la principal causa de fatiga escolar",
                    "explicacion" => "El hierro forma parte de la hemoglobina, la molécula que lleva oxígeno desde los pulmones hasta cada célula, incluidas las neuronas. Sin suficiente hierro llega menos oxígeno al cerebro.",
                    "puntos" => ["Combina alimentos ricos en hierro con vitamina C para absorberlo mejor", "Las carnes rojas magras aportan hierro de fácil absorción", "Las legumbres son la mejor fuente vegetal de hierro"],
                    "alimentos" => ["Lentejas", "Carne magra", "Espinaca"],
                ],
                [
                    "icono" => "⚡",
                    "categoria" => "Alerta Alimentaria",
                    "color" => "#e67e22",
                    "titulo" => "Azúcar vs. Energía",
                    "imagen" => "https://www.fisiologiadelejercicio.com/wp-content/uploads/2023/06/azucar-scaled.jpg",
                    "gancho" => "No toda la energía dura lo mismo.",
                    "dato_clave" => "Un pico de azúcar dura ~20 min; luego llega la bajada",
                    "explicacion" => "Los azúcares refinados elevan la glucosa de forma abrupta, dando energía momentánea. El cuerpo responde liberando insulina de golpe, y la caída posterior trae cansancio y dificultad para concentrarse.",
                    "puntos" => ["Los carbohidratos complejos liberan energía de forma gradual", "El azúcar añadida aparece en las etiquetas con muchos nombres distintos", "Combinar dulces con fibra o proteína suaviza el pico de glucosa"],
                    "alimentos" => ["Avena", "Frutos secos", "Manzana"],
                ],
                [
                    "icono" => "🌾",
                    "categoria" => "Digestión",
                    "color" => "#16a085",
                    "titulo" => "Beneficios de la Fibra",
                    "imagen" => "https://colbritanico.edu.co/wp-content/uploads/sites/27/2025/01/fibra-coetica-1.jpg",
                    "gancho" => "La aliada silenciosa de tu digestión.",
                    "dato_clave" => "Ralentiza hasta un 30% la absorción de azúcar",
                    "explicacion" => "La fibra no se digiere, pero forma una red que ralentiza la absorción de nutrientes, prolonga la saciedad y alimenta a las bacterias buenas del intestino.",
                    "puntos" => ["Ayuda a mantener estable el azúcar en la sangre", "Favorece un sistema digestivo saludable", "Aumenta la sensación de saciedad tras comer"],
                    "alimentos" => ["Frijoles", "Avena", "Brócoli"],
                ],
                [
                    "icono" => "🐟",
                    "categoria" => "Cerebro y Memoria",
                    "color" => "#3498db",
                    "titulo" => "El Rol de los Omega-3",
                    "imagen" => "https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcSyjoLV7OqARDYBHMXMGDSCxHGkKnjSGpZ3Aw&s",
                    "gancho" => "El \"ladrillo\" de tus neuronas.",
                    "dato_clave" => "Forman hasta el 60% de la grasa estructural del cerebro",
                    "explicacion" => "Los ácidos grasos omega-3, en especial el DHA, forman parte de las membranas de las neuronas y favorecen la comunicación entre células cerebrales, lo que se relaciona con mejor memoria y aprendizaje.",
                    "puntos" => ["El cuerpo no los produce: deben venir de la alimentación", "Los pescados grasos son la fuente más concentrada", "Las nueces y semillas son buenas alternativas vegetales"],
                    "alimentos" => ["Salmón", "Nueces", "Semillas de chía"],
                ],
                [
                    "icono" => "🔋",
                    "categoria" => "Energía Celular",
                    "color" => "#8e44ad",
                    "titulo" => "Vitaminas del Grupo B",
                    "imagen" => "https://institutodyn.com/wp-content/uploads/vitaminas-del-grupo-b.jpg",
                    "gancho" => "Las que convierten comida en energía.",
                    "dato_clave" => "Actúan en más de 100 reacciones del cuerpo",
                    "explicacion" => "Las vitaminas B1, B2, B3, B6, B9 y B12 funcionan como ayudantes de las enzimas que transforman carbohidratos, grasas y proteínas en energía utilizable por cada célula.",
                    "puntos" => ["Están presentes en casi todos los grupos de alimentos", "La B12 se encuentra casi exclusivamente en alimentos de origen animal", "Su déficit provoca cansancio y falta de concentración"],
                    "alimentos" => ["Huevo", "Cereal integral", "Plátano"],
                ],
                [
                    "icono" => "🦴",
                    "categoria" => "Crecimiento",
                    "color" => "#d4a017",
                    "titulo" => "Calcio y Salud Ósea",
                    "imagen" => "https://cdn.static.aptavs.com/imagenes/alimentos-ricos-en-calcio_905x603.jpg",
                    "gancho" => "El momento clave para fortalecer tus huesos.",
                    "dato_clave" => "El 90% de la masa ósea se forma antes de los 20 años",
                    "explicacion" => "Durante la adolescencia el cuerpo construye la mayor parte de la densidad ósea que tendrá toda la vida. El calcio, junto con la vitamina D, es el mineral clave para lograr huesos fuertes.",
                    "puntos" => ["La actividad física ayuda a fijar el calcio en los huesos", "La vitamina D es indispensable para absorber el calcio", "Los lácteos no son la única fuente: hay opciones vegetales"],
                    "alimentos" => ["Leche", "Queso", "Almendras"],
                ],
            ];

            foreach ($cards as $c) {
                $puntosHtml = '';
                foreach ($c['puntos'] as $p) {
                    $puntosHtml .= '<li>' . htmlspecialchars($p) . '</li>';
                }
                $alimentosHtml = '';
                foreach ($c['alimentos'] as $a) {
                    $alimentosHtml .= '<span class="nutriInfo-chip">' . htmlspecialchars($a) . '</span>';
                }

                echo '
    <div class="flip-card nutri-flip-card-v2" style="--nutri-color: ' . htmlspecialchars($c['color']) . ';" tabindex="0" role="button" aria-label="' . htmlspecialchars($c['titulo']) . ', toca para ver más" onclick="toggleFlip(this)" onkeypress="if(event.key===\'Enter\')toggleFlip(this)">
        <div class="flip-card-inner">
            <div class="flip-card-front">
                <div class="nutriInfo-media">
                    <img src="' . htmlspecialchars($c['imagen']) . '" alt="' . htmlspecialchars($c['titulo']) . '" loading="lazy">
                    <span class="nutriInfo-badge">' . htmlspecialchars($c['categoria']) . '</span>
                    <span class="nutriInfo-icon">' . $c['icono'] . '</span>
                </div>
                <div class="nutriInfo-body">
                    <h5>' . htmlspecialchars($c['titulo']) . '</h5>
                    <p class="nutriInfo-gancho">' . htmlspecialchars($c['gancho']) . '</p>
                    <div class="nutriInfo-stat">📊 ' . htmlspecialchars($c['dato_clave']) . '</div>
                    <span class="flip-hint">👆 Toca para saber más</span>
                </div>
            </div>
            <div class="flip-card-back nutriInfo-back">
                <h5>' . $c['icono'] . ' ' . htmlspecialchars($c['titulo']) . '</h5>
                <p>' . htmlspecialchars($c['explicacion']) . '</p>
                <ul class="nutriInfo-puntos">' . $puntosHtml . '</ul>
                <div class="nutriInfo-alimentos">' . $alimentosHtml . '</div>
                <span class="flip-hint">↩️ Toca para volver</span>
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
                            <span id="quiz-progreso" style="font-size: 0.92em; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;">Pregunta 1 de 3</span>
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
                    <p style="text-align: center; margin-top: 20px; font-size: 0.96em;">
                        <a href="#registro" class="btn-nav-internal" style="color: #386134; text-decoration: none; font-weight: 600;">¿Olvidaste tu contraseña?</a>
                    </p>
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
                    <span>🍂 Feria de Otoño: Cosecha y Sabores — del 28 de septiembre al 2 de octubre &nbsp;|&nbsp; 📸 Reto "Mi Lonchera Saludable": participa hasta el viernes &nbsp;|&nbsp; 👨‍🍳 Cocina en vivo con chef invitado este viernes &nbsp;|&nbsp; 🌾 Segunda cosecha de la huerta escolar: sábado 3 de octubre &nbsp;|&nbsp; 🏅 ¡Certificación en Buenas Prácticas de Manipulación de Alimentos!</span>
                </div>
            </div>

            <!-- Tarjetas de eventos próximos -->
            <div class="noticias-grid">

                <div class="noticia-card destacada">
                    <div class="noticia-badge">📌 Destacado</div>
                    <div class="noticia-fecha">Lunes 28 Sept. · Viernes 2 Oct. 2026</div>
                    <h4>🍂 Feria de Otoño: Cosecha y Sabores</h4>
                    <p>Una semana llena de degustaciones, stands de comida y actividades para celebrar los productos de temporada y su lugar en una alimentación balanceada.</p>
                    <div class="noticia-meta">🕘 Todo el día &nbsp;|&nbsp; 📍 Patio central</div>
                </div>

                <div class="noticia-card">
                    <div class="noticia-fecha">Miércoles 30 · Septiembre 2026</div>
                    <h4>📸 Reto "Mi Lonchera Saludable"</h4>
                    <p>Sube una foto de tu lonchera más nutritiva con el hashtag #LlenosDeAmorReto. Las tres mejores ganan un kit de cocina saludable. ¡Vale creatividad y color!</p>
                    <div class="noticia-meta">🕙 Todo el día &nbsp;|&nbsp; 📍 Redes sociales del colegio</div>
                </div>

                <div class="noticia-card">
                    <div class="noticia-fecha">Jueves 1 · Octubre 2026</div>
                    <h4>🧠 Charla: Alimentación y Rendimiento Académico</h4>
                    <p>La nutricionista Dra. Marcela Ospina explicará cómo lo que comemos afecta la concentración y la energía para estudiar, con ejemplos prácticos para el día a día.</p>
                    <div class="noticia-meta">🕙 10:00 a.m. &nbsp;|&nbsp; 📍 Auditorio principal</div>
                </div>

                <div class="noticia-card">
                    <div class="noticia-fecha">Viernes 2 · Octubre 2026</div>
                    <h4>👨‍🍳 Cocina en Vivo con Chef Invitado</h4>
                    <p>Un chef local prepara en directo una receta de temporada con ingredientes de la huerta escolar, con degustación incluida para los estudiantes.</p>
                    <div class="noticia-meta">🕑 12:00 p.m. &nbsp;|&nbsp; 📍 Cafetería principal</div>
                </div>

                <div class="noticia-card">
                    <div class="noticia-fecha">Sábado 3 · Octubre 2026</div>
                    <h4>🌾 Segunda Cosecha de la Huerta Escolar</h4>
                    <p>¡Es hora de recoger lo sembrado! Familias y estudiantes están invitados a cosechar juntos los vegetales que luego llegarán a la cocina del restaurante.</p>
                    <div class="noticia-meta">🕙 9:00 a.m. &nbsp;|&nbsp; 📍 Zona Verde</div>
                </div>

                <div class="noticia-card logro">
                    <div class="noticia-badge logro-badge">🏅 Logro</div>
                    <div class="noticia-fecha">Agosto 2026</div>
                    <h4>Certificación en Buenas Prácticas de Manipulación de Alimentos</h4>
                    <p>¡Nuestro restaurante escolar obtuvo la certificación oficial de la Secretaría de Salud de Antioquia! Un respaldo más a la calidad y seguridad de lo que servimos.</p>
                    <div class="noticia-meta">🎉 Reconocimiento oficial de la Secretaría de Salud</div>
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
                <p style="color:#666; margin-top:8px; font-size:0.96em;">Feria de Otoño: Cosecha y Sabores — Lunes 28 de septiembre</p>
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
                                <p id="plato-hint" style="color:#aaa; font-size:0.87em; margin:0;">Suelta aquí tus alimentos</p>
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
                        <div style="display:flex; justify-content:space-between; margin-top:10px; font-size:0.92em; color:#666;">
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
                        <span style="font-size:0.92em; color:#666;">Pares encontrados: <strong id="mem-pares">0</strong>/6</span>
                        <button class="button primary" style="padding:8px 18px; font-size:0.92em;" onclick="iniciarMemoria()">Reiniciar 🔄</button>
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
                    <div style="display:flex; justify-content:space-between; margin-top:10px; font-size:0.96em;">
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

    <!-- Chatbot Nutribot: fuera de las secciones para que se vea en todas las pestañas -->
    <div class="chatbot-container" id="chat-container">
        <button class="chatbot-toggle" id="chat-toggle" onclick="toggleChat()">
            💬 <span style="font-size: 0.87em; margin-left: 5px;">Nutribot</span>
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

    <footer>
        <div class="footer-contenido">
            <div class="footer-col footer-marca">
                <div class="footer-logo">
                    <img src="logo.jpeg" alt="Llenos de Amor Logo">
                    <span>Llenos de Amor</span>
                </div>
            </div>

            <div class="footer-col">
                <h5>Contáctanos</h5>
                <p>I.E Presbítero Montoya Giraldo</p>
                <p>Antioquia, Colombia</p>
            </div>

            <div class="footer-col">
                <h5>Síguenos</h5>
                <div class="footer-social">
                    <a href="https://www.instagram.com/lleno.sdeamor" target="_blank" rel="noopener noreferrer" class="footer-social-link">
                        <span class="footer-social-icono">📸</span>
                        <span>@lleno.sdeamor</span>
                    </a>
                </div>
            </div>
        </div>

        <div class="footer-inferior">
            <p>Llenos de Amor &copy; 2026 — Todos los derechos reservados</p>
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

        function mostrarSemanaMenu(numero) {
            document.querySelectorAll('.menu-semana-contenido').forEach(function (div) {
                div.style.display = (div.dataset.semana === String(numero)) ? 'block' : 'none';
            });
            document.querySelectorAll('.menu-semana-btn').forEach(function (btn) {
                btn.classList.toggle('activa', btn.dataset.semana === String(numero));
            });
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
                    <p style="margin: 0; color: #475569; font-size: 1em;">
                        Tu Gasto Energético Basal estimado es de <strong>${Math.round(tmb)} calorías diarias</strong>. Esto representa la energía neta mínima que tu organismo requiere para mantener funciones celulares vitales, sin contar las calorías adicionales que gastas al caminar o entrenar.
                    </p>
                </div>
            `;

            registrarImc(peso, estatura, genero, imc, clasificacion, Math.round(tmb));
        }

        // Envía el resultado de la calculadora de IMC/TMB al servidor para
        // guardarlo en resultados_imc. Si el usuario no tiene sesión iniciada,
        // el backend responde 401 y aquí simplemente no se hace nada más.
        function registrarImc(peso, estatura, genero, imc, clasificacion, tmb) {
            fetch('guardar_imc.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ peso, estatura, genero, imc, clasificacion, tmb })
            })
            .then(r => r.json())
            .catch(err => console.error('No se pudo registrar el resultado de IMC:', err));
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

                registrarQuiz(respuestasCorrectasContador, bancoPreguntas.length);
            }
        }

        // Envía el puntaje final del quiz al servidor para guardarlo en
        // resultados_quiz. Si el usuario no tiene sesión iniciada, el backend
        // responde 401 y aquí simplemente no se hace nada más.
        function registrarQuiz(puntaje, total) {
            fetch('guardar_quiz.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ puntaje, total })
            })
            .then(r => r.json())
            .catch(err => console.error('No se pudo registrar el resultado del quiz:', err));
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
            const objetivo = new Date('2026-09-28T07:00:00');
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
        // y la agrega de inmediato a "Mis juegos jugados" sin tener que refrescar la página.
        function registrarJuego(juego, puntaje, resultado) {
            fetch('guardar_juego.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ juego, puntaje, resultado })
            })
            .then(r => r.json())
            .then(data => {
                if (data && data.ok && data.partida) {
                    agregarJuegoAlPanel(data.partida);
                }
            })
            .catch(err => console.error('No se pudo registrar el juego:', err));
        }

        function agregarJuegoAlPanel(p) {
            const lista = document.getElementById('lista-mis-juegos');
            if (!lista) return;

            // Si el panel estaba vacío, quita el mensaje "Todavía no has jugado..."
            const sinJuegos = lista.querySelector('.sin-juegos');
            if (sinJuegos) sinJuegos.remove();

            const item = document.createElement('div');
            item.className = 'juego-item';
            item.innerHTML = `
                <div class="juego-info">
                    <div class="juego-nombre">${p.juego} — ${p.puntaje} pts</div>
                    <div class="juego-detalle">${p.resultado ?? ''} · ${p.fecha}</div>
                </div>
                <a class="juego-borrar" href="?eliminar_juego=${p.id}&panel=perfil" title="Borrar esta partida" onclick="return confirm('¿Borrar esta partida de tu historial?');">✕</a>
            `;
            lista.prepend(item);
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
            {e:'🥦',s:true},{e:'🍗',s:true},{e:'🍎',s:true},{e:'🥕',s:true},{e:'🥚',s:true},{e:'🍌',s:true},
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