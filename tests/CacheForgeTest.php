<?php
/**
 * * CacheForge 测试。
 *
 * 重点覆盖：
 * - 多级读写的正确性与 null/false 语义；
 * - 原子写入、惰性过期、目录缺失的降级；
 * - 击穿锁在异常路径下是否释放；
 * - 序列化器的对象注入防护。
 *
 * @package MornRain\CacheForge\Tests
 */

declare(strict_types=1);

namespace MornRain\CacheForge\Tests;

use InvalidArgumentException;
use MornRain\CacheForge\CacheForge;
use MornRain\CacheForge\FileStore;
use MornRain\CacheForge\Serializer;
use MornRain\CacheForge\StampedeLock;

/**
 * CacheForge 测试。
 */
class CacheForgeTest extends TestCase
{
    /** @var string */
    private $dir;

    /** @var CacheForge */
    private $forge;

    protected function setUp(): void
    {
        $this->dir   = sys_get_temp_dir() . '/morn-cf-test-' . getmypid() . '-' . uniqid();
        $this->forge = new CacheForge([
            'directory' => $this->dir,
            'ttl'       => 60,
            'layers'    => ['memory', 'file'],
        ]);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->dir);
    }

    /**
     * 递归删除。
     */
    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    /* ---------- 基础读写 ---------- */

    public function testSetAndGet(): void
    {
        self::assertTrue($this->forge->set('k', 'value'));
        self::assertSame('value', $this->forge->get('k'));
    }

    public function testGetReturnsDefaultOnMiss(): void
    {
        self::assertSame('fallback', $this->forge->get('missing', 'fallback'));
        self::assertNull($this->forge->get('missing'));
    }

    public function testNullValueIsCachedNotTreatedAsMiss(): void
    {
        // 这是哨兵机制的核心：值为 null 必须能被正确命中
        $this->forge->set('null-key', null);

        self::assertTrue($this->forge->has('null-key'), '值为 null 的键应算作存在');
        self::assertNull($this->forge->get('null-key', 'DEFAULT'));
    }

    public function testFalseValueIsCachedNotTreatedAsMiss(): void
    {
        $this->forge->set('false-key', false);

        self::assertTrue($this->forge->has('false-key'));
        self::assertFalse($this->forge->get('false-key', true), '值为 false 不应被当成未命中');
    }

    public function testEmptyStringValueIsCached(): void
    {
        $this->forge->set('empty', '');
        self::assertSame('', $this->forge->get('empty', 'DEFAULT'));
    }

    public function testComplexValuesRoundTrip(): void
    {
        $cases = [
            'int'      => 42,
            'float'    => 3.14,
            'bool'     => true,
            'array'    => ['a' => 1, 'b' => [2, 3]],
            'chinese'  => '中文内容 🎉',
            'binary'   => "\x00\x01\x02",
            'long'     => str_repeat('x', 100000),
        ];

        foreach ($cases as $key => $value) {
            $this->forge->set($key, $value);
            self::assertSame($value, $this->forge->get($key), "类型 {$key} 往返失败");
        }
    }

    public function testDeleteRemovesKey(): void
    {
        $this->forge->set('gone', 'x');
        self::assertTrue($this->forge->has('gone'));

        $this->forge->delete('gone');
        self::assertFalse($this->forge->has('gone'));
    }

    public function testDeleteMissingKeyReturnsFalse(): void
    {
        self::assertFalse($this->forge->delete('never-existed'));
    }

    public function testHasRespectsValueNull(): void
    {
        self::assertFalse($this->forge->has('nope'));
        $this->forge->set('y', 0);
        self::assertTrue($this->forge->has('y'), '值为 0 的键应算作存在');
    }

    public function testKeysArePrefixedAndIsolated(): void
    {
        $a = new CacheForge(['directory' => $this->dir, 'prefix' => 'pfx_a_', 'layers' => ['memory', 'file']]);
        $b = new CacheForge(['directory' => $this->dir, 'prefix' => 'pfx_b_', 'layers' => ['memory', 'file']]);

        $a->set('same', 'A');
        $b->set('same', 'B');

        self::assertSame('A', $a->get('same'), '不同前缀不应互相污染');
        self::assertSame('B', $b->get('same'));
    }

    public function testUnicodeAndSpecialCharKeys(): void
    {
        foreach (["中文键", "emoji🔑", "with space", "with:colon", "with/slash", ''] as $key) {
            $this->forge->set($key, 'v');
            self::assertSame('v', $this->forge->get($key, 'MISS'), "键「{$key}」往返失败");
        }
    }

    public function testVeryLongKey(): void
    {
        $key = str_repeat('k', 5000);
        $this->forge->set($key, 'v');

        self::assertSame('v', $this->forge->get($key));
    }

    public function testLargeArrayValue(): void
    {
        $big = range(1, 10000);
        $this->forge->set('big', $big);

        self::assertCount(10000, $this->forge->get('big'));
    }

    /* ---------- remember ---------- */

    public function testRememberCallsCallbackOnce(): void
    {
        $calls = 0;
        $cb    = static function (string $key) use (&$calls) {
            $calls++;

            return 'computed';
        };

        self::assertSame('computed', $this->forge->remember('r1', $cb));
        self::assertSame('computed', $this->forge->remember('r1', $cb));
        self::assertSame(1, $calls, '命中缓存后不应再执行回调');
    }

    public function testRememberPassesKeyToCallback(): void
    {
        $seen = null;
        $this->forge->remember('the-key', static function (string $k) use (&$seen) {
            $seen = $k;

            return 1;
        });

        self::assertSame('the-key', $seen);
    }

    public function testRememberCachesNullResult(): void
    {
        $calls = 0;
        $cb    = static function () use (&$calls) {
            $calls++;

            return null;
        };

        $this->forge->remember('null-cb', $cb);
        $this->forge->remember('null-cb', $cb);

        self::assertSame(1, $calls, '回调返回 null 时也必须被缓存，不能反复执行');
    }

    public function testRememberDoesNotCacheWhenCallbackThrows(): void
    {
        $cb = static function (): void {
            throw new \RuntimeException('boom');
        };

        self::assertThrows(\RuntimeException::class, function () use ($cb): void {
            $this->forge->remember('boom', $cb);
        });

        self::assertFalse($this->forge->has('boom'), '回调异常时不应写入缓存');
    }

    /* ---------- rememberLocked ---------- */

    public function testRememberLockedComputesOnce(): void
    {
        $calls = 0;
        $cb    = static function () use (&$calls) {
            $calls++;

            return 'locked-value';
        };

        self::assertSame('locked-value', $this->forge->rememberLocked('lk', $cb));
        self::assertSame('locked-value', $this->forge->rememberLocked('lk', $cb));
        self::assertSame(1, $calls);
    }

    public function testRememberLockedHandlesFalseResult(): void
    {
        // 等待方的 readHit 曾用空串表示「未命中」，会被误判为命中而返回 null
        $result = $this->forge->rememberLocked('false-result', static function (): bool {
            return false;
        });

        self::assertFalse($result, '回调返回 false 时必须原样返回');
        self::assertFalse($this->forge->rememberLocked('false-result', static function (): bool {
            return true;
        }), '已缓存的 false 值不应被当成未命中');
    }

    public function testRememberLockedHandlesArrayResult(): void
    {
        $value = ['a' => 1, 'b' => [2, 3]];
        $got   = $this->forge->rememberLocked('arr', static function () use ($value) {
            return $value;
        });

        self::assertSame($value, $got);
    }

    public function testRememberLockedReleasesLockOnException(): void
    {
        $lock = $this->forge->lock();

        self::assertThrows(\RuntimeException::class, function (): void {
            $this->forge->rememberLocked('exc', static function (): void {
                throw new \RuntimeException('fail');
            });
        });

        // 异常后必须能立刻重新获取锁，否则会永久阻塞后续请求
        self::assertFalse($lock->isLocked('exc'), '回调抛异常后锁必须被释放');
    }

    public function testRememberLockedWithStaleFallback(): void
    {
        $lock = new StampedeLock($this->dir . '/locks2');

        // 预先占住锁，迫使 remember 走降级分支
        $token = $lock->acquire('stale-key', 5);
        self::assertNotNull($token);

        $result = $lock->remember(
            'stale-key',
            static function () {
                return 'fresh';
            },
            static function () {
                return null;
            },
            ['wait' => 0.1, 'stale' => 'STALE']
        );

        self::assertSame('STALE', $result, '超时且提供 stale 时应返回降级值');
    }

    /* ---------- increment ---------- */

    public function testIncrement(): void
    {
        self::assertSame(1, $this->forge->increment('cnt'));
        self::assertSame(2, $this->forge->increment('cnt'));
        self::assertSame(12, $this->forge->increment('cnt', 10));
        // 步长为 0 时从 0 起步，仍返回 0
        self::assertSame(0, $this->forge->increment('zero-step', 0));
    }

    public function testIncrementWithNegativeStep(): void
    {
        $this->forge->increment('dec', 5);
        self::assertSame(3, $this->forge->increment('dec', -2));
    }

    /* ---------- 指标 ---------- */

    public function testMetricsTrackHitsAndMisses(): void
    {
        $this->forge->set('m', 1);
        $this->forge->get('m');
        $this->forge->get('absent');

        $report = $this->forge->report();
        self::assertArrayHasKey('hit_rate', $report);
        self::assertTrue($report['total_reads'] >= 3);
        self::assertTrue($report['total_hits'] >= 1);
    }

    public function testMetricsResetIsIndependent(): void
    {
        $this->forge->set('r', 1);
        $this->forge->get('r');
        $this->forge->metrics()->reset();

        self::assertSame(0.0, $this->forge->metrics()->hitRate());
    }

    /* ---------- 层级与配置 ---------- */

    public function testLayersAccessor(): void
    {
        self::assertSame(['memory', 'file'], $this->forge->layers());
    }

    public function testPrefixAccessor(): void
    {
        self::assertSame('morn_', $this->forge->prefix());
    }

    public function testSetDefaultTtlClampsNegative(): void
    {
        $this->forge->setDefaultTtl(-5);
        self::assertDoesNotThrow(function (): void {
            $this->forge->set('after', 'v');
        });
    }

    public function testFillDownTogglesCleanly(): void
    {
        $forge = $this->forge->fillDown(false);
        self::assertSame($forge, $this->forge, 'fillDown 应返回自身以支持链式调用');
        $forge->set('fd', 'v');
        self::assertSame('v', $forge->get('fd'));
    }

    /* ---------- 标签失效 ---------- */

    public function testTagFlush(): void
    {
        $this->forge->set('post:1:a', 'A', 60, ['post:1']);
        $this->forge->set('post:1:b', 'B', 60, ['post:1']);
        $this->forge->set('post:2:c', 'C', 60, ['post:2']);

        $result = $this->forge->flushTags('post:1');
        self::assertTrue($result['deleted'] >= 1);
        self::assertFalse($this->forge->has('post:1:a'), '标签内键应被清除');
        self::assertTrue($this->forge->has('post:2:c'), '其他标签不应受影响');
    }

    public function testFlushWhereWithMultipleTags(): void
    {
        // 语义：(命中全部 include) AND (命中任一 anyOf)
        $this->forge->set('t:x:1', 'X', 60, ['type:post', 'lang:zh']);
        $this->forge->set('t:y:1', 'Y', 60, ['type:page', 'lang:zh']);
        $this->forge->set('t:z:1', 'Z', 60, ['type:post', 'lang:en']);

        $this->forge->flushWhere(['lang:zh'], ['type:post']);

        self::assertFalse($this->forge->has('t:x:1'), '同时命中 include 与 anyOf，应删除');
        self::assertTrue($this->forge->has('t:y:1'), '只命中 include（type:page），应保留');
        self::assertTrue($this->forge->has('t:z:1'), '只命中 anyOf（lang:en），应保留');
    }

    public function testFlushWhereWithOnlyIncludeTags(): void
    {
        $this->forge->set('i:a', 'A', 60, ['lang:zh', 'type:post']);
        $this->forge->set('i:b', 'B', 60, ['lang:zh']);
        $this->forge->set('i:c', 'C', 60, ['lang:en']);

        $this->forge->flushWhere(['lang:zh']);

        self::assertFalse($this->forge->has('i:a'));
        self::assertFalse($this->forge->has('i:b'));
        self::assertTrue($this->forge->has('i:c'), '其他语言的键不应受影响');
    }

    public function testFlushWhereWithEmptyArgsDoesNothing(): void
    {
        $this->forge->set('n:a', 'A', 60, ['t']);
        $result = $this->forge->flushWhere([], []);

        self::assertSame(0, $result['deleted']);
        self::assertTrue($this->forge->has('n:a'), '无条件时不应删除任何键');
    }

    public function testTagNameSanitization(): void
    {
        $tags = $this->forge->tags();
        // 标签名会参与构造存储键，必须清洗
        self::assertSame('safe_tag', $tags->sanitizeTag('safe_tag'));
        // 路径分隔符与点号必须压成下划线，避免与 a_b 撞车
        self::assertSame('a_b', $tags->sanitizeTag('a/../b'));
        self::assertSame('a_b', $tags->sanitizeTag('a_b'));
        self::assertSame('', $tags->sanitizeTag('   '));
        self::assertSame('', $tags->sanitizeTag(''));
    }

    public function testSanitizedTagsDoNotCollide(): void
    {
        $tags = $this->forge->tags();
        // 点号与连字符是合法字符，必须保持区分
        self::assertNotSame($tags->sanitizeTag('a.b'), $tags->sanitizeTag('a-b'));
        // 路径写法被归一化，与等价的下划线写法一致（这是预期行为）
        self::assertSame($tags->sanitizeTag('a/../b'), $tags->sanitizeTag('a_b'));
        // 全非法字符的输入清洗后为空，调用方据此跳过登记
        self::assertSame('', $tags->sanitizeTag('!!!'));
        self::assertTrue($tags->sanitizeTag('a.b') !== '');
    }

    public function testFlushWhereAndFilterMustBothApply(): void
    {
        // 回归：曾经先用 anyOf 取并集、再用 include 过滤，
        // 导致「只命中 anyOf、不命中 include」的键被误删。
        $this->forge->set('t:post:zh', 'X', 60, ['type:post', 'lang:zh']);
        $this->forge->set('t:post:en', 'Y', 60, ['type:post', 'lang:en']);

        $this->forge->flushWhere(['lang:zh'], ['type:post']);

        self::assertFalse($this->forge->has('t:post:zh'), '同时命中 include 与 anyOf，应删除');
        self::assertTrue($this->forge->has('t:post:en'), '只命中 anyOf 的键必须保留');
    }

    /* ---------- 清理 ---------- */

    public function testGcDoesNotFailOnMissingDirectory(): void
    {
        $this->forge->set('g', 1);
        // 外部删除目录后，gc/flush/stats 必须优雅降级而不是抛致命错误
        self::removeTree($this->dir);

        self::assertDoesNotThrow(function (): void {
            $this->forge->gc();
        });
        self::assertDoesNotThrow(function (): void {
            $this->forge->flush();
        });
        self::assertDoesNotThrow(function (): void {
            $this->forge->fileStore()->stats();
        });
        self::assertDoesNotThrow(function (): void {
            $this->forge->fileStore()->files();
        });
    }

    public function testFlushClearsMemoryLayer(): void
    {
        $this->forge->set('f1', 'v');
        $this->forge->flush();

        self::assertFalse($this->forge->has('f1'));
    }
}
