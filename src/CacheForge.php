<?php
/**
 * 多级缓存门面。
 *
 * 读取优先级（命中即返回）：
 *   1. 内存层   —— 请求内数组，零 IO，同一请求内重复读最快
 *   2. 对象缓存 —— WordPress wp_cache_*（Redis / Memcached），跨请求共享
 *   3. 瞬态层   —— WordPress Transient，跨请求共享，自带过期
 *   4. 文件层   —— FileStore，无 WordPress 环境的兜底
 *   5. 默认值   —— 全部未命中时返回
 *
 * 写入策略：自上而下逐层写入（可配置），让每层都成为热缓存，
 * 这样下次读取在第一层就能命中，不必穿透到下层。
 *
 * 击穿保护：remember() 配合 StampedeLock，
 * 保证热点键过期瞬间只有一个请求去执行昂贵的回调。
 *
 * @package MornRain\CacheForge
 */

declare(strict_types=1);

namespace MornRain\CacheForge;

use Throwable;

/**
 * 多级缓存门面。
 */
class CacheForge
{
    /** @var string 默认键前缀 */
    public const DEFAULT_PREFIX = 'morn_';

    /** @var int 默认有效期（秒） */
    public const DEFAULT_TTL = 3600;

    /** @var array<string,mixed> 内存层 */
    protected $memory = [];

    /** @var array<int,string> 已启用层级，顺序即优先级 */
    protected $layers;

    /** @var FileStore|null 文件层 */
    protected $fileStore;

    /** @var StampedeLock|null 击穿锁 */
    protected $lock;

    /** @var CacheTags|null 标签管理器 */
    protected $tags;

    /** @var Metrics 指标统计 */
    protected $metrics;

    /** @var Serializer 序列化器 */
    protected $serializer;

    /** @var string 键前缀 */
    protected $prefix;

    /** @var int 默认有效期 */
    protected $defaultTtl;

    /** @var bool 写入时是否向下穿透填充所有层 */
    protected $fillDown = true;

    /** @var array<int,string> 内存层本请求内不过期的键 */
    protected $memoryNoExpiry = [];

    /** 层级读取的内部哨兵：区分「未命中」与「缓存值为 null」 */
    protected const LAYER_MISS = "\0morn_layer_miss\0";

    /**
     * 构造函数。
     *
     * @param array<string,mixed> $config 配置项：
     *        prefix       键前缀
     *        ttl          默认有效期
     *        directory    文件缓存目录
     *        layers       启用层级 ['memory','object','transient','file']
     *        lock_dir     锁目录
     *        serializer   序列化器
     *        fill_down    是否写入时填充所有层
     */
    public function __construct(array $config = [])
    {
        $this->prefix    = (string) ($config['prefix'] ?? self::DEFAULT_PREFIX);
        $this->defaultTtl = max(0, (int) ($config['ttl'] ?? self::DEFAULT_TTL));
        $this->serializer = $config['serializer'] ?? new Serializer();
        $this->metrics    = $config['metrics'] ?? new Metrics();
        $this->fillDown   = (bool) ($config['fill_down'] ?? true);

        $layers = $config['layers'] ?? null;
        if (!is_array($layers) || $layers === []) {
            // WordPress 环境默认启用对象缓存与瞬态，纯 PHP 环境只启用内存与文件
            $layers = Compat::isWordPress()
                ? [Metrics::LAYER_MEMORY, Metrics::LAYER_OBJECT, Metrics::LAYER_TRANSIENT, Metrics::LAYER_FILE]
                : [Metrics::LAYER_MEMORY, Metrics::LAYER_FILE];
        }
        $this->layers = array_values(array_unique(array_map('strval', $layers)));

        if (in_array(Metrics::LAYER_FILE, $this->layers, true)) {
            $directory = (string) ($config['directory'] ?? (sys_get_temp_dir() . '/morn-cache-forge'));
            $this->fileStore = new FileStore($directory, $this->serializer, $this->defaultTtl);
        }

        $lockDir = (string) ($config['lock_dir'] ?? ($this->fileStore !== null
            ? $this->fileStore->directory() . '/locks'
            : sys_get_temp_dir() . '/morn-cache-forge-locks'));
        $this->lock = new StampedeLock($lockDir, (int) ($config['lock_ttl'] ?? 10), (float) ($config['lock_jitter'] ?? 0.2));

        $tagResolver = isset($config['tag_resolver']) && is_callable($config['tag_resolver'])
            ? $config['tag_resolver']
            : null;
        $this->tags = new CacheTags($this->prefix, $tagResolver);
    }

