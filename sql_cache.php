<?php
declare(strict_types=1);

/**
 * Нормализуем SQL, чтобы ключи были стабильнее (лишние пробелы/переводы строк).
 */
function sql_cache_normalize(string $sql): string
{
    $sql = trim($sql);
    $sql = preg_replace('~\s+~u', ' ', $sql) ?? $sql;
    return $sql;
}

/**
 * Стабильная нормализация параметров (для ключа).
 * @param array<int|string, mixed> $params
 * @return array<int|string, mixed>
 */
function sql_cache_normalize_params(array $params): array
{
    // Сортируем только ассоциативные (чтобы json был стабильнее)
    $isAssoc = array_keys($params) !== range(0, count($params) - 1);
    if ($isAssoc) {
        ksort($params);
    }
    return $params;
}

/**
 * Кеш SELECT-запросов.
 * Возвращает массив строк (PDO::FETCH_ASSOC).
 *
 * Пример:
 *  $rows = sql_query_cached("SELECT * FROM users WHERE id = :id", ['id' => 5], 120);
 */
function sql_query_cached(string $sql, array $params = [], int $ttl = 300): array
{
    if ($ttl <= 0) {
        // кеш выключен
        global $pdo;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $sqlN = sql_cache_normalize($sql);
    $paramsN = sql_cache_normalize_params($params);

    // Можно вручную “сбросить” SQL-кеш увеличением версии
    $sqlCacheVersion = defined('SQL_CACHE_VERSION') ? (string)SQL_CACHE_VERSION : 'v1';

    $keyMaterial = $sqlCacheVersion . "\0" . $sqlN . "\0" . json_encode($paramsN, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $key = 'sql:' . hash('sha256', $keyMaterial);

    return cache()->remember($key, $ttl, function () use ($sql, $params) {
        global $pdo;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    });
}

/**
 * Удобный вариант: одна строка (или null).
 */
function sql_row_cached(string $sql, array $params = [], int $ttl = 300): ?array
{
    $rows = sql_query_cached($sql, $params, $ttl);
    return $rows[0] ?? null;
}

/**
 * Кеш скалярного значения (COUNT(*), SUM(), etc.).
 */
function sql_scalar_cached(string $sql, array $params = [], int $ttl = 300): mixed
{
    $row = sql_row_cached($sql, $params, $ttl);
    if ($row === null) {
        return null;
    }
    // берём первое поле
    foreach ($row as $v) {
        return $v;
    }
    return null;
}

/**
 * Кеширование картинки/файла в cache/files/...
 * $source может быть локальным путём или URL.
 *
 * Пример:
 *  $localPath = cache()->rememberFile("avatar:{$uid}", 3600, $url, 'jpg');
 */
