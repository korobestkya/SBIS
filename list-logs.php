<?php
include 'auth.php';

$logDir = __DIR__ . '/logs';
$files = [];

if (is_dir($logDir)) {
    foreach (scandir($logDir) as $file) {
        if (is_file("$logDir/$file") && pathinfo($file, PATHINFO_EXTENSION) === 'txt') {
            $files[] = $file;
        }
    }
}

header('Content-Type: application/json');
echo json_encode([
    'files' => $files,
    'debugPath' => $logDir
]);
