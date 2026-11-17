## 测试

复制 `tests/db.example.php` 为 `tests/db.php`，填写 MySQL、PostgreSQL 中需要测试的连接；留空的驱动不运行。测试会自行初始化基础表与种子数据，无需导入 SQL。建议使用独立的测试数据库。

在项目根目录运行：

```bash
php tests/run.php
```

可在命令末尾传入 PHPUnit 参数，例如 `php tests/run.php --filter TableManagementTest`。配置两个驱动时会分别启动一轮 PHPUnit，任一轮失败则入口返回非零状态。

`tests/phpunit.sh` 是同一入口的 shell 包装。直接运行 `vendor/bin/phpunit -c tests/phpunit.xml.dist` 只使用第一组配置；双库验证请使用 `tests/run.php`。`PoolTest` 依赖 Swoole 协程。

### 无数据库的冲突写入单元测试

以下命令绕过默认数据库初始化，仅使用连接替身验证 Model、驱动和参数绑定，不需要配置或连接测试数据库：

```bash
php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php tests/Context/ModelConflictTest.php

# 同时执行驱动与 SQL 安全回归
php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php tests/Context/ModelConflictTest.php tests/DriverTest.php tests/SqlSecurityTest.php
```

`ModelConflictTest` 覆盖单条与批量冲突写入、字段顺序对齐、自动及显式时间、自定义和关闭时间字段、DO NOTHING、非法参数与异常后的模型状态清理；这些测试不替代真实 MySQL、PostgreSQL 的集成测试。
