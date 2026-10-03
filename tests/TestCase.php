<?php
/**
 * 测试基类 + 轻量运行器（零依赖，可独立执行）。
 *
 * 设计要点：
 * 1. 装了 PHPUnit 时，本类继承 PHPUnit\Framework\TestCase，
 *    于是 tests/ 下的用例既能被 `vendor/bin/phpunit` 跑，
 *    也能被 `php tests/run-tests.php` 跑，无需维护两份测试。
 * 2. 没装 PHPUnit 时，本类自带断言实现，同样支持
 *    assertSame / assertTrue / assertThrows 等常用 API。
 *
 * @package MornRain\CacheForge\Tests
 */

declare(strict_types=1);

namespace MornRain\CacheForge\Tests;

use PHPUnit\Framework\TestCase as PhpUnitTestCase;

/**
 * 断言失败。
 */
class AssertionFailed extends \Exception
{
}

// PHPUnit 存在时直接复用其 TestCase（其断言 API 与本套用法一致）。
if (class_exists(PhpUnitTestCase::class)) {
    /**
     * @see \PHPUnit\Framework\TestCase
     */
    abstract class TestCase extends PhpUnitTestCase
    {
    }
} else {
    /**
     * 零依赖测试基类。
     */
    abstract class TestCase
    {
        /** @var int 全局断言计数 */
        public static $assertions = 0;

        protected function setUp(): void
        {
        }

        protected function tearDown(): void
        {
        }

        public static function assertTrue($value, string $message = ''): void
        {
            self::$assertions++;
            if ($value !== true) {
                throw new AssertionFailed(self::describe($message, 'true', $value));
            }
        }

        public static function assertFalse($value, string $message = ''): void
        {
            self::$assertions++;
            if ($value !== false) {
                throw new AssertionFailed(self::describe($message, 'false', $value));
            }
        }

        public static function assertTruthy($value, string $message = ''): void
        {
            self::$assertions++;
            if (!$value) {
                throw new AssertionFailed(self::describe($message, 'truthy', $value));
            }
        }

        public static function assertFalsy($value, string $message = ''): void
        {
            self::$assertions++;
            if ($value) {
                throw new AssertionFailed(self::describe($message, 'falsy', $value));
            }
        }

        public static function assertNull($value, string $message = ''): void
        {
            self::$assertions++;
            if ($value !== null) {
                throw new AssertionFailed(self::describe($message, 'null', $value));
            }
        }

        public static function assertNotNull($value, string $message = ''): void
        {
            self::$assertions++;
            if ($value === null) {
                throw new AssertionFailed(self::describe($message, 'not null', 'null'));
            }
        }

        public static function assertSame($expected, $actual, string $message = ''): void
        {
            self::$assertions++;
            if ($expected !== $actual) {
                throw new AssertionFailed(self::describe($message, self::dump($expected), self::dump($actual)));
            }
        }

        public static function assertNotSame($expected, $actual, string $message = ''): void
        {
            self::$assertions++;
            if ($expected === $actual) {
                throw new AssertionFailed(self::describe($message, 'not ' . self::dump($expected), self::dump($actual)));
            }
        }

        public static function assertEquals($expected, $actual, string $message = ''): void
        {
            self::$assertions++;
            if ($expected != $actual) {
                throw new AssertionFailed(self::describe($message, self::dump($expected), self::dump($actual)));
            }
        }

        public static function assertStringContains(string $needle, string $haystack, string $message = ''): void
        {
            self::$assertions++;
            if (strpos($haystack, $needle) === false) {
                throw new AssertionFailed(self::describe($message, 'string containing ' . self::dump($needle), self::clip($haystack)));
            }
        }

        public static function assertStringNotContains(string $needle, string $haystack, string $message = ''): void
        {
            self::$assertions++;
            if (strpos($haystack, $needle) !== false) {
                throw new AssertionFailed(self::describe($message, 'string NOT containing ' . self::dump($needle), self::clip($haystack)));
            }
        }

        public static function assertContains($needle, array $haystack, string $message = ''): void
        {
            self::$assertions++;
            if (!in_array($needle, $haystack, true)) {
                throw new AssertionFailed(self::describe($message, 'array containing ' . self::dump($needle), self::dump($haystack)));
            }
        }

        public static function assertNotContains($needle, array $haystack, string $message = ''): void
        {
            self::$assertions++;
            if (in_array($needle, $haystack, true)) {
                throw new AssertionFailed(self::describe($message, 'array NOT containing ' . self::dump($needle), self::dump($haystack)));
            }
        }

        public static function assertArrayHasKey($key, array $array, string $message = ''): void
        {
            self::$assertions++;
            if (!array_key_exists($key, $array)) {
                throw new AssertionFailed(self::describe($message, 'array with key ' . self::dump($key), 'keys: ' . self::dump(array_keys($array))));
            }
        }

        public static function assertArrayNotHasKey($key, array $array, string $message = ''): void
        {
            self::$assertions++;
            if (array_key_exists($key, $array)) {
                throw new AssertionFailed(self::describe($message, 'array without key ' . self::dump($key), 'key present'));
            }
        }

        public static function assertCount(int $expected, $countable, string $message = ''): void
        {
            self::$assertions++;
            $actual = is_array($countable) || $countable instanceof \Countable ? count($countable) : -1;
            if ($actual !== $expected) {
                throw new AssertionFailed(self::describe($message, (string) $expected . ' items', $actual . ' items'));
            }
        }

        public static function assertInstanceOf(string $expected, $actual, string $message = ''): void
        {
            self::$assertions++;
            if (!($actual instanceof $expected)) {
                throw new AssertionFailed(self::describe($message, $expected, is_object($actual) ? get_class($actual) : gettype($actual)));
            }
        }

        public static function assertEqualsWithDelta(float $expected, float $actual, float $delta, string $message = ''): void
        {
            self::$assertions++;
            if (abs($expected - $actual) > $delta) {
                throw new AssertionFailed(self::describe($message, $expected . ' ± ' . $delta, (string) $actual));
            }
        }

        /**
         * 断言抛出指定异常。
         *
         * @param class-string $exception 期望异常类。
         * @return \Throwable
         */
        public static function assertThrows(string $exception, callable $callback, string $message = '')
        {
            self::$assertions++;

            try {
                $callback();
            } catch (\Throwable $e) {
                if (!($e instanceof $exception)) {
                    throw new AssertionFailed(self::describe($message, $exception, get_class($e) . ': ' . $e->getMessage()));
                }

                return $e;
            }

            throw new AssertionFailed(self::describe($message, $exception . ' thrown', 'nothing thrown'));
        }

        /**
         * 断言不抛异常。
         *
         * @return mixed 返回值
         */
        public static function assertDoesNotThrow(callable $callback, string $message = '')
        {
            self::$assertions++;

            try {
                return $callback();
            } catch (\Throwable $e) {
                throw new AssertionFailed(self::describe($message, 'no exception', get_class($e) . ': ' . $e->getMessage()));
            }
        }

        protected static function describe(string $message, string $expected, $actual): string
        {
            return ($message !== '' ? $message . ' | ' : '') . "expected {$expected}, got " . self::dump($actual);
        }

        /**
         * @param mixed $value
         */
        protected static function dump($value): string
        {
            if (is_string($value)) {
                return '"' . self::clip($value) . '"';
            }
            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }
            if ($value === null) {
                return 'null';
            }
            if (is_array($value)) {
                $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

                return self::clip($json === false ? 'array' : $json);
            }
            if (is_object($value)) {
                return get_class($value);
            }

            return self::clip((string) $value);
        }

        protected static function clip(string $value, int $limit = 200): string
        {
            $value = str_replace(["\r", "\n"], ['\\r', '\\n'], $value);
            $len   = mb_strlen($value);
            if ($len <= $limit) {
                return $value;
            }

            return mb_substr($value, 0, $limit) . '…(+' . ($len - $limit) . ')';
        }
    }
}

