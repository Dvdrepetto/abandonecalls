<?php
// Constantes v4: Arquitectura final y correcta

// --- Conexión 1: Servidor Local de Issabel (para Asterisk y nuestro plugin) ---
define('ISSABEL_DB_HOST', '127.0.0.1'); 
define('ISSABEL_DB_USER', 'root');
define('ISSABEL_DB_PASS', '030611563aA');

// Nombres de las bases de datos DENTRO del servidor Issabel
define('ASTERISK_DB_NAME', 'asterisk');
define('ABANDONED_DB_NAME', 'prueba1');
define('CDR_DB_NAME', 'asteriskcdrdb');

// --- Conexión 2: Servidor Remoto de aapanel (para Dolibarr) ---
define('DOLIBARR_DB_HOST', '65.21.57.122');
define('DOLIBARR_DB_USER', 'sql_dolibarr_cli');
define('DOLIBARR_DB_PASS', '8c53cfa96034d');
define('DOLIBARR_DB_NAME', 'sql_dolibarr_cli');
?>