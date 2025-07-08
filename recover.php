<?php
// plugins/abandonedcalls/recover.php

$uniqueid = $_GET['uniqueid'] ?? '';
if ($uniqueid) {
    $recovered_file = __DIR__ . '/recovered.txt';
    file_put_contents($recovered_file, $uniqueid . "\n", FILE_APPEND);
    echo "OK";
} else {
    http_response_code(400);
    echo "Missing uniqueid";
}
?>
