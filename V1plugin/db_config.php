<?php
require_once 'db_config_constants.php';

// Conexión Issabel (local)
$issabelConn = new mysqli(ISSABEL_DB_HOST, ISSABEL_DB_USER, ISSABEL_DB_PASS, ASTERISK_DB_NAME);
if ($issabelConn->connect_error) {
    die("Error conexión Issabel: " . $issabelConn->connect_error);
}
$issabelConn->set_charset('utf8mb4');

// Conexión Dolibarr (remota)
$dolibarrConn = new mysqli(DOLIBARR_DB_HOST, DOLIBARR_DB_USER, DOLIBARR_DB_PASS, DOLIBARR_DB_NAME);
if ($dolibarrConn->connect_error) {
    die("Error conexión Dolibarr: " . $dolibarrConn->connect_error);
}
$dolibarrConn->set_charset('utf8mb4');