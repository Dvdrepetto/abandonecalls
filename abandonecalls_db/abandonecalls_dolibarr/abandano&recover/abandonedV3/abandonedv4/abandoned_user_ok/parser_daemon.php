#!/usr/bin/php
<?php
// Versión 7.3: Simple y Directa con dos conexiones

chdir(__DIR__);
require_once __DIR__ . '/db_config_constants.php';

echo "Iniciando demonio v7.3 (Doble Conexión Directa)...\n";

// --- CONEXIONES A BASES DE DATOS ---
$mysqli_issabel = new mysqli(ISSABEL_DB_HOST, ISSABEL_DB_USER, ISSABEL_DB_PASS);
if ($mysqli_issabel->connect_error) {
    die("Error CRÍTICO: No se puede conectar a la BBDD de Issabel: " . $mysqli_issabel->connect_error);
}
$mysqli_issabel->set_charset('utf8mb4');

$mysqli_dolibarr = new mysqli(DOLIBARR_DB_HOST, DOLIBARR_DB_USER, DOLIBARR_DB_PASS, DOLIBARR_DB_NAME);
if ($mysqli_dolibarr->connect_error) {
    error_log("ADVERTENCIA: No se puede conectar a la BBDD de Dolibarr: " . $mysqli_dolibarr->connect_error);
    $mysqli_dolibarr = null;
} else {
    $mysqli_dolibarr->set_charset('utf8mb4');
}

// --- VARIABLES GLOBALES ---
$stmt_abandon = null; $stmt_delete_on_complete = null; $call_details = [];

$queue_log_file = '/var/log/asterisk/queue_log';

// --- FUNCIONES ---
function findContactInDolibarr($phoneNumber) {
    global $mysqli_dolibarr;
    $contact_data = ['name' => '', 'company' => ''];
    if (!$mysqli_dolibarr) return $contact_data;
    $clean_phone = preg_replace('/[^0-9]/', '', $phoneNumber);
    if (empty($clean_phone)) return $contact_data;
    $sql = "SELECT CONCAT(p.firstname, ' ', p.lastname) as fullname, s.nom as company_name FROM llx_socpeople as p LEFT JOIN llx_societe as s ON p.fk_soc = s.rowid WHERE p.phone = ? OR p.phone_perso = ? OR p.phone_mobile = ? OR p.fax = ? LIMIT 1";
    $stmt = $mysqli_dolibarr->prepare($sql);
    if(!$stmt) { error_log("Dolibarr Search Error: " . $mysqli_dolibarr->error); return $contact_data; }
    $stmt->bind_param("ssss", $clean_phone, $clean_phone, $clean_phone, $clean_phone);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $contact_data['name'] = $row['fullname'];
        $contact_data['company'] = $row['company_name'];
    }
    $stmt->close();
    return $contact_data;
}

function getDbInfo($query, $param, $default = null) {
    global $mysqli_issabel;
    if (empty($param)) return $default ?? $param;
    $stmt = $mysqli_issabel->prepare($query);
    if(!$stmt) return $default ?? $param;
    $stmt->bind_param("s", $param);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row ? reset($row) : ($default ?? $param);
}

function prepare_statements() {
    global $mysqli_issabel, $stmt_abandon, $stmt_delete_on_complete;
    $sql_insert = "INSERT IGNORE INTO `" . ABANDONED_DB_NAME . "`.abandoned_calls (uniqueid, call_time, queue_name, caller_id, queue_human_name, contact_name, company_name, wait_time, abandon_position, dial_string, inbound_route, trunk_name) VALUES (?, FROM_UNIXTIME(?), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt_abandon = $mysqli_issabel->prepare($sql_insert);
    $sql_delete = "DELETE FROM `" . ABANDONED_DB_NAME . "`.abandoned_calls WHERE caller_id = ? AND status = 'abandoned'";
    $stmt_delete_on_complete = $mysqli_issabel->prepare($sql_delete);
    if (!$stmt_abandon || !$stmt_delete_on_complete) {
        error_log("Fallo al preparar sentencias: " . $mysqli_issabel->error);
        return false;
    }
    return true;
}

