<?php
// get_history.php - API para obtener el historial de un número de teléfono

require_once __DIR__ . '/db_config_constants.php';

// Conectamos a la BBDD local de Issabel
$mysqli = new mysqli(ISSABEL_DB_HOST, ISSABEL_DB_USER, ISSABEL_DB_PASS, ABANDONED_DB_NAME);
if ($mysqli->connect_error) { 
    http_response_code(500);
    exit; 
}
$mysqli->set_charset('utf8mb4');

// Obtenemos el caller_id que nos envía el JavaScript
$caller_id = $_GET['caller_id'] ?? '';

if (empty($caller_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'Falta el caller_id']);
    exit;
}

$history = [];

// --- CONSULTA SQL PARA EL HISTORIAL ---
// Seleccionamos todas las interacciones de un caller_id de los últimos 7 días
// Ordenamos por fecha para ver la historia en orden cronológico
$sql = "SELECT 
            status,
            agent_id,
            notes,
            DATE_FORMAT(call_time, '%d/%m %H:%i') as event_time,
            DATE_FORMAT(recovery_time, '%d/%m %H:%i') as recovery_time_f
        FROM abandoned_calls 
        WHERE caller_id = ? 
        AND call_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY call_time DESC";

$stmt = $mysqli->prepare($sql);
if ($stmt) {
    $stmt->bind_param("s", $caller_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $history[] = $row;
    }
    $stmt->close();
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Error en la consulta SQL']);
    $mysqli->close();
    exit;
}

$mysqli->close();

header('Content-Type: application/json');
echo json_encode($history);
?>