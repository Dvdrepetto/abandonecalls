<?php
// Versión corregida para trabajar con la configuración de múltiples BBDD

// Usamos el nuevo archivo de configuración que solo define constantes
require_once __DIR__ . '/db_config_constants.php';

// Establecemos la conexión de la misma forma que en el demonio
$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS);

// Comprobar la conexión
if ($mysqli->connect_error) {
    http_response_code(500);
    // Es bueno devolver un JSON con el error para que JS pueda interpretarlo si es necesario
    echo json_encode(['error' => 'Error de conexión a la BBDD: ' . $mysqli->connect_error]);
    exit;
}
$mysqli->set_charset('utf8mb4');


$calls = [];

// --- MODIFICACIÓN CLAVE ---
// Añadimos el nombre de la base de datos a la consulta
$sql = "SELECT uniqueid, call_time, queue_human_name as queue, caller_id as `from`, contact_name 
        FROM `" . ABANDONED_DB_NAME . "`.abandoned_calls 
        WHERE status = 'abandoned' 
        ORDER BY call_time DESC";

if ($result = $mysqli->query($sql)) {
    while ($row = $result->fetch_assoc()) {
        // La columna 'from' ahora la renombramos a 'caller_id' en la consulta
        // para evitar conflictos con la palabra reservada 'from'.
        // Pero nuestro JS espera un campo 'from'.
        // Además, ahora también tenemos 'contact_name'.
        $display_name = !empty($row['contact_name']) ? $row['contact_name'] : $row['from'];

        $calls[] = [
            'time'     => strtotime($row['call_time']), 
            'queue'    => $row['queue'],
            'uniqueid' => $row['uniqueid'],
            'from'     => $display_name // JS usará 'from' para mostrar el nombre o el número
        ];
    }
    $result->free();
} else {
    // Si la consulta falla, devolvemos un error
    http_response_code(500);
    echo json_encode(['error' => 'Error en la consulta SQL: ' . $mysqli->error]);
    $mysqli->close();
    exit;
}

$mysqli->close();

header('Content-Type: application/json');
echo json_encode($calls);
?>