#!/usr/bin/php
<?php
// Versión 9.1: Lógica final con mapa de rutas y búsqueda por nombre.

chdir(__DIR__);
require_once __DIR__ . '/db_config_constants.php';
if (!defined('CDR_DB_NAME')) {
    define('CDR_DB_NAME', 'asteriskcdrdb');
}

echo "Iniciando demonio v9.1 (Mapa de Rutas)...\n";

// --- CONEXIONES Y VARIABLES GLOBALES ---
$mysqli_issabel = new mysqli(ISSABEL_DB_HOST, ISSABEL_DB_USER, ISSABEL_DB_PASS);
if ($mysqli_issabel->connect_error) { die("Error CRÍTICO: BBDD Issabel: " . $mysqli_issabel->connect_error); }
$mysqli_issabel->set_charset('utf8mb4');

$mysqli_dolibarr = new mysqli(DOLIBARR_DB_HOST, DOLIBARR_DB_USER, DOLIBARR_DB_PASS, DOLIBARR_DB_NAME);
if ($mysqli_dolibarr->connect_error) { 
    error_log("ADVERTENCIA: No se puede conectar a la BBDD de Dolibarr.");
    $mysqli_dolibarr = null; 
} else { 
    $mysqli_dolibarr->set_charset('utf8mb4'); 
}

$stmt_abandon = null; 
$stmt_delete_on_complete = null; 
$call_details = [];
$queue_log_file = '/var/log/asterisk/queue_log';

// --- FUNCIONES DE AYUDA ---

function getCdrInfo($uniqueid) {
    global $mysqli_issabel;
    if (empty($uniqueid)) return null;
    $cdr_query = "SELECT `src`, COALESCE(NULLIF(`did`, ''), `dst`) as effective_did FROM `" . CDR_DB_NAME . "`.`cdr` WHERE `uniqueid` = ? OR `linkedid` = ? ORDER BY `calldate` DESC LIMIT 1";
    $stmt = $mysqli_issabel->prepare($cdr_query);
    if(!$stmt) { error_log("Error preparando consulta CDR: " . $mysqli_issabel->error); return null; }
    $stmt->bind_param("ss", $uniqueid, $uniqueid);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row;
}

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

