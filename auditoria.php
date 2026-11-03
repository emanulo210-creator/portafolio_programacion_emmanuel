<?php
// auditoria.php
// Función reutilizable: llámala cada vez que el CRUD haga un INSERT, UPDATE
// o DELETE (ya sea el usuario editando sus propios datos o el admin
// editando a cualquier usuario), y deja el rastro en la tabla "auditoria".
//
// Requiere que ya exista $pdo (conexion.php) y session_start() ya llamado,
// porque usa $_SESSION para saber quién hizo el cambio.

function registrar_auditoria(
    $pdo,
    $accion,            // 'INSERT' | 'UPDATE' | 'DELETE'
    $tabla_afectada,    // nombre de la tabla que cambió, ej: 'registro'
    $registro_id = null,      // id de la fila afectada
    $datos_anteriores = null, // array asociativo con el estado previo (o null)
    $datos_nuevos = null,     // array asociativo con el estado nuevo (o null)
    $descripcion = null       // texto corto opcional para leer fácil el log
) {
    $usuario_id  = $_SESSION['usuario_id'] ?? null;
    $rol_usuario = (!empty($_SESSION['es_admin'])) ? 'admin' : 'usuario';

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO auditoria
                (usuario_id, rol_usuario, accion, tabla_afectada, registro_id, datos_anteriores, datos_nuevos, descripcion)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $usuario_id,
            $rol_usuario,
            $accion,
            $tabla_afectada,
            $registro_id,
            $datos_anteriores !== null ? json_encode($datos_anteriores, JSON_UNESCAPED_UNICODE) : null,
            $datos_nuevos !== null ? json_encode($datos_nuevos, JSON_UNESCAPED_UNICODE) : null,
            $descripcion,
        ]);
    } catch (PDOException $e) {
        // Si falla el log, no debe tumbar la acción principal del CRUD;
        // solo lo dejamos en el log de errores del servidor.
        error_log("Error al registrar auditoría: " . $e->getMessage());
    }
}

// registrar_cambio_rol(): úsala específicamente cuando el admin convierte a
// alguien en administrador o le quita ese permiso. Deja bien claro en el
// log QUIÉN hizo el cambio, A QUIÉN se lo hizo, y en qué sentido fue.
function registrar_cambio_rol($pdo, $usuario_id_afectado, $nombre_afectado, $nuevo_valor_admin) {
    $descripcion = $nuevo_valor_admin
        ? "Convirtió en administrador a \"$nombre_afectado\" (usuario #$usuario_id_afectado)"
        : "Le quitó el rol de administrador a \"$nombre_afectado\" (usuario #$usuario_id_afectado)";

    registrar_auditoria(
        $pdo,
        'UPDATE_ROL',
        'registro',
        $usuario_id_afectado,
        ['es_admin' => $nuevo_valor_admin ? 0 : 1],
        ['es_admin' => $nuevo_valor_admin ? 1 : 0],
        $descripcion
    );
}

/*
================================================================================
 EJEMPLO DE USO — dentro de panel_admin.php (o donde hagas el UPDATE/DELETE)
================================================================================

require_once "conexion.php";
require_once "auditoria.php";
session_start();

// --- Ejemplo: EDITAR un usuario ---
// 1) Guarda cómo estaba ANTES
$stmt = $pdo->prepare("SELECT nombre, correo, documento FROM registro WHERE id = ?");
$stmt->execute([$id]);
$datos_anteriores = $stmt->fetch();

// 2) Haz el UPDATE normal
$stmt = $pdo->prepare("UPDATE registro SET nombre = ?, correo = ?, documento = ? WHERE id = ?");
$stmt->execute([$nombre, $correo, $documento, $id]);

// 3) Registra el cambio
$datos_nuevos = ["nombre" => $nombre, "correo" => $correo, "documento" => $documento];
registrar_auditoria($pdo, 'UPDATE', 'registro', $id, $datos_anteriores, $datos_nuevos, "Editó datos del usuario #$id");


// --- Ejemplo: BORRAR un usuario ---
$stmt = $pdo->prepare("SELECT nombre, correo FROM registro WHERE id = ?");
$stmt->execute([$id]);
$datos_anteriores = $stmt->fetch();

$stmt = $pdo->prepare("DELETE FROM registro WHERE id = ?");
$stmt->execute([$id]);

registrar_auditoria($pdo, 'DELETE', 'registro', $id, $datos_anteriores, null, "Eliminó al usuario #$id");


// --- Ejemplo: CREAR un usuario nuevo (registro) ---
$stmt = $pdo->prepare("INSERT INTO registro (nombre, correo, documento, password) VALUES (?, ?, ?, ?)");
$stmt->execute([$nombre, $correo, $documento, $password_hash]);
$nuevo_id = $pdo->lastInsertId();

$datos_nuevos = ["nombre" => $nombre, "correo" => $correo, "documento" => $documento];
registrar_auditoria($pdo, 'INSERT', 'registro', $nuevo_id, null, $datos_nuevos, "Nuevo registro de usuario");


// --- Ejemplo: HACER o QUITAR administrador a alguien ---
// $nuevo_valor debe ser 1 (lo hago admin) o 0 (le quito el permiso)
$stmt = $pdo->prepare("UPDATE registro SET es_admin = ? WHERE id = ?");
$stmt->execute([$nuevo_valor, $id]);

// Necesitas el nombre del usuario afectado para que el log sea legible
$stmt = $pdo->prepare("SELECT nombre FROM registro WHERE id = ?");
$stmt->execute([$id]);
$nombre_afectado = $stmt->fetchColumn();

registrar_cambio_rol($pdo, $id, $nombre_afectado, $nuevo_valor);
================================================================================
*/
