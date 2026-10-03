<?php
/**
 * 缓存序列化封装。
 *
 * 策略：
 * 1. 优先 JSON —— 可读、跨语言兼容、便于用 `jq` 排查缓存文件；
 * 2. JSON 编码不完整（NaN、INF、资源）时静默回退到 serialize，不影响功能；
 * 3. 读取时若 JSON 解析失败，视为缓存损坏并尝试原生格式回退；
 * 4. 统一使用「信封」结构保存版本、类型与负载，便于后续格式演进。
 *
 * 安全说明：unserialize 存在对象注入风险，因此默认
 * allowed_classes = false —— 只还原标量与数组，不实例化任何类。
 * 确实需要存对象时，调用方须自行确认数据来源可信并显式开启。
 *
 * @package MornRain\CacheForge
 */

declare(strict_types=1);

namespace MornRain\CacheForge;

use InvalidArgumentException;
use Throwable;

/**
 * 序列化器。
 */
class Serializer
{
    /** 编码方式：JSON */
    public const FORMAT_JSON = 'json';

    /** 编码方式：PHP serialize */
    public const FORMAT_NATIVE = 'native';

    /** 信封版本号 */
    public const VERSION = 1;

    /** @var string 默认编码方式 */
    protected $format;

    /** @var bool 反序列化时是否允许实例化类 */
    protected $allowClasses;

    /**
     * 构造函数。
     *
     * @param string $format       默认编码方式。
     * @param bool   $allowClasses 反序列化时是否允许实例化类（默认否）。
     */
    public function __construct(string $format = self::FORMAT_JSON, bool $allowClasses = false)
    {
        $this->useFormat($format);
        $this->allowClasses = $allowClasses;
    }

    /**
     * 切换默认编码方式。
     *
     * @throws InvalidArgumentException 格式不受支持。
     */
    public function useFormat(string $format): self
    {
        if (!in_array($format, [self::FORMAT_JSON, self::FORMAT_NATIVE], true)) {
            throw new InvalidArgumentException('不支持的序列化格式：' . $format);
        }
        $this->format = $format;

        return $this;
    }

    /**
     * 是否允许反序列化时实例化类。
     */
    public function allowClasses(bool $allowed = true): self
    {
        $this->allowClasses = $allowed;

        return $this;
    }

    /**
     * 序列化。
     *
     * @param mixed $value 待序列化的值。
     * @return string 无法编码时返回空串（表示「不可缓存」）。
     */
    public function encode($value): string
    {
        // null 是合法缓存值，用专门的标记编码，避免与「不可缓存」的空串混淆。
        if ($value === null) {
            return (string) json_encode([
                'ver'     => self::VERSION,
                'type'    => 'null',
                'format'  => self::FORMAT_JSON,
                'payload' => 'null',
            ]);
        }

        $format  = $this->format;
        $payload = '';

        if ($format === self::FORMAT_JSON) {
            $encoded = json_encode(
                $value,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
            );
            // 只在完全无错时采用 JSON 结果
            if ($encoded !== false && json_last_error() === JSON_ERROR_NONE) {
                $payload = $encoded;
            } else {
                $format = self::FORMAT_NATIVE;
            }
        }

        if ($format === self::FORMAT_NATIVE) {
            try {
                $payload = serialize($value);
            } catch (Throwable $e) {
                return '';
            }
            if ($payload === false) {
                return '';
            }
        }

        $envelope = json_encode(
            [
                'ver'    => self::VERSION,
                'type'   => $this->detectType($value),
                'format' => $format,
                'payload' => $payload,
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($envelope === false) {
            // 信封本身编不出来（几乎不可能），退化为裸原生串
            return 'N|' . $format . '|' . base64_encode($payload);
        }

        return $envelope;
    }

    /**
     * 反序列化。
     *
     * @param string $raw     编码后的字符串。
     * @param mixed  $default 解析失败时的返回值。
     * @return mixed
     */
    public function decode(string $raw, $default = null)
    {
        if ($raw === '') {
            return $default;
        }

        $envelope = json_decode($raw, true);
        if (!is_array($envelope) || !isset($envelope['ver'], $envelope['format'])) {
            // 非信封格式，可能是历史裸数据
            return $this->decodeLegacy($raw, $default);
        }

        $version = (int) $envelope['ver'];
        if ($version > self::VERSION) {
            // 缓存由更高版本库写入，本版本无法解析
            return $default;
        }

        $payload = isset($envelope['payload']) ? (string) $envelope['payload'] : '';
        if ($payload === '') {
            return $default;
        }

        if ((string) $envelope['format'] === self::FORMAT_JSON) {
            $value = json_decode($payload, true);

            return ($value === null && trim($payload) !== 'null') ? $default : $value;
        }

        return $this->safeUnserialize($payload, $default);
    }

    /**
     * 解析非信封格式的历史数据。
     *
     * @param string $raw     原始字符串。
     * @param mixed  $default 失败时的返回值。
     * @return mixed
     */
    protected function decodeLegacy(string $raw, $default)
    {
        // 形如 "N|native|<base64>" 的退化格式
        if (strncmp($raw, 'N|', 2) === 0) {
            $parts    = explode('|', $raw, 3);
            $decoded  = isset($parts[2]) ? base64_decode($parts[2], true) : false;
            if ($decoded === false) {
                return $default;
            }

            return (string) ($parts[1] ?? '') === self::FORMAT_JSON
                ? json_decode($decoded, true)
                : $this->safeUnserialize($decoded, $default);
        }

        $value = $this->safeUnserialize($raw, null);
        if ($value !== null) {
            return $value;
        }

        // 最后尝试 base64 包裹的原生数据
        $decoded = base64_decode($raw, true);
        if ($decoded !== false && $decoded !== $raw) {
            $value = $this->safeUnserialize($decoded, null);
            if ($value !== null) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * 安全反序列化：屏蔽告警，并按配置限制类实例化。
     *
     * @param string $payload 序列化字符串。
     * @param mixed  $default 失败时的返回值。
     * @return mixed
     */
    protected function safeUnserialize(string $payload, $default)
    {
        $first = $payload[0] ?? '';
        // serialize() 只可能以这些字符开头
        if (!in_array($first, ['a', 'O', 's', 'i', 'd', 'b', 'N'], true)) {
            return $default;
        }

        set_error_handler(static function (): bool {
            return true;
        });
        try {
            $value = unserialize($payload, ['allowed_classes' => $this->allowClasses]);
        } catch (Throwable $e) {
            return $default;
        } finally {
            restore_error_handler();
        }

        // unserialize 失败返回 false，但合法的 false 值编码为 'b:0;'
        if ($value === false && $payload !== 'b:0;') {
            return $default;
        }

        return $value;
    }

    /**
     * 推断值类型标签，便于排查缓存内容。
     *
     * @param mixed $value 待判断的值。
     */
    protected function detectType($value): string
    {
        if (is_array($value)) {
            return 'array';
        }
        if (is_object($value)) {
            return 'object';
        }
        if (is_string($value)) {
            return 'string';
        }
        if (is_int($value)) {
            return 'int';
        }
        if (is_float($value)) {
            return 'float';
        }
        if (is_bool($value)) {
            return 'bool';
        }

        return 'unknown';
    }
}
