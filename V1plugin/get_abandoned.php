<?php
// get_abandoned.php

require_once __DIR__ . '/db_config_constants.php';
$mysqli = new mysqli(ISSABEL_DB_HOST, ISSABEL_DB_USER, ISSABEL_DB_PASS, ABANDONED_DB_NAME);
if ($mysqli->connect_error) { http_response_code(500); exit; }
$mysqli->set_charset('utf8mb4');

// Obtenemos parámetros
$filter = $_GET['filter'] ?? '';
$sort = $_GET['sort'] ?? 'call_time_desc'; // Valor por defecto

$calls = [];

// Construimos la consulta base
$sql = "SELECT * FROM abandoned_calls WHERE status IN ('abandoned', 'processing')";

// Lógica de filtro (sin cambios)
$params = [];
$types = '';
if (!empty($filter)) {
    $sql .= " AND (caller_id LIKE ? OR contact_name LIKE ? OR queue_human_name LIKE ?)";
    $like_filter = '%' . $filter . '%';
    $params[] = &$like_filter;
    $params[] = &$like_filter;
    $params[] = &$like_filter;
    $types .= 'sss';
}

// ===== NUEVA LÓGICA DE ORDENAMIENTO DINÁMICO =====
// Lista blanca de opciones de ordenamiento permitidas para evitar inyección SQL
$allowed_sorts = [
    'call_time_desc' => 'call_time DESC',
    'wait_time_desc' => 'CAST(wait_time AS UNSIGNED) DESC'
];

// Usamos la opción por defecto si el valor recibido no está en la lista blanca
$orderByClause = $allowed_sorts[$sort] ?? $allowed_sorts['call_time_desc'];

$sql .= " ORDER BY " . $orderByClause;
// =================================================

$stmt = $mysqli->prepare($sql);
if ($stmt) {
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