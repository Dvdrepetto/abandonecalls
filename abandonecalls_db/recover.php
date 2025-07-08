<?php
require_once __DIR__ . '/db_config.php';

$uniqueid = $_GET['uniqueid'] ?? '';

if (empty($uniqueid)) {
    http_response_code(400);
    echo "Missing uniqueid";
    exit;
}

// Usamos sentencias preparadas para prevenir inyección SQL.
$stmt = $mysqli->prepare("UPDATE abandoned_calls SET status = 'recovered' WHERE uniqueid = ?");

if ($stmt) {
    $stmt->bind_param("s", $uniqueid);

    if ($stmt->execute()) {
        echo "OK";
    } else {
        http_response_code(500);
        echo "Error updating record";
    }

    $stmt->close();
} else {
    http_response_code(500);
    echo "Error preparing statement";
}

$mysqli->close();
?>