    /* ================================================================
     *  核心读写
     * ================================================================ */

    /**
     * 读取缓存。
     *
     * @param string   $key     缓存键。
     * @param mixed    $default 默认值。
     * @param int|null $ttl     覆盖默认有效期。
     * @return mixed
     */
    public function get(string $key, $default = null, ?int $ttl = null)
    {
        $fullKey = $this->prefix . $key;

        foreach ($this->layers as $layer) {
            $found = false;
            $value = null;
            $start = microtime(true);

            switch ($layer) {
                case Metrics::LAYER_MEMORY:
                    if (array_key_exists($fullKey, $this->memory)) {
                        $value = $this->memory[$fullKey];
                        $found = true;
                    }
                    break;

                case Metrics::LAYER_OBJECT:
                    $value = $this->readObjectCache($fullKey);
                    $found = $value !== self::LAYER_MISS;
                    break;

                case Metrics::LAYER_TRANSIENT:
                    $value = $this->readTransient($fullKey);
                    $found = $value !== self::LAYER_MISS;
                    break;

                case Metrics::LAYER_FILE:
                    if ($this->fileStore !== null) {
                        $value = $this->fileStore->get($fullKey, self::LAYER_MISS, $ttl);
                        $found = $value !== self::LAYER_MISS;
                    }
                    break;

                default:
                    break;
            }

            $cost = microtime(true) - $start;

            // Transient 层命中时带包装对象，拆包后参与后续流程
            if ($found && $value instanceof CachedValue) {
                $value = $value->value();
            }

            $this->metrics->recordRead($fullKey, $found, $layer, $cost);

            if ($found) {
                // 命中下层时回填上层，避免下次再次穿透
                if ($this->fillDown) {
                    $this->fillUpperLayers($fullKey, $value, $layer, $ttl);
                }

                return $value;
            }
        }

        $this->metrics->recordRead($fullKey, false, Metrics::LAYER_DEFAULT);

        return $default;
    }

    /**
     * 写入缓存。
     *
     * @param string                $key   缓存键。
     * @param mixed                 $value 值。
     * @param int|null              $ttl   有效期（秒），0 表示永不过期。
     * @param array<int,string>|null $tags  关联标签，用于批量失效。
     * @return bool 是否成功。
     */
    public function set(string $key, $value, ?int $ttl = null, ?array $tags = null): bool
    {
        $fullKey = $this->prefix . $key;
        $ttl     = $ttl === null ? $this->defaultTtl : max(0, $ttl);

        $targets = $this->fillDown ? $this->layers : [Metrics::LAYER_MEMORY];
        $ok      = false;

        foreach ($targets as $layer) {
            $written = $this->writeLayer($layer, $fullKey, $value, $ttl);
            if ($written) {
                $ok = true;
                $this->metrics->recordWrite($fullKey, $layer);
            }
        }

        if ($tags !== null && $this->fileStore !== null) {
            $this->tags->register($fullKey, $tags, $this->fileStore);
        }

        return $ok;
    }

    /**
     * 读取并回填：未命中时执行回调，成功后写入缓存。
     *
     * 这是最常用的入口。不加锁，适用于回调代价低的场景
     * （如读一次 wp_options）。
     *
     * @param string                $key      缓存键。
     * @param callable              $callback 回调，接收 $key。
     * @param int|null              $ttl      有效期。
     * @param array<int,string>|null $tags   关联标签。
     * @return mixed
     */
    public function remember(string $key, callable $callback, ?int $ttl = null, ?array $tags = null)
    {
        $sentinel = $this->sentinelFor($key);

        // 用唯一哨兵值区分「缓存值为 null」与「未命中」
        $cached = $this->get($key, $sentinel, $ttl);
        if ($cached !== $sentinel) {
            return $cached;
        }

        $value = $callback($key);
        $this->set($key, $value, $ttl, $tags);

        return $value;
    }

