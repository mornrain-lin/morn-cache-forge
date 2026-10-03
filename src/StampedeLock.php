<?php
/**
 * 缓存击穿锁（cache stampede lock）。
 *
 * 问题：热点键过期瞬间，成千上万并发请求会同时发现「未命中」，
 * 然后一起去执行同一个昂贵的回调（读数据库、调 API），
 * 把下游打垮 —— 这就是缓存击穿。
 *
 * 解法：
 * 1. 未命中时先尝试加锁（底层用原子文件写入 / WordPress add_option 实现）；
 * 2. 抢到锁的请求执行回调并写入缓存；
 * 3. 没抢到的请求短暂轮询等待，等待期间若锁消失说明对方已写入，直接读缓存；
 * 4. 超时未拿到锁则「降级返回」——返回过期值（若有）或直接执行回调，
 *    宁可牺牲一次上游压力也不能让用户看到错误；
 * 5. 锁的过期时间附加随机抖动，避免同一秒大量锁同时到期的「锁惊群」。
 *
 * @package MornRain\CacheForge
 */

declare(strict_types=1);

namespace MornRain\CacheForge;

use Throwable;

/**
 * 缓存击穿锁。
 */
class StampedeLock
{
    /** @var string 锁文件目录 */
    protected $lockDir;

    /** @var int 默认锁有效期（秒） */
    protected $defaultTtl;

    /** @var float 抖动比例（0~1），0.2 表示实际 TTL 在 80%~120% 间随机 */
    protected $jitterRatio;

    /**
     * 构造函数。
     *
     * @param string $lockDir     锁文件目录。
     * @param int    $defaultTtl  默认锁有效期（秒）。
     * @param float  $jitterRatio 抖动比例。
     */
    public function __construct(string $lockDir, int $defaultTtl = 10, float $jitterRatio = 0.2)
    {
        if ($defaultTtl < 1) {
            throw new \InvalidArgumentException('锁有效期不得小于 1 秒');
        }
        if ($jitterRatio < 0 || $jitterRatio > 1) {
            throw new \InvalidArgumentException('抖动比例须在 0~1 之间');
        }

        $this->lockDir     = rtrim(str_replace('\\', '/', $lockDir), '/');
        $this->defaultTtl  = $defaultTtl;
        $this->jitterRatio = $jitterRatio;

        if (!is_dir($this->lockDir)) {
            @mkdir($this->lockDir, 0755, true);
        }
    }

    /**
     * 带锁执行回调：击穿保护的标准用法。
     *
     * @template T
     * @param string   $key      缓存键。
     * @param callable $callback 实际计算逻辑，接收 $key 参数。
     * @param callable $readHit  等待期间读取已写入值的回调。
     *                           **约定**：返回 null 表示「尚未读到」，
     *                           返回其他值（含 false / 0 / 空数组）一律视为已命中。
     *                           切勿用空串表示未命中，否则等待方会拿到空值。
     * @param array{
     *     ttl?: int,
     *     wait?: float,
     *     stale?: mixed
     * } $options 可选项：ttl 锁有效期、wait 最长等待秒数、stale 降级值。
     * @return mixed 回调返回值，或等待期间读到的值，或降级值。
     */
    public function remember(string $key, callable $callback, callable $readHit, array $options = [])
    {
        $ttl       = isset($options['ttl']) ? max(1, (int) $options['ttl']) : $this->defaultTtl;
        $wait      = isset($options['wait']) ? max(0.0, (float) $options['wait']) : 3.0;
        $stale     = array_key_exists('stale', $options) ? $options['stale'] : null;
        $lockToken = $this->acquire($key, $ttl);

        if ($lockToken !== null) {
            // 抢到锁：由本请求负责计算
            try {
                $value = $callback($key);
                $this->release($key, $lockToken);

                return $value;
            } catch (Throwable $e) {
                // 计算失败必须释放锁，否则其他请求会一直空等。
                // 放在 finally 之外以确保回调抛出的异常原样向上传播。
                $this->release($key, $lockToken);
                throw $e;
            }
        }

        // 没抢到锁：轮询等待对方写入
        if ($wait > 0) {
            $deadline = microtime(true) + $wait;
            $interval = 30000; // 30ms

            while (microtime(true) < $deadline) {
                usleep($interval);
                $value = $readHit($key);
                // 只有 null 表示「还没读到」
                if ($value !== null) {
                    return $value;
                }
                // 锁已消失但还没读到值，说明对方计算失败，再给一次机会
                if ($this->isLocked($key)) {
                    continue;
                }
                $value = $readHit($key);
                if ($value !== null) {
                    return $value;
                }
                break;
            }
        }

        // 降级：优先用过期值，其次自行计算
        if ($stale !== null) {
            return $stale;
        }

        return $callback($key);
    }

