<?php
// guardar_imc.php
// Recibe (por fetch/JS) el resultado de la calculadora de IMC/TMB y lo
// guarda en la tabla resultados_imc, asociado al usuario con sesión activa.

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

$peso = isset($datos['peso']) ? (float) $datos['peso'] : 0;
$estatura = isset($datos['estatura']) ? (float) $datos['estatura'] : 0;
$generosValidos = ['masculino', 'femenino'];
$genero = in_array($datos['genero'] ?? '', $generosValidos, true) ? $datos['genero'] : '';
$imc = isset($datos['imc']) ? (float) $datos['imc'] : 0;
$clasificacion = isset($datos['clasificacion']) ? trim($datos['clasificacion']) : '';
$tmb = isset($datos['tmb']) ? (int) $datos['tmb'] : 0;

if ($peso <= 0 || $estatura <= 0 || $genero === '' || $imc <= 0 || $clasificacion === '') {
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "Datos incompletos o no válidos."]);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "INSERT INTO resultados_imc (usuario_id, peso, estatura, genero, imc, clasificacion, tmb) VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([$_SESSION['usuario_id'], $peso, $estatura, $genero, $imc, $clasificacion, $tmb]);

    echo json_encode([
        "ok" => true,
        "resultado" => [
            "id" => $pdo->lastInsertId(),
            "imc" => number_format($imc, 1),
            "clasificacion" => $clasificacion,
            "tmb" => $tmb,
            "fecha" => date("d/m/Y H:i"),
        ],
    ]);
} catch (PDOException $e) {
    error_log("Error al guardar resultado de IMC: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["ok" => false, "error" => "No se pudo guardar el resultado."]);
}
