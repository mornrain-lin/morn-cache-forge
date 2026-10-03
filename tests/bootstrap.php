<?php
/**
 * PHPUnit 引导文件。
 *
 * 优先使用 Composer 的自动加载；没有安装依赖时退化为内置的 PSR-4 引导，
 * 保证 `vendor/bin/phpunit` 与 `php tests/run-tests.php` 两种方式都能工作。
 *
 * @package MornRain\CacheForge\Tests
 */

declare(strict_types=1);

$vendor = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($vendor)) {
    require_once $vendor;
}

require_once __DIR__ . '/TestCase.php';
