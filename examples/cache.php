<?php
/**
 * morn-cache-forge 使用示例。
 *
 * 运行方式（CLI）：
 *   php examples/cache.php
 *
 * 覆盖多级读写、序列化回退、令牌桶、击穿锁、标签失效、gc 与指标统计。
 * 本示例不依赖 WordPress，文件层会自动落在系统临时目录。
 */

declare(strict_types=1);

require __DIR__ . '/../src/Compat.php';
require __DIR__ . '/../src/Serializer.php';
require __DIR__ . '/../src/FileStore.php';
require __DIR__ . '/../src/StampedeLock.php';
require __DIR__ . '/../src/CacheTags.php';
require __DIR__ . '/../src/Metrics.php';
require __DIR__ . '/../src/CachedValue.php';
require __DIR__ . '/../src/CacheForge.php';

use MornRain\CacheForge\CacheForge;
use MornRain\CacheForge\FileStore;
use MornRain\CacheForge\Metrics;
use MornRain\CacheForge\Serializer;
use MornRain\CacheForge\StampedeLock;

function section(string $title): void
{
    echo PHP_EOL . '=== ' . $title . ' ===' . PHP_EOL;
}

$cacheDir = sys_get_temp_dir() . '/morn-cache-demo';
// 每次运行从干净状态开始，便于观察
if (is_dir($cacheDir)) {
    (new FileStore($cacheDir))->flush();
} else {
    @mkdir($cacheDir, 0755, true);
}
echo '缓存目录: ' . $cacheDir . PHP_EOL;

$forge = new CacheForge([
    'prefix'    => 'demo_',
    'ttl'       => 60,
    'directory' => $cacheDir,
    'layers'    => [Metrics::LAYER_MEMORY, Metrics::LAYER_FILE],
]);

echo '启用层级: ' . implode(' → ', $forge->layers()) . PHP_EOL;
echo 'WordPress 环境: ' . var_export(MornRain\CacheForge\Compat::isWordPress(), true) . PHP_EOL;

/* ------------------------------------------------------------------ */
section('1. 基础读写');

$forge->set('user:1', ['name' => '林墨', 'role' => 'admin', 'login_count' => 42]);

$user = $forge->get('user:1');
echo '读 user:1 → ' . json_encode($user, JSON_UNESCAPED_UNICODE) . PHP_EOL;

// 第二次读命中内存层
$user2 = $forge->get('user:1');
echo '再读一次 → ' . json_encode($user2, JSON_UNESCAPED_UNICODE) . PHP_EOL;

echo 'has(user:1): ' . var_export($forge->has('user:1'), true) . PHP_EOL;
echo 'has(user:999): ' . var_export($forge->has('user:999'), true) . PHP_EOL;
echo '未命中返回默认值: ' . var_export($forge->get('user:999', 'fallback'), true) . PHP_EOL;

// 删除后内存层也清掉
$forge->delete('user:1');
echo '删除后 has(user:1): ' . var_export($forge->has('user:1'), true) . PHP_EOL;

/* ------------------------------------------------------------------ */
section('2. 多层穿透与回填');

$forge->flush();
echo '已清空所有层' . PHP_EOL;

// 直接写文件层，模拟「文件层有、内存层没有」的场景
$forge->fileStore()->set('demo_article:9', ['title' => '缓存击穿防护', 'views' => 1024]);
echo '已直接写入文件层 demo_article:9' . PHP_EOL;

$article = $forge->get('article:9');
echo '经门面读取（应命中文件层）→ ' . json_encode($article, JSON_UNESCAPED_UNICODE) . PHP_EOL;
echo '说明：命中下层后会自动回填内存层' . PHP_EOL;

// 验证内存层已被回填：直接读内存
$ref = new ReflectionProperty(CacheForge::class, 'memory');
$ref->setAccessible(true);
$memory = $ref->getValue($forge);
echo '内存层现有键: ' . implode(', ', array_keys($memory)) . PHP_EOL;

/* ------------------------------------------------------------------ */
section('3. remember() 回填');

$calls = 0;
$expensive = static function (string $key) use (&$calls): string {
    $calls++;

    return 'computed-' . $key . '-at-' . $calls;
};

