<?php
// get_recovered.php - Versión final

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
// Seleccionamos los campos necesarios para la tabla de "Gestionadas"
$sql = "SELECT 
            caller_id as `from`, 
            contact_name, 
            queue_human_name as queue, 
            agent_id,
            DATE_FORMAT(recovery_time, '%d/%m/%Y %H:%i:%s') as recovery_time,
            dial_string
        FROM abandoned_calls 
        WHERE status = 'recovered' 
        ORDER BY recovery_time DESC 
        LIMIT 50";

if ($result = $mysqli->query($sql)) {
    while ($row = $result->fetch_assoc()) {
        $calls[] = $row;
    }
    $result->free();
} else {
    error_log("Error en get_recovered.php: " . $mysqli->error);
    http_response_code(500);
    echo json_encode(['error' => 'sql query failed']);
    $mysqli->close();
    exit;
}

$mysqli->close();

header('Content-Type: application/json');
echo json_encode($calls);
?>
