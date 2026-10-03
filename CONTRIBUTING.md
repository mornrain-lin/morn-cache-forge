# 贡献指南

感谢你愿意为 **MornRain Cache Forge** 出力。这份文档说明如何把改动安全地并进来。

- 库定位：多级缓存封装（内存 → 对象缓存 → Transient → 文件）
- 负责范围：缓存门面、文件存储、击穿锁、序列化与标签失效
- 协议：[MIT](LICENSE)

## 环境要求

| 项目 | 要求 |
| --- | --- |
| PHP | >=7.4 |
| 扩展 | ['不要求额外扩展（用到 mbstring 时会自动降级）'] |
| 最低受支持版本 | PHP 7.4（CI 亦覆盖 8.0 ~ 8.4） |

本库刻意不使用 PHP 8 专属语法（如 `match`、`enum`、`readonly`、构造器属性提升、
`str_contains()` 等）。新增代码必须继续遵守这一约束，
否则会直接破坏我们对外声明的兼容性。

## 快速开始

```bash
git clone <你的 fork>
cd morn-cache-forge
composer install
```

## 跑测试

本库提供两条等价的测试路径，用同一份用例：

```bash
# 零依赖方式（推荐先跑这个，不需要 composer install）
php tests/run-tests.php

# 只跑名称含某关键字的用例
php tests/run-tests.php robots

# 装了 PHPUnit 时
composer test          # 等价于 vendor/bin/phpunit
vendor/bin/phpunit --filter testRobots
```

**提交前请确保下面三条命令全部为 0 退出码：**

```bash
php tests/run-tests.php     # 用例全绿
composer lint               # 所有 PHP 文件语法正确
composer lint:style         # PSR-12 代码风格
```

## 写测试的要求

现有用例不只覆盖「正常路径」，还必须覆盖：

1. **边界情况** —— 空值、空数组、零与负数、超长输入、多字节与 emoji；
2. **安全路径** —— 注入、XSS、路径穿越、令牌篡改、重放；
3. **回归用例** —— 修 bug 时必须同时补一条能复现该 bug 的断言。

反例（会被打回）：

```php
public function testGet(): void
{
    $this->assertTrue(true);       // 什么都没验证
}

public function testEmptyString(): void
{
    // 只测了空字符串，没有测 null / 空数组 / 超长输入
    $this->assertSame('', $obj->handle(''));
}
```

正例：

```php
public function testHandleRejectsNull(): void
{
    self::assertThrows(InvalidArgumentException::class, function (): void {
        $obj->handle(null);
    });
}
```

测试类请**一文件一类**，文件名以 `Test.php` 结尾，
放在 `tests/` 并使用命名空间 `MornRain\CacheForge\Tests`。

## 代码风格

- 遵循 PSR-12，配置见 `phpcs.xml.dist`；
- 行宽上限 150（中文注释按字节计，`PSR12` 默认 120 会大量误报）；
- 文件统一 `declare(strict_types=1);`；
- 命名空间与目录严格对应 PSR-4，**一个文件一个类**；
- 公开方法必须有类型声明与 `@param` / `@return` docblock；
- 中文注释解释「为什么」，不要复述「做了什么」。

## 兼容性红线

以下改动**必须**视为破坏性变更，需要走主版本号升级：

- 删除或重命名任何 public 方法 / 常量；
- 修改 public 方法的参数数量、顺序或类型；
- 改变已有返回值的结构或语义；
- 提高最低 PHP 版本要求。

新增可选参数、抛出新的异常类型属于可接受变更，但需要在
`CHANGELOG.md` 的 `### Changed` 或 `### Fixed` 中写明。

## 禁止事项

- ❌ 不要引入**运行时**依赖（本库必须「零依赖」，dev 依赖随意）；
- ❌ 不要使用 PHP 8 专属语法；
- ❌ 不要提交任何真实凭据、令牌、盐值或私钥；
- ❌ 不要在代码里留下 `var_dump()` / `print_r()` 等调试语句；
- ❌ 不要为了让测试通过而放宽断言。

## 提交与PR

1. 从 `main` 切出特性分支：`git checkout -b fix/xxx`；
2. 改动尽量小而聚焦，一个 PR 只做一件事；
3. 提交信息采用 [Conventional Commits](https://www.conventionalcommits.org/)：

   ```
   fix(guard-kit): 非可信代理不应读取 X-Forwarded-For

   此前通配符 203.0.113.* 被算成 /23，导致相邻网段也被信任，
   可被用于伪造客户端 IP。改为 /24 并对中途通配直接报错。
   ```

4. 推送后开 PR，描述中请写明：
   - 问题现象与影响范围；
   - 修复思路；
   - 关联的 issue（若有）。

## 授权

本项目采用 MIT 协议。**你提交的任何贡献，都视为你同意将其以 MIT 协议授权出去**，
与本仓库原有代码采用同一份许可。贡献即代表你确认拥有该代码的合法权利。

## 安全问题

**请不要**用公开 issue 报告安全漏洞。参见 [SECURITY.md](SECURITY.md) 的私下报告流程。
