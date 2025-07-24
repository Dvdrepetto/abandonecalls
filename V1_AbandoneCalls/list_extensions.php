#!/usr/bin/php
<?php
// Cambiamos al directorio del script para que __DIR__ funcione
chdir(__DIR__);

// Requerimos el archivo con datos de conexión
require_once __DIR__ . '/db_config_constants.php';

// Crear conexión con la base 'asterisk'
$mysqli = new mysqli(ISSABEL_DB_HOST, ISSABEL_DB_USER, ISSABEL_DB_PASS, ASTERISK_DB_NAME);

// Verificamos si la conexión falla
if ($mysqli->connect_error) {
    die("Error de conexión a la base de datos: " . $mysqli->connect_error);
}

$mysqli->set_charset('utf8mb4');

// Consulta para obtener las extensiones y nombres
$sql = "SELECT extension, name FROM users ORDER BY extension LIMIT 20";

if ($result = $mysqli->query($sql)) {
    // Mostrar cada extensión y nombre
    while ($row = $result->fetch_assoc()) {
        echo "Extensión: " . $row['extension'] . " - Nombre: " . $row['name'] . "\n";
    }
    $result->free();
} else {
    echo "Error en la consulta: " . $mysqli->error;
}

// Cerramos la conexión
$mysqli->close();
?>