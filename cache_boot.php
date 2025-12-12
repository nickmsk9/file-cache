<?php
declare(strict_types=1);

require_once __DIR__ . '/file_cache.php';

/**
 * Единая точка доступа к кешу.
 * ROOT_PATH должен быть определён в проекте.
 */
function cache(): FileCache
{
    static $instance = null;

    if ($instance === null) {
        $cacheDir = rtrim(ROOT_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'cache';

        $instance = new FileCache(
            dir: $cacheDir,
            defaultTtl: 300,
            options: [
                // Соль делает ключи уникальнее для конкретного проекта/инстанса
                'salt' => defined('CACHE_SALT') ? (string)CACHE_SALT : 'tbdev:file-cache:v1',

                // Включает разбиение по подпапкам (чтобы не было 100k файлов в одном месте)
                'shard_depth' => 2, // 2 => /aa/bb/...

                // Если значение большое — кладём в отдельный .bin, а в .php только метаданные
                'max_inline_bytes' => 262144, // 256 KB

                // Компрессия больших значений (если доступно)
                'compress_threshold' => 8192, // 8 KB

                // Безопасность: по умолчанию запрещаем классы при unserialize
                'allowed_classes' => false,

                // Рандомный GC при set()
                'gc_probability' => 0.01, // 1%
            ]
        );
    }

    return $instance;
}