if (!class_exists(__NAMESPACE__ . '\\Runner', false)) {
    /**
     * 零依赖测试运行器。
     *
     * 用法：php tests/run-tests.php [方法名过滤]
     */
    final class Runner
    {
        /** @var array<int,array{class:string,method:string}> */
        private $tests = [];

        /** @var string */
        private $testDir;

        /** @var string */
        private $rootDir;

        /** @var string */
        private $prefix;

        public function __construct(string $testDir, string $rootDir, string $prefix)
        {
            $this->testDir = rtrim($testDir, '/\\');
            $this->rootDir = rtrim($rootDir, '/\\');
            $this->prefix   = trim($prefix, '\\');
        }

        /**
         * @return int 退出码，0 为全通过
         */
        public function run(string $filter = ''): int
        {
            $this->autoload();
            $this->collect($filter);

            if ($this->tests === []) {
                $this->out('未发现测试用例（tests/*Test.php）。');

                return 1;
            }

            $this->out('');
            $this->out('测试套件：' . $this->prefix);
            $this->out(str_repeat('=', 64));

            $passed  = 0;
            $failed  = [];
            $started = microtime(true);

            foreach ($this->tests as $test) {
                $label = $test['class'] . '::' . $test['method'];

                try {
                    $this->invoke($test['class'], $test['method']);
                    $passed++;
                    $this->out(sprintf('  [PASS] %s', $label));
                } catch (AssertionFailed $e) {
                    $failed[] = [$label, $e->getMessage(), null];
                    $this->out(sprintf('  [FAIL] %s', $label));
                    $this->out('         ' . $e->getMessage());
                } catch (\Throwable $e) {
                    $failed[] = [$label, $e->getMessage(), $e];
                    $this->out(sprintf('  [ERROR] %s', $label));
                    $this->out(sprintf(
                        '         %s: %s (%s:%d)',
                        get_class($e),
                        $e->getMessage(),
                        basename($e->getFile()),
                        $e->getLine()
                    ));
                }
            }

            $elapsed = (microtime(true) - $started) * 1000;

            $this->out(str_repeat('-', 64));
            $this->out(sprintf(
                '用例 %d | 通过 %d | 失败 %d | 断言 %d | %.0f ms',
                count($this->tests),
                $passed,
                count($failed),
                TestCase::$assertions,
                $elapsed
            ));

            if ($failed === []) {
                $this->out('全部通过 ✓');
                $this->out('');

                return 0;
            }

            $this->out('');
            $this->out('失败详情：');
            foreach ($failed as $i => $entry) {
                $this->out(sprintf('  %d) %s', $i + 1, $entry[0]));
                $this->out('     ' . $entry[1]);
                if ($entry[2] instanceof \Throwable) {
                    $this->out(sprintf(
                        '     抛出 %s 于 %s:%d',
                        get_class($entry[2]),
                        basename($entry[2]->getFile()),
                        $entry[2]->getLine()
                    ));
                }
            }
            $this->out('');
            $this->out('存在失败 ✗');
            $this->out('');

            return 1;
        }

        private function autoload(): void
        {
            $src = $this->rootDir . '/src';
            if (!is_dir($src)) {
                return;
            }

            $ns = __NAMESPACE__;
            // $ns 形如 MornRain\CacheForge\Tests，取其上一级作为库前缀
            $libPrefix = substr($ns, 0, (int) strrpos($ns, '\\'));

            spl_autoload_register(static function (string $class) use ($src, $ns, $libPrefix): void {
                // 用例命名空间：MornRain\<Lib>\Tests\Xxx → tests/Xxx.php
                if (strpos($class, $ns . '\\') === 0) {
                    $rel = str_replace('\\', '/', substr($class, strlen($ns . '\\')));
                    $f   = $src . '/../tests/' . $rel . '.php';
                    if (is_file($f)) {
                        require_once $f;
                    }

                    return;
                }

                // 库命名空间：MornRain\<Lib>\Xxx → src/Xxx.php
                if (strpos($class, $libPrefix . '\\') !== 0) {
                    return;
                }
                $rel = str_replace('\\', '/', substr($class, strlen($libPrefix . '\\')));
                $f   = $src . '/' . $rel . '.php';
                if (is_file($f)) {
                    require_once $f;
                }
            });

            // 非 PSR-4 文件（函数包）需显式引入
            if (is_file($src . '/functions.php')) {
                require_once $src . '/functions.php';
            }
        }

        private function collect(string $filter): void
        {
            $files = glob($this->testDir . '/*Test.php') ?: [];
            sort($files);

            foreach ($files as $file) {
                require_once $file;

                $class = $this->prefix . '\\' . basename($file, '.php');
                if (!class_exists($class)) {
                    continue;
                }

                $ref = new \ReflectionClass($class);
                if ($ref->isAbstract()) {
                    continue;
                }

                foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                    if (strpos($method->getName(), 'test') !== 0) {
                        continue;
                    }
                    if ($filter !== '' && stripos($method->getName(), $filter) === false) {
                        continue;
                    }
                    $this->tests[] = ['class' => $class, 'method' => $method->getName()];
                }
            }
        }

        private function invoke(string $class, string $method): void
        {
            $instance = new $class();

            $setUp = new \ReflectionMethod($instance, 'setUp');
            $tearDown = new \ReflectionMethod($instance, 'tearDown');
            $setUp->setAccessible(true);
            $tearDown->setAccessible(true);

            $setUp->invoke($instance);

            try {
                $instance->{$method}();
            } finally {
                // 清理必须执行，否则临时目录会残留污染后续测试
                $tearDown->invoke($instance);
            }
        }

        private function out(string $text): void
        {
            fwrite(STDOUT, $text . PHP_EOL);
        }
    }
}