$r1 = $forge->remember('compute:key', $expensive, 120);
$r2 = $forge->remember('compute:key', $expensive, 120);
$r3 = $forge->remember('compute:key', $expensive, 120);

echo '第 1 次: ' . $r1 . PHP_EOL;
echo '第 2 次: ' . $r2 . PHP_EOL;
echo '第 3 次: ' . $r3 . PHP_EOL;
echo '回调实际执行次数: ' . $calls . '（应为 1）' . PHP_EOL;

/* ------------------------------------------------------------------ */
section('4. Serializer：JSON 优先与自动回退');

$serializer = new Serializer();
echo '默认格式: ' . (new ReflectionProperty(Serializer::class, 'format'))->getName() . PHP_EOL;

$cases = [
    '字符串'        => 'hello 世界',
    '整数'          => 12345,
    '浮点'          => 3.14159,
    '布尔'          => true,
    'null'          => null,
    '数组'          => ['a' => 1, 'b' => [2, 3, 4]],
    '含中文的嵌套'   => ['标题' => '中文标题', '标签' => ['性能', '缓存']],
    '空数组'        => [],
];

foreach ($cases as $label => $value) {
    $encoded = $serializer->encode($value);
    $decoded = $serializer->decode($encoded, '<<解码失败>>');
    $same    = var_export($decoded, true) === var_export($value, true);
    printf(
        "  %-14s 编码 %6d 字节  回读一致: %s\n",
        $label,
        strlen($encoded),
        $same ? '是' : '否'
    );
}

// null 是一组合法缓存值，必须能被正确往返
$nullEncoded = $serializer->encode(null);
echo '  null 往返: 编码 ' . strlen($nullEncoded) . ' 字节，回读 === null: '
    . var_export($serializer->decode($nullEncoded, 'x') === null, true) . PHP_EOL;

// JSON 无法编码的场景：INF
$inf = INF;
$encodedInf = $serializer->encode($inf);
echo '  INF 编码 → ' . ($encodedInf === '' ? '空串（不可缓存）' : '回退原生格式，长度 ' . strlen($encodedInf)) . PHP_EOL;
if ($encodedInf !== '') {
    $back = $serializer->decode($encodedInf);
    echo '  INF 回读: ' . var_export(is_infinite((float) $back), true) . PHP_EOL;
}

// 损坏数据
echo '  损坏数据回读: ' . var_export($serializer->decode('{"broken":', '默认值'), true) . PHP_EOL;
echo '  空串回读: ' . var_export($serializer->decode('', '默认值'), true) . PHP_EOL;

// 切换到原生格式
$native = (new Serializer(Serializer::FORMAT_NATIVE));
$encoded = $native->encode(['x' => 1]);
echo '  原生格式回读: ' . json_encode($native->decode($encoded), JSON_UNESCAPED_UNICODE) . PHP_EOL;

/* ------------------------------------------------------------------ */
section('5. FileStore：原子写入、increment、gc');

$store = new FileStore($cacheDir, $serializer, 60);

// increment
echo 'increment(view:post) 第 1 次: ' . $store->increment('view:post', 1, 300) . PHP_EOL;
echo 'increment(view:post) 第 2 次: ' . $store->increment('view:post', 1, 300) . PHP_EOL;
echo 'increment(view:post) 第 3 次: ' . $store->increment('view:post', 5, 300) . PHP_EOL;

// 原子写入验证：写入后立刻读，内容必须完整
$big = str_repeat('A', 200000);
$store->set('big:blob', $big, 60);
$readBack = $store->get('big:blob', '');
echo '大对象写入后长度一致: ' . var_export(strlen($readBack) === 200000, true) . PHP_EOL;

// 短期键：TTL 设为 1 秒，验证惰性过期
$store->set('temp:key', 'temp-value', 1);
echo '刚写入 has(temp:key, ttl=1): ' . var_export($store->has('temp:key', 1), true) . PHP_EOL;
echo '刚写入 get(temp:key, 默认值, ttl=1): ' . var_export($store->get('temp:key', '已过期', 1), true) . PHP_EOL;
sleep(2);
echo '2 秒后 has(temp:key, ttl=1): ' . var_export($store->has('temp:key', 1), true) . PHP_EOL;
echo '2 秒后 get(temp:key, 默认值, ttl=1): ' . var_export($store->get('temp:key', '已过期', 1), true) . PHP_EOL;
echo '读时不传 ttl（沿用默认 60 秒）: ' . var_export($store->get('temp:key', 'MISS'), true) . PHP_EOL;
echo '→ 上面已返回 MISS：惰性过期判定通过后会把文件直接删除，因此后续读取一律未命中' . PHP_EOL;

