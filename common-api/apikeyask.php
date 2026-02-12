<?php

function logToFile($message, $logFile = 'sbis_token_log.txt') {
    $logDir = __DIR__ . '/../logs'; // ../ — если файл в подкаталоге
    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }

    $logPath = $logDir . '/' . $logFile;
    $logEntry = "[" . date('Y-m-d H:i:s') . "] " . $message . PHP_EOL;
    file_put_contents($logPath, $logEntry, FILE_APPEND);
}


