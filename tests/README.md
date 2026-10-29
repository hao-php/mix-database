## 测试

复制 `tests/db.example.php` 为 `tests/db.php`，填写 MySQL、PostgreSQL 中需要测试的连接；留空的驱动不运行。测试会自行初始化基础表与种子数据，无需导入 SQL。建议使用独立的测试数据库。

在项目根目录运行：

```bash
php tests/run.php
```

可在命令末尾传入 PHPUnit 参数，例如 `php tests/run.php --filter TableManagementTest`。配置两个驱动时会分别启动一轮 PHPUnit，任一轮失败则入口返回非零状态。

`tests/phpunit.sh` 是同一入口的 shell 包装。直接运行 `vendor/bin/phpunit -c tests/phpunit.xml.dist` 只使用第一组配置；双库验证请使用 `tests/run.php`。`PoolTest` 依赖 Swoole 协程。
