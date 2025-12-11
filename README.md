# ⚡ File Cache — файловый кеш для PHP 8.1+

Лёгкий, стабильный и быстрый файловый кеш-движок для TBDev/Torrentside.  
Работает полностью *без Memcached, Redis и XCache*, используя:

- каталог `cache/` в корне проекта;
- атомарную запись файлов;
- PHP-файлы, кешируемые **OPcache**;
- безопасную сериализацию;
- удобный API.

Пример использования:

```php
cache()->remember('key', 300, fn() => compute());
```

---

## 🚀 Особенности

- Оптимизирован под PHP 8.1+
- Использует OPcache для ускоренного чтения кеш-файлов
- Не требует внешних сервисов
- Атомарная запись исключает повреждения кеша
- Поддерживает методы:
  - get()
  - set()
  - remember()
  - delete()
  - clear()
- Встроенный SQL-кешер: sql_query_cached()

---

## 📁 Структура проекта

```
/cache/                   ← кеш-файлы
/include/file_cache.php   ← класс FileCache
/include/cache_boot.php   ← глобальная функция cache()
/include/sql_cache.php    ← SQL-кеширование
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

```php
<?php
declare(strict_types=1);

final class FileCache
{
    private string $dir;
    private int $defaultTtl;

    public function __construct(string $dir, int $defaultTtl = 300)
    {
        $this->dir = rtrim($dir, DIRECTORY_SEPARATOR);

        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0775, true);
        }

        if (!is_writable($this->dir)) {
            throw new RuntimeException("Cache dir '{$this->dir}' is not writable");
        }

        $this->defaultTtl = $defaultTtl;
    }

    private function keyToPath(string $key): string
    {
        $short = preg_replace('~[^a-zA-Z0-9_\-]~', '_', substr($key, 0, 40));
        $hash  = sha1($key);

        return $this->dir . DIRECTORY_SEPARATOR . $short . '_' . $hash . '.php';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $path = $this->keyToPath($key);

        if (!is_file($path)) {
            return $default;
        }

        $data = @include $path;

        if (!is_array($data) || !isset($data['e'], $data['v'])) {
            @unlink($path);
            return $default;
        }

        if ($data['e'] !== 0 && $data['e'] < time()) {
            @unlink($path);
            return $default;
        }

        return @unserialize($data['v'], ['allowed_classes' => true]);
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        $path = $this->keyToPath($key);

        $ttl ??= $this->defaultTtl;
        $expiresAt = $ttl > 0 ? time() + $ttl : 0;

        $payload = [
            'e' => $expiresAt,
            'v' => serialize($value),
        ];

        $php = '<?php return ' . var_export($payload, true) . ';';

        $temp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

        file_put_contents($temp, $php, LOCK_EX);
        chmod($temp, 0664);
        rename($temp, $path);
    }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $cached = $this->get($key);

        if ($cached !== null) {
            return $cached;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    public function delete(string $key): void
    {
        $path = $this->keyToPath($key);
        if (is_file($path)) {
            unlink($path);
        }
    }

    public function clear(): void
    {
        foreach (glob($this->dir . '/*.php') as $file) {
            unlink($file);
        }
    }
}
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
        $instance = new FileCache(ROOT_PATH . '/cache', 300);
    }

    return $instance;
}
```

---

## 4. Файл: include/sql_cache.php

```php
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

---

# ⚙ Рекомендации по производительности

- Включить **OPcache**
- Кешировать:
  - главную страницу
  - блоки 
  - тяжёлые SELECT/JOIN
- Инвалидировать кеш при:
  - добавлении торрента
  - комментарии
  - обновлении новостей

---

# 📄 Лицензия

MIT — свободное использование.

---


