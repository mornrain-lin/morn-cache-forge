<?php
/**
 * 缓存命中率统计。
 *
 * 记录读、写、命中、未命中、删除各类事件的次数，并按层级
 * （内存 / 对象缓存 / Transient / 文件）分别计数，
 * 便于判断「瓶颈在哪一层」和「该不该加内存层」。
 *
 * 设计为进程内单例语义：一次请求内的所有操作共享同一份计数，
 * 便于在请求结束时输出诊断报告或写入 Transient 做趋势统计。
 *
 * @package MornRain\CacheForge
 */

declare(strict_types=1);

namespace MornRain\CacheForge;

/**
 * 指标统计器。
 */
class Metrics
{
    /** 层级：请求内内存 */
    public const LAYER_MEMORY = 'memory';

    /** 层级：WordPress 对象缓存 */
    public const LAYER_OBJECT = 'object';

    /** 层级：WordPress Transient */
    public const LAYER_TRANSIENT = 'transient';

    /** 层级：文件 */
    public const LAYER_FILE = 'file';

    /** 层级：默认值（未命中） */
    public const LAYER_DEFAULT = 'default';

    /** @var array<string,int> 读操作计数 */
    protected $reads = [];

    /** @var array<string,int> 命中计数 */
    protected $hits = [];

    /** @var array<string,int> 写操作计数 */
    protected $writes = [];

    /** @var array<string,int> 删除计数 */
    protected $deletes = [];

    /** @var array<string,float> 各操作累计耗时（秒） */
    protected $timings = [];

    /** @var array<string,array{hits:int,misses:int}> 分层明细 */
    protected $byLayer = [];

    /**
     * 记录一次读取。
     *
     * @param string $key   缓存键。
     * @param bool   $hit   是否命中。
     * @param string $layer 所在层级。
     * @param float  $cost  耗时（秒）。
     */
    public function recordRead(string $key, bool $hit, string $layer = '', float $cost = 0.0): void
    {
        $this->reads[$key] = ($this->reads[$key] ?? 0) + 1;

        if ($hit) {
            $this->hits[$key] = ($this->hits[$key] ?? 0) + 1;
        }

        if ($layer !== '') {
            $layer = $this->normalizeLayer($layer);
            $this->layerStat($layer, $hit ? 'hits' : 'misses');
        }

        if ($cost > 0) {
            $this->timings[$key] = ($this->timings[$key] ?? 0.0) + $cost;
        }
    }

    /**
     * 记录一次写入。
     *
     * @param string $key   缓存键。
     * @param string $layer 所在层级。
     */
    public function recordWrite(string $key, string $layer = ''): void
    {
        $this->writes[$key] = ($this->writes[$key] ?? 0) + 1;
        if ($layer !== '') {
            $layer = $this->normalizeLayer($layer);
            $this->layerStat($layer, 'writes');
        }
    }

    /**
     * 记录一次删除。
     *
     * @param string $key   缓存键。
     * @param string $layer 所在层级。
     */
    public function recordDelete(string $key, string $layer = ''): void
    {
        $this->deletes[$key] = ($this->deletes[$key] ?? 0) + 1;
        if ($layer !== '') {
            $layer = $this->normalizeLayer($layer);
            $this->layerStat($layer, 'deletes');
        }
    }

    /**
     * 取得并初始化分层统计槽位。
     *
     * 统一走这个方法，避免某类事件先创建了槽位却缺少其他字段，
     * 导致后续 `$stat['hits']++` 触发未定义键告警。
     *
     * @param string $layer 层级名。
     * @param string $field 字段名。
     */
    protected function layerStat(string $layer, string $field): int
    {
        if (!isset($this->byLayer[$layer])) {
            $this->byLayer[$layer] = ['hits' => 0, 'misses' => 0, 'writes' => 0, 'deletes' => 0];
        }
        if (!isset($this->byLayer[$layer][$field])) {
            $this->byLayer[$layer][$field] = 0;
        }
        $this->byLayer[$layer][$field]++;

        return $this->byLayer[$layer][$field];
    }

    /**
     * 取得某个键的读取次数。
     */
    public function readsOf(string $key): int
    {
        return $this->reads[$key] ?? 0;
    }

    /**
     * 取得某个键的命中次数。
     */
    public function hitsOf(string $key): int
    {
        return $this->hits[$key] ?? 0;
    }

    /**
     * 取得某个键的写入次数。
     */
    public function writesOf(string $key): int
    {
        return $this->writes[$key] ?? 0;
    }

    /**
     * 取得某个键的删除次数。
     */
    public function deletesOf(string $key): int
    {
        return $this->deletes[$key] ?? 0;
    }

    /**
     * 取得某个键的平均读取耗时（毫秒）。
     */
    public function averageCostOf(string $key): float
    {
        $reads = $this->reads[$key] ?? 0;
        if ($reads === 0) {
            return 0.0;
        }

        return round(($this->timings[$key] ?? 0.0) / $reads * 1000, 3);
    }

    /**
     * 整体命中率（0~1）。
     */
    public function hitRate(): float
    {
        $totalReads = array_sum($this->reads);
        if ($totalReads === 0) {
            return 0.0;
        }

        return round(array_sum($this->hits) / $totalReads, 4);
    }

