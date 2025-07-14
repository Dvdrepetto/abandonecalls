<?php
// recover.php - v4 Mejorado

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once __DIR__ . '/db_config_constants.php';

$response = [];

$mysqli = new mysqli(ISSABEL_DB_HOST, ISSABEL_DB_USER, ISSABEL_DB_PASS, ABANDONED_DB_NAME);
if ($mysqli->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'DB Connection failed']);
    exit;
}
$mysqli->set_charset('utf8mb4');

$uniqueid = isset($_GET['uniqueid']) ? trim($_GET['uniqueid']) : '';
$agent_id = isset($_GET['agent']) ? trim($_GET['agent']) : 'unknown';

if (empty($uniqueid)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing uniqueid']);
    $mysqli->close();
    exit;
}

$sql = "UPDATE abandoned_calls 
        SET status = 'processing', 
            agent_id = ?, 
            recovery_time = NOW() 
        WHERE uniqueid = ? AND status = 'abandoned'";

$stmt = $mysqli->prepare($sql);

if ($stmt) {
    $stmt->bind_param("ss", $agent_id, $uniqueid);
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            $response['status'] = 'OK';
        } else {
            $response['status'] = 'ALREADY_TAKEN';
        }
    } else {
        http_response_code(500);
        $response['error'] = 'Failed to execute statement';
    }
    $stmt->close();
} else {
    http_response_code(500);
    $response['error'] = 'Failed to prepare statement';
}

$mysqli->close();
echo json_encode($response);
exit;
?>