    /**
     * 带击穿保护的读取回填。
     *
     * 适用于回调代价高（外部 API、复杂查询）的场景：
     * 同一时刻只有一个请求执行回调，其余请求短暂等待并读结果。
     *
     * @param string                $key      缓存键。
     * @param callable              $callback 回调，接收 $key。
     * @param array{
     *     ttl?: int,
     *     wait?: float,
     *     lock_ttl?: int,
     *     stale?: mixed,
     *     tags?: array<int,string>
     * } $options 可选项。
     * @return mixed
     */
    public function rememberLocked(string $key, callable $callback, array $options = [])
    {
        $ttl      = isset($options['ttl']) ? max(0, (int) $options['ttl']) : $this->defaultTtl;
        $lockTtl  = (int) ($options['lock_ttl'] ?? 10);
        $wait     = (float) ($options['wait'] ?? 3.0);
        $tags     = $options['tags'] ?? null;
        $sentinel = $this->sentinelFor($key);

        // 先正常读一次，命中就不必走锁
        $cached = $this->get($key, $sentinel, $ttl);
        if ($cached !== $sentinel) {
            return $cached;
        }

        $build = function (string $lockKey) use ($callback, $ttl, $tags): string {
            $value = $callback($lockKey);
            $this->set($lockKey, $value, $ttl, $tags);

            // 返回序列化结果，供等待方直接复用，避免等待方再读一次缓存。
            // serialize() 对任何值都返回非空串（null → "N;"，false → "b:0;"），
            // 因此可以用来安全地表达「已取到值」。
            return serialize($value);
        };

        $readHit = function (string $readKey) use ($sentinel, $ttl): ?string {
            $value = $this->get($readKey, $sentinel, $ttl);

            // 未命中必须返回 null：StampedeLock 以 null 作为「还没读到」的信号。
            // 此处若返回空串，会被误判成已命中，导致等待方立刻拿到 null
            // 而不是等到持锁方写入的真实值。
            return $value === $sentinel ? null : serialize($value);
        };

        $lockOptions = ['ttl' => $lockTtl, 'wait' => $wait];
        if (array_key_exists('stale', $options)) {
            $lockOptions['stale'] = $options['stale'];
        }

        $result = $this->lock->remember($key, $build, $readHit, $lockOptions);

        return $this->unserializeLoose($result);
    }

    /**
     * 删除缓存。
     *
     * @param string $key 缓存键。
     * @return bool 是否删除了至少一层。
     */
    public function delete(string $key): bool
    {
        $fullKey = $this->prefix . $key;
        $deleted = false;

        unset($this->memory[$fullKey], $this->memoryNoExpiry[$fullKey]);

        foreach ($this->layers as $layer) {
            $removed = false;
            switch ($layer) {
                case Metrics::LAYER_OBJECT:
                    $removed = $this->deleteObjectCache($fullKey);
                    break;
                case Metrics::LAYER_TRANSIENT:
                    $removed = $this->deleteTransient($fullKey);
                    break;
                case Metrics::LAYER_FILE:
                    $removed = $this->fileStore !== null && $this->fileStore->delete($fullKey);
                    break;
                default:
                    break;
            }
            if ($removed) {
                $deleted = true;
                $this->metrics->recordDelete($fullKey, $layer);
            }
        }

        if ($deleted) {
            $this->metrics->recordDelete($fullKey, Metrics::LAYER_MEMORY);
        }

        return $deleted;
    }

    /**
     * 判断键是否存在于任一启用层级。
     */
    public function has(string $key, ?int $ttl = null): bool
    {
        $sentinel = $this->sentinelFor($key);

        return $this->get($key, $sentinel, $ttl) !== $sentinel;
    }

    /**
     * 递增数值。
     */
    public function increment(string $key, int $by = 1, ?int $ttl = null)
    {
        $fullKey = $this->prefix . $key;
        $ttl     = $ttl === null ? $this->defaultTtl : max(0, $ttl);

        if ($this->fileStore !== null) {
            return $this->fileStore->increment($fullKey, $by, $ttl);
        }

        // 无文件层时退化到内存
        $current = (int) ($this->memory[$fullKey] ?? 0);
        $next    = $current + $by;
        $this->set($key, $next, $ttl);

        return $next;
    }

    /**
     * 按标签批量失效。
     *
     * @param string|array<int,string> $tags 标签。
     * @return array{deleted:int,keys:array<int,string>}
     */
    public function flushTags($tags): array
    {
        if ($this->fileStore === null) {
            return ['deleted' => 0, 'keys' => []];
        }

        $result = $this->tags->flushTags($tags, $this->fileStore);

        // 内存层里对应的键也要清掉
        foreach ($result['keys'] as $key) {
            unset($this->memory[$key], $this->memoryNoExpiry[$key]);
        }

        return $result;
    }

