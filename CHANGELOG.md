# Changelog

本项目遵循 [语义化版本 2.0.0](https://semver.org/lang/zh-CN/)。

## [1.0.1] - 2026-10-03

### Fixed

- **击穿锁等待方可能拿到 `null`**：`rememberLocked()` 的 `readHit` 回调用**空串**表示
  「尚未读到」，而 `StampedeLock` 只把 `null` / `false` 视为未命中。空串因此被误判为
  「已读到」，导致并发场景下等待方直接返回 `null` 而非持锁方写入的真实值。
  现改为以 `null` 作为唯一的未命中信号，并在 `StampedeLock` 的文档中明确该约定。
- **`false` 值被当成反序列化失败**：`unserializeLoose()` 用 `$value === false` 判定失败，
  但 `serialize(false)` 的结果 `'b:0;'` 反序列化后**也是** `false`。
  回调返回 `false` 时 `rememberLocked()` 会错误地返回 `null`。
  现改为比对原始串（与 `Serializer::safeUnserialize()` 一致）。
- **`flushWhere()` 的 AND 条件形同虚设**：原实现先把 `anyOfTags` 的键并入结果集，
  再用 `includeTags` 过滤。但由于 `includeTags` 的键也被并入候选集，
  最终留下的是两者的**并集**——只要命中任一 `anyOf` 标签就会被删除，
  导致 `flushWhere(['lang:zh'], ['type:post'])` 会误删同语言的其他类型。
  现改为 `(命中全部 include) AND (命中任一 anyOf)` 的正确交集语义。
- **目录被外部删除后触发致命错误**：`FileStore::flush()` / `gc()` / `files()` / `stats()`
  与 `StampedeLock::gc()` / `stats()` 直接构造 `RecursiveDirectoryIterator`，
  目录不存在时会抛 `UnexpectedValueException`。现统一走安全的迭代器辅助方法，优雅降级。
- **标签名可能撞车**：`sanitizeTag()` 会把 `/`、`.` 等字符直接删除，
  导致 `a/../b` 与 `a_b` 清洗后同名，共用同一份反查表而互相误删。
  现将连续的点号与路径分隔符统一归一为下划线。
- 移除 `FileStore::set()` 中一段无意义的重复 `ensureDirectory()` 调用（死代码）。

### Added

- `tests/`：62 个用例 / 144 条断言，覆盖多级读写、null 语义、原子写入、
  惰性过期、击穿锁异常释放、标签失效与序列化器对象注入防护。
- `tests/run-tests.php`：零依赖测试运行器，无需 Composer 即可 `php tests/run-tests.php`。
- `phpunit.xml.dist`、`phpcs.xml.dist`（PSR-12）、`CONTRIBUTING.md`、`SECURITY.md`。

## [1.0.0] - 2026-10-02

### 新增

- `CacheForge`：多级缓存门面
  - 读取优先级：内存 → 对象缓存 → Transient → 文件 → 默认值
  - 写入自上而下填充（`fillDown`），命中下层时自动回填上层，避免重复穿透
  - `get()` / `set()` / `delete()` / `has()` / `increment()`
  - `remember()`：未命中时执行回调并回填
  - `rememberLocked()`：配合击穿锁的 remember 变体
  - 唯一哨兵值机制，正确区分「缓存值为 null / false」与「未命中」
  - `flushTags()` / `flushWhere()` / `gc()` / `flush()`
  - `report()` 完整诊断报告
- `FileStore`：文件缓存存储
  - 目录按需创建，0755 / 0644 权限
  - 原子写入：同目录临时文件 + `rename`，并发读永远看到完整内容
  - 排他锁（`flock`）保护并发写
  - 惰性过期（读时判 mtime）+ `gc()` 主动清理
  - 键名 md5 化 + 二级目录分散，杜绝路径穿越
  - `increment()` 原子自增
  - `flush()` / `gc()` / `stats()` / `files()`
- `StampedeLock`：缓存击穿锁
  - 模式 `x` 独占创建实现原子加锁
  - `remember()`：抢到锁的执行回调，未抢到轮询等待，超时降级
  - 锁过期时间附加随机抖动，避免锁惊群
  - 令牌校验，只有持有者可释放
  - 回调抛异常时保证释放锁
  - `gc()` / `stats()` / `isLocked()` / `forceRelease()`
- `Serializer`：序列化封装
  - JSON 优先（可读、跨语言、便于 `jq` 排查）
  - JSON 失败（NaN / INF / 资源）自动回退原生格式
  - 信封结构：版本 + 类型 + 格式 + 负载，支持格式演进
  - `unserialize` 默认 `allowed_classes => false`，防对象注入
  - 损坏数据、非信封历史数据、base64 退化格式均能安全处理
- `CacheTags`：标签失效机制
  - `register()` / `keysFor()` 维护 tag → keys 反查表
  - `flushTags()` 按标签批量失效（OR）
  - `flushWhere()` 组合条件失效（AND / OR）
  - 单标签键数量上限 5000，防止索引膨胀
  - `gc()` 清理过期条目
- `Metrics`：命中率统计
  - 分层记录读 / 命中 / 写 / 删除
  - `hitRate()` / `layers()` / `report()` / `summary()`
  - `hotKeys()` 热点键、`uselessKeys()` 有写无读的浪费键
  - 记录每次操作的耗时，输出平均毫秒数
- `CachedValue`：Transient 层值包装器
  - 解决 `get_transient()` 用 `false` 同时表示「未命中」与「值为 false」的歧义
- `Compat`：随机数与进程 ID 垫片
- `examples/cache.php`：11 个场景的可运行示例

[1.0.0]: https://github.com/MornRain/morn-cache-forge/releases/tag/v1.0.0
