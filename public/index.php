<?php

use App\Kernel;

if (!file_exists(__DIR__ . '/images/logo-icon.png') || !file_exists(__DIR__ . '/images/logo-full.png')) {
    $srcIcon = 'C:\\Users\\ulric\\.gemini\\antigravity-ide\\brain\\1527e36d-4ef6-4b55-ba0a-f122da0d9b67\\media__1784753606141.png';
    $srcFull = 'C:\\Users\\ulric\\.gemini\\antigravity-ide\\brain\\1527e36d-4ef6-4b55-ba0a-f122da0d9b67\\media__1784753679902.png';
    if (!is_dir(__DIR__ . '/images')) {
        @mkdir(__DIR__ . '/images', 0777, true);
    }
    if (file_exists($srcIcon)) { @copy($srcIcon, __DIR__ . '/images/logo-icon.png'); }
    if (file_exists($srcFull)) { @copy($srcFull, __DIR__ . '/images/logo-full.png'); }
}

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
