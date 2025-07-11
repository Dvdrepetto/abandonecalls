<?php
session_start();
header('Content-Type: application/json');

$response = ['agent' => 'unknown'];

if (isset($_SESSION['FOP2']) && is_array($_SESSION['FOP2']) && isset($_SESSION['FOP2']['extension'])) {
    $response['agent'] = $_SESSION['FOP2']['extension'];
}

echo json_encode($response);
exit;
?>

<?php
session_start();
header('Content-Type: application/json');

$response = [
    'agent' => 'unknown',
    'session' => $_SESSION,
    'phpsessid' => session_id(),
    'cookies' => $_COOKIE
];

if (isset($_SESSION['FOP2']['extension'])) {
    $response['agent'] = $_SESSION['FOP2']['extension'];
}

echo json_encode($response);
exit;
?>