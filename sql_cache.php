<?php

function sql_query_cached(string $sql, array $params = [], int $ttl = 300): array
{
    $key = 'sql:' . $sql . '|' . json_encode($params, JSON_UNESCAPED_UNICODE);

    return cache()->remember($key, $ttl, function () use ($sql, $params) {
        global $pdo;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    });
}