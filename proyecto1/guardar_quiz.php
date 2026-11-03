<?php
// guardar_quiz.php
// Recibe (por fetch/JS) el puntaje final del "Desafío de Conocimiento
// Nutricional" y lo guarda en la tabla resultados_quiz, asociado al
// usuario con sesión activa.

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

$puntaje = isset($datos['puntaje']) ? (int) $datos['puntaje'] : -1;
$total = isset($datos['total']) ? (int) $datos['total'] : 0;

if ($puntaje < 0 || $total <= 0 || $puntaje > $total) {
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "Datos incompletos o no válidos."]);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "INSERT INTO resultados_quiz (usuario_id, puntaje, total_preguntas) VALUES (?, ?, ?)"
    );
    $stmt->execute([$_SESSION['usuario_id'], $puntaje, $total]);

    echo json_encode([
        "ok" => true,
        "resultado" => [
            "id" => $pdo->lastInsertId(),
            "puntaje" => $puntaje,
            "total" => $total,
            "fecha" => date("d/m/Y H:i"),
        ],
    ]);
} catch (PDOException $e) {
    error_log("Error al guardar resultado del quiz: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["ok" => false, "error" => "No se pudo guardar el resultado."]);
}
