<?php

$srcIcon = 'C:\\Users\\ulric\\.gemini\\antigravity-ide\\brain\\1527e36d-4ef6-4b55-ba0a-f122da0d9b67\\media__1784753606141.png';
$srcFull = 'C:\\Users\\ulric\\.gemini\\antigravity-ide\\brain\\1527e36d-4ef6-4b55-ba0a-f122da0d9b67\\media__1784753679902.png';

$destDir = __DIR__ . '/images';
if (!is_dir($destDir)) {
    mkdir($destDir, 0777, true);
}

$resIcon = copy($srcIcon, $destDir . '/logo-icon.png');
$resFull = copy($srcFull, $destDir . '/logo-full.png');

echo json_encode([
    'icon_copied' => $resIcon,
    'full_copied' => $resFull,
    'icon_size' => file_exists($destDir . '/logo-icon.png') ? filesize($destDir . '/logo-icon.png') : 0,
    'full_size' => file_exists($destDir . '/logo-full.png') ? filesize($destDir . '/logo-full.png') : 0,
]);
