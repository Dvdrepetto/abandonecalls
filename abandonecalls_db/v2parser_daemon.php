#!/usr/bin/php
<?php
// Versión 4.2: Demonio con mapeo de contactos y manejo de errores robusto

chdir(__DIR__);
require_once __DIR__ . '/db_config.php';

$queue_log_file = '/var/log/asterisk/queue_log';
echo "Iniciando demonio v4.2 (mapeo y errores robustos). Monitoreando: $queue_log_file\n";
echo "Presiona Ctrl+C para detener.\n";

// ==================================================================
// --- CONFIGURACIÓN DE MAPEOS ---
// ==================================================================
$queue_names_map = [
    '1600' => 'Soporte Técnico', '1601' => 'Ventas', '933637624' => 'BMATIKA'
];
$dial_prefix_map = [
    '1600' => '7353', '1601' => '8001', '933637624' => '7353'
];
$contacts_map = [
    '654013526' => 'David García',
    // Rellena tus contactos aquí
];
// ==================================================================

// --- MODIFICACIÓN CLAVE: Mover la preparación de sentencias FUERA de la función ---
// --- y verificar el éxito antes de empezar. ---
$sql_insert = "INSERT IGNORE INTO abandoned_calls 
            (uniqueid, call_time, queue_name, caller_id, queue_human_name, 
                wait_time, abandon_position, dial_string, contact_name) 
            VALUES (?, FROM_UNIXTIME(?), ?, ?, ?, ?, ?, ?, ?)";
$stmt_abandon = $mysqli->prepare($sql_insert);
if ($stmt_abandon === false) {
    die("Error CRÍTICO preparando la sentencia INSERT: " . $mysqli->error . "\n");
}

$sql_delete = "DELETE FROM abandoned_calls WHERE caller_id = ? AND status = 'abandoned'";
$stmt_delete_on_complete = $mysqli->prepare($sql_delete);
if ($stmt_delete_on_complete === false) {
    die("Error CRÍTICO preparando la sentencia DELETE: " . $mysqli->error . "\n");
}
// ------------------------------------------------------------------------------------

function process_line($line, $mysqli, $queue_names_map, $dial_prefix_map, $contacts_map) {
    global $stmt_abandon, $stmt_delete_on_complete, $call_details; // Usar variables globales

    $parts = explode('|', trim($line));
    if (count($parts) < 5) return;

    $timestamp = $parts[0];
    $uniqueid = $parts[1];
    $queue_number = $parts[2];
    $event = $parts[4];

    if ($event === 'ENTERQUEUE' && count($parts) > 6) {
        $caller_number = $parts[6];
        $call_details[$uniqueid] = ['from' => $caller_number];
    }
    elseif ($event === 'ABANDON') {
        if (count($parts) < 7) return;

        $from_number = $call_details[$uniqueid]['from'] ?? 'Desconocido';
        $abandon_position = $parts[5];
        $wait_time = $parts[6];
        
        $queue_human_name = $queue_names_map[$queue_number] ?? $queue_number;
        $dial_prefix = $dial_prefix_map[$queue_number] ?? '';
        $dial_string = $dial_prefix . $from_number;
        $contact_name = $contacts_map[$from_number] ?? '';

        $stmt_abandon->bind_param("sisssiiss", 
            $uniqueid, $timestamp, $queue_number, $from_number,
            $queue_human_name, $wait_time, $abandon_position, $dial_string, $contact_name
        );
        
        if (!$stmt_abandon->execute()) {
            error_log("AbandonedDaemon: Error al insertar $uniqueid: " . $stmt_abandon->error);
        }
        
        unset($call_details[$uniqueid]);
    }
    elseif ($event === 'COMPLETECALLER' || $event === 'COMPLETEAGENT') {
        $from = $call_details[$uniqueid]['from'] ?? 'Desconocido';
        
        if ($from !== 'Desconocido') {
            $stmt_delete_on_complete->bind_param("s", $from);
            if (!$stmt_delete_on_complete->execute()) {
                error_log("AbandonedDaemon: Error al borrar para $from: " . $stmt_delete_on_complete->error);
            }
        }
        unset($call_details[$uniqueid]);
    }
}

// Inicializar el array de detalles de llamada
$call_details = [];

// Bucle principal del demonio
$handle = popen("tail -f -n 0 " . escapeshellarg($queue_log_file) . " 2>&1", 'r');
if ($handle === false) { die("No se pudo ejecutar el comando tail."); }

while (!feof($handle)) {
    $line = fgets($handle);
    if ($line) {
        process_line(trim($line), $mysqli, $queue_names_map, $dial_prefix_map, $contacts_map);
    }
}
pclose($handle);
$mysqli->close();