<?php
// whoami.php - versión sin cookies, lee el primer agente disponible de fop2users

require_once(dirname(__FILE__).'/db_config_constants.php');

$response = ['agent' => 'unknown'];

$mysqli = new mysqli(ISSABEL_DB_HOST, ISSABEL_DB_USER, ISSABEL_DB_PASS, ASTERISK_DB_NAME);

if (!$mysqli->connect_error) {
    $mysqli->set_charset('utf8mb4');

    // Buscamos el primer usuario (exten) que haya en fop2users
    $sql = "SELECT exten FROM fop2users LIMIT 1";
    $result = $mysqli->query($sql);

    if ($result && $row = $result->fetch_assoc()) {
        $response['agent'] = $row['exten'];
    }

    $mysqli->close();
}

// Devolvemos en JSON
header('Content-Type: application/json');
echo json_encode($response);
exit;
?>