    /**
     * 尝试获取锁。
     *
     * @param string $key 锁键。
     * @param int    $ttl 锁有效期（秒）。
     * @return string|null 成功返回令牌，失败返回 null。
     */
    public function acquire(string $key, int $ttl = 0): ?string
    {
        $ttl  = $ttl > 0 ? $ttl : $this->defaultTtl;
        $path = $this->pathFor($key);
        $this->ensureDir();

        $token = bin2hex(Compat::randomBytes(8));
        $payload = json_encode(
            [
                'token'    => $token,
                'expire'   => time() + $this->jitter($ttl),
                'pid'      => Compat::pid(),
            ],
            JSON_UNESCAPED_UNICODE
        );

        // 模式 'x' 保证文件必须新建 —— 这是加锁的关键（原子且互斥）
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            // 已存在：判断是否过期，过期则尝试接管
            if (!$this->isLocked($key)) {
                @unlink($path);
                $handle = @fopen($path, 'x');
            }
            if ($handle === false) {
                return null;
            }
        }

        fwrite($handle, (string) $payload);
        fclose($handle);

        return $token;
    }

    /**
     * 释放锁。
     *
     * 只有持有者本人才能释放，避免误删他人已接管的锁。
     *
     * @param string $key   锁键。
     * @param string $token 令牌。
     */
    public function release(string $key, string $token): bool
    {
        $path = $this->pathFor($key);
        if (!is_file($path)) {
            return false;
        }

        $raw     = (string) @file_get_contents($path);
        $payload = json_decode($raw, true);
        if (is_array($payload) && isset($payload['token']) && $payload['token'] !== $token) {
            return false;
        }

        return @unlink($path);
    }

    /**
     * 判断锁是否仍被持有。
     */
    public function isLocked(string $key): bool
    {
        $path = $this->pathFor($key);
        if (!is_file($path)) {
            return false;
        }

        $raw     = (string) @file_get_contents($path);
        $payload = json_decode($raw, true);
        if (!is_array($payload) || !isset($payload['expire'])) {
            // 内容损坏，视为陈旧锁
            return false;
        }

        return (int) $payload['expire'] > time();
    }

    /**
     * 强制解锁。
     */
    public function forceRelease(string $key): bool
    {
        $path = $this->pathFor($key);

        return is_file($path) ? @unlink($path) : false;
    }

    /**
     * 清理过期锁。
     *
     * @return int 清理数量。
     */
    public function gc(): int
    {
        $deleted = 0;
        $now     = time();

        foreach ($this->iterateLockFiles() as $path) {
            $raw     = (string) @file_get_contents($path);
            $payload = json_decode($raw, true);
            $expire  = is_array($payload) && isset($payload['expire']) ? (int) $payload['expire'] : 0;
            if ($expire <= $now && @unlink($path)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * 锁统计。
     *
     * @return array{active:int,total:int}
     */
    public function stats(): array
    {
        $active = 0;
        $total  = 0;
        $now    = time();

        foreach ($this->iterateLockFiles() as $path) {
            $total++;
            $raw     = (string) @file_get_contents($path);
            $payload = json_decode($raw, true);
            $expire  = is_array($payload) && isset($payload['expire']) ? (int) $payload['expire'] : 0;
            if ($expire > $now) {
                $active++;
            }
        }

        return ['active' => $active, 'total' => $total];
    }

    /**
     * 遍历锁文件。
     *
     * 锁目录可能因清理脚本或 tmpfs 重启而消失，
     * 此时迭代器构造函数会抛 UnexpectedValueException，这里统一降级为空列表。
     *
     * @return array<int,string> 锁文件绝对路径
     */
    protected function iterateLockFiles(): array
    {
        if (!is_dir($this->lockDir)) {
            return [];
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->lockDir, \FilesystemIterator::SKIP_DOTS)
            );
        } catch (\UnexpectedValueException $e) {
            return [];
        }

        $out = [];
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isFile() && substr($item->getFilename(), -5) === '.lock') {
                $out[] = $item->getPathname();
            }
        }

        return $out;
    }

    /**
     * 计算带抖动的实际过期时间。
     *
     * @param int $ttl 基础秒数。
     */
    protected function jitter(int $ttl): int
    {
        if ($this->jitterRatio <= 0.0) {
            return $ttl;
        }

        $delta   = (int) round($ttl * $this->jitterRatio);
        if ($delta < 1) {
            return $ttl;
        }

        return max(1, $ttl + Compat::randomInt(-$delta, $delta));
    }

    /**
     * 锁文件路径。
     */
    protected function pathFor(string $key): string
    {
        return $this->lockDir . DIRECTORY_SEPARATOR . md5($key) . '.lock';
    }

    /**
     * 确保锁目录存在。
     */
    protected function ensureDir(): void
    {
        if (!is_dir($this->lockDir)) {
            @mkdir($this->lockDir, 0755, true);
        }
    }
}
