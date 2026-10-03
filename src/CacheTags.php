<?php
/**
 * 缓存标签失效机制。
 *
 * 解决「批量清理」问题：缓存了 100 个页面的数据，
 * 更新一篇文章后需要把这 100 个页面全部失效 —— 但它们的 key 事先并不知道。
 *
 * 做法：
 * 1. 写入时通过 tag 反查表（tag => [key1, key2, ...]）登记归属关系；
 * 2. 失效时按 tag 查出所有 key 并批量删除；
 * 3. 反查表自身有过期时间，避免无限增长；
 * 4. 支持多标签组合（AND / OR），与 WordPress 对象缓存 group 无关，
 *    完全由本类自管，可与任意存储后端配合。
 *
 * @package MornRain\CacheForge
 */

declare(strict_types=1);

namespace MornRain\CacheForge;

/**
 * 标签失效管理器。
 */
class CacheTags
{
    /** 反查表键前缀 */
    public const INDEX_PREFIX = 'morn_tagidx_';

    /** 反查表默认有效期（秒） */
    public const INDEX_TTL = 86400;

    /** @var string 键前缀 */
    protected $prefix;

    /** @var callable|null 键到标签的映射函数 */
    protected $tagResolver;

    /** @var array<string,bool> 本请求内登记过的标签，供 gc() 遍历 */
    protected $knownTags = [];

    /**
     * 构造函数。
     *
     * @param string          $prefix      所有缓存键的统一前缀。
     * @param callable|null   $tagResolver 标签推导函数，接收原始键返回标签数组。
     */
    public function __construct(string $prefix = 'morn_', ?callable $tagResolver = null)
    {
        $this->prefix = $prefix === '' ? 'morn_' : $prefix;
        if ($tagResolver !== null) {
            $this->tagResolver = $tagResolver;
        }
    }

    /**
     * 推导某个键的标签集合。
     *
     * 未设置 resolver 时，尝试从键名中提取形如 `key:{tag}:xxx` 的段。
     *
     * @param string $key 缓存键。
     * @return array<int,string>
     */
    public function tagsFor(string $key): array
    {
        if ($this->tagResolver !== null) {
            // 直接调用可变 callable，无需 call_user_func 包装。
            // 这里的 callable 来自开发者传入的配置，不是外部输入。
            $tags = (array) ($this->tagResolver)($key);
            $clean = [];
            foreach ($tags as $tag) {
                $tag = $this->sanitizeTag((string) $tag);
                if ($tag !== '') {
                    $clean[] = $tag;
                }
            }

            return array_values(array_unique($clean));
        }

        // 默认约定：post:123:related → 标签 post:123
        if (preg_match('/^([a-z_]+:[^:]+):/', $key, $m) === 1) {
            return [$m[1]];
        }

        return [];
    }

    /**
     * 登记键到标签的归属。
     *
     * @param string            $key   缓存键。
     * @param array<int,string> $tags  标签列表。
     * @param FileStore         $store 用于持久化反查表的存储。
     * @return int 登记的标签数。
     */
    public function register(string $key, array $tags, FileStore $store): int
    {
        $tags = array_values(array_unique(array_filter(array_map([$this, 'sanitizeTag'], $tags))));
        if ($tags === []) {
            return 0;
        }

        $registered = 0;
        foreach ($tags as $tag) {
            $indexKey = self::INDEX_PREFIX . $tag;
            $members  = $store->get($indexKey, []);
            if (!is_array($members)) {
                $members = [];
            }

            $members[$key] = time();
            // 限制单个标签下的键数量，避免热标签索引膨胀
            if (count($members) > 5000) {
                $members = array_slice($members, -5000, null, true);
            }
            $store->set($indexKey, $members, self::INDEX_TTL);
            $this->knownTags[$tag] = true;
            $registered++;
        }

        return $registered;
    }

    /**
     * 取得某标签下的全部键。
     *
     * @param string    $tag   标签。
     * @param FileStore $store 存储。
     * @return array<int,string>
     */
    public function keysFor(string $tag, FileStore $store): array
    {
        $indexKey = self::INDEX_PREFIX . $this->sanitizeTag($tag);
        $members  = $store->get($indexKey, []);

        return is_array($members) ? array_keys($members) : [];
    }

    /**
     * 按标签批量失效。
     *
     * @param string|array<int,string> $tags  一个或多个标签（OR 关系）。
     * @param FileStore $store 存储。
     * @return array{deleted:int,keys:array<int,string>}
     */
    public function flushTags($tags, FileStore $store): array
    {
        $tags = is_array($tags) ? $tags : [$tags];
        $keys = [];

        foreach ($tags as $tag) {
            foreach ($this->keysFor((string) $tag, $store) as $key) {
                $keys[$key] = true;
            }
            // 反查表本身一并删除
            $store->delete(self::INDEX_PREFIX . $this->sanitizeTag((string) $tag));
        }

        $deleted = 0;
        foreach (array_keys($keys) as $key) {
            if ($store->delete((string) $key)) {
                $deleted++;
            }
        }

        return ['deleted' => $deleted, 'keys' => array_keys($keys)];
    }

