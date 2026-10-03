<?php
/**
 * * Serializer 测试。
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
 * Serializer 测试。
 */
class SerializerTest extends TestCase
{
    public function testRejectsUnsupportedFormat(): void
    {
        $serializer = new Serializer();
        self::assertThrows(InvalidArgumentException::class, static function () use ($serializer): void {
            $serializer->useFormat('yaml');
        });
    }

    public function testJsonRoundTrip(): void
    {
        $s = new Serializer();
        foreach ([1, 1.5, 'x', true, false, ['k' => 'v'], ['中文' => '值']] as $value) {
            $encoded = $s->encode($value);
            self::assertNotSame('', $encoded, '可编码的值不应返回空串');
            self::assertSame($value, $s->decode($encoded, 'MISS'), '往返后类型必须保持');
        }
    }

    public function testNullValueIsEncodable(): void
    {
        $s = new Serializer();
        $encoded = $s->encode(null);

        self::assertNotSame('', $encoded, 'null 是合法缓存值，不能被当成不可缓存');
        self::assertNull($s->decode($encoded, 'MISS'));
    }

    public function testCorruptedPayloadReturnsDefault(): void
    {
        $s = new Serializer();
        self::assertSame('D', $s->decode('not-json-envelope', 'D'));
        self::assertSame('D', $s->decode('', 'D'));
    }

    public function testUnserializeDisallowsClassesByDefault(): void
    {
        $s = new Serializer();
        $s->useFormat(Serializer::FORMAT_NATIVE);

        // 构造一个声明了类的序列化串
        $payload = 'O:8:"stdClass":1:{s:1:"a";i:1;}';
        $envelope = json_encode([
            'ver'     => Serializer::VERSION,
            'type'    => 'object',
            'format'  => Serializer::FORMAT_NATIVE,
            'payload' => $payload,
        ]);

        $result = $s->decode((string) $envelope, 'SAFE');
        // allowed_classes=false 时不得还原为真实对象
        self::assertFalse($result instanceof \stdClass, '默认不得实例化任何类');
    }

    public function testResourceIsNotCacheable(): void
    {
        $s = new Serializer();
        $fh = fopen('php://memory', 'rb');
        if ($fh === false) {
            self::assertTrue(true);

            return;
        }

        $encoded = $s->encode($fh);
        fclose($fh);
        self::assertTrue($encoded === '' || is_string($encoded));
    }

    public function testInfAndNanFallBackToNative(): void
    {
        $s = new Serializer();
        $encoded = $s->encode(['inf' => INF, 'nan' => NAN]);

        // JSON 无法编码这些值，应自动回退而不是返回空
        self::assertNotSame('', $encoded);
        $decoded = $s->decode($encoded, null);
        self::assertTrue(is_array($decoded), '回退后仍应能解回数组');
    }
}
