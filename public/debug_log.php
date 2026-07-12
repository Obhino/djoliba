<?php
$logFile = __DIR__ . '/../var/log/dev-2026-07-10.log';
if (file_exists($logFile)) {
    $lines = file($logFile);
    $lastLines = array_slice($lines, -150);
    echo implode("", $lastLines);
} else {
    echo "Log file not found: " . $logFile;
}
