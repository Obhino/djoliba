<?php

$srcIcon = 'C:\\Users\\ulric\\.gemini\\antigravity-ide\\brain\\1527e36d-4ef6-4b55-ba0a-f122da0d9b67\\media__1784753606141.png';
$srcFull = 'C:\\Users\\ulric\\.gemini\\antigravity-ide\\brain\\1527e36d-4ef6-4b55-ba0a-f122da0d9b67\\media__1784753679902.png';

$assetsDir = dirname(__DIR__) . '/assets/images';
$publicDir = __DIR__ . '/images';

if (!is_dir($assetsDir)) { mkdir($assetsDir, 0777, true); }
if (!is_dir($publicDir)) { mkdir($publicDir, 0777, true); }

$resIcon1 = copy($srcIcon, $assetsDir . '/logo-icon.png');
$resFull1 = copy($srcFull, $assetsDir . '/logo-full.png');

$resIcon2 = copy($srcIcon, $publicDir . '/logo-icon.png');
$resFull2 = copy($srcFull, $publicDir . '/logo-full.png');

echo json_encode([
    'assets_icon' => $resIcon1,
    'assets_full' => $resFull1,
    'public_icon' => $resIcon2,
    'public_full' => $resFull2,
]);
