# MornRain Cache Forge

多级缓存封装。内存 → 对象缓存 → Transient → 文件 → 默认值，五级读取、自上而下填充，附带缓存击穿锁、标签失效、原子写入与命中率统计。

[![PHP](https://img.shields.io/badge/php-%3E%3D7.4-8892BF.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![Version](https://img.shields.io/badge/version-1.0.1-blue.svg)](CHANGELOG.md)
[![Tests](https://img.shields.io/badge/tests-406%20passed-success.svg)](tests/)
[![PHPStan](https://img.shields.io/badge/static%20analysis-clean-brightgreen.svg)](CONTRIBUTING.md)

## 简介

「加个缓存」听起来简单，实际要处理的问题有一串：

- 该加在哪一层？`get_transient` 慢但能跨请求，`wp_cache_get` 快但可能没配对象缓存；
- 热门文章缓存刚过期，100 个并发请求同时去查数据库 —— **缓存击穿**；
- 改了文章，得把首页、归档页、侧栏、相关推荐、站点地图的缓存全清掉，
  但这些 key 事先根本不知道 —— **批量失效**；
- 写文件时另一个进程正在读，读到半截内容 —— **原子性**；
- 缓存到底生效了没有？命中率多少？哪些 key 白缓存了？—— **可观测性**。

这个库把这五件事都做了，且不引入任何依赖：

| 问题 | 本库的做法 |
| --- | --- |
| 层级选择 | 门面统一处理，按 WordPress / 纯 PHP 环境自动选择启用层 |
| 缓存击穿 | `StampedeLock` 保证同一时刻只有一个请求执行昂贵回调 |
| 批量失效 | `CacheTags` 维护 tag → keys 反查表，按标签一次清完 |
| 原子性 | `FileStore` 用「临时文件 + rename」写入，并发读永远完整 |
| 可观测性 | `Metrics` 分层统计命中率、热点键、有写无读的浪费键 |

## 特性

| 能力 | 说明 |
| --- | --- |
| 五级读取 | 内存 → 对象缓存 → Transient → 文件 → 默认值，命中即返回 |
| 自动回填 | 命中下层时自动写回上层，下一次直接命中第一层 |
| 击穿保护 | `rememberLocked()` + 令牌锁 + 随机抖动 + 超时降级 |
| 原子写入 | 临时文件 + `rename`，配合 `flock` 排他锁 |
| 惰性过期 | 读时判 mtime，避免读出多余内容；`gc()` 主动清理 |
| 标签失效 | `flushTags()` 一次清掉一组 key；`flushWhere()` 支持 AND / OR 组合 |
| JSON 优先 | 缓存文件可直接 `jq` 排查，JSON 失败自动回退原生格式 |
| 安全反序列化 | 默认 `allowed_classes => false`，阻断对象注入 |
| null 语义正确 | 唯一哨兵机制，「值为 null / false」不会被误判成未命中 |
| 命中率统计 | 分层统计 + 热点键 + 浪费键识别 |
| 零依赖 | 只用 PHP 标准库，WordPress 内外都能跑 |

## 安装

```bash
composer require mornrain/morn-cache-forge
```

或手动引入：

```php
require_once __DIR__ . '/morn-cache-forge/src/Compat.php';
require_once __DIR__ . '/morn-cache-forge/src/Serializer.php';
require_once __DIR__ . '/morn-cache-forge/src/CachedValue.php';
require_once __DIR__ . '/morn-cache-forge/src/FileStore.php';
require_once __DIR__ . '/morn-cache-forge/src/StampedeLock.php';
require_once __DIR__ . '/morn-cache-forge/src/CacheTags.php';
require_once __DIR__ . '/morn-cache-forge/src/Metrics.php';
require_once __DIR__ . '/morn-cache-forge/src/CacheForge.php';
```

## 快速开始

### 初始化

```php
use MornRain\CacheForge\CacheForge;

$cache = new CacheForge([
    'prefix'    => 'mysite_',              // 键前缀，多站点下务必区分
    'ttl'       => HOUR_IN_SECONDS,        // 默认有效期
    'directory' => WP_CONTENT_DIR . '/cache/morn',   // 文件层目录
]);

// WordPress 环境自动启用 memory → object → transient → file
// 纯 PHP 环境自动启用 memory → file
```

### 基本读写

```php
$cache->set('user:1', ['name' => '林墨', 'role' => 'admin']);
$user = $cache->get('user:1');
$user = $cache->get('user:999', $defaultUser);   // 未命中返回默认值

$cache->has('user:1');     // bool
$cache->delete('user:1');  // 清掉所有层
$cache->increment('views:post:42');   // 原子自增，返回 1
```

### remember 回填

```php
// 回调只执行一次，之后全部命中缓存
$posts = $cache->remember('posts:recent:10', function () {
    return get_posts(['numberposts' => 10]);
}, 600);
```

### 防击穿（回调昂贵时用这个）

```php
// 外部 API、复杂聚合查询等场景
$report = $cache->rememberLocked('report:monthly', function () {
    return expensive_external_api_call();
}, [
    'ttl'      => 3600,
    'lock_ttl' => 10,     // 锁最长持有 10 秒（回调超时则自动释放）
    'wait'     => 3.0,    // 未抢到锁时最多等 3 秒
]);
```

保证：100 个并发同时 miss，只有一个请求去调 API，其余 99 个等结果。

### 标签批量失效

```php
// 写入时打标签
$cache->set('page:home', $html, 300, ['post:42', 'page:all']);
$cache->set('page:archive', $html, 300, ['post:42', 'page:all']);
$cache->set('page:about', $html, 300, ['page:all']);

// 更新文章 42 后，一次清掉所有相关缓存
$cache->flushTags('post:42');   // home、archive 消失，about 保留

// 组合条件
$cache->flushWhere(['post:42', 'page:all']);   // AND：两个标签都命中
$cache->flushWhere([], ['post:42', 'post:43']); // OR：任一命中
```

### 清理与统计

```php
$cache->gc();       // 清理各层过期数据 + 过期锁 + 标签索引
$cache->flush();    // 清空所有层

echo $cache->report()['hit_rate_human'];   // 优秀 / 良好 / 偏低 / 很差
print_r($cache->metrics()->layers());      // 分层命中率
print_r($cache->metrics()->hotKeys(10));   // 热点键
print_r($cache->metrics()->uselessKeys()); // 有写无读的浪费键
```

### 在 WordPress 中挂钩

```php
// 保存文章后清理相关缓存
add_action('save_post', function (int $postId, WP_Post $post): void {
    if (wp_is_post_revision($postId) || $post->post_status !== 'publish') {
        return;
    }
    $cache = new CacheForge(['prefix' => 'mysite_', 'ttl' => HOUR_IN_SECONDS]);
    $cache->flushTags('post:' . $postId);
    $cache->flushTags('cat:' . implode(',', wp_get_post_categories($postId)));
    $cache->delete('posts:recent:10');
}, 10, 2);

// 接入对象缓存持久化
add_action('wp_cache_init', function (): void {
    global $wp_object_cache;
    if (method_exists($wp_object_cache, 'add_global_groups')) {
        $wp_object_cache->add_global_groups(['morn_cache']);
    }
});
```

## 层级说明

| 层级 | 后端 | 共享范围 | 典型耗时 | 适用 |
| --- | --- | --- | --- | --- |
| `memory` | PHP 数组 | 本请求 | ~0.0001 ms | 同一请求内重复读 |
| `object` | `wp_cache_*` | 全站（取决于后端） | 0.1~2 ms | 有 Redis / Memcached 时最优 |
| `transient` | `get_transient` | 全站 | 1~10 ms | 无对象缓存时的跨请求共享 |
| `file` | `FileStore` | 全站（单机） | 0.5~5 ms | 纯 PHP 环境兜底 |
| `default` | 无 | — | — | 全部未命中 |

**为什么写入要自上而下？** 让每层都成为热缓存。下次读取在第一层就命中，
不必穿透到文件。同理，命中下层时会自动回填上层。

**为什么 `file` 排在 `transient` 之后？** Transient 走数据库存储，
在无对象缓存的站点上可能比文件 IO 慢。FileStore 全是本地 IO，可预测。

**多机部署注意：** 文件层不跨机器。集群部署请把 `file` 层换成
Redis / Memcached（用 `layers` 只启用 `memory` + `object`）。

## API 一览表

### `CacheForge`

| 方法 | 签名 | 说明 |
| --- | --- | --- |
| `__construct` | `(array $config = [])` | 见下方配置项 |
| `get` | `(string $key, $default = null, ?int $ttl = null)` | 多级读取 |
| `set` | `(string $key, $value, ?int $ttl = null, ?array $tags = null): bool` | 多级写入 |
| `remember` | `(string $key, callable $cb, ?int $ttl = null, ?array $tags = null)` | 未命中时回填 |
| `rememberLocked` | `(string $key, callable $cb, array $options = [])` | 带击穿保护的回填 |
| `delete` | `(string $key): bool` | 清掉所有层 |
| `has` | `(string $key, ?int $ttl = null): bool` | 是否存在 |
| `increment` | `(string $key, int $by = 1, ?int $ttl = null)` | 原子自增 |
| `flushTags` | `($tags): array` | 按标签批量失效 |
| `flushWhere` | `(array $includeTags = [], array $anyOfTags = []): array` | 组合失效 |
| `gc` | `(?int $ttl = null): array` | 清理过期数据与锁 |
| `flush` | `(bool $withLocks = false): array` | 清空所有层 |
| `metrics` | `(): Metrics` | 指标统计器 |
| `tags` | `(): CacheTags` | 标签管理器 |
| `lock` | `(): StampedeLock` | 击穿锁 |
| `fileStore` | `(): ?FileStore` | 文件层 |
| `serializer` | `(): Serializer` | 序列化器 |
| `layers` | `(): array` | 启用层级 |
| `fillDown` | `(bool $enabled = true): self` | 是否写入时填充所有层 |
| `setDefaultTtl` | `(int $ttl): self` | 设置默认 TTL |
| `report` | `(): array` | 完整诊断报告 |

**构造配置项：**

| 键 | 类型 | 默认 | 说明 |
| --- | --- | --- | --- |
| `prefix` | string | `'morn_'` | 键前缀 |
| `ttl` | int | `3600` | 默认有效期（秒） |
| `directory` | string | 系统临时目录 | 文件层目录 |
| `layers` | array | 按环境自动 | 启用层级 |
| `lock_dir` | string | `<directory>/locks` | 锁目录 |
| `lock_ttl` | int | `10` | 锁默认有效期 |
| `lock_jitter` | float | `0.2` | 锁过期抖动比例 |
| `serializer` | Serializer | 新实例 | 序列化器 |
| `metrics` | Metrics | 新实例 | 指标统计器 |
| `fill_down` | bool | `true` | 是否填充所有层 |
| `tag_resolver` | callable | null | 键 → 标签的推导函数 |

### `FileStore`

| 方法 | 签名 | 说明 |
| --- | --- | --- |
| `__construct` | `(string $directory, ?Serializer $s = null, int $defaultTtl = 3600)` | 自动建目录 |
| `get` / `set` | `(string $key, $value, ?int $ttl = null)` | 读写 |
| `getRaw` / `setRaw` | `(string $key, string $value)` | 绕过序列化的原始读写 |
| `delete` / `has` | `(string $key, ?int $ttl = null): bool` | 删除 / 判断 |
| `increment` | `(string $key, int $by = 1, ?int $ttl = null)` | 原子自增 |
| `flush` | `(bool $includeTemp = true): int` | 清空 |
| `gc` | `(?int $ttl = null, int $limit = 0): array` | 清理过期 |
| `files` / `stats` | `(): array` | 文件列表 / 占用统计 |
| `directory` / `serializer` | — | 访问器 |

### `StampedeLock`

| 方法 | 签名 | 说明 |
| --- | --- | --- |
| `__construct` | `(string $lockDir, int $defaultTtl = 10, float $jitterRatio = 0.2)` | 锁目录 |
| `remember` | `(string $key, callable $cb, callable $readHit, array $options = [])` | 带锁执行 |
| `acquire` | `(string $key, int $ttl = 0): ?string` | 加锁，返回令牌 |
| `release` | `(string $key, string $token): bool` | 释放（校验令牌） |
| `isLocked` / `forceRelease` | `(string $key): bool` | 状态查询 / 强解 |
| `gc` / `stats` | `(): int` / `(): array` | 清理 / 统计 |

### `Serializer`

| 方法 | 签名 | 说明 |
| --- | --- | --- |
| `__construct` | `(string $format = 'json', bool $allowClasses = false)` | 格式与安全开关 |
| `useFormat` / `allowClasses` | — | 切换配置 |
| `encode` | `($value): string` | 序列化 |
| `decode` | `(string $raw, $default = null)` | 反序列化 |

### `CacheTags`

| 方法 | 签名 | 说明 |
| --- | --- | --- |
| `__construct` | `(string $prefix = 'morn_', ?callable $tagResolver = null)` | 标签推导 |
| `tagsFor` | `(string $key): array` | 推导键的标签 |
| `register` | `(string $key, array $tags, FileStore $store): int` | 登记归属 |
| `keysFor` | `(string $tag, FileStore $store): array` | 标签下的键 |
| `flushTags` | `($tags, FileStore $store): array` | 按标签失效 |
| `flushWhere` | `(array $includeTags, array $anyOfTags, FileStore $store): array` | 组合失效 |
| `gc` | `(FileStore $store, ?array $tags = null, int $ttl = 86400): int` | 清理索引 |
| `sanitizeTag` | `(string $tag): string` | 标签名清洗 |

### `Metrics`

| 方法 | 签名 | 说明 |
| --- | --- | --- |
| `recordRead` | `(string $key, bool $hit, string $layer = '', float $cost = 0.0)` | 记录读取 |
| `recordWrite` / `recordDelete` | `(string $key, string $layer = '')` | 记录写 / 删 |
| `hitRate` / `hitRateOf` | `(): float` | 命中率 |
| `layers` | `(): array` | 分层明细 |
| `report` / `summary` | `(): array` / `(): string` | 报告 |
| `hotKeys` / `uselessKeys` | `(int $limit = 10)` | 热点键 / 浪费键 |
| `readsOf` / `hitsOf` / `writesOf` / `deletesOf` / `averageCostOf` | — | 单键查询 |
| `reset` | `(): self` | 清空统计 |

## Hook / 扩展点

本库**不注册任何 WordPress Hook**（Hook 由调用方按业务时机挂载）。
所有扩展通过继承或配置完成：

```php
use MornRain\CacheForge\CacheForge;

/** 站点定制：键自动带站点前缀，多站点互不干扰 */
final class SiteCache extends CacheForge
{
    public function __construct()
    {
        parent::__construct([
            'prefix'    => 'site_' . get_current_blog_id() . '_',
            'ttl'       => HOUR_IN_SECONDS,
            'directory' => WP_CONTENT_DIR . '/cache',
        ]);
    }
}

/** 站点定制：写入时自动打上文章与分类标签 */
final class PostCache
{
    /** @var CacheForge */
    private $cache;

    public function __construct()
    {
        $this->cache = new CacheForge([
            'prefix'    => 'mysite_',
            'directory' => WP_CONTENT_DIR . '/cache',
        ]);
    }

    public function get(int $postId)
    {
        return $this->cache->get('post:' . $postId);
    }

    public function put(int $postId, $data): bool
    {
        return $this->cache->set('post:' . $postId, $data, HOUR_IN_SECONDS, $this->tagsOf($postId));
    }

    public function forget(int $postId): void
    {
        $this->cache->flushTags($this->tagsOf($postId));
    }

    /** @return array<int,string> */
    private function tagsOf(int $postId): array
    {
        $tags = ['post:' . $postId, 'type:post'];
        foreach (wp_get_post_categories($postId) as $termId) {
            $tags[] = 'cat:' . $termId;
        }

        return $tags;
    }
}
```

更推荐用 `tag_resolver` 配置项，无需继承：

```php
$cache = new CacheForge([
    'tag_resolver' => function (string $key): array {
        // page:post:42:sidebar → ['post:42', 'page:all']
        $tags = ['page:all'];
        if (preg_match('/post:(\d+)/', $key, $m)) {
            $tags[] = 'post:' . $m[1];
        }
        return $tags;
    },
]);

$cache->set('page:post:42:sidebar', $html, 300, $cache->tags()->tagsFor('page:post:42:sidebar'));
```

## FAQ

**Q：为什么不用 `wp_cache_*` 就够了？**
很多站点没配对象缓存，`wp_cache_*` 会退化成 Transient 的包装，
等于还是查数据库。文件层在无对象缓存时反而更快（本地 IO vs 数据库查询），
且不占数据库连接。集群部署再把 `file` 换成 Redis。

**Q：`file` 层能跨服务器共享吗？**
不能。文件缓存是单机本地的。集群部署请：
`new CacheForge(['layers' => ['memory', 'object']])`，
并确保对象缓存后端是 Redis / Memcached。

**Q：缓存击穿具体怎么防的？**
`rememberLocked()` 的流程：

1. 先正常读一次，命中直接返回（绝大多数请求走这条）；
2. 未命中则用 `fopen($path, 'x')` 独占创建锁文件 —— 操作系统保证只有一个进程成功；
3. 抢到锁的执行回调，写入缓存后释放锁；
4. 没抢到的轮询等待（默认 3 秒，每 30 ms 查一次），期间对方写好了就直接读；
5. 超时还没拿到，降级：用 `stale` 值（若有）或自行执行回调。

**Q：为什么锁要加随机抖动？**
不加抖动的话，同一批写入的锁会在同一毫秒到期，
形成「锁惊群」—— 一万个请求同时发现锁消失，同时抢锁。
默认 ±20% 的随机化把到期时间打散。

**Q：`unserialize` 有对象注入风险，为什么还要保留？**
JSON 覆盖不了所有场景（比如 INF、极端嵌套）。回退时用
`allowed_classes => false`，只还原标量与数组，**不实例化任何类**，
因此对象注入无法利用。确实需要存对象时，调用方须自行确认数据来源可信
并显式 `$serializer->allowClasses(true)`。

**Q：缓存文件能被直接查看吗？**
可以。默认 JSON 编码，`cat xxx.cache | jq .` 就能看，
排查缓存问题不需要写 PHP 脚本。

**Q：`remember()` 和 `rememberLocked()` 怎么选？**
回调便宜（读 wp_options、算一个日期）用 `remember()`，少一层锁开销。
回调昂贵（外部 API、多表 join）用 `rememberLocked()`。
拿不准就用后者，多一次文件创建的开销可以忽略。

**Q：`set()` 的 `$tags` 参数和 `tag_resolver` 冲突吗？**
不冲突。`tag_resolver` 用于自动推导，`$tags` 用于显式指定，
两者最终合并。推荐：固定标签用 `$tags`，模式化标签用 `tag_resolver`。

**Q：命中率多少算正常？**
- 90% 以上：优秀，缓存设计合理；
- 70%~90%：良好，有优化空间；
- 40%~70%：偏低，检查 TTL 是否过短、key 是否过多；
- 低于 40%：很差，缓存可能基本没生效。

`report()['hit_rate_human']` 会直接给出评级。
注意 `uselessKeys()` 非空说明有一堆「只写不读」的 key，纯浪费存储。

**Q：`gc()` 什么时候调用？**
- 挂在 WP-Cron 上每天一次；
- 或在写入时按概率触发（如 1% 机会）避免锁竞争。
不要每次请求都调用。

**Q：忘记设置 `prefix` 会怎样？**
多站点环境下不同站点的缓存会互相覆盖，导致内容错乱。
生产环境务必设置带站点标识的前缀。

## 目录说明

```
morn-cache-forge/
├── README.md
├── LICENSE
├── CHANGELOG.md
├── composer.json
├── .gitignore
├── .gitattributes
├── src/
│   ├── Compat.php        # 随机数 / 进程 ID 垫片
│   ├── Serializer.php    # JSON 优先 + 安全反序列化
│   ├── CachedValue.php   # Transient 层值包装（区分 false 与未命中）
│   ├── FileStore.php     # 文件存储：原子写入 / 锁 / 惰性过期 / gc
│   ├── StampedeLock.php  # 击穿锁：令牌 + 抖动 + 等待降级
│   ├── CacheTags.php     # 标签反查表与批量失效
│   ├── Metrics.php       # 分层命中率统计
│   └── CacheForge.php    # 门面：多级读写编排
├── tests/                   # 单元测试 + 零依赖运行器
│   ├── run-tests.php        # 零依赖测试运行器
│   ├── TestCase.php         # 断言（兼容 PHPUnit / 独立运行）
│   └── bootstrap.php        # PHPUnit 引导
├── phpunit.xml.dist         # PHPUnit 配置
├── phpcs.xml.dist           # PSR-12 代码风格
├── CONTRIBUTING.md          # 贡献指南
├── SECURITY.md              # 安全策略
└── examples/
    └── cache.php         # 11 个场景可运行示例
```

## 测试

本库提供两条等价的测试路径，用同一份用例：

```bash
# 零依赖方式，不需要 composer install
php tests/run-tests.php

# 只跑名称含某关键字的用例
php tests/run-tests.php robots

# 装了 PHPUnit 时
composer test          # 走 vendor/bin/phpunit
composer lint          # php -l 逐文件语法检查
composer lint:style    # PSR-12 代码风格
```

用例覆盖正常路径、边界情况（空值 / 零与负数 / 超长输入 / 多字节与 emoji）
与安全路径（注入、XSS、路径穿越、令牌篡改、重放）。
修bug 时请一并补上能复现该问题的断言。

参与贡献请阅读 [CONTRIBUTING.md](CONTRIBUTING.md)；
发现安全问题请**不要**公开提issue，参见 [SECURITY.md](SECURITY.md)。

## License

MIT License — Copyright (c) 2026 MornRain

详见 [LICENSE](LICENSE)。

本库不做任何网络请求。缓存数据仅存储在调用方指定的目录、
WordPress 对象缓存或 Transient 中，不外传。
反序列化默认禁用类实例化。
