<?php
declare(strict_types=1);

require_once __DIR__ . '/file_cache.php';

function cache(): FileCache
{
    static $instance = null;

    if ($instance === null) {
        $instance = new FileCache(ROOT_PATH . '/cache', 300);
    }

    return $instance;
}