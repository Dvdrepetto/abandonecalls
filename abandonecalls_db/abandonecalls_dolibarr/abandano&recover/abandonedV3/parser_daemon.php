#!/usr/bin/php
<?php
// Versión 7.0: Integración Profunda con BBDD de Issabel/Asterisk

// Cambiamos al directorio del script para que las rutas relativas funcionen
chdir(__DIR__);
// Cargamos las constantes de conexión (que ahora deben apuntar a la BBDD local de Issabel)
require_once __DIR__ . '/db_config_constants.php';

echo "Iniciando demonio v7.0 (Integración Issabel)...\n";

// --- Configuración ---
$queue_log_file = '/var/log/asterisk/queue_log';
$tail_path = '/usr/bin/tail';

// --- Variables Globales ---
$mysqli = null;
$stmt_abandon = null;
$stmt_delete_on_complete = null;
$call_details = []; // Array en memoria para guardar detalles entre eventos de una misma llamada

// ==================================================================
// --- Funciones de Ayuda ---
// ==================================================================

// Función para conectar/mantener viva la conexión a la BBDD
function connect_db() {
    global $mysqli;
    if ($mysqli !== null && $mysqli->ping()) {
        return true;
    }
    if ($mysqli !== null) $mysqli->close();
    
    $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS);
    if ($mysqli->connect_error) {
        error_log("Fallo de conexión a BBDD: " . $mysqli->connect_error);
        $mysqli = null;
        return false;
    }
    $mysqli->set_charset('utf8mb4');
    return true;
}

// Función para buscar contactos en Dolibarr (la mantenemos igual)
function findContactInDolibarr($phoneNumber) {
    global $mysqli;
    if (!$mysqli) return '';
    $clean_phone = preg_replace('/[^0-9]/', '', $phoneNumber);
    $sql = "SELECT CONCAT(p.firstname, ' ', p.lastname) as fullname FROM `" . DOLIBARR_DB_NAME . "`.llx_socpeople as p WHERE p.phone = ? OR p.phone_perso = ? OR p.phone_mobile = ? OR p.fax = ? LIMIT 1";
    $stmt = $mysqli->prepare($sql);
    if(!$stmt) return '';
    $stmt->bind_param("ssss", $clean_phone, $clean_phone, $clean_phone, $clean_phone);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row ? $row['fullname'] : '';
}

// --- NUEVA FUNCIÓN GENÉRICA PARA BUSCAR DATOS EN LA BBDD DE ASTERISK ---
function getDbInfo($query, $param, $default = null) {
    global $mysqli;
    if (!$mysqli || empty($param)) return $default ?? $param;
    
    $stmt = $mysqli->prepare($query);
    if(!$stmt) return $default ?? $param;
    
    $stmt->bind_param("s", $param);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    // Devuelve el primer campo del resultado, o el valor por defecto si no hay resultado
    return $row ? reset($row) : ($default ?? $param);
}

