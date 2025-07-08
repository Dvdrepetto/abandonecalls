<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/db_config.php';

$calls = [];

// Preparamos la consulta para obtener las llamadas no recuperadas, ordenadas por la más reciente primero.
$sql = "SELECT uniqueid, call_time, queue_name, caller_id FROM abandoned_calls WHERE status = 'abandoned' ORDER BY call_time DESC";

if ($result = $mysqli->query($sql)) {
    while ($row = $result->fetch_assoc()) {
        $calls[] = [
            // El JS espera un timestamp UNIX. La BBDD nos da 'YYYY-MM-DD HH:MM:SS'. Lo convertimos.
            'time'     => strtotime($row['call_time']), 
            'queue'    => $row['queue_name'],
            'uniqueid' => $row['uniqueid'],
            'from'     => $row['caller_id']
        ];
    }
    $result->free();
}

$mysqli->close();

header('Content-Type: application/json');
echo json_encode($calls);
?>