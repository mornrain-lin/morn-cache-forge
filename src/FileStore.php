<?php
/**
 * 文件缓存存储。
 *
 * 特性：
 * - 目录按需创建，权限 0755，文件内容 0644；
 * - 原子写入：先写同目录临时文件，再 rename 覆盖，
 *   保证并发读永远看到完整内容（PHP 手册明确 rename 在同文件系统内是原子的）；
 * - 排他锁：并发写同一 key 时串行化，避免后写覆盖先写；
 * - 过期机制：读取时惰性判断 + `gc()` 主动清理；
 * - 键名安全：md5 化，杜绝路径穿越；
 * - 支持序列化委托给 Serializer，也支持存纯文本。
 *
 * @package MornRain\CacheForge
 */

declare(strict_types=1);

namespace MornRain\CacheForge;

use RuntimeException;

/**
 * 文件缓存存储。
 */
class FileStore
{
    /** 默认目录权限 */
    public const DIR_MODE = 0755;

    /** 默认文件权限 */
    public const FILE_MODE = 0644;

    /** 内部哨兵：区分「未命中」与「缓存值本身就是 null」 */
    protected const MISS = "\0morn_cache_miss\0";

    /** @var string 缓存根目录 */
    protected $directory;

    /** @var Serializer 序列化器 */
    protected $serializer;

    /** @var int 默认有效期（秒），0 表示永不过期 */
    protected $defaultTtl;

    /** @var bool 是否已确认目录可写 */
    protected $directoryReady = false;

    /** @var int gc() 单次最多清理的文件数 */
    protected $gcLimit = 5000;

    /**
     * 构造函数。
     *
     * @param string       $directory  缓存目录，不存在会自动创建。
     * @param Serializer|null $serializer 序列化器。
     * @param int          $defaultTtl 默认有效期（秒）。
     *
     * @throws RuntimeException 目录无法创建或不可写。
     */
    public function __construct(string $directory, ?Serializer $serializer = null, int $defaultTtl = 3600)
    {
        $this->directory = rtrim($this->normalize($directory), '/\\');
        $this->serializer = $serializer ?? new Serializer();
        $this->defaultTtl = max(0, $defaultTtl);

        $this->ensureDirectory();
    }

    /**
     * 读取。
     *
     * @param string $key     缓存键。
     * @param mixed  $default 未命中时的返回值。
     * @param int|null $ttl 覆盖默认有效期；null 表示沿用默认。
     * @return mixed
     */
    public function get(string $key, $default = null, ?int $ttl = null)
    {
        $path = $this->pathFor($key);
        if (!is_file($path)) {
            return $default;
        }

        $ttl = $ttl === null ? $this->defaultTtl : max(0, $ttl);

        // 惰性过期：先看文件 mtime，避免读出多余内容
        if ($ttl > 0) {
            $mtime = @filemtime($path);
            if ($mtime !== false && (time() - $mtime) >= $ttl) {
                @unlink($path);

                return $default;
            }
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return $default;
        }

        $value = $this->serializer->decode($raw, self::MISS);
        if ($value === self::MISS) {
            // 内容损坏，直接清理避免反复命中坏数据
            @unlink($path);

            return $default;
        }

        return $value;
    }

    /**
     * 写入。
     *
     * @param string $key   缓存键。
     * @param mixed  $value 值。
     * @param int|null $ttl 有效期（秒），0 表示永不过期。
     * @return bool 是否成功。
     */
    public function set(string $key, $value, ?int $ttl = null): bool
    {
        $this->ensureDirectory();

        $encoded = $this->serializer->encode($value);
        if ($encoded === '') {
            return false;
        }

        return $this->atomicWrite($this->pathFor($key), $encoded);
    }

    /**
     * 读取原始字符串（不经序列化）。
     *
     * @param string $key     缓存键。
     * @param string $default 默认值。
     */
    public function getRaw(string $key, string $default = ''): string
    {
        $path = $this->pathFor($key);
        if (!is_file($path)) {
            return $default;
        }
        $raw = @file_get_contents($path);

        return $raw === false ? $default : $raw;
    }

    /**
     * 写入原始字符串。
     *
     * @param string $key   缓存键。
     * @param string $value 内容。
     */
    public function setRaw(string $key, string $value): bool
    {
        $this->ensureDirectory();

        return $this->atomicWrite($this->pathFor($key), $value);
    }

    /**
     * 删除单个键。
     */
    public function delete(string $key): bool
    {
        $path = $this->pathFor($key);

        return is_file($path) ? @unlink($path) : false;
    }

