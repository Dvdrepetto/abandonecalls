<?php
// get_abandoned.php - Versión final que envía todos los campos necesarios

require_once __DIR__ . '/db_config_constants.php';

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, ABANDONED_DB_NAME);
if ($mysqli->connect_error) { 
    http_response_code(500); 
    echo json_encode(['error' => 'db connection failed']);
    exit; 
}
$mysqli->set_charset('utf8mb4');

$calls = [];

// --- ESTA ES LA CONSULTA CLAVE ---
// Seleccionamos TODOS los campos que el JavaScript va a necesitar.
$sql = "SELECT 
            uniqueid, 
            call_time, 
            queue_human_name as queue, 
            caller_id as `from`, 
            contact_name, 
            wait_time, 
            abandon_position, 
            dial_string,
            status, 
            agent_id
        FROM abandoned_calls 
        WHERE status IN ('abandoned', 'processing')
        ORDER BY call_time DESC";

if ($result = $mysqli->query($sql)) {
    while ($row = $result->fetch_assoc()) {
        // Convertimos la fecha a un timestamp UNIX para el JS
        $row['time'] = strtotime($row['call_time']);
        $calls[] = $row;
    }
    $result->free();
} else {
    // Es útil registrar el error si la consulta falla
    error_log("Error en get_abandoned.php: " . $mysqli->error);
    http_response_code(500);
    echo json_encode(['error' => 'sql query failed']);
    $mysqli->close();
    exit;
}

$mysqli->close();

header('Content-Type: application/json');
echo json_encode($calls);
?>