    /**
     * 组合条件失效。
     *
     * @param array<int,string> $includeTags 必须全部命中（AND）。
     * @param array<int,string> $anyOfTags   任一命中（OR）。
     * @return array{deleted:int,keys:array<int,string>}
     */
    public function flushWhere(array $includeTags = [], array $anyOfTags = []): array
    {
        if ($this->fileStore === null) {
            return ['deleted' => 0, 'keys' => []];
        }

        $result = $this->tags->flushWhere($includeTags, $anyOfTags, $this->fileStore);
        foreach ($result['keys'] as $key) {
            unset($this->memory[$key], $this->memoryNoExpiry[$key]);
        }

        return $result;
    }

    /**
     * 清理各层过期数据。
     *
     * @param int|null $ttl 文件层判定标准。
     * @return array<string,mixed>
     */
    public function gc(?int $ttl = null): array
    {
        $out = ['file' => null, 'locks' => $this->lock->gc()];

        if ($this->fileStore !== null) {
            $out['file'] = $this->fileStore->gc($ttl);
            $out['tags'] = $this->tags->gc($this->fileStore);
        }

        return $out;
    }

    /**
     * 清空所有层。
     *
     * @param bool $withLocks 是否同时清理锁。
     */
    public function flush(bool $withLocks = false): array
    {
        $out = [
            'memory'    => count($this->memory),
            'transient' => 0,
            'file'      => 0,
        ];

        $this->memory        = [];
        $this->memoryNoExpiry = [];

        if (function_exists('wp_cache_flush')) {
            @wp_cache_flush();
            $out['object'] = 'flushed';
        }

        if ($this->fileStore !== null) {
            $out['file'] = $this->fileStore->flush();
        }

        if ($withLocks) {
            $stats = $this->lock->stats();
            $out['locks'] = $stats['active'];
        }

        return $out;
    }

    /* ================================================================
     *  访问器
     * ================================================================ */

    /**
     * 取得指标统计器。
     */
    public function metrics(): Metrics
    {
        return $this->metrics;
    }

    /**
     * 取得标签管理器。
     */
    public function tags(): CacheTags
    {
        return $this->tags;
    }

    /**
     * 取得击穿锁。
     */
    public function lock(): StampedeLock
    {
        return $this->lock;
    }

    /**
     * 取得文件层（未启用时返回 null）。
     */
    public function fileStore(): ?FileStore
    {
        return $this->fileStore;
    }

    /**
     * 取得序列化器。
     */
    public function serializer(): Serializer
    {
        return $this->serializer;
    }

    /**
     * 取得启用的层级列表。
     *
     * @return array<int,string>
     */
    public function layers(): array
    {
        return $this->layers;
    }

    /**
     * 取得键前缀。
     */
    public function prefix(): string
    {
        return $this->prefix;
    }

    /**
     * 是否启用向下填充。
     */
    public function fillDown(bool $enabled = true): self
    {
        $this->fillDown = $enabled;

        return $this;
    }

    /**
     * 设置默认有效期。
     */
    public function setDefaultTtl(int $ttl): self
    {
        $this->defaultTtl = max(0, $ttl);

        return $this;
    }

    /**
     * 诊断报告。
     */
    public function report(): array
    {
        $report = $this->metrics->report();
        $report['config'] = [
            'prefix'     => $this->prefix,
            'ttl'        => $this->defaultTtl,
            'layers'     => $this->layers,
            'fill_down'  => $this->fillDown,
            'file_dir'   => $this->fileStore !== null ? $this->fileStore->directory() : null,
            'wp_context' => Compat::isWordPress(),
        ];
        if ($this->fileStore !== null) {
            $report['file_stats'] = $this->fileStore->stats();
        }
        $report['locks'] = $this->lock->stats();

        return $report;
    }

    /* ================================================================
     *  层级实现
     * ================================================================ */

    /**
     * 读取 WordPress 对象缓存。
     *
     * @return mixed 未命中返回 LAYER_MISS 哨兵。
     */
    protected function readObjectCache(string $key)
    {
        if (!function_exists('wp_cache_get')) {
            return self::LAYER_MISS;
        }

        // wp_cache_get 的第四参数用于区分「未命中」与「值为 false」
        $found = false;
        $value = wp_cache_get($key, 'morn_cache', false, $found);

        return $found ? $value : self::LAYER_MISS;
    }

