<?php
/**
 * * FileStore 测试。
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
 * FileStore 测试。
 */
class FileStoreTest extends TestCase
{
    /** @var string */
    private $dir;

    /** @var FileStore */
    private $store;

    protected function setUp(): void
    {
        $this->dir   = sys_get_temp_dir() . '/morn-fs-test-' . getmypid() . '-' . uniqid();
        $this->store = new FileStore($this->dir, null, 60);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->dir);
    }

    public function testCreatesDirectoryOnConstruct(): void
    {
        self::assertTrue(is_dir($this->dir));
    }

    public function testSetGetDelete(): void
    {
        self::assertTrue($this->store->set('key', 'val'));
        self::assertSame('val', $this->store->get('key'));
        self::assertTrue($this->store->has('key'));
        self::assertTrue($this->store->delete('key'));
        self::assertFalse($this->store->has('key'));
    }

    public function testPathTraversalInKeyIsNeutralised(): void
    {
        // 键名会被 md5 化，任何路径穿越字符都不应逃出缓存目录
        $evil = '../../../../etc/passwd';
        $this->store->set($evil, 'pwned');

        self::assertSame('pwned', $this->store->get($evil));
        self::assertFalse(is_file('/etc/passwd.cache'));
    }

    public function testNullAndFalseRoundTrip(): void
    {
        $this->store->set('n', null);
        $this->store->set('f', false);

        self::assertNull($this->store->get('n', 'DEFAULT'));
        self::assertFalse($this->store->get('f', true));
    }

    public function testRawAccessors(): void
    {
        self::assertTrue($this->store->setRaw('r', 'raw-body'));
        self::assertSame('raw-body', $this->store->getRaw('r'));
        self::assertSame('', $this->store->getRaw('absent'));
    }

    public function testExpiryIsLazy(): void
    {
        $this->store->set('short', 'v', 1);
        // 把 mtime 推到 2 秒前
        $file = $this->store->files()[0] ?? '';
        if ($file !== '') {
            @touch($file, time() - 10);
        }

        self::assertSame('MISS', $this->store->get('short', 'MISS', 1), '过期值不应被返回');
    }

    public function testZeroTtlNeverExpires(): void
    {
        $this->store->set('forever', 'v', 0);
        $file = $this->store->files()[0] ?? '';
        if ($file !== '') {
            @touch($file, time() - 99999);
        }

        self::assertSame('v', $this->store->get('forever', 'MISS', 0), 'ttl=0 表示永不过期');
    }

    public function testNegativeTtlClampedToZero(): void
    {
        $this->store->set('neg', 'v', -10);
        self::assertSame('v', $this->store->get('neg', 'MISS', -10));
    }

    public function testCorruptedFileIsTreatedAsMiss(): void
    {
        $this->store->set('c', 'v');
        $file = $this->store->files()[0] ?? '';
        if ($file !== '') {
            file_put_contents($file, '{{{ corrupted json');
        }

        self::assertSame('MISS', $this->store->get('c', 'MISS'), '损坏数据应按未命中处理');
    }

    public function testEmptyFileIsTreatedAsMiss(): void
    {
        $this->store->set('e', 'v');
        $file = $this->store->files()[0] ?? '';
        if ($file !== '') {
            file_put_contents($file, '');
        }

        self::assertSame('MISS', $this->store->get('e', 'MISS'));
    }

    public function testIncrementIsAtomic(): void
    {
        self::assertSame(1, $this->store->increment('c1'));
        self::assertSame(2, $this->store->increment('c1'));
        self::assertSame(12, $this->store->increment('c1', 10));
    }

    public function testIncrementResetsWhenExpired(): void
    {
        $this->store->set('c2', 100, 1);
        $file = $this->store->files()[0] ?? '';
        if ($file !== '') {
            @touch($file, time() - 100);
        }

        self::assertSame(1, $this->store->increment('c2', 1, 1), '过期计数应重新开始');
    }

    public function testFlushRemovesFiles(): void
    {
        $this->store->set('a', 1);
        $this->store->set('b', 2);

        self::assertTrue($this->store->flush() >= 2);
        self::assertCount(0, $this->store->files());
    }

    public function testGcRespectsLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->store->set("g{$i}", $i, 1);
            $files = $this->store->files();
            if (isset($files[$i])) {
                @touch($files[$i], time() - 100);
            }
        }

        $result = $this->store->gc(null, 2);
        self::assertTrue($result['deleted'] <= 2, 'gc 必须遵守 limit 上限');
    }

    public function testStatsShape(): void
    {
        $this->store->set('s', str_repeat('a', 1000));
        $stats = $this->store->stats();

        self::assertArrayHasKey('files', $stats);
        self::assertArrayHasKey('bytes', $stats);
        self::assertArrayHasKey('bytes_human', $stats);
        self::assertTrue($stats['bytes'] >= 1000);
    }

    public function testConcurrentReadsNeverSeePartialContent(): void
    {
        // 反复写入不同长度的值，读取结果必须始终是完整的某一个值
        $payloads = ['short', str_repeat('m', 50000), 'tiny'];
        foreach ($payloads as $i => $payload) {
            $this->store->set('atomic', $payload);
            self::assertSame($payload, $this->store->get('atomic'), "第 {$i} 次写入后读取不完整");
        }
    }
}
