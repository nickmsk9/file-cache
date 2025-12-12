<?php
declare(strict_types=1);

final class FileCache
{
    private string $dir;
    private int $defaultTtl;

    /** @var array<string, mixed> */
    private array $opt;

    public function __construct(string $dir, int $defaultTtl = 300, array $options = [])
    {
        $this->dir = rtrim($dir, DIRECTORY_SEPARATOR);
        $this->defaultTtl = $defaultTtl;

        $this->opt = [
            'salt' => 'file-cache',
            'shard_depth' => 2,               // 0..3
            'max_inline_bytes' => 262144,      // 256 KB
            'compress_threshold' => 8192,      // 8 KB
            'allowed_classes' => false,        // unserialize security
            'gc_probability' => 0.0,           // 0..1
            'file_subdir' => 'files',          // where cached files are stored
            'connect_timeout' => 5,            // for URL downloads
            'read_timeout' => 20,              // for URL downloads
            'user_agent' => 'TBDev-FileCache/1.0',
        ];

        foreach ($options as $k => $v) {
            $this->opt[$k] = $v;
        }

        if (!is_dir($this->dir)) {
            $this->mkdirp($this->dir, 0775);
        }

        if (!is_writable($this->dir)) {
            throw new RuntimeException("Cache dir '{$this->dir}' is not writable");
        }
    }

    /* ------------------------------ Public API ------------------------------ */

    public function get(string $key, mixed $default = null): mixed
    {
        $paths = $this->pathsForKey($key);

        if (!is_file($paths['meta'])) {
            return $default;
        }

        /** @var array{e:int, i:int, c:int, s:string, v?:string, p?:string}|false $meta */
        $meta = @include $paths['meta'];

        if (!is_array($meta) || !isset($meta['e'], $meta['i'], $meta['c'], $meta['s'])) {
            $this->safeUnlink($paths['meta']);
            $this->safeUnlink($paths['bin']);
            return $default;
        }

        $expiresAt = (int)$meta['e'];
        if ($expiresAt !== 0 && $expiresAt < time()) {
            $this->delete($key);
            return $default;
        }

        $blob = '';
        if ((int)$meta['i'] === 1) {
            // inline base64 in meta
            $b64 = (string)($meta['v'] ?? '');
            if ($b64 === '') {
                $this->delete($key);
                return $default;
            }
            $decoded = base64_decode($b64, true);
            if ($decoded === false) {
                $this->delete($key);
                return $default;
            }
            $blob = $decoded;
        } else {
            // external binary file
            if (!is_file($paths['bin'])) {
                $this->delete($key);
                return $default;
            }
            $data = @file_get_contents($paths['bin']);
            if ($data === false) {
                return $default;
            }
            $blob = $data;
        }

        if ((int)$meta['c'] === 1) {
            $inflated = $this->inflate($blob);
            if ($inflated === null) {
                $this->delete($key);
                return $default;
            }
            $blob = $inflated;
        }

        return $this->unserializeValue($blob);
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        $ttl ??= $this->defaultTtl;
        $expiresAt = $ttl > 0 ? (time() + $ttl) : 0;

        $paths = $this->pathsForKey($key);
        $this->mkdirp($paths['dir'], 0775);

        $raw = $this->serializeValue($value);

        $compressed = 0;
        if (strlen($raw) >= (int)$this->opt['compress_threshold']) {
            $deflated = $this->deflate($raw);
            if ($deflated !== null && strlen($deflated) < strlen($raw)) {
                $raw = $deflated;
                $compressed = 1;
            }
        }

        $inline = 1;
        $meta = [
            'e' => $expiresAt,
            'i' => 1,                 // inline by default
            'c' => $compressed,        // compressed?
            's' => $this->serializerName(),
        ];

        if (strlen($raw) > (int)$this->opt['max_inline_bytes']) {
            // store large value in .bin
            $inline = 0;
            $meta['i'] = 0;

            $this->atomicWrite($paths['bin'], $raw, 0664);
        } else {
            $meta['v'] = base64_encode($raw);
            // if previously had .bin, remove it
            $this->safeUnlink($paths['bin']);
        }

        $this->atomicWritePhpReturn($paths['meta'], $meta, 0664);

        // occasional GC
        $p = (float)$this->opt['gc_probability'];
        if ($p > 0.0 && mt_rand() / mt_getrandmax() < $p) {
            $this->gc(500);
        }
    }

