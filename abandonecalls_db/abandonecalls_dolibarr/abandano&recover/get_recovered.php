<?php
// API para obtener las últimas llamadas recuperadas
require_once __DIR__ . '/db_config_constants.php';

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, ABANDONED_DB_NAME); // Conectamos directamente a la BBDD del plugin
if ($mysqli->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Error de conexión']);
    exit;
}
$mysqli->set_charset('utf8mb4');

$calls = [];

// Seleccionamos las llamadas con estado 'recovered'
// Ordenamos por la fecha de recuperación para ver las más recientes primero
// Usamos DATE_FORMAT para que la fecha se vea bonita
$sql = "SELECT
            caller_id as `from`,
            contact_name,
            queue_human_name as queue,
            DATE_FORMAT(call_time, '%d/%m/%Y %H:%i:%s') as abandon_time,
            agent_id,
            DATE_FORMAT(recovery_time, '%d/%m/%Y %H:%i:%s') as recovery_time
        FROM abandoned_calls
        WHERE status = 'recovered'
        ORDER BY recovery_time DESC
        LIMIT 50"; // Limitamos a las últimas 50 para no sobrecargar

if ($result = $mysqli->query($sql)) {
    while ($row = $result->fetch_assoc()) {
        $calls[] = $row;
    }
    $result->free();
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Error en la consulta SQL: ' . $mysqli->error]);
    $mysqli->close();
    exit;
}

$mysqli->close();

header('Content-Type: application/json');
echo json_encode($calls);
?>