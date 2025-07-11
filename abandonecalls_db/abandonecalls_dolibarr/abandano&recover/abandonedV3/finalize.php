<?php
// API para finalizar la gestión de una llamada
require_once __DIR__ . '/db_config_constants.php';

$mysqli = new mysqli(ISSABEL_DB_HOST, ISSABEL_DB_USER, ISSABEL_DB_PASS, ABANDONED_DB_NAME);
if ($mysqli->connect_error) { http_response_code(500); exit; }
$mysqli->set_charset('utf8mb4');

$uniqueid = $_GET['uniqueid'] ?? '';

if (empty($uniqueid)) {
    http_response_code(400);
    exit("Missing uniqueid");
}

// Cambiamos el estado de 'processing' a 'recovered'.
// No necesitamos WHERE agent_id, porque la interfaz solo mostrará el botón al agente correcto.
$sql = "UPDATE abandoned_calls
        SET status = 'recovered'
        WHERE uniqueid = ? AND status = 'processing'";

$stmt = $mysqli->prepare($sql);

if ($stmt) {
    $stmt->bind_param("s", $uniqueid);
    if ($stmt->execute()) {
        echo "OK";
    } else {
	http_response_code(500);
    }
    $stmt->close();
} else {
    http_response_code(500);
}

$mysqli->close();
?>