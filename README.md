# ⚡ File Cache — файловый кеш для PHP 8.1+

**File Cache** — быстрый и надёжный кеш-движок для **TBDev**, который работает **без Memcached, Redis и XCache** и хранит всё прямо в файловой системе проекта.

Он создан для реальных нагрузок трекера: **кеширование SQL-запросов**, тяжёлых вычислений, блоков страниц, а также **файлов/картинок** (в `cache/`), с защитой от stampede (одновременных пересчётов) и поддержкой **OPcache**.

---

## ✅ Что он использует

- каталог `cache/` в корне проекта;
- **атомарную запись** (tmp → rename) без битых кеш-файлов;
- **PHP-файлы метаданных**, которые отлично кешируются **OPcache**;
- хранение **крупных значений** в `.bin` (не раздувает `.php`);
- опциональную компрессию больших данных;
- **lock-файлы** для защиты от множественных одновременных пересчётов;
- удобный API (get/set/remember/delete/clear) + SQL helper.

---

## Быстрый пример

```php
$result = cache()->remember('top:torrents', 120, fn() => get_top_torrents());
```

Пример использования:

```php
cache()->remember('key', 300, fn() => compute());
```

---

## 🚀 Особенности

- Оптимизирован под PHP 8.1+
- Быстрое чтение через OPcache (метаданные в PHP-return файлах)
- Не требует внешних сервисов
- Sharding: разбиение кеша по подпапкам (не создаёт свалку из 100k файлов в одной директории)
- Anti-stampede: per-key lock + double-check внутри remember()
- Поддержка больших значений: .php (meta) + .bin (payload)
- Опциональная компрессия больших значений
- Встроенная очистка протухших записей (GC)

Кеширование SQL:
- sql_query_cached()
- sql_row_cached()
- sql_scalar_cached()

Кеширование файлов/картинок:
- rememberFile() (локальный файл или URL)
- getFilePath()

---

## 📁 Структура проекта

```
/cache/                     ← кеш (данные + files)
/include/file_cache.php     ← движок FileCache
/include/cache_boot.php     ← глобальная функция cache()
/include/sql_cache.php      ← SQL-хелперы
```

---

# 📦 Установка

## 1. Создать каталог кеша

```bash
mkdir cache
chmod 775 cache
```

---

## 2. Файл: include/file_cache.php

```Вставь актуальную версию движка FileCache (из текущей реализации проекта).
Важно: файл создаёт подпапки внутри cache/ автоматически.
```

---

## 3. Файл: include/cache_boot.php

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/file_cache.php';

function cache(): FileCache
{
    static $instance = null;

    if ($instance === null) {
        $instance = new FileCache(
            dir: ROOT_PATH . '/cache',
            defaultTtl: 300,
            options: [
                'salt' => 'tbdev:file-cache:v1',
                'shard_depth' => 2,
                'max_inline_bytes' => 262144,
                'compress_threshold' => 8192,
                'allowed_classes' => false,
                'gc_probability' => 0.01,
            ]
        );
    }

    return $instance;
}

```

---

## 4. Файл: include/sql_cache.php

```php
<?php
declare(strict_types=1);

function sql_cache_normalize(string $sql): string
{
    $sql = trim($sql);
    $sql = preg_replace('~\s+~u', ' ', $sql) ?? $sql;
    return $sql;
}

function sql_cache_normalize_params(array $params): array
{
    $isAssoc = array_keys($params) !== range(0, count($params) - 1);
    if ($isAssoc) {
        ksort($params);
    }
    return $params;
}

function sql_query_cached(string $sql, array $params = [], int $ttl = 300): array
{
    if ($ttl <= 0) {
        global $pdo;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $sqlN = sql_cache_normalize($sql);
    $paramsN = sql_cache_normalize_params($params);

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

function sql_row_cached(string $sql, array $params = [], int $ttl = 300): ?array
{
    $rows = sql_query_cached($sql, $params, $ttl);
    return $rows[0] ?? null;
}

function sql_scalar_cached(string $sql, array $params = [], int $ttl = 300): mixed
{
    $row = sql_row_cached($sql, $params, $ttl);
    if ($row === null) {
        return null;
    }
    foreach ($row as $v) {
        return $v;
    }
    return null;
}

```

---

# 🧠 Использование

## Простое сохранение/получение

```php
cache()->set('hello', 'world', 600);
echo cache()->get('hello');
```

## Ленивый кеш

```php
$top = cache()->remember('top_torrents', 120, function () {
    return get_top_torrents();
});
```

## SQL-кеширование

```php
$rows = sql_query_cached(
    'SELECT * FROM torrents ORDER BY added DESC LIMIT 50',
    [],
    60
);

```
## Кеширование файла/картинки

```php
// локальный файл или URL
$path = cache()->rememberFile(
    key: 'avatar:user:15',
    ttl: 3600,
    source: 'https://example.com/avatar.jpg',
    ext: 'jpg'
);

// дальше можно отдавать файл из $path через nginx/php

```
---

# ⚙ Рекомендации по производительности

- Включить OPcache (и убедиться, что opcache.enable=1)

Кешировать в первую очередь:
- главную страницу (блоки)
- топы/списки/каталоги
- тяжёлые SELECT/JOIN и агрегаты

Инвалидировать кеш логически:
- при добавлении торрента (сброс “топов/списков”)
- при комментариях (сброс страницы торрента/комментов)
- при обновлении новостей (сброс новостного блока)
  
Для глобального сброса SQL-кеша можно поднять:
```
define('SQL_CACHE_VERSION', 'v2');
```
---

# 📄 Лицензия

MIT — свободное использование.

---


