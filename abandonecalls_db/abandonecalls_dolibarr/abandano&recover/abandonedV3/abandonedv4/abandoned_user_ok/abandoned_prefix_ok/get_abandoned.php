<?php
// get_abandoned.php - con lógica de filtro

require_once __DIR__ . '/db_config_constants.php';
$mysqli = new mysqli(ISSABEL_DB_HOST, ISSABEL_DB_USER, ISSABEL_DB_PASS, ABANDONED_DB_NAME);
if ($mysqli->connect_error) { http_response_code(500); exit; }
$mysqli->set_charset('utf8mb4');

// Obtenemos el parámetro de filtro
$filter = $_GET['filter'] ?? '';

$calls = [];

// Construimos la consulta base
$sql = "SELECT * FROM abandoned_calls WHERE status IN ('abandoned', 'processing')";

$params = [];
$types = '';

// Si hay un filtro, añadimos condiciones WHERE
if (!empty($filter)) {
    $sql .= " AND (caller_id LIKE ? OR contact_name LIKE ? OR queue_human_name LIKE ?)";
    $like_filter = '%' . $filter . '%';
    $params[] = &$like_filter;
    $params[] = &$like_filter;
    $params[] = &$like_filter;
    $types .= 'sss';
}

$sql .= " ORDER BY call_time DESC";

$stmt = $mysqli->prepare($sql);
if ($stmt) {
    // Si hay parámetros, los vinculamos
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['time'] = strtotime($row['call_time']);
        $row['from'] = $row['caller_id'];
        $row['queue'] = $row['queue_human_name'];
        $calls[] = $row;
    }
    $stmt->close();
}

$mysqli->close();
header('Content-Type: application/json');
echo json_encode($calls);
?>