// --- FUNCIÓN PRINCIPAL DE PROCESAMIENTO (CORREGIDA) ---
function process_line($line) {
    global $stmt_abandon, $stmt_delete_on_complete, $call_details;
    
    // --- MAPA DE ASOCIACIÓN: Nombre de Ruta de Entrada -> Nombre de Ruta de Salida ---
    // Este es el "cerebro" configurable.
    $route_map = [
        'PruebaCliente_Inbound' => 'PruebaCliente_Inbound',
        // 'Ventas_Web' => 'Salida_Ventas', // Ejemplo de otra regla
    ];
    
    $parts = explode('|', trim($line));
    if (count($parts) < 5) return;
    $uniqueid = $parts[1]; $event = $parts[4];

    if ($event === 'ENTERQUEUE' && count($parts) > 6) { $call_details[$uniqueid]['from'] = $parts[6]; }
    elseif ($event === 'ABANDON') {
        if (count($parts) < 8) return;
        
        // Esperamos 1 segundo para asegurar que el CDR está escrito
        sleep(1);
        
        $cdr_data = getCdrInfo($uniqueid);
        if (!$cdr_data || empty($cdr_data['src'])) {
            error_log("[WARN] No se encontró CDR o 'src' válido para el uniqueid: $uniqueid");
            unset($call_details[$uniqueid]);
            return;
        }
	// Aca condicionamos si el llamante es menor de 6 digitos, lo ignora como abandonado
        $from_number = $cdr_data['src'];
        if (strlen($from_number) < 6) {
            echo "[INFO] Ignorando llamada interna desde: $from_number\n";
            unset($call_details[$uniqueid]);
            return;
        }

        // 1. Obtenemos el nombre de la Ruta de Entrada
        $inbound_did = $cdr_data['effective_did'] ?? '';
        $did_query = "SELECT `description` FROM `" . ASTERISK_DB_NAME . "`.`incoming` WHERE `extension` = ? LIMIT 1";
        $inbound_route_name = getDbInfo($did_query, $inbound_did, 'Directa');
        
        // 2. Usamos el MAPA para encontrar la Ruta de Salida
        $outbound_route_name = $route_map[$inbound_route_name] ?? null;

        // 3. Si encontramos una ruta de salida, buscamos su prefijo
        $prefix = '';
        if ($outbound_route_name) {
            $prefix_query = "SELECT p.match_pattern_prefix FROM `" . ASTERISK_DB_NAME . "`.`outbound_route_patterns` p JOIN `" . ASTERISK_DB_NAME . "`.`outbound_routes` r ON p.route_id = r.route_id WHERE r.name = ? LIMIT 1";
            $prefix = getDbInfo($prefix_query, $outbound_route_name, '');
        }
        
        $dial_string = $prefix . $from_number;
        
        // Recopilamos el resto de la información
        $timestamp = $parts[0];
        $queue_number = $parts[2];
        $channel_raw = $parts[3] ?? '';
        $abandon_position = (int)$parts[6];
        $wait_time = (int)$parts[7];
        $q_query = "SELECT `descr` FROM `" . ASTERISK_DB_NAME . "`.`queues_config` WHERE `extension` = ? LIMIT 1";
        $queue_human_name = getDbInfo($q_query, $queue_number);
        $trunk_name_raw = explode('-', $channel_raw)[0];
        $trunk_name = count(explode('/', $trunk_name_raw)) > 1 ? explode('/', $trunk_name_raw)[1] : $trunk_name_raw;
        $dolibarr_info = findContactInDolibarr($from_number);
        $contact_name = $dolibarr_info['name'];
        $company_name = $dolibarr_info['company'];
        
        // Insertamos en la base de datos
        if ($stmt_abandon instanceof mysqli_stmt) {
            $stmt_abandon->bind_param("sisssssiissi", $uniqueid, $timestamp, $queue_number, $from_number, $queue_human_name, $contact_name, $company_name, $wait_time, $abandon_position, $dial_string, $inbound_route_name, $trunk_name);
            if ($stmt_abandon->execute()) {
                echo "INSERT: $from_number a BBDD. [Ruta: $inbound_route_name, Prefijo: $prefix, Dial: $dial_string]\n";
            } else {
                error_log("Error en INSERT: " . $stmt_abandon->error);
            }
        } else {
            error_log("Error: stmt_abandon no es un objeto válido de mysqli_stmt.");
        }
        unset($call_details[$uniqueid]);
    }
    elseif ($event === 'COMPLETECALLER' || $event === 'COMPLETEAGENT') {
        unset($call_details[$uniqueid]);
    }
}

// --- BUCLE PRINCIPAL ---
if (!prepare_statements()) { die("Fallo de inicialización. Saliendo."); }
$handle = popen("/usr/bin/tail -f -n 0 " . escapeshellarg($queue_log_file) . " 2>&1", 'r');
if ($handle === false) { die("No se pudo ejecutar el comando tail."); }
while (true) {
    $read = [$handle]; $write = null; $except = null;
    $num_changed_streams = stream_select($read, $write, $except, 30);
    if ($mysqli_issabel && !$mysqli_issabel->ping()) { $mysqli_issabel->close(); $mysqli_issabel = new mysqli(ISSABEL_DB_HOST, ISSABEL_DB_USER, ISSABEL_DB_PASS); if(!$mysqli_issabel->connect_error) prepare_statements(); }
    if ($mysqli_dolibarr && !$mysqli_dolibarr->ping()) { $mysqli_dolibarr->close(); $mysqli_dolibarr = new mysqli(DOLIBARR_DB_HOST, DOLIBARR_DB_USER, DOLIBARR_DB_PASS, DOLIBARR_DB_NAME); }
    if ($num_changed_streams > 0) {
        $line = fgets($handle);
        if ($line !== false && !empty(trim($line))) { process_line(trim($line)); }
    }
}
?>