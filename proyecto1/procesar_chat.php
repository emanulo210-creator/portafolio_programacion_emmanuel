<?php
// procesar_chat.php
// La API key vive en un archivo aparte (config_chat.php) que NO se sube a git/hosting público
// ni se comparte por chat, para que no vuelva a quedar expuesta.
require_once __DIR__ . "/config_chat.php"; // define $api_key

$datos = json_decode(file_get_contents('php://input'), true);

// Verificamos que recibimos mensajes
if (!isset($datos['messages'])) {
    echo json_encode(["error" => "No se recibió historial de mensajes"]);
    exit;
}

$historial = $datos['messages'];

if (empty($historial)) {
    echo json_encode(["error" => "El historial de mensajes está vacío"]);
    exit;
}

$url = "https://openrouter.ai/api/v1/chat/completions";

// El JS ya manda { role: 'user'|'assistant', content: '...' }, que es
// exactamente el formato que espera aimlapi (compatible con OpenAI).
$mensajesFormateados = [];

// Instrucción de sistema: puede responder cualquier pregunta, pero mantiene
// el tono y el enfoque del sitio (alimentación saludable para niños).
$mensajesFormateados[] = [
    "role" => "system",
    "content" => "Eres el asistente virtual de 'Llenos de Amor', un sitio educativo sobre alimentación saludable para niños. " .
                  "Puedes responder cualquier pregunta que te hagan, no solo de nutrición. " .
                  "Responde siempre en español, de forma breve, clara, cercana y apropiada para todo público (incluyendo niños). " .
                  "Si la pregunta es sobre nutrición, menús o hábitos saludables, da prioridad a esa información."
];

foreach ($historial as $msg) {
    if (!isset($msg['content']) || trim($msg['content']) === '') continue;
    $rol = ($msg['role'] ?? 'user') === 'assistant' ? 'assistant' : 'user';
    $mensajesFormateados[] = [
        "role" => $rol,
        "content" => $msg['content']
    ];
}

$payload = [
    "model" => "openrouter/free",
    "messages" => $mensajesFormateados
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $api_key
]);

$resultado = curl_exec($ch);

if (curl_errno($ch)) {
    echo json_encode(["error" => "Error de conexión: " . curl_error($ch)]);
    curl_close($ch);
    exit;
}

curl_close($ch);

$res_array = json_decode($resultado, true);

// Extraer el texto de la respuesta (formato estilo OpenAI: choices[0].message.content)
if (isset($res_array['choices'][0]['message']['content'])) {
    $texto = $res_array['choices'][0]['message']['content'];
    // Devolvemos el formato que tu JavaScript espera
    echo json_encode(["content" => [["text" => $texto]]]);
} else {
    // Si la API responde con un error, lo devolvemos para verlo en la consola
    echo json_encode(["error" => "Respuesta inesperada de la API", "raw" => $res_array]);
}