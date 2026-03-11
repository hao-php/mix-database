## 测试说明

- **测试入口脚本**：`tests/phpunit.sh`
- **默认配置文件**：`tests/phpunit.xml.dist`（仓库内共享），根目录下的 `phpunit.xml` 仅用于本地覆盖，已被 `.gitignore` 忽略。
- **数据库配置文件**：
  - 示例：`tests/db.example.php`
  - 实际使用：`tests/db.php`（从示例复制并修改）

### 准备数据库配置

在项目根目录下执行：

```bash
cp tests/db.example.php tests/db.php
```

然后根据你的环境修改 `tests/db.php` 中的常量：

> 建议在 `.gitignore` 中忽略 `tests/db.php`，避免把本地数据库账号密码提交到仓库。

### 在项目根目录下运行所有测试

**使用配置文件（推荐）：**

```bash
vendor/bin/phpunit -c tests/phpunit.xml.dist
```

**不依靠 phpunit.xml / phpunit.xml.dist 的完整命令（显式指定 bootstrap 和测试目录）：**

```bash
vendor/bin/phpunit --bootstrap=tests/bootstrap.php tests
```

### 使用脚本运行单个测试文件

在项目根目录下：

```bash
tests/phpunit.sh tests/InsertTest.php
```

说明：
- `tests/phpunit.sh` 要求必须传入一个参数（测试目标），可以是：
  - 单个文件：`tests/InsertTest.php`
  - 目录：`tests`
- 脚本实际执行命令等价于：

```bash
vendor/bin/phpunit -c tests/phpunit.xml.dist <你传入的目标>
```

### PHPUnit 结果缓存与常用选项

- **结果缓存文件**：`.phpunit.result.cache`，记录每个测试上一次的结果和耗时，用来优化执行顺序。

- **按缺陷优先执行（基于缓存中的结果）**：

```bash
vendor/bin/phpunit --order-by=defects
```

  - 会优先运行上一次**失败 / 错误 / 不完整 / 跳过**的测试，用缓存里的结果决定“先跑谁”。

- **按耗时排序执行（依赖缓存中的耗时数据）**：

```bash
vendor/bin/phpunit --order-by=duration
```

- **控制是否写入缓存**：

```bash
vendor/bin/phpunit --order-by=defects --do-not-cache-result
```

  - `--cache-result`：运行结束后写入/更新 `.phpunit.result.cache`（新版本一般默认开启）。
  - `--do-not-cache-result`：本次运行不更新缓存（仍然可以读取旧缓存）。

> 一般开发场景可以直接使用 `--order-by=defects`，快速看到修复是否生效；若只是想“干净跑一轮”又不想影响缓存，可加上 `--do-not-cache-result`。

### 关于 PoolTest

- `PoolTest.php` 依赖 Swoole 协程环境。
- 在 `tests/phpunit.xml.dist` 中，如果需要跳过 Pool 相关测试，可以取消注释：

```xml
<!-- <exclude>tests/PoolTest.php</exclude> -->
```

