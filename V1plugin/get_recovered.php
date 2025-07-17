<?php
// get_recovered.php - con lógica de filtro

require_once __DIR__ . '/db_config_constants.php';
$mysqli = new mysqli(ISSABEL_DB_HOST, ISSABEL_DB_USER, ISSABEL_DB_PASS, ABANDONED_DB_NAME);
if ($mysqli->connect_error) { http_response_code(500); exit; }
$mysqli->set_charset('utf8mb4');

$filter = $_GET['filter'] ?? '';
$calls = [];

$sql = "SELECT *, caller_id as `from`, queue_human_name as queue, DATE_FORMAT(recovery_time, '%d/%m %H:%i:%s') as recovery_time_formatted FROM abandoned_calls WHERE status = 'recovered'";
$params = [];
$types = '';

if (!empty($filter)) {
    // Aquí podemos filtrar también por agente
    $sql .= " AND (caller_id LIKE ? OR contact_name LIKE ? OR queue_human_name LIKE ? OR agent_id LIKE ?)";
    $like_filter = '%' . $filter . '%';
    $params[] = &$like_filter;
    $params[] = &$like_filter;
    $params[] = &$like_filter;
    $params[] = &$like_filter;
    $types .= 'ssss';
}

$sql .= " ORDER BY recovery_time DESC LIMIT 50";

$stmt = $mysqli->prepare($sql);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $calls[] = $row;
    }
    $stmt->close();
}

$mysqli->close();
header('Content-Type: application/json');
echo json_encode($calls);
?>