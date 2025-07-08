<?php

// Ruta al log de colas
$queue_log = '/var/log/asterisk/queue_log';
// Ruta al archivo que guarda los IDs de llamadas ya vistas
$recovered_file = __DIR__ . '/recovered.txt';

// Carga los IDs de las llamadas ya recuperadas en un array para búsqueda rápida
$recovered_ids = [];
if (file_exists($recovered_file)) {
    $recovered_lines = file($recovered_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($recovered_lines) {
        $recovered_ids = array_flip($recovered_lines);
    }
}

$calls = [];
if (file_exists($queue_log) && is_readable($queue_log)) {
    $fh = fopen($queue_log, 'r');
    if ($fh) {
        $call_details = [];

        // Primera pasada: buscar el número que llama
        rewind($fh);
        while (($line = fgets($fh)) !== false) {
            $parts = explode('|', trim($line));
            if (count($parts) > 6 && $parts[4] == 'ENTERQUEUE') {
                $uniqueid = $parts[1];
                $caller_number = $parts[6];
                $call_details[$uniqueid] = ['from' => $caller_number];
            }
        }

        // Segunda pasada: buscar las abandonadas y juntar los datos
        rewind($fh);
        while (($line = fgets($fh)) !== false) {
            $parts = explode('|', trim($line));

            // Una llamada abandonada tiene el evento 'ABANDON'
            if (count($parts) > 4 && $parts[4] == 'ABANDON') {
                $timestamp = $parts[0];
                $uniqueid  = $parts[1]; // El UniqueID está en la segunda columna (índice 1)
                $queue     = $parts[2];

                // Si el uniqueid NO está en la lista de recuperados
                if (!isset($recovered_ids[$uniqueid])) {
                    $calls[] = [
                        'time'     => date('Y-m-d H:i:s', $timestamp),
                        'queue'    => $queue,
                        'uniqueid' => $uniqueid,
                        // Añadimos el número que llama si lo encontramos en la primera pasada
                        'from'     => isset($call_details[$uniqueid]['from']) ? $call_details[$uniqueid]['from'] : 'Desconocido'
                    ];
                }
            }
        }
        fclose($fh);
    }
}

// Devuelve los datos como JSON
header('Content-Type: application/json');
echo json_encode(array_reverse($calls));
?>