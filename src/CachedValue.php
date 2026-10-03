<?php
/**
 * 缓存值包装器。
 *
 * 存在的唯一理由：WordPress 的 get_transient() 用 `false` 同时表示
 * 「缓存未命中」与「缓存值就是 false」，无法区分。
 * Transient 层命中时用本类包装，读出后由 CacheForge 拆包，
 * 从而让「值为 false 的缓存项」也能被正确识别为命中。
 *
 * @package MornRain\CacheForge
 */

declare(strict_types=1);

namespace MornRain\CacheForge;

/**
 * 缓存值包装器。
 */
final class CachedValue
{
    /** @var mixed 被包装的值 */
    private $value;

    /**
     * 构造函数。
     *
     * @param mixed $value 被包装的值。
     */
    public function __construct($value)
    {
        $this->value = $value;
    }

    /**
     * 取出被包装的值。
     *
     * @return mixed
     */
    public function value()
    {
        return $this->value;
    }
}
