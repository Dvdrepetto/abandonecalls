<?php
// recover.php - VERSIÓN ESTABLE Y FUNCIONAL

header('Content-Type: text/plain');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once __DIR__ . '/db_config_constants.php';

$mysqli = new mysqli(ISSABEL_DB_HOST, ISSABEL_DB_USER, ISSABEL_DB_PASS, ABANDONED_DB_NAME);
if ($mysqli->connect_error) {
    http_response_code(500);
    exit('DB Connection failed');
}
$mysqli->set_charset('utf8mb4');

$uniqueid = isset($_GET['uniqueid']) ? trim($_GET['uniqueid']) : '';
$agent_id = isset($_GET['agent']) ? trim($_GET['agent']) : 'unknown';

if (empty($uniqueid)) {
    http_response_code(400);
    exit('Missing uniqueid');
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
            echo 'OK';
        } else {
            $check_stmt = $mysqli->prepare("SELECT status FROM abandoned_calls WHERE uniqueid = ?");
            $check_stmt->bind_param("s", $uniqueid);
            $check_stmt->execute();
            $result = $check_stmt->get_result()->fetch_assoc();
            if ($result && $result['status'] === 'processing') {
                echo 'ALREADY_TAKEN';
            } else {
                echo 'NOT_FOUND_OR_NOT_ABANDONED';
            }
            $check_stmt->close();
        }
    } else {
        http_response_code(500);
        exit('Failed to execute statement');
    }
    $stmt->close();
} else {
    http_response_code(500);
    exit('Failed to prepare statement');
}
$mysqli->close();
exit;
?>