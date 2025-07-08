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
[DR3p3tt0@centralita abandonedcalls]$ cat db_config_constants.php 
<?php
// Constantes de configuración de la base de datos

define('DB_HOST', '65.21.57.122');
define('DB_USER', 'sql_dolibarr_cli');
define('DB_PASS', '8c53cfa96034d');

define('DOLIBARR_DB_NAME', 'sql_dolibarr_cli');
define('ABANDONED_DB_NAME', 'prueba1');
?>
[DR3p3tt0@centralita abandonedcalls]$ cat recover.php
<?php
require_once __DIR__ . '/db_config_constants.php';

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS);
if ($mysqli->connect_error) {
    http_response_code(500);
    exit("Error de conexión");
}
$mysqli->set_charset('utf8mb4');

$uniqueid = $_GET['uniqueid'] ?? '';

if (empty($uniqueid)) {
    http_response_code(400);
    echo "Missing uniqueid";
    exit;
}

// --- MODIFICACIÓN CLAVE ---
// Añadimos el nombre de la base de datos a la consulta UPDATE
$stmt = $mysqli->prepare("UPDATE `" . ABANDONED_DB_NAME . "`.abandoned_calls SET status = 'recovered' WHERE uniqueid = ?");

if ($stmt) {
    $stmt->bind_param("s", $uniqueid);
    if ($stmt->execute()) {
        echo "OK";
    } else {
        http_response_code(500);
        echo "Error updating record";
    }
    $stmt->close();
} else {
    http_response_code(500);
    echo "Error preparing statement";
}

$mysqli->close();
?>