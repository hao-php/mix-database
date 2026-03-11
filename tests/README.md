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

### 使用脚本运行单个测试文件 / 方法

在项目根目录下：

- **只跑某个测试文件**：

```bash
tests/phpunit.sh tests/InsertTest.php
```

- **只跑某个测试方法**（例如 `PoolTest::testQueryTriggersCoroutineSwitch`）：

```bash
tests/phpunit.sh "tests/PoolTest.php --filter testQueryTriggersCoroutineSwitch"
```

- **只跑 Context（运行上下文封装）相关测试**：

```bash
tests/phpunit.sh tests/Context
```

说明：
- `tests/phpunit.sh` 要求必须传入一个参数（测试目标），可以是：
  - 单个文件：`tests/InsertTest.php`
  - 目录：`tests`
  - 携带额外参数的一整串字符串（例如上面的 `tests/PoolTest.php --filter ...`）
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

### 定位执行很久的测试用例

- **实时查看当前正在执行的用例**：

```bash
vendor/bin/phpunit --debug
```

  - 每个测试开始前会打印一行 `TestClass::testMethod`，卡住时最后一行就是当前跑得很久的用例。

- **优先运行历史上最慢的用例**（基于缓存中的耗时）：

```bash
vendor/bin/phpunit --order-by=duration --debug
```

  - 先用默认命令跑一遍生成缓存，然后用上面的命令，可以很快看到哪些用例最慢。

- **输出每个用例的耗时报告（JUnit 格式，可选）**：

```bash
vendor/bin/phpunit --log-junit tests/junit.xml
```

  - 打开 `tests/junit.xml`，每个 `<testcase>` 节点的 `time` 属性就是该用例的耗时，可按时间排序找到最慢的测试。

### 关于 PoolTest

- `PoolTest.php` 依赖 Swoole 协程环境。
- 在 `tests/phpunit.xml.dist` 中，如果需要跳过 Pool 相关测试，可以取消注释：

```xml
<!-- <exclude>tests/PoolTest.php</exclude> -->
```