    /**
     * 判断键是否存在且未过期。
     */
    public function has(string $key, ?int $ttl = null): bool
    {
        $path = $this->pathFor($key);
        if (!is_file($path)) {
            return false;
        }
        $ttl = $ttl === null ? $this->defaultTtl : max(0, $ttl);
        if ($ttl === 0) {
            return true;
        }
        $mtime = @filemtime($path);

        return $mtime !== false && (time() - $mtime) < $ttl;
    }

    /**
     * 递增数值。
     *
     * 适用于「计数器」类场景。首次调用视为 0，返回递增后的值。
     *
     * @param string   $key   缓存键。
     * @param int      $by    步长。
     * @param int|null $ttl   有效期。
     * @return int|false 失败返回 false。
     */
    public function increment(string $key, int $by = 1, ?int $ttl = null)
    {
        $path = $this->pathFor($key);
        $this->ensureDirectory();

        $lock = $this->acquireLock($key, 2.0);
        if ($lock === null) {
            return false;
        }

        try {
            $current = 0;
            if (is_file($path)) {
                $mtime = @filemtime($path);
                $effectiveTtl = $ttl === null ? $this->defaultTtl : max(0, $ttl);
                $fresh = $effectiveTtl === 0 || ($mtime !== false && (time() - $mtime) < $effectiveTtl);
                if ($fresh) {
                    $decoded = $this->serializer->decode((string) @file_get_contents($path), 0);
                    $current = is_numeric($decoded) ? (int) $decoded : 0;
                }
            }

            $next = $current + $by;
            $this->atomicWrite($path, $this->serializer->encode($next));

            return $next;
        } finally {
            $this->releaseLock($lock);
        }
    }

