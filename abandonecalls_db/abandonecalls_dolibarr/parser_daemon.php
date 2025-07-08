#!/usr/bin/php
<?php
// Versión 5.5: Corregido el error "Cannot pass parameter by reference" en bind_param.

chdir(__DIR__);
require_once __DIR__ . '/db_config_constants.php';

echo "Iniciando demonio v5.5 (Corregido)...\n";

// --- CONFIGURACIÓN ---
$queue_log_file = '/var/log/asterisk/queue_log';
$tail_path = '/usr/bin/tail';
$queue_names_map = [ '1600' => 'Soporte', '1601' => 'Ventas', '933637624' => 'BMATIKA' ];
$dial_prefix_map = [ '1600' => '7353', '1601' => '8001', '933637624' => '7353' ];

// --- VARIABLES GLOBALES ---
$mysqli = null; $stmt_abandon = null; $stmt_delete_on_complete = null; $call_details = [];

// --- DEFINICIÓN DE FUNCIONES ---
function connect_db() { /* ...código sin cambios... */ global $mysqli; if ($mysqli !== null && $mysqli->ping()) { return true; } if ($mysqli !== null) $mysqli->close(); $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS); if ($mysqli->connect_error) { error_log("Fallo de conexión a BBDD: " . $mysqli->connect_error); $mysqli = null; return false; } $mysqli->set_charset('utf8mb4'); echo "Conexión a la BBDD establecida/verificada.\n"; return true; }
function prepare_statements() { /* ...código sin cambios... */ global $mysqli, $stmt_abandon, $stmt_delete_on_complete; $sql_insert = "INSERT IGNORE INTO `" . ABANDONED_DB_NAME . "`.abandoned_calls (uniqueid, call_time, queue_name, caller_id, queue_human_name, wait_time, abandon_position, dial_string, contact_name) VALUES (?, FROM_UNIXTIME(?), ?, ?, ?, ?, ?, ?, ?)"; $stmt_abandon = $mysqli->prepare($sql_insert); $sql_delete = "DELETE FROM `" . ABANDONED_DB_NAME . "`.abandoned_calls WHERE caller_id = ? AND status = 'abandoned'"; $stmt_delete_on_complete = $mysqli->prepare($sql_delete); if (!$stmt_abandon || !$stmt_delete_on_complete) { error_log("Fallo al preparar sentencias: " . $mysqli->error); return false; } echo "Sentencias preparadas con éxito.\n"; return true; }
function findContactInDolibarr($phoneNumber) { /* ...código sin cambios... */ global $mysqli; if (!$mysqli) return ''; $clean_phone = preg_replace('/[^0-9]/', '', $phoneNumber); $sql = "SELECT CONCAT(p.firstname, ' ', p.lastname) as fullname FROM `" . DOLIBARR_DB_NAME . "`.llx_socpeople as p WHERE p.phone = ? OR p.phone_perso = ? OR p.phone_mobile = ? OR p.fax = ? LIMIT 1"; $stmt = $mysqli->prepare($sql); if(!$stmt) { error_log("Error preparando búsqueda Dolibarr: " . $mysqli->error); return ''; } $stmt->bind_param("ssss", $clean_phone, $clean_phone, $clean_phone, $clean_phone); $stmt->execute(); $result = $stmt->get_result(); $row = $result->fetch_assoc(); $stmt->close(); return $row ? $row['fullname'] : ''; }

function process_line($line) {
    global $stmt_abandon, $stmt_delete_on_complete, $call_details, $queue_names_map, $dial_prefix_map;
    
    $parts = explode('|', trim($line));
    if (count($parts) < 5) return;
    $timestamp = $parts[0]; $uniqueid = $parts[1]; $queue_number = $parts[2]; $event = $parts[4];

    if ($event === 'ENTERQUEUE' && count($parts) > 6) {
        $call_details[$uniqueid] = ['from' => $parts[6]];
    }
    elseif ($event === 'ABANDON') {
        if (count($parts) < 7) return;
        $from_number = $call_details[$uniqueid]['from'] ?? 'Desconocido';
        if ($from_number === 'Desconocido') return;

        $contact_name = findContactInDolibarr($from_number);
        
        // ==========================================================
        // ¡¡¡ AQUÍ ESTÁ LA CORRECCIÓN !!!
        // Guardamos los resultados de las expresiones en variables.
        // ==========================================================
        $queue_human_name = $queue_names_map[$queue_number] ?? $queue_number;
        $abandon_position = $parts[5];
        $wait_time = $parts[6];
        $dial_string = ($dial_prefix_map[$queue_number] ?? '') . $from_number;
        
        // Ahora, bind_param solo recibe variables.
        $stmt_abandon->bind_param("sisssiiss", 
            $uniqueid, $timestamp, $queue_number, $from_number,
            $queue_human_name, $abandon_position, $wait_time, 
            $dial_string, $contact_name
        );

        if ($stmt_abandon->execute()) {
            echo "INSERT: $from_number (" . ($contact_name ?: 'Desconocido') . ") a BBDD.\n";
        } else {
            error_log("Error en INSERT: " . $stmt_abandon->error);
        }
        unset($call_details[$uniqueid]);
    }
    elseif ($event === 'COMPLETECALLER' || $event === 'COMPLETEAGENT') {
        /* ...código sin cambios... */
        $from = $call_details[$uniqueid]['from'] ?? 'Desconocido';
        if ($from !== 'Desconocido') {
            $stmt_delete_on_complete->bind_param("s", $from);
            if ($stmt_delete_on_complete->execute() && $stmt_delete_on_complete->affected_rows > 0) {
                echo "DELETE: Borradas abandonadas previas para $from.\n";
            }
        }
        unset($call_details[$uniqueid]);
    }
}

// --- BUCLE PRINCIPAL (sin cambios) ---
if (!connect_db() || !prepare_statements()) { die("Fallo de inicialización. Saliendo."); }
$handle = popen("{$tail_path} -f -n 0 " . escapeshellarg($queue_log_file) . " 2>&1", 'r');
if ($handle === false) { die("No se pudo ejecutar el comando tail."); }
echo "Comando tail ejecutado, handle abierto. Esperando datos...\n";
$last_ping = time();
while (true) { $read = [$handle]; $write = null; $except = null; $num_changed_streams = stream_select($read, $write, $except, 15); if (time() - $last_ping >= 30) { if (!connect_db()) { sleep(5); continue; } $last_ping = time(); } if ($num_changed_streams > 0) { $line = fgets($handle); if ($line !== false && !empty(trim($line))) { process_line(trim($line)); } } }
pclose($handle);
?>