    /**
     * 写入 WordPress 对象缓存。
     *
     * @param mixed $value 值。
     * @param int   $ttl   有效期。
     */
    protected function writeObjectCache(string $key, $value, int $ttl): bool
    {
        if (!function_exists('wp_cache_set')) {
            return false;
        }
        if ($ttl === 0) {
            return (bool) wp_cache_set($key, $value, 'morn_cache');
        }

        return (bool) wp_cache_set($key, $value, 'morn_cache', $ttl);
    }

    /**
     * 删除对象缓存。
     */
    protected function deleteObjectCache(string $key): bool
    {
        if (!function_exists('wp_cache_delete')) {
            return false;
        }

        return (bool) wp_cache_delete($key, 'morn_cache');
    }

    /**
     * 读取 Transient。
     *
     * get_transient() 用 false 同时表示「未命中」与「值为 false」，
     * 因此命中时统一包一层 CachedValue，由调用方拆包。
     *
     * @return CachedValue|self::LAYER_MISS
     */
    protected function readTransient(string $key)
    {
        if (!function_exists('get_transient')) {
            return self::LAYER_MISS;
        }

        $value = get_transient($key, false);
        if ($value === false) {
            return self::LAYER_MISS;
        }

        return new CachedValue($value);
    }

    /**
     * 写入 Transient。
     */
    protected function writeTransient(string $key, $value, int $ttl): bool
    {
        if (!function_exists('set_transient')) {
            return false;
        }
        if ($ttl === 0) {
            // Transient 不支持永不过期，用一年代替
            $ttl = 31536000;
        }

        return (bool) set_transient($key, $value, $ttl);
    }

    /**
     * 删除 Transient。
     */
    protected function deleteTransient(string $key): bool
    {
        if (!function_exists('delete_transient')) {
            return false;
        }

        return (bool) delete_transient($key);
    }

    /**
     * 写入指定层级。
     *
     * @param string $key   完整键。
     * @param mixed  $value 值。
     * @param int    $ttl   有效期。
     */
    protected function writeLayer(string $layer, string $key, $value, int $ttl): bool
    {
        switch ($layer) {
            case Metrics::LAYER_MEMORY:
                $this->memory[$key] = $value;

                return true;

            case Metrics::LAYER_OBJECT:
                return $this->writeObjectCache($key, $value, $ttl);

            case Metrics::LAYER_TRANSIENT:
                return $this->writeTransient($key, $value, $ttl);

            case Metrics::LAYER_FILE:
                return $this->fileStore !== null && $this->fileStore->set($key, $value, $ttl);

            default:
                return false;
        }
    }

    /**
     * 命中下层时回填上层。
     *
     * 只回填「比命中层更靠前」的层，避免重复写入下层。
     *
     * @param string $key       完整键。
     * @param mixed  $value     值。
     * @param string $hitLayer  命中的层级。
     * @param int|null $ttl     有效期。
     */
    protected function fillUpperLayers(string $key, $value, string $hitLayer, ?int $ttl = null): void
    {
        $ttl   = $ttl === null ? $this->defaultTtl : max(0, $ttl);
        $index = array_search($hitLayer, $this->layers, true);
        if ($index === false || $index <= 0) {
            return;
        }

        for ($i = 0; $i < $index; $i++) {
            $layer = $this->layers[$i];
            // 内存层只回填未设置过的键
            if ($layer === Metrics::LAYER_MEMORY) {
                if (!array_key_exists($key, $this->memory)) {
                    $this->memory[$key] = $value;
                }
                continue;
            }
            $this->writeLayer($layer, $key, $value, $ttl);
        }
    }

    /**
     * 生成未命中哨兵值。
     *
     * 用唯一字符串而非 null，是为了区分「缓存里存的确实是 null」
     * 与「没有命中」这两种语义上都该重新计算的情况。
     *
     * @param string $key 原始键（不含前缀）。
     */
    protected function sentinelFor(string $key): string
    {
        return '__morn_miss_' . md5($this->prefix . $key) . '__';
    }

/**
     * 宽松反序列化：锁的回调之间传递数据用。
     *
     * @param string $raw serialize 后的字符串。
 * @return mixed
     */
    protected function unserializeLoose($raw)
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        // 关键：unserialize() 失败时返回 false，而 serialize(false) 的结果
        // 'b:0;' 反序列化后**也是** false。二者无法用返回值区分，
        // 必须像 Serializer 那样比对原始串来判定。
        $value = @unserialize($raw, ['allowed_classes' => false]);

        if ($value === false && $raw !== 'b:0;') {
            return null;
        }

        return $value;
    }
}
