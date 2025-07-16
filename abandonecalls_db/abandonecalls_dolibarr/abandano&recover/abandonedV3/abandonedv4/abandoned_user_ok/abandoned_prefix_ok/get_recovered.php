<?php
// get_recovered.php - vFinal con todos los datos necesarios

require_once __DIR__ . '/db_config_constants.php';

// Conectamos a la BBDD local de Issabel
$mysqli = new mysqli(ISSABEL_DB_HOST, ISSABEL_DB_USER, ISSABEL_DB_PASS, ABANDONED_DB_NAME);
if ($mysqli->connect_error) { 
    http_response_code(500);
    exit; 
}
$mysqli->set_charset('utf8mb4');

$calls = [];

// --- CONSULTA ACTUALIZADA ---
// Ahora seleccionamos todos los campos que la tabla de gestionadas necesita.
$sql = "SELECT
            uniqueid,
            caller_id,
            contact_name,
            company_name,
            queue_human_name, 
            agent_id,
            notes,
            DATE_FORMAT(recovery_time, '%d/%m/%Y %H:%i:%s') as recovery_time_formatted,
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
}

$mysqli->close();

header('Content-Type: application/json');
echo json_encode($calls);
?>