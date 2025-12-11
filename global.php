<?php
declare(strict_types=1);

require_once __DIR__ . '/file_cache.php';

function cache(): FileCache
{
    static $instance = null;

    if ($instance === null) {
        // Папка /cache в корне TBDev
        $cacheDir = ROOT_PATH . '/cache';
        $instance = new FileCache($cacheDir, 300);
    }

    return $instance;
}