    /**
     * Anti-stampede remember(): лочит ключ, второй раз проверяет кеш, затем вычисляет.
     */
    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $cached = $this->get($key, null);
        if ($cached !== null) {
            return $cached;
        }

        $paths = $this->pathsForKey($key);
        $this->mkdirp($paths['dir'], 0775);

        $fp = @fopen($paths['lock'], 'c');
        if ($fp === false) {
            // lock не смогли — просто считаем (хуже, но работает)
            $value = $callback();
            $this->set($key, $value, $ttl);
            return $value;
        }

        try {
            // EX lock
            if (!flock($fp, LOCK_EX)) {
                $value = $callback();
                $this->set($key, $value, $ttl);
                return $value;
            }

            // double-check after lock
            $cached2 = $this->get($key, null);
            if ($cached2 !== null) {
                return $cached2;
            }

            $value = $callback();
            $this->set($key, $value, $ttl);
            return $value;
        } finally {
            @flock($fp, LOCK_UN);
            @fclose($fp);
        }
    }

    public function delete(string $key): void
    {
        $paths = $this->pathsForKey($key);

        $this->safeUnlink($paths['meta']);
        $this->safeUnlink($paths['bin']);
        $this->safeUnlink($paths['lock']);
    }

    /**
     * Полная очистка кеша (рекурсивно).
     */
    public function clear(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        /** @var SplFileInfo $f */
        foreach ($it as $f) {
            $path = $f->getPathname();
            if ($f->isFile()) {
                $this->safeUnlink($path);
            } else {
                @rmdir($path);
            }
        }
    }

    /**
     * Лимитированный GC: удаляет протухшие meta/bin (до $limit файлов за раз).
     */
    public function gc(int $limit = 500): int
    {
        if (!is_dir($this->dir)) {
            return 0;
        }

        $now = time();
        $deleted = 0;

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        /** @var SplFileInfo $f */
        foreach ($it as $f) {
            if ($deleted >= $limit) {
                break;
            }

            if (!$f->isFile()) {
                continue;
            }

            $name = $f->getFilename();
            if (!str_ends_with($name, '.php')) {
                continue;
            }

            $metaPath = $f->getPathname();

            /** @var array{e:int, i:int}|false $meta */
            $meta = @include $metaPath;
            if (!is_array($meta) || !isset($meta['e'], $meta['i'])) {
                $this->safeUnlink($metaPath);
                $deleted++;
                continue;
            }

            $expiresAt = (int)$meta['e'];
            if ($expiresAt !== 0 && $expiresAt < $now) {
                $this->safeUnlink($metaPath);
                $binPath = substr($metaPath, 0, -4) . '.bin';
                $lockPath = substr($metaPath, 0, -4) . '.lock';
                $this->safeUnlink($binPath);
                $this->safeUnlink($lockPath);
                $deleted++;
            }
        }

        return $deleted;
    }

    /* -------------------------- Cached files/images -------------------------- */

    /**
     * Возвращает путь к закешированному файлу (если валиден), иначе null.
     */
    public function getFilePath(string $key): ?string
    {
        $paths = $this->filePathsForKey($key);

        if (!is_file($paths['meta'])) {
            return null;
        }

        /** @var array{e:int, p:string}|false $meta */
        $meta = @include $paths['meta'];
        if (!is_array($meta) || !isset($meta['e'], $meta['p'])) {
            $this->safeUnlink($paths['meta']);
            return null;
        }

        $expiresAt = (int)$meta['e'];
        if ($expiresAt !== 0 && $expiresAt < time()) {
            $this->safeUnlink($paths['meta']);
            $this->safeUnlink($paths['file']);
            return null;
        }

        $file = (string)$meta['p'];
        if (!is_file($file)) {
            $this->safeUnlink($paths['meta']);
            return null;
        }

        return $file;
    }

    /**
     * Кеширует файл (локальный путь или URL) и возвращает путь до кеш-файла.
     * Умеет предотвращать stampede через lock.
     */
    public function rememberFile(string $key, int $ttl, string $source, ?string $ext = null): string
    {
        $hit = $this->getFilePath($key);
        if ($hit !== null) {
            return $hit;
        }

        $paths = $this->filePathsForKey($key, $ext);
        $this->mkdirp($paths['dir'], 0775);

        $fp = @fopen($paths['lock'], 'c');
        if ($fp !== false) {
            try {
                @flock($fp, LOCK_EX);

                $hit2 = $this->getFilePath($key);
                if ($hit2 !== null) {
                    return $hit2;
                }

                $this->fetchToFile($source, $paths['file']);
                $expiresAt = $ttl > 0 ? (time() + $ttl) : 0;

                $meta = [
                    'e' => $expiresAt,
                    'p' => $paths['file'],
                ];

                $this->atomicWritePhpReturn($paths['meta'], $meta, 0664);
                return $paths['file'];
            } finally {
                @flock($fp, LOCK_UN);
                @fclose($fp);
            }
        }

        // fallback without lock
        $this->fetchToFile($source, $paths['file']);
        $expiresAt = $ttl > 0 ? (time() + $ttl) : 0;
        $meta = ['e' => $expiresAt, 'p' => $paths['file']];
        $this->atomicWritePhpReturn($paths['meta'], $meta, 0664);

        return $paths['file'];
    }

    /* ------------------------------ Internals ------------------------------ */

    /** @return array{dir:string, meta:string, bin:string, lock:string} */
    private function pathsForKey(string $key): array
    {
        $hash = hash('sha256', (string)$this->opt['salt'] . "\0" . $key);

        $dir = $this->dir;
        $depth = max(0, min(3, (int)$this->opt['shard_depth']));
        if ($depth >= 1) $dir .= DIRECTORY_SEPARATOR . substr($hash, 0, 2);
        if ($depth >= 2) $dir .= DIRECTORY_SEPARATOR . substr($hash, 2, 2);
        if ($depth >= 3) $dir .= DIRECTORY_SEPARATOR . substr($hash, 4, 2);

        $base = $dir . DIRECTORY_SEPARATOR . $hash;

        return [
            'dir'  => $dir,
            'meta' => $base . '.php',
            'bin'  => $base . '.bin',
            'lock' => $base . '.lock',
        ];
    }

    /** @return array{dir:string, meta:string, file:string, lock:string} */
    private function filePathsForKey(string $key, ?string $ext = null): array
    {
        $hash = hash('sha256', (string)$this->opt['salt'] . "\0file\0" . $key);

        $dir = $this->dir . DIRECTORY_SEPARATOR . (string)$this->opt['file_subdir'];
        $depth = max(0, min(3, (int)$this->opt['shard_depth']));
        if ($depth >= 1) $dir .= DIRECTORY_SEPARATOR . substr($hash, 0, 2);
        if ($depth >= 2) $dir .= DIRECTORY_SEPARATOR . substr($hash, 2, 2);
        if ($depth >= 3) $dir .= DIRECTORY_SEPARATOR . substr($hash, 4, 2);

        $ext = $ext ? ltrim($ext, '.') : 'bin';
        $base = $dir . DIRECTORY_SEPARATOR . $hash;

        return [
            'dir'  => $dir,
            'meta' => $base . '.meta.php',
            'file' => $base . '.' . $ext,
            'lock' => $base . '.lock',
        ];
    }

    private function serializeValue(mixed $value): string
    {
        // igbinary быстрее/компактнее (если расширение установлено)
        if (function_exists('igbinary_serialize')) {
            /** @var string $s */
            $s = igbinary_serialize($value);
            return $s;
        }
        return serialize($value);
    }

    private function unserializeValue(string $blob): mixed
    {
        $allowed = $this->opt['allowed_classes'];

        if (function_exists('igbinary_unserialize')) {
            // igbinary не поддерживает allowed_classes, но обычно это безопасно для массивов/скаляров
            return @igbinary_unserialize($blob);
        }

        return @unserialize($blob, ['allowed_classes' => $allowed]);
    }

    private function serializerName(): string
    {
        return function_exists('igbinary_serialize') ? 'igbinary' : 'php';
    }

    private function deflate(string $data): ?string
    {
        if (!function_exists('gzdeflate')) {
            return null;
        }
        $out = @gzdeflate($data, 6);
        return is_string($out) ? $out : null;
    }

    private function inflate(string $data): ?string
    {
        if (!function_exists('gzinflate')) {
            return null;
        }
        $out = @gzinflate($data);
        return is_string($out) ? $out : null;
    }

    private function mkdirp(string $dir, int $mode): void
    {
        if (is_dir($dir)) {
            return;
        }
        // suppress warnings in race conditions
        @mkdir($dir, $mode, true);
        if (!is_dir($dir)) {
            throw new RuntimeException("Cannot create cache dir '{$dir}'");
        }
    }

    private function atomicWrite(string $path, string $data, int $chmod): void
    {
        $dir = dirname($path);
        $this->mkdirp($dir, 0775);

        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $ok = @file_put_contents($tmp, $data, LOCK_EX);
        if ($ok === false) {
            $this->safeUnlink($tmp);
            throw new RuntimeException("Cannot write cache file '{$path}'");
        }

        @chmod($tmp, $chmod);
        @rename($tmp, $path);

        $this->opcacheInvalidate($path);
    }

    /** @param array<string, mixed> $payload */
    private function atomicWritePhpReturn(string $path, array $payload, int $chmod): void
    {
        $php = "<?php\ndeclare(strict_types=1);\nreturn " . var_export($payload, true) . ";\n";
        $this->atomicWrite($path, $php, $chmod);
    }

    private function safeUnlink(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
            $this->opcacheInvalidate($path);
        }
    }

    private function opcacheInvalidate(string $path): void
    {
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    }

    private function fetchToFile(string $source, string $dest): void
    {
        $dir = dirname($dest);
        $this->mkdirp($dir, 0775);

        $tmp = $dest . '.' . bin2hex(random_bytes(6)) . '.tmp';

        // local file copy
        if (is_file($source)) {
            $in = @fopen($source, 'rb');
            if ($in === false) {
                throw new RuntimeException("Cannot open source file '{$source}'");
            }
            $out = @fopen($tmp, 'wb');
            if ($out === false) {
                @fclose($in);
                throw new RuntimeException("Cannot write cache file '{$dest}'");
            }

            try {
                stream_copy_to_stream($in, $out);
            } finally {
                @fclose($in);
                @fclose($out);
            }

            @chmod($tmp, 0664);
            @rename($tmp, $dest);
            return;
        }

        // URL download
        $ctx = stream_context_create([
            'http' => [
                'timeout' => (int)$this->opt['read_timeout'],
                'header'  => "User-Agent: " . (string)$this->opt['user_agent'] . "\r\n",
            ],
            'https' => [
                'timeout' => (int)$this->opt['read_timeout'],
                'header'  => "User-Agent: " . (string)$this->opt['user_agent'] . "\r\n",
            ],
        ]);

        $in = @fopen($source, 'rb', false, $ctx);
        if ($in === false) {
            $this->safeUnlink($tmp);
            throw new RuntimeException("Cannot open source '{$source}'");
        }

        $out = @fopen($tmp, 'wb');
        if ($out === false) {
            @fclose($in);
            $this->safeUnlink($tmp);
            throw new RuntimeException("Cannot write cache file '{$dest}'");
        }

        try {
            stream_copy_to_stream($in, $out);
        } finally {
            @fclose($in);
            @fclose($out);
        }

        @chmod($tmp, 0664);
        @rename($tmp, $dest);
    }
}
