<?php

$nombre = $_POST["nombre"];
$asignatura = $_POST["asignatura"];
$nota1 = $_POST["nota1"];
$nota2 = $_POST["nota2"];

$promedio = ($nota1 + $nota2) / 2;

// Valoración
if ($promedio >= 4.6) {
    $valoracion = "Superior";
} elseif ($promedio >= 4.0) {
    $valoracion = "Alto";
} elseif ($promedio >= 3.0) {
    $valoracion = "Básico";
} else {
    $valoracion = "Bajo";
}

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Resultado</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="contenedor">
<h2>Resultado del Estudiante</h2>

<p><strong>Nombre:</strong> <?php echo $nombre; ?></p>
<p><strong>Asignatura:</strong> <?php echo $asignatura; ?></p>
<p><strong>Nota 1:</strong> <?php echo $nota1; ?></p>
<p><strong>Nota 2:</strong> <?php echo $nota2; ?></p>
<p><strong>Promedio:</strong> <?php echo number_format($promedio, 1); ?></p>
<p><strong>Valoración:</strong> <?php echo $valoracion; ?></p>

<br>

<a href="formulario.php">Volver al formulario</a>
</div>
</body>
</html>