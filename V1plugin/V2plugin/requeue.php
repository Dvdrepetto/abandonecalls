<?php
// requeue.php - Devuelve una llamada en proceso a la cola de abandonadas.

require_once __DIR__ . '/db_config_constants.php';

// Verificamos que se ha enviado el uniqueid
if (empty($_POST['uniqueid'])) {
    http_response_code(400);
    echo "Error: uniqueid es requerido.";
    exit;
}

$uniqueid = $_POST['uniqueid'];
$mysqli = new mysqli(ISSABEL_DB_HOST, ISSABEL_DB_USER, ISSABEL_DB_PASS, ABANDONED_DB_NAME);
if ($mysqli->connect_error) {
    http_response_code(500);
    exit;
}
$mysqli->set_charset('utf8mb4');

// Creamos la nota que se va a añadir.
// es-ES para formato español, Europe/Madrid para la zona horaria.
date_default_timezone_set('Europe/Madrid');
$new_note = "Intento de llamada sin respuesta el " . date('d/m/Y \a \l\a\s H:i');

// Preparamos la consulta SQL para actualizar la llamada.
// La clave aquí es CONCAT para AÑADIR la nueva nota, no para sobreescribir las antiguas.
$sql = "UPDATE abandoned_calls 
        SET 
            status = 'abandoned', 
            agent_id = NULL,
            notes = CONCAT(IFNULL(notes, ''), ?)
        WHERE 
            uniqueid = ? AND status = 'processing'";
            
// Usamos CONCAT para añadir la nota. IFNULL maneja el caso en que `notes` sea NULL.
// El separador '\n' añade un salto de línea entre notas.
$note_with_separator = $new_note . "\n";

$stmt = $mysqli->prepare($sql);
if ($stmt) {
    // Vinculamos los parámetros: la nota nueva y el uniqueid.
    $stmt->bind_param('ss', $note_with_separator, $uniqueid);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        echo "OK";
    } else {
        echo "NOT_FOUND_OR_NOT_PROCESSING";
    }
    $stmt->close();
} else {
    http_response_code(500);
    echo "Error al preparar la consulta.";
}

$mysqli->close();
?>