    /**
     * 某个键的命中率（0~1）。
     */
    public function hitRateOf(string $key): float
    {
        $reads = $this->reads[$key] ?? 0;
        if ($reads === 0) {
            return 0.0;
        }

        return round(($this->hits[$key] ?? 0) / $reads, 4);
    }

    /**
     * 分层统计。
     *
     * @return array<string,array<string,mixed>>
     */
    public function layers(): array
    {
        $out = [];
        foreach ($this->byLayer as $layer => $stat) {
            $total = $stat['hits'] + $stat['misses'];
            $out[$layer] = [
                'hits'     => $stat['hits'],
                'misses'   => $stat['misses'],
                'writes'   => $stat['writes'] ?? 0,
                'deletes'  => $stat['deletes'] ?? 0,
                'requests' => $total,
                'hit_rate' => $total > 0 ? round($stat['hits'] / $total, 4) : 0.0,
            ];
        }

        return $out;
    }

    /**
     * 完整报告。
     *
     * @return array<string,mixed>
     */
    public function report(): array
    {
        return [
            'total_reads'    => array_sum($this->reads),
            'total_hits'     => array_sum($this->hits),
            'total_misses'   => array_sum($this->reads) - array_sum($this->hits),
            'total_writes'   => array_sum($this->writes),
            'total_deletes'  => array_sum($this->deletes),
            'hit_rate'       => $this->hitRate(),
            'hit_rate_human' => $this->hitRate() >= 0.9
                ? '优秀'
                : ($this->hitRate() >= 0.7 ? '良好' : ($this->hitRate() >= 0.4 ? '偏低' : '很差')),
            'unique_keys'    => count($this->reads),
            'layers'         => $this->layers(),
            'hot_keys'       => $this->hotKeys(5),
            'useless_keys'   => $this->uselessKeys(5),
        ];
    }

    /**
     * 可读报告。
     */
    public function summary(): string
    {
        $report = $this->report();
        $lines  = [];
        $lines[] = sprintf(
            '读取 %d 次，命中 %d 次（%.1f%%，%s），写入 %d 次，删除 %d 次，涉及 %d 个键。',
            $report['total_reads'],
            $report['total_hits'],
            $report['hit_rate'] * 100,
            $report['hit_rate_human'],
            $report['total_writes'],
            $report['total_deletes'],
            $report['unique_keys']
        );

        if ($report['layers'] !== []) {
            $lines[] = '分层明细：';
            foreach ($report['layers'] as $layer => $stat) {
                $lines[] = sprintf(
                    '    %-10s 请求 %3d，命中 %3d（%.1f%%），写入 %3d',
                    $layer,
                    $stat['requests'],
                    $stat['hits'],
                    $stat['hit_rate'] * 100,
                    $stat['writes']
                );
            }
        }

        if ($report['hot_keys'] !== []) {
            $lines[] = '热点键：';
            foreach ($report['hot_keys'] as $key => $stat) {
                $lines[] = sprintf('    %s（读 %d 次，命中 %.0f%%，均耗 %.3f ms）', $key, $stat['reads'], $stat['hit_rate'] * 100, $stat['avg_cost_ms']);
            }
        }

        if ($report['useless_keys'] !== []) {
            $lines[] = '零命中键（缓存了但没人读，或每次都未命中）：';
            foreach ($report['useless_keys'] as $key) {
                $lines[] = '    ' . $key;
            }
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * 找出读取最频繁的键。
     *
     * @param int $limit 返回数量。
     * @return array<string,array<string,mixed>>
     */
    public function hotKeys(int $limit = 10): array
    {
        $keys = $this->reads;
        arsort($keys);

        $out = [];
        foreach (array_slice($keys, 0, max(1, $limit), true) as $key => $reads) {
            $out[(string) $key] = [
                'reads'       => $reads,
                'hits'        => $this->hits[$key] ?? 0,
                'hit_rate'    => $this->hitRateOf((string) $key),
                'avg_cost_ms' => $this->averageCostOf((string) $key),
            ];
        }

        return $out;
    }

    /**
     * 找出有写无读的键 —— 这类缓存是纯浪费。
     *
     * @param int $limit 返回数量。
     * @return array<int,string>
     */
    public function uselessKeys(int $limit = 10): array
    {
        $out = [];
        foreach ($this->writes as $key => $writes) {
            if (($this->reads[$key] ?? 0) === 0) {
                $out[] = (string) $key;
            }
        }

        return array_slice($out, 0, max(1, $limit));
    }

    /**
     * 归一化层级名称。
     */
    protected function normalizeLayer(string $layer): string
    {
        $map = [
            'memory'    => self::LAYER_MEMORY,
            'object'    => self::LAYER_OBJECT,
            'obj'       => self::LAYER_OBJECT,
            'transient' => self::LAYER_TRANSIENT,
            'file'      => self::LAYER_FILE,
            'default'   => self::LAYER_DEFAULT,
        ];
        $key = strtolower(trim($layer));

        return $map[$key] ?? $key;
    }

    /**
     * 清空所有统计。
     */
    public function reset(): self
    {
        $this->reads    = [];
        $this->hits     = [];
        $this->writes   = [];
        $this->deletes  = [];
        $this->timings  = [];
        $this->byLayer  = [];

        return $this;
    }
}
