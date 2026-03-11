## 测试说明

- **测试入口脚本**：`tests/phpunit.sh`
- **默认配置文件**：`tests/phpunit.xml.dist`（仓库内共享），根目录下的 `phpunit.xml` 仅用于本地覆盖，已被 `.gitignore` 忽略。

### 运行

在项目根目录执行：

```bash
vendor/bin/phpunit -c tests/phpunit.xml.dist
```

### 使用脚本运行单个测试文件

项目根目录下：

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

### 关于 PoolTest

- `PoolTest.php` 依赖 Swoole 协程环境。
- 在 `tests/phpunit.xml.dist` 中，如果需要跳过 Pool 相关测试，可以取消注释：

```xml
<!-- <exclude>tests/PoolTest.php</exclude> -->
```