// 单独用一个键证明「读取参数 ttl 优先于存储默认 TTL」
$store->set('temp:key2', 'value2', 1);
sleep(2);
echo '新键写入 TTL=1 秒，2 秒后不传 ttl 读取（用默认 60 秒）: '
    . var_export($store->get('temp:key2', 'MISS'), true) . '（值仍在）' . PHP_EOL;
echo '新键显式传 ttl=1 读取: '
    . var_export($store->get('temp:key2', 'MISS', 1), true) . '（判定过期）' . PHP_EOL;

// gc 演示：把 TTL 设为 0（永不过期）无法验证清理，改为显式短 TTL 扫描
$store->set('gc:a', 1, 1);
$store->set('gc:b', 2, 1);
sleep(2);
$gc = $store->gc(1);
echo 'gc(TTL=1s): 删除 ' . $gc['deleted'] . ' 个，扫描 ' . $gc['scanned'] . ' 个' . PHP_EOL;

$stats = $store->stats();
echo '文件统计: ' . $stats['files'] . ' 个文件，' . $stats['bytes_human'] . PHP_EOL;

$gc = $store->gc(60);
echo 'gc(60s): 删除 ' . $gc['deleted'] . ' 个，扫描 ' . $gc['scanned'] . ' 个' . PHP_EOL;

/* ------------------------------------------------------------------ */
section('6. StampedeLock：击穿保护');

$lockDir = $cacheDir . '/demo-locks';
$lock    = new StampedeLock($lockDir, 5, 0.2);

echo '锁目录当前状态: ' . json_encode($lock->stats()) . PHP_EOL;

$token = $lock->acquire('hot:key', 5);
echo '第 1 次 acquire: ' . ($token !== null ? '成功，令牌 ' . substr($token, 0, 12) . '…' : '失败') . PHP_EOL;

$token2 = $lock->acquire('hot:key', 5);
echo '第 2 次 acquire（同键）: ' . ($token2 !== null ? '成功' : '失败（已被占用）') . PHP_EOL;

echo 'isLocked: ' . var_export($lock->isLocked('hot:key'), true) . PHP_EOL;

$released = $lock->release('hot:key', $token2 ?? 'wrong-token');
echo '用错误令牌释放: ' . var_export($released, true) . '（应失败，保护他人锁）' . PHP_EOL;

$released = $lock->release('hot:key', (string) $token);
echo '用正确令牌释放: ' . var_export($released, true) . PHP_EOL;
echo '释放后 isLocked: ' . var_export($lock->isLocked('hot:key'), true) . PHP_EOL;

// 完整 remember 流程
$computeCount = 0;
$result = $lock->remember(
    'hot:computed',
    static function (string $k) use (&$computeCount): string {
        $computeCount++;
        usleep(50000);   // 模拟耗时计算

        return 'value-for-' . $k;
    },
    static function (string $k): string {
        return '';       // 模拟「还没写好」
    },
    ['ttl' => 5, 'wait' => 1.0]
);
echo 'remember 结果: ' . var_export($result, true) . '，回调执行 ' . $computeCount . ' 次' . PHP_EOL;

// 抖动验证
echo PHP_EOL . '抖动验证（TTL=10，比例 0.2，采样 8 次实际过期时间）:' . PHP_EOL;
$jitterLock = new StampedeLock($cacheDir . '/jitter-locks', 10, 0.3);
$expires = [];
for ($i = 0; $i < 8; $i++) {
    $t = $jitterLock->acquire('jitter:' . $i, 10);
    if ($t === null) {
        continue;
    }
    $raw = json_decode((string) file_get_contents($cacheDir . '/jitter-locks/' . md5('jitter:' . $i) . '.lock'), true);
    if (isset($raw['expire'])) {
        $expires[] = (int) $raw['expire'] - time();
    }
    $jitterLock->release('jitter:' . $i, $t);
}
echo '  实际 TTL: ' . implode(', ', $expires) . '（围绕 10 秒波动，末位不同即证明抖动生效）' . PHP_EOL;

