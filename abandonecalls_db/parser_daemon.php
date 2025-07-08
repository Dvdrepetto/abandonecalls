#!/usr/bin/php
<?php

chdir(__DIR__);
require_once __DIR__ . '/db_config.php';

$queue_log_file = '/var/log/asterisk/queue_log';
echo "Iniciando demonio v2 (con borrado inteligente). Monitoreando: $queue_log_file\n";
echo "Presiona Ctrl+C para detener.\n";

function process_line($line, $mysqli) {
    static $call_details = [];
    static $stmt_abandon = null;
    static $stmt_delete_on_complete = null;

    // Preparamos las sentencias UNA SOLA VEZ
    if ($stmt_abandon === null) {
        $stmt_abandon = $mysqli->prepare("INSERT IGNORE INTO abandoned_calls (uniqueid, call_time, queue_name, caller_id) VALUES (?, FROM_UNIXTIME(?), ?, ?)");
        $stmt_delete_on_complete = $mysqli->prepare("DELETE FROM abandoned_calls WHERE caller_id = ? AND status = 'abandoned'");
        if (!$stmt_abandon || !$stmt_delete_on_complete) {
            error_log("AbandonedDaemon: Error preparando sentencias: " . $mysqli->error);
            return;
        }
    }

    $parts = explode('|', trim($line));
    if (count($parts) < 5) return;

    $timestamp = $parts[0];
    $uniqueid = $parts[1];
    $queue = $parts[2];
    $event = $parts[4];

    // Guardamos el Caller ID cuando una llamada entra en la cola
    if ($event === 'ENTERQUEUE' && count($parts) > 6) {
        $caller_number = $parts[6];
        $call_details[$uniqueid] = ['from' => $caller_number];
        echo "ENTRA: $caller_number en cola $queue ($uniqueid)\n";
    }
    // Si la llamada es abandonada, la insertamos
    elseif ($event === 'ABANDON') {
        $from = $call_details[$uniqueid]['from'] ?? 'Desconocido';

        $stmt_abandon->bind_param("siss", $uniqueid, $timestamp, $queue, $from);
        if ($stmt_abandon->execute()) {
            echo "ABANDONADA: $from en cola $queue ($uniqueid)\n";
        }
        unset($call_details[$uniqueid]);
    }
    // SI LA LLAMADA ES ATENDIDA (eventos COMPLETECALLER o COMPLETEAGENT)
    elseif ($event === 'COMPLETECALLER' || $event === 'COMPLETEAGENT') {
        $from = $call_details[$uniqueid]['from'] ?? 'Desconocido';
        if ($from !== 'Desconocido') {
            // Ejecutamos el borrado de todas las abandonadas anteriores de este número
            $stmt_delete_on_complete->bind_param("s", $from);
            if ($stmt_delete_on_complete->execute()) {
                $affected_rows = $stmt_delete_on_complete->affected_rows;
                if ($affected_rows > 0) {
                    echo "ATENDIDA: $from. Borrando $affected_rows abandonada(s) previa(s) de este número.\n";
                } else {
                    echo "ATENDIDA: $from. No había abandonadas previas que borrar.\n";
                }
            }
        }
        unset($call_details[$uniqueid]);
    }
}

// ... (El resto del código con popen, while, etc. es el mismo) ...
$handle = popen("tail -f -n 0 " . escapeshellarg($queue_log_file) . " 2>&1", 'r');
if ($handle === false) {
    die("No se pudo ejecutar el comando tail. ¿Está instalado? ¿Hay permisos?");
}
while (!feof($handle)) {
    $line = fgets($handle);
    if ($line) {
        process_line(trim($line), $mysqli);
    }
}
pclose($handle);
$mysqli->close();