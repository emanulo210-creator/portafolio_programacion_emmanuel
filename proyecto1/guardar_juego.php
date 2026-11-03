<?php
// guardar_juego.php
// Recibe (por fetch/JS) el resultado de una partida y lo guarda en
// la tabla juegos_jugados, asociado al usuario que tiene la sesión activa.

session_start();
require_once "conexion.php";

header('Content-Type: application/json');

// Solo usuarios con sesión iniciada pueden registrar resultados
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(["ok" => false, "error" => "Debes iniciar sesión para guardar tu resultado."]);
    exit;
}

$datos = json_decode(file_get_contents('php://input'), true);

$juegosValidos = ['plato', 'adivina', 'memoria', 'atrapa'];
$juego = $datos['juego'] ?? '';
$puntaje = isset($datos['puntaje']) ? (int) $datos['puntaje'] : 0;
$resultado = isset($datos['resultado']) ? trim($datos['resultado']) : null;

if (!in_array($juego, $juegosValidos, true)) {
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "Juego no válido."]);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "INSERT INTO juegos_jugados (usuario_id, juego, puntaje, resultado) VALUES (?, ?, ?, ?)"
    );
    $stmt->execute([$_SESSION['usuario_id'], $juego, $puntaje, $resultado]);

    echo json_encode([
        "ok" => true,
        "partida" => [
            "id" => $pdo->lastInsertId(),
            "juego" => $juego,
            "puntaje" => $puntaje,
            "resultado" => $resultado,
            "fecha" => date("d/m/Y H:i"),
        ],
    ]);
} catch (PDOException $e) {
    error_log("Error al guardar resultado de juego: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["ok" => false, "error" => "No se pudo guardar el resultado."]);
}
