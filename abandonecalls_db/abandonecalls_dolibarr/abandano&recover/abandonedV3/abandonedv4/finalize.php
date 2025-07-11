[DR3p3tt0@centralita abandonedcalls]$ cat finalize.php 
<?php
// finalize.php - Versión 2, ahora con guardado de notas

require_once __DIR__ . '/db_config_constants.php';

$mysqli = new mysqli(ISSABEL_DB_HOST, ISSABEL_DB_USER, ISSABEL_DB_PASS, ABANDONED_DB_NAME);
if ($mysqli->connect_error) { 
    http_response_code(500); 
    exit; 
}
$mysqli->set_charset('utf8mb4');

// Ahora esperamos dos parámetros: uniqueid y notes
$uniqueid = $_GET['uniqueid'] ?? '';
$notes = $_GET['notes'] ?? ''; // Capturamos la nota

if (empty($uniqueid)) {
    http_response_code(400);
    exit("Missing uniqueid");
}

// La consulta UPDATE ahora incluye el campo 'notes'
$sql = "UPDATE abandoned_calls 
        SET status = 'recovered', 
            notes = ? 
        WHERE uniqueid = ? AND status = 'processing'";

$stmt = $mysqli->prepare($sql);

if ($stmt) {
    // Tenemos dos parámetros string: la nota y el uniqueid
    $stmt->bind_param("ss", $notes, $uniqueid);
    
    if ($stmt->execute()) {
        echo "OK";
    } else {
        http_response_code(500);
    }
    $stmt->close();
} else {
    http_response_code(500);
}

$mysqli->close();
?>