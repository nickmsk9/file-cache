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

    /**
     * Нормализация ключа + хэш, чтобы не было проблем с именами файлов.
     */
    private function keyToPath(string $key): string
    {
        // чуть-чуть человекочитаемости + защита от мусора
        $short = preg_replace('~[^a-zA-Z0-9_\-]~', '_', substr($key, 0, 40));
        $hash  = sha1($key);

        return $this->dir . DIRECTORY_SEPARATOR . $short . '_' . $hash . '.php';
    }

    /**
     * Получить значение из кеша.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $path = $this->keyToPath($key);

        if (!is_file($path)) {
            return $default;
        }

        // include даёт нам опкод-кеш (opcache), что быстрее обычного чтения/парсинга
        /** @var array{e:int, v:string}|false $data */
        $data = @include $path;

        if (!is_array($data) || !array_key_exists('e', $data) || !array_key_exists('v', $data)) {
            // битый файл — удаляем
            @unlink($path);
            return $default;
        }

        $expiresAt = (int)$data['e'];

        if ($expiresAt !== 0 && $expiresAt < time()) {
            @unlink($path);
            return $default;
        }

        return @unserialize($data['v'], ['allowed_classes' => true]);
    }

    /**
     * Записать значение в кеш.
     */
    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        $path = $this->keyToPath($key);

        $ttl       ??= $this->defaultTtl;
        $expiresAt = $ttl > 0 ? (time() + $ttl) : 0;

        $payload = [
            'e' => $expiresAt,
            'v' => serialize($value),
        ];

        // Генерируем PHP-файл, который возвращает массив — отлично дружит с opcache
        $php = '<?php return ' . var_export($payload, true) . ';';

        $tempPath = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

        // atomic write: сначала во временный файл, потом rename
        file_put_contents($tempPath, $php, LOCK_EX);
        @chmod($tempPath, 0664);
        rename($tempPath, $path);
    }

    /**
     * Получить значение из кеша или вычислить и сохранить.
     */
    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $cached = $this->get($key, null);

        if ($cached !== null) {
            return $cached;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    /**
     * Удалить один ключ.
     */
    public function delete(string $key): void
    {
        $path = $this->keyToPath($key);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Очистка всего кеша (осторожно).
     */
    public function clear(): void
    {
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*.php') as $file) {
            @unlink($file);
        }
    }
}