    /**
     * 清除全部缓存文件。
     *
     * @param bool $includeTemp 是否同时删除遗留的临时文件。
     * @return int 删除的文件数。
     */
    public function flush(bool $includeTemp = true): int
    {
        $deleted = 0;

        foreach ($this->iterateFiles() as $item) {
            /** @var \SplFileInfo $item */
            if (!$item->isFile()) {
                continue;
            }
            $name = $item->getFilename();
            $isTemp = $this->isTempFile($name);

            if ($isTemp && !$includeTemp) {
                continue;
            }
            if (!$isTemp && $this->isMetaFile($name)) {
                continue;
            }
            if (@unlink($item->getPathname())) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * 清理过期文件。
     *
     * @param int|null $ttl   判定标准有效期（秒），null 表示用默认值。
     * @param int      $limit 单次最多清理数量。
     * @return array{deleted:int,scanned:int,remaining:int}
     */
    public function gc(?int $ttl = null, int $limit = 0): array
    {
        $ttl = $ttl === null ? $this->defaultTtl : max(0, $ttl);
        $limit = $limit > 0 ? $limit : $this->gcLimit;

        $deleted  = 0;
        $scanned  = 0;
        $now      = time();

        foreach ($this->iterateFiles() as $item) {
            /** @var \SplFileInfo $item */
            if (!$item->isFile()) {
                continue;
            }
            $name = $item->getFilename();
            if ($this->isMetaFile($name)) {
                continue;
            }

            $scanned++;
            // 临时文件与锁文件超过 1 小时视为残留
            $isOrphan = $this->isTempFile($name) || $this->isLockFile($name);
            $mtime    = $item->getMTime();
            $expired  = $isOrphan
                ? ($mtime !== false && ($now - $mtime) > 3600)
                : ($ttl > 0 && $mtime !== false && ($now - $mtime) >= $ttl);

            if ($expired && @unlink($item->getPathname())) {
                $deleted++;
            }
            if ($scanned >= $limit) {
                break;
            }
        }

        return [
            'deleted'   => $deleted,
            'scanned'   => $scanned,
            'remaining' => max(0, $scanned - $deleted),
        ];
    }

    /**
     * 列出全部缓存键（文件名的 md5 之前无法还原，仅返回文件名）。
     *
     * @return array<int,string>
     */
    public function files(): array
    {
        $out = [];

        foreach ($this->iterateFiles() as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isFile() && !$this->isTempFile($item->getFilename())) {
                $out[] = $item->getPathname();
            }
        }

        return $out;
    }

    /**
     * 统计缓存占用。
     *
     * @return array{files:int,bytes:int,bytes_human:string}
     */
    public function stats(): array
    {
        $files = 0;
        $bytes = 0;
        foreach ($this->files() as $path) {
            $files++;
            $size = @filesize($path);
            if ($size !== false) {
                $bytes += $size;
            }
        }

        return [
            'files'       => $files,
            'bytes'       => $bytes,
            'bytes_human' => $this->humanBytes($bytes),
        ];
    }

    /**
     * 取得缓存目录。
     */
    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * 取得序列化器。
     */
    public function serializer(): Serializer
    {
        return $this->serializer;
    }

    /* ================================================================
     *  内部实现
     * ================================================================ */

    /**
     * 由 key 计算文件路径（md5 防路径穿越）。
     */
    protected function pathFor(string $key): string
    {
        // 二级目录分散文件数量，避免单目录文件过多导致的性能退化
        $hash = md5($key);
        $sub  = substr($hash, 0, 2);

        return $this->directory . DIRECTORY_SEPARATOR . $sub . DIRECTORY_SEPARATOR . $hash . '.cache';
    }

    /**
     * 遍历缓存目录下的所有文件。
     *
     * 目录可能因外部因素（清理脚本、tmpfs 重启、权限变更）而消失，
     * 此时 RecursiveDirectoryIterator 会抛 UnexpectedValueException。
     * 这里统一返回空迭代器，让 flush/gc/files/stats 都能优雅降级。
     *
     * @return \Traversable<int,\SplFileInfo>
     */
    protected function iterateFiles(): \Traversable
    {
        if (!is_dir($this->directory)) {
            return new \ArrayIterator([]);
        }

        try {
            return new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
        } catch (\UnexpectedValueException $e) {
            // 目录在 is_dir 之后被删除
            return new \ArrayIterator([]);
        }
    }

    /**
     * 原子写入：临时文件 + rename。
     */
    protected function atomicWrite(string $path, string $content): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, self::DIR_MODE, true) && !is_dir($dir)) {
            return false;
        }

        $tmp = $path . '.' . getmypid() . '.' . bin2hex(Compat::randomBytes(4)) . '.tmp';

        $bytes = @file_put_contents($tmp, $content, LOCK_EX);
        if ($bytes === false) {
            @unlink($tmp);

            return false;
        }
        @chmod($tmp, self::FILE_MODE);

        // 同目录 rename 在 POSIX 与 Windows 上均为原子操作
        if (!@rename($tmp, $path)) {
            @unlink($tmp);

            return false;
        }

        @chmod($path, self::FILE_MODE);
        // 清理目录 stat 缓存，保证 filemtime 立即反映写入时间
        @clearstatcache(true, $path);

        return true;
    }

    /**
     * 获取排他锁。
     *
     * @return resource|null 句柄，超时返回 null。
     */
    protected function acquireLock(string $key, float $timeout = 2.0)
    {
        $lockPath = $this->directory . DIRECTORY_SEPARATOR . 'locks'
            . DIRECTORY_SEPARATOR . md5($key) . '.lock';

        $lockDir = dirname($lockPath);
        if (!is_dir($lockDir) && !@mkdir($lockDir, self::DIR_MODE, true) && !is_dir($lockDir)) {
            return null;
        }

        $handle = @fopen($lockPath, 'c');
        if ($handle === false) {
            return null;
        }

        $deadline = microtime(true) + $timeout;
        while (true) {
            if (@flock($handle, LOCK_EX | LOCK_NB)) {
                return $handle;
            }
            if (microtime(true) >= $deadline) {
                fclose($handle);

                return null;
            }
            usleep(10000);
        }
    }

    /**
     * 释放锁。
     *
     * @param resource $handle 锁句柄。
     */
    protected function releaseLock($handle): void
    {
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * 判断键是否已过期。
     */
    protected function isExpired(string $key, int $ttl): bool
    {
        $path = $this->pathFor($key);
        $mtime = @filemtime($path);
        if ($mtime === false) {
            return true;
        }

        return (time() - $mtime) >= $ttl;
    }

    /**
     * 确保目录存在且可写。
     *
     * @throws RuntimeException 目录不可用。
     */
    protected function ensureDirectory(): void
    {
        if ($this->directoryReady && is_dir($this->directory)) {
            return;
        }
        if ($this->directory === '') {
            throw new RuntimeException('缓存目录不能为空');
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, self::DIR_MODE, true) && !is_dir($this->directory)) {
            throw new RuntimeException('无法创建缓存目录：' . $this->directory);
        }
        if (!is_writable($this->directory)) {
            throw new RuntimeException('缓存目录不可写：' . $this->directory);
        }

        $this->directoryReady = true;
    }

    /**
     * 是否为临时文件。
     */
    protected function isTempFile(string $name): bool
    {
        return substr($name, -4) === '.tmp';
    }

    /**
     * 是否为锁文件。
     */
    protected function isLockFile(string $name): bool
    {
        return substr($name, -5) === '.lock';
    }

    /**
     * 是否为元文件。
     */
    protected function isMetaFile(string $name): bool
    {
        return $name === '.gitkeep' || $name === '.htaccess' || $name === 'index.html';
    }

    /**
     * 统一路径分隔符。
     */
    protected function normalize(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    /**
     * 字节数格式化。
     */
    protected function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / 1048576, 2) . ' MB';
    }
}
