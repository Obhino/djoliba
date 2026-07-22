<?php

use App\Kernel;

$assetsDir = dirname(__DIR__) . '/assets/images';
$publicDir = __DIR__ . '/images';

if (!file_exists($assetsDir . '/logo-icon.png') || !file_exists($assetsDir . '/logo-full.png') || !file_exists($publicDir . '/logo-icon.png')) {
    $srcIcon = 'C:\\Users\\ulric\\.gemini\\antigravity-ide\\brain\\1527e36d-4ef6-4b55-ba0a-f122da0d9b67\\media__1784753606141.png';
    $srcFull = 'C:\\Users\\ulric\\.gemini\\antigravity-ide\\brain\\1527e36d-4ef6-4b55-ba0a-f122da0d9b67\\media__1784753679902.png';

    if (!is_dir($assetsDir)) { @mkdir($assetsDir, 0777, true); }
    if (!is_dir($publicDir)) { @mkdir($publicDir, 0777, true); }

    if (file_exists($srcIcon)) {
        @copy($srcIcon, $assetsDir . '/logo-icon.png');
        @copy($srcIcon, $publicDir . '/logo-icon.png');
    }
    if (file_exists($srcFull)) {
        @copy($srcFull, $assetsDir . '/logo-full.png');
        @copy($srcFull, $publicDir . '/logo-full.png');
    }
}

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
