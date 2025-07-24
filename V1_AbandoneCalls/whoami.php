<?php
session_start();
header('Content-Type: application/json');

require_once(dirname(__FILE__).'/db_config_constants.php');

$response = ['agent' => 'unknown'];

// 1. Intentar primero con $_SESSION['FOP2']['extension']
if (isset($_SESSION['FOP2']) && is_array($_SESSION['FOP2']) && isset($_SESSION['FOP2']['extension']) && !empty($_SESSION['FOP2']['extension'])) {
    $response['agent'] = $_SESSION['FOP2']['extension'];
} else {
    // 2. Si no existe, intentar con la DB fop2users
    $mysqli = new mysqli(ISSABEL_DB_HOST, ISSABEL_DB_USER, ISSABEL_DB_PASS, ASTERISK_DB_NAME);
    if (!$mysqli->connect_error) {
        $mysqli->set_charset('utf8mb4');
        $sql = "SELECT exten FROM fop2users LIMIT 1";
        $result = $mysqli->query($sql);
        if ($result && $row = $result->fetch_assoc()) {
            $response['agent'] = $row['exten'];
        }
        $mysqli->close();
    }
}

// 3. Por último, devolver JSON
echo json_encode($response);
exit;
?>