    /**
     * 按标签组合失效。
     *
     * 语义为 `(命中全部 includeTags) AND (命中任一 anyOfTags)`；
     * includeTags 为空时退化为 anyOfTags 的并集，
     * anyOfTags 为空时退化为 includeTags 的并集。
     *
     * @param array<int,string> $includeTags 必须全部命中（AND）的标签。
     * @param array<int,string> $anyOfTags   任一命中（OR）的标签。
     * @param FileStore         $store       存储。
     * @return array{deleted:int,keys:array<int,string>}
     */
    public function flushWhere(array $includeTags = [], array $anyOfTags = [], FileStore $store): array
    {
        // 语义：(命中全部 include 标签) AND (命中任一 anyOf 标签)。
        //
        // 注意：include 非空时，最终集合必须**从 include 的键出发**，
        // 再用 anyOf 过滤；若反过来先合并 anyOf 再用 include 过滤，
        // 会把「只命中 anyOf、不命中 include」的键也保留下来，导致误删。
        $keys = [];

        if ($includeTags === []) {
            // 没有 AND 条件时，anyOf 的并集就是结果
            foreach ($anyOfTags as $tag) {
                foreach ($this->keysFor((string) $tag, $store) as $key) {
                    $keys[$key] = true;
                }
            }
        } else {
            foreach ($includeTags as $tag) {
                foreach ($this->keysFor((string) $tag, $store) as $key) {
                    $keys[$key] = true;
                }
            }

            if ($anyOfTags !== []) {
                $allowed = [];
                foreach ($anyOfTags as $tag) {
                    foreach ($this->keysFor((string) $tag, $store) as $key) {
                        $allowed[$key] = true;
                    }
                }
                $keys = array_intersect_key($keys, $allowed);
            }
        }

        $deleted = 0;
        foreach (array_keys($keys) as $key) {
            if ($store->delete((string) $key)) {
                $deleted++;
            }
        }

        // 清理涉及到的反查表
        foreach (array_merge($includeTags, $anyOfTags) as $tag) {
            $store->delete(self::INDEX_PREFIX . $this->sanitizeTag((string) $tag));
        }

        return ['deleted' => $deleted, 'keys' => array_keys($keys)];
    }

    /**
     * 判断键是否同时归属于全部给定标签。
     *
     * @param string            $key   缓存键。
     * @param array<int,string> $tags  标签列表。
     * @param FileStore         $store 存储。
     */
    protected function matchesAll(string $key, array $tags, FileStore $store): bool
    {
        foreach ($tags as $tag) {
            if (!in_array($key, $this->keysFor((string) $tag, $store), true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 清理过期的反查表条目。
     *
     * 反查表以键存在，但键名已被 md5 化，无法从文件名反推标签。
     * 因此本方法接收标签清单逐一检查：
     * - 不传时只清理本实例已登记过的标签（本请求内有效）；
     * - 由外部持久化标签清单时，可覆盖跨请求的全量清理需求。
     *
     * @param FileStore          $store 存储。
     * @param array<int,string>|null $tags 待检查的标签列表，null 表示用本实例记录的。
     * @param int                $ttl   条目存活时间（秒）。
     * @return int 清理条目数。
     */
    public function gc(FileStore $store, ?array $tags = null, int $ttl = self::INDEX_TTL): int
    {
        $tags = $tags === null ? array_keys($this->knownTags) : $tags;

        $cleaned = 0;
        $now     = time();

        foreach ($tags as $tag) {
            $indexKey = self::INDEX_PREFIX . $this->sanitizeTag((string) $tag);
            $members  = $store->get($indexKey, []);
            if (!is_array($members) || $members === []) {
                continue;
            }

            $kept = array_filter($members, static function ($ts) use ($now, $ttl): bool {
                return is_int($ts) && ($now - $ts) < $ttl;
            });

            if (count($kept) === count($members)) {
                continue;
            }

            $cleaned += count($members) - count($kept);
            if ($kept === []) {
                $store->delete($indexKey);
            } else {
                $store->set($indexKey, $kept, self::INDEX_TTL);
            }
        }

        return $cleaned;
    }

/**
 * 标签名清洗：只保留安全字符。
 *
 * 标签名会参与构造反查表的存储键（INDEX_PREFIX . $tag），
 * 因此必须严格清洗：不同输入必须映射到**互不相同**的标签，
 * 否则会出现「A 与 B 共用同一份反查表」的串扰，
 * 进而导致 flushTags 误删不该删的缓存。
 */
    public function sanitizeTag(string $tag): string
    {
        $tag = trim($tag);
        if ($tag === '') {
            return '';
        }

        // 先把连续的点与路径分隔符压成单个下划线，避免 a/../b 与 a_b 撞车
        $tag = (string) preg_replace('/[.\/\\\\]+/', '_', $tag);
        $tag = (string) preg_replace('/[^A-Za-z0-9_:.\-]/', '', $tag);

        return substr($tag, 0, 100);
    }

    /**
     * 取得键前缀。
     */
    public function prefix(): string
    {
        return $this->prefix;
    }
}
