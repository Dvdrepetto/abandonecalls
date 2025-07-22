<?php
// finalize.php - v3, ahora también asegura el agent_id
require_once __DIR__ . '/db_config_constants.php';

$mysqli = new mysqli(ISSABEL_DB_HOST, ISSABEL_DB_USER, ISSABEL_DB_PASS, ABANDONED_DB_NAME);
if ($mysqli->connect_error) { http_response_code(500); exit; }
$mysqli->set_charset('utf8mb4');

$uniqueid = $_GET['uniqueid'] ?? '';
$notes = $_GET['notes'] ?? '';
$agent_id = $_GET['agent'] ?? 'unknown_finalize'; // Capturamos el agente aquí también

if (empty($uniqueid)) {
    http_response_code(400); exit("Missing uniqueid");
}

// Ahora actualizamos status, notes Y agent_id.
$sql = "UPDATE abandoned_calls 
        SET status = 'recovered', 
            notes = ?,
            agent_id = ?
        WHERE uniqueid = ? AND status = 'processing'";

$stmt = $mysqli->prepare($sql);
if ($stmt) {
    // 3 parámetros: nota (s), agente (s), uniqueid (s)
    $stmt->bind_param("sss", $notes, $agent_id, $uniqueid);
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
