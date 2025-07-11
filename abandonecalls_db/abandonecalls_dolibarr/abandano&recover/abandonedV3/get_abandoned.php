<?php
// get_abandoned.php - Versión con todos los datos enriquecidos
require_once __DIR__ . '/db_config_constants.php';
$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, ABANDONED_DB_NAME);
if ($mysqli->connect_error) { http_response_code(500); exit; }
$mysqli->set_charset('utf8mb4');

$calls = [];
// Pedimos todas las columnas nuevas
$sql = "SELECT uniqueid, call_time, queue_human_name as queue, caller_id as `from`, 
            contact_name, wait_time, abandon_position, dial_string
        FROM abandoned_calls 
        WHERE status = 'abandoned' 
        ORDER BY call_time DESC";

if ($result = $mysqli->query($sql)) {
    while ($row = $result->fetch_assoc()) {
        $row['time'] = strtotime($row['call_time']);
        $calls[] = $row;
    }
    $result->free();
}
$mysqli->close();

header('Content-Type: application/json');
echo json_encode($calls);
?>