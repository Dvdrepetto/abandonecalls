#!/usr/bin/php
<?php
// para ser ejecutado desde la línea de comandos (CLI) con un cron job.

// Cambiamos al directorio del script para que __DIR__ funcione correctamente
chdir(__DIR__);
require_once __DIR__ . '/db_config.php';

$queue_log = '/var/log/asterisk/queue_log';

if (!file_exists($queue_log) || !is_readable($queue_log)) {
    error_log("AbandonedCalls Parser: No se puede leer el archivo $queue_log");
    exit(1);
}

$fh = fopen($queue_log, 'r');
if (!$fh) {
    error_log("AbandonedCalls Parser: No se puede abrir el archivo $queue_log");
    exit(1);
}

$call_details = [];

// Primera pasada: buscar el número que llama (caller ID)
while (($line = fgets($fh)) !== false) {
    $parts = explode('|', trim($line));
    if (count($parts) > 6 && $parts[4] == 'ENTERQUEUE') {
        $uniqueid = $parts[1];
        $caller_number = $parts[6];
        $call_details[$uniqueid] = ['from' => $caller_number];
    }
}

// Usamos INSERT IGNORE para que si un uniqueid ya existe, simplemente se ignore y no cause un error.
$stmt = $mysqli->prepare("INSERT IGNORE INTO abandoned_calls (uniqueid, call_time, queue_name, caller_id) VALUES (?, FROM_UNIXTIME(?), ?, ?)");

// Segunda pasada: buscar las abandonadas e insertarlas en la BBDD
rewind($fh);
while (($line = fgets($fh)) !== false) {
    $parts = explode('|', trim($line));

    if (count($parts) > 4 && $parts[4] == 'ABANDON') {
        $timestamp = $parts[0];
        $uniqueid  = $parts[1];
        $queue     = $parts[2];
        $from      = $call_details[$uniqueid]['from'] ?? 'Desconocido';

        // Vinculamos los parámetros y ejecutamos la inserción
        $stmt->bind_param("siss", $uniqueid, $timestamp, $queue, $from);
        $stmt->execute();
    }
}

$stmt->close();
fclose($fh);
$mysqli->close();

echo "Procesamiento de llamadas abandonadas completado.\n";
?>