function process_line($line) {
    global $stmt_abandon, $stmt_delete_on_complete, $call_details;
    $parts = explode('|', trim($line));
    if (count($parts) < 5) return;
    $timestamp = $parts[0]; $uniqueid = $parts[1]; $queue_number = $parts[2]; $channel_raw = $parts[3]; $event = $parts[4];
    if ($event === 'DID') { $call_details[$uniqueid]['inbound_did'] = $parts[6] ?? ''; }
    elseif ($event === 'ENTERQUEUE' && count($parts) > 6) { $call_details[$uniqueid]['from'] = $parts[6]; $call_details[$uniqueid]['trunk_channel'] = $channel_raw; }
    elseif ($event === 'ABANDON') {
        if (count($parts) < 8) return;
        $from_number = $call_details[$uniqueid]['from'] ?? 'Desconocido';
        if ($from_number === 'Desconocido') return;
        $q_query = "SELECT `descr` FROM `" . ASTERISK_DB_NAME . "`.`queues_config` WHERE `extension` = ? LIMIT 1";
        $queue_human_name = getDbInfo($q_query, $queue_number);
        $inbound_did = $call_details[$uniqueid]['inbound_did'] ?? '';
        $did_query = "SELECT `description` FROM `" . ASTERISK_DB_NAME . "`.`incoming` WHERE `extension` = ? LIMIT 1";
        $inbound_route_name = getDbInfo($did_query, $inbound_did, 'Directa');
        $trunk_channel = $call_details[$uniqueid]['trunk_channel'] ?? '';
        $trunk_name_raw = explode('-', $trunk_channel)[0];
        $trunk_name = count(explode('/', $trunk_name_raw)) > 1 ? explode('/', $trunk_name_raw)[1] : $trunk_name_raw;
        $prefix = ($inbound_route_name === 'PRUEBA LÍNEA CLIENTE') ? '7624' : ''; 
        $dial_string = $prefix . $from_number;
        $abandon_position = (int)$parts[5];
        $wait_time = (int)$parts[7];
        $dolibarr_info = findContactInDolibarr($from_number);
        $contact_name = $dolibarr_info['name'];
        $company_name = $dolibarr_info['company'];
        $stmt_abandon->bind_param("sisssssiissi", $uniqueid, $timestamp, $queue_number, $from_number, $queue_human_name, $contact_name, $company_name, $wait_time, $abandon_position, $dial_string, $inbound_route_name, $trunk_name);
        if ($stmt_abandon->execute()) {
            $log_contact = $contact_name . ($company_name ? " ($company_name)" : "");
            echo "INSERT: $from_number (" . ($log_contact ?: 'Desconocido') . ") a BBDD.\n";
        } else { error_log("Error en INSERT: " . $stmt_abandon->error); }
        unset($call_details[$uniqueid]);
    }
    elseif ($event === 'COMPLETECALLER' || $event === 'COMPLETEAGENT') {
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

// BUCLE PRINCIPAL
if (!prepare_statements()) { die("Fallo de inicialización. Saliendo."); }
$handle = popen("/usr/bin/tail -f -n 0 " . escapeshellarg($queue_log_file) . " 2>&1", 'r');
if ($handle === false) { die("No se pudo ejecutar el comando tail."); }

while (true) {
    $read = [$handle]; $write = null; $except = null;
    $num_changed_streams = stream_select($read, $write, $except, 30);
    if ($mysqli_issabel && !$mysqli_issabel->ping()) { $mysqli_issabel->close(); $mysqli_issabel = new mysqli(ISSABEL_DB_HOST, ISSABEL_DB_USER, ISSABEL_DB_PASS); }
    if ($mysqli_dolibarr && !$mysqli_dolibarr->ping()) { $mysqli_dolibarr->close(); $mysqli_dolibarr = new mysqli(DOLIBARR_DB_HOST, DOLIBARR_DB_USER, DOLIBARR_DB_PASS, DOLIBARR_DB_NAME); }

    if ($num_changed_streams > 0) {
        $line = fgets($handle);
        if ($line !== false && !empty(trim($line))) {
            process_line(trim($line));
        }
    }
}
?>