/* ------------------------------------------------------------------ */
section('7. CacheTags：按标签批量失效');

// 模拟：文章 42 被 5 个页面缓存引用
$pages = ['home', 'archive', 'sidebar', 'related', 'sitemap'];
foreach ($pages as $page) {
    $forge->set('page:' . $page, ['rendered' => '<div>' . $page . '</div>', 'post' => 42], 300, ['post:42', 'page:all']);
}
echo '已写入 ' . count($pages) . ' 个页面缓存，均带标签 post:42 与 page:all' . PHP_EOL;

$tagKeys = $forge->tags()->keysFor('post:42', $forge->fileStore());
echo '标签 post:42 关联的键: ' . implode(', ', $tagKeys) . PHP_EOL;

// 无关缓存
$forge->set('page:other', ['rendered' => 'other'], 300, ['post:99']);
echo '额外写入 page:other（标签 post:99）' . PHP_EOL;

$flushed = $forge->flushTags('post:42');
echo '按 post:42 失效: 删除 ' . $flushed['deleted'] . ' 个键' . PHP_EOL;
echo 'page:home 是否还在: ' . var_export($forge->has('page:home'), true) . '（应 false）' . PHP_EOL;
echo 'page:other 是否还在: ' . var_export($forge->has('page:other'), true) . '（应 true）' . PHP_EOL;

/* ------------------------------------------------------------------ */
section('8. rememberLocked：带锁的回填');

$calls2 = 0;
$locked = static function (string $key) use (&$calls2): array {
    $calls2++;
    usleep(30000);

    return ['key' => $key, 'computed_at' => $calls2];
};

$v1 = $forge->rememberLocked('api:report', $locked, ['ttl' => 120, 'lock_ttl' => 5, 'wait' => 1.0]);
$v2 = $forge->rememberLocked('api:report', $locked, ['ttl' => 120, 'lock_ttl' => 5, 'wait' => 1.0]);

echo '第 1 次: ' . json_encode($v1, JSON_UNESCAPED_UNICODE) . PHP_EOL;
echo '第 2 次: ' . json_encode($v2, JSON_UNESCAPED_UNICODE) . PHP_EOL;
echo '昂贵回调执行次数: ' . $calls2 . '（应 1）' . PHP_EOL;

/* ------------------------------------------------------------------ */
section('9. Metrics 指标统计');

$metrics = $forge->metrics();
echo $metrics->summary() . PHP_EOL;

echo PHP_EOL . '分层明细:' . PHP_EOL;
foreach ($metrics->layers() as $layer => $stat) {
    printf(
        "  %-9s 请求 %3d，命中 %3d（%5.1f%%），写入 %3d\n",
        $layer,
        $stat['requests'],
        $stat['hits'],
        $stat['hit_rate'] * 100,
        $stat['writes']
    );
}

echo PHP_EOL . '整体命中率: ' . ($metrics->hitRate() * 100) . '%' . PHP_EOL;

/* ------------------------------------------------------------------ */
section('10. 完整报告');

$report = $forge->report();
echo '配置: ' . json_encode($report['config'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
echo '文件统计: ' . $report['file_stats']['files'] . ' 个文件，' . $report['file_stats']['bytes_human'] . PHP_EOL;
echo '锁状态: ' . json_encode($report['locks']) . PHP_EOL;
echo '命中率评级: ' . $report['hit_rate_human'] . PHP_EOL;

/* ------------------------------------------------------------------ */
section('11. 清理演示');

$gcResult = $forge->gc();
echo 'CacheForge::gc() → ' . json_encode($gcResult, JSON_UNESCAPED_UNICODE) . PHP_EOL;

$flushResult = $forge->flush(true);
echo 'CacheForge::flush() → ' . json_encode($flushResult, JSON_UNESCAPED_UNICODE) . PHP_EOL;
echo 'flush 后读 page:other: ' . var_export($forge->get('page:other', '已被清空'), true) . PHP_EOL;

// 收尾：删掉演示目录
if (is_dir($cacheDir)) {
    $cleanup = new FileStore($cacheDir);
    $cleanup->flush();
}

echo PHP_EOL . 'CacheForge 示例运行结束。' . PHP_EOL;
