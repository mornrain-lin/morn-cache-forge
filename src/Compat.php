<?php
/**
 * 环境兼容工具。
 *
 * 缓存库要在 PHP 7.4 ~ 8.3 与「有/无 WordPress」两类环境下都跑，
 * 少量底层能力（随机数、原子写、对象缓存探测）在此统一封装，
 * 避免各文件重复定义辅助函数。
 *
 * @package MornRain\CacheForge
 */

declare(strict_types=1);

namespace MornRain\CacheForge;

use Exception;

/**
 * 兼容工具类。
 */
final class Compat
{
    /**
     * 取得随机字节。
     *
     * @param int $length 字节数。
     */
    public static function randomBytes(int $length): string
    {
        if ($length < 1) {
            return '';
        }
        if (function_exists('random_bytes')) {
            try {
                return random_bytes($length);
            } catch (Exception $e) {
                // 落到下方实现
            }
        }

        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= chr(mt_rand(0, 255));
        }

        return $out;
    }

    /**
     * 取得区间内的随机整数。
     *
     * @param int $min 最小值。
     * @param int $max 最大值。
     */
    public static function randomInt(int $min, int $max): int
    {
        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }
        if (function_exists('random_int')) {
            try {
                return random_int($min, $max);
            } catch (Exception $e) {
                // 落到下方实现
            }
        }

        return mt_rand($min, $max);
    }

    /**
     * 当前进程 ID。
     */
    public static function pid(): int
    {
        return function_exists('getmypid') ? (int) getmypid() : 0;
    }

    /**
     * 是否运行在 WordPress 环境。
     */
    public static function isWordPress(): bool
    {
        return function_exists('wp_cache_get') && function_exists('wp_cache_set');
    }
}