// Función para preparar las sentencias SQL una sola vez
function prepare_statements() {
    global $mysqli, $stmt_abandon, $stmt_delete_on_complete;
    
    // Sentencia INSERT con las nuevas columnas para inbound_route y trunk_name
    $sql_insert = "INSERT IGNORE INTO `" . ABANDONED_DB_NAME . "`.abandoned_calls 
                    (uniqueid, call_time, queue_name, caller_id, queue_human_name, contact_name, wait_time, abandon_position, dial_string, inbound_route, trunk_name) 
                   VALUES (?, FROM_UNIXTIME(?), ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt_abandon = $mysqli->prepare($sql_insert);

    $sql_delete = "DELETE FROM `" . ABANDONED_DB_NAME . "`.abandoned_calls WHERE caller_id = ? AND status = 'abandoned'";
    $stmt_delete_on_complete = $mysqli->prepare($sql_delete);

    if (!$stmt_abandon || !$stmt_delete_on_complete) {
        error_log("Fallo al preparar sentencias: " . $mysqli->error);
        return false;
    }
    echo "Sentencias preparadas con éxito.\n";
    return true;
}

// ==================================================================
// --- La Lógica Principal de Procesamiento ---
// ==================================================================

function process_line($line) {
    global $mysqli, $stmt_abandon, $stmt_delete_on_complete, $call_details;
    
    $parts = explode('|', trim($line));
    if (count($parts) < 5) return;
    $timestamp = $parts[0]; $uniqueid = $parts[1]; $queue_number = $parts[2];
    $channel_raw = $parts[3]; $event = $parts[4];

    // Capturamos la información de la llamada a medida que llega
    if ($event === 'DID') {
        $call_details[$uniqueid]['inbound_did'] = $parts[6] ?? '';
    } elseif ($event === 'ENTERQUEUE' && count($parts) > 6) {
        $call_details[$uniqueid]['from'] = $parts[6];
        $call_details[$uniqueid]['trunk_channel'] = $channel_raw;
    }
    elseif ($event === 'ABANDON') {
        if (count($parts) < 8) return;
        $from_number = $call_details[$uniqueid]['from'] ?? 'Desconocido';
        if ($from_number === 'Desconocido') return;

        // --- ENRIQUECIMIENTO DE DATOS DESDE LA BBDD DE ISSABEL ---
        
        // 1. Nombre de la Cola
        $q_query = "SELECT `descr` FROM `" . ASTERISK_DB_NAME . "`.`queues_config` WHERE `extension` = ? LIMIT 1";
        $queue_human_name = getDbInfo($q_query, $queue_number);

        // 2. Nombre de la Ruta de Entrada
        $inbound_did = $call_details[$uniqueid]['inbound_did'] ?? '';
        $did_query = "SELECT `description` FROM `" . ASTERISK_DB_NAME . "`.`incoming` WHERE `extension` = ? LIMIT 1";
        $inbound_route_name = getDbInfo($did_query, $inbound_did, 'Directa');

        // 3. Nombre del Troncal
        $trunk_channel = $call_details[$uniqueid]['trunk_channel'] ?? '';
        $trunk_name_raw = explode('-', $trunk_channel)[0];
        $trunk_name = count(explode('/', $trunk_name_raw)) > 1 ? explode('/', $trunk_name_raw)[1] : $trunk_name_raw;

        // 4. Prefijo y Número a Marcar (Lógica a mejorar según reglas de negocio)
        // Ejemplo: si la ruta de entrada es la de prueba, se usa un prefijo, si no, otro.
        $prefix = ($inbound_route_name === 'PRUEBA LÍNEA CLIENTE') ? '7624' : ''; 
        $dial_string = $prefix . $from_number;
        
        // --- FIN DEL ENRIQUECIMIENTO ---

        $abandon_position = (int)$parts[5];
        $wait_time = (int)$parts[7];
        $contact_name = findContactInDolibarr($from_number);
        
        // El bind_param ahora tiene 11 parámetros
        $stmt_abandon->bind_param("sisssssiiss",
            $uniqueid, $timestamp, $queue_number, $from_number,
            $queue_human_name, $contact_name,
            $wait_time, $abandon_position, 
            $dial_string, $inbound_route_name, $trunk_name
        );

        if ($stmt_abandon->execute()) {
            echo "INSERT: $from_number a BBDD. [Cola: $queue_human_name, Ruta: $inbound_route_name, Troncal: $trunk_name]\n";
        } else {
            error_log("Error en INSERT: " . $stmt_abandon->error);
        }
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

// ==================================================================
// --- Bucle Principal del Demonio ---
// ==================================================================

if (!connect_db() || !prepare_statements()) {
    die("Fallo de inicialización. Saliendo.");
}

$handle = popen("{$tail_path} -f -n 0 " . escapeshellarg($queue_log_file) . " 2>&1", 'r');
if ($handle === false) { die("No se pudo ejecutar el comando tail."); }

$last_ping = time();
while (true) {
    // Mantener la conexión a la BBDD viva
    if (time() - $last_ping >= 30) {
        if (!connect_db()) {
            sleep(10); continue;
        }
        $last_ping = time();
    }

    // Esperar por nuevas líneas en el log de forma eficiente
    $read = [$handle]; $write = null; $except = null;
    $num_changed_streams = stream_select($read, $write, $except, 15);

    if ($num_changed_streams > 0) {
        $line = fgets($handle);
        if ($line !== false && !empty(trim($line))) {
            process_line(trim($line));
        }
    }
}

pclose($handle);
?>a