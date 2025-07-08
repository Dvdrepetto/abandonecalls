<?php
define('DB_HOST', '65.21.57.122');
define('DB_USER', 'prueba1');
define('DB_PASS', 'zGWNsENzr8HjhjPX');
define('DB_NAME', 'prueba1');

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($mysqli->connect_error) {
    http_response_code(500);
    die("Error de conexión a la base de datos: " . $mysqli->connect_error);
}

$mysqli->set_charset('utf8mb4');
?>