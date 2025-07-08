<?php
define('DB_HOST', '65.21.57.122');
define('DB_USER', 'sql_dolibarr_cli');
define('DB_PASS', '8c53cfa96034d');
define('DOLIBARR_DB_NAME', 'sql_dolibarr_cli');
define('ABANDONED_DB_NAME', 'prueba1');

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS);

if ($mysqli->connect_error) {
    http_response_code(500);
    die("Error de conexión a la base de datos: " . $mysqli->connect_error);
}

$mysqli->set_charset('utf8mb4');
?>