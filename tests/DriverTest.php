<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Haoa\MixDatabase\Driver\DriverFactory;
use Haoa\MixDatabase\Driver\DriverInterface;
use Haoa\MixDatabase\Driver\MysqlDriver;
use Haoa\MixDatabase\Driver\PgsqlDriver;

/**
 * 驱动架构测试
 */
final class DriverTest extends TestCase
{

    // ==================== DriverFactory 测试 ====================

    /** 工厂应根据 MySQL DSN 创建 MySQL 驱动 */
    public function testFactoryCreatesMysqlDriver(): void
    {
        $driver = DriverFactory::create('mysql:host=127.0.0.1;dbname=test');
        $this->assertInstanceOf(MysqlDriver::class, $driver);
        $this->assertInstanceOf(DriverInterface::class, $driver);
    }

    /** 工厂应根据 PostgreSQL DSN 创建 PostgreSQL 驱动 */
    public function testFactoryCreatesPgsqlDriver(): void
    {
        $driver = DriverFactory::create('pgsql:host=127.0.0.1;dbname=test');
        $this->assertInstanceOf(PgsqlDriver::class, $driver);
        $this->assertInstanceOf(DriverInterface::class, $driver);
    }

    /** 工厂收到不支持的 DSN 时应抛出异常 */
    public function testFactoryThrowsOnUnsupportedDsn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DriverFactory::create('unsupported:host=127.0.0.1');
    }

    /** 工厂应支持注册并创建自定义驱动 */
    public function testFactoryRegisterCustomDriver(): void
    {
        DriverFactory::register('custom', MysqlDriver::class);
        $driver = DriverFactory::create('custom:host=127.0.0.1');
        $this->assertInstanceOf(MysqlDriver::class, $driver);
    }

    // ==================== MysqlDriver 方言测试 ====================

    /** MySQL 驱动应生成正确的 LIMIT 偏移语法和参数 */
    public function testMysqlBuildLimit(): void
    {
        $driver = new MysqlDriver();
        list($sql, $values) = $driver->buildLimit(10, 5);
        $this->assertEquals('LIMIT ?, ?', $sql);
        $this->assertEquals([10, 5], $values);
    }

    /** MySQL 驱动应生成复制表结构的 SQL */
    public function testMysqlBuildCreateTableLikeSql(): void
    {
        $driver = new MysqlDriver();
        $sql = $driver->buildCreateTableLikeSql('runtime_log', 'runtime_log_2026_08_10');
        $this->assertEquals(
            'CREATE TABLE IF NOT EXISTS `runtime_log_2026_08_10` LIKE `runtime_log`',
            $sql
        );
    }

    /** MySQL 驱动应生成检查数据表是否存在的 SQL */
    public function testMysqlBuildTableExistsSql(): void
    {
        $driver = new MysqlDriver();
        list($sql, $values) = $driver->buildTableExistsSql('runtime_log_20260810');

        $this->assertEquals(
            'SELECT EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?) AS table_exists',
            $sql
        );
        $this->assertEquals(['runtime_log_20260810'], $values);
    }

    /** MySQL 驱动应返回共享锁语法 */
    public function testMysqlSharedLock(): void
    {
        $driver = new MysqlDriver();
        $this->assertEquals('LOCK IN SHARE MODE', $driver->sharedLockSql());
    }

    /** MySQL 驱动应返回排他锁语法 */
    public function testMysqlForUpdate(): void
    {
        $driver = new MysqlDriver();
        $this->assertEquals('FOR UPDATE', $driver->forUpdateSql());
    }

    /** MySQL 断线错误特征应包含 server has gone away */
    public function testMysqlDisconnectMessages(): void
    {
        $driver = new MysqlDriver();
        $messages = $driver->disconnectMessages();
        $this->assertIsArray($messages);
        $this->assertContains('server has gone away', $messages);
    }

    // ==================== PgsqlDriver 方言测试 ====================

    /** PostgreSQL 驱动应生成正确的 LIMIT OFFSET 语法和参数 */
    public function testPgsqlBuildLimit(): void
    {
        $driver = new PgsqlDriver();
        list($sql, $values) = $driver->buildLimit(10, 5);
        $this->assertEquals('LIMIT ? OFFSET ?', $sql);
        // PgSQL: LIMIT count OFFSET offset
        $this->assertEquals([5, 10], $values);
    }

    /** PostgreSQL 驱动应生成复制表结构的 SQL */
    public function testPgsqlBuildCreateTableLikeSql(): void
    {
        $driver = new PgsqlDriver();
        $sql = $driver->buildCreateTableLikeSql('runtime_log', 'runtime_log_2026_08_10');
        $this->assertEquals(
            'CREATE TABLE IF NOT EXISTS "runtime_log_2026_08_10" (LIKE "runtime_log" INCLUDING ALL)',
            $sql
        );
    }

    /** PostgreSQL 驱动应生成检查数据表是否存在的 SQL */
    public function testPgsqlBuildTableExistsSql(): void
    {
        $driver = new PgsqlDriver();
        list($sql, $values) = $driver->buildTableExistsSql('runtime_log_20260810');

        $this->assertEquals(
            'SELECT CASE WHEN to_regclass(?) IS NULL THEN 0 ELSE 1 END AS table_exists',
            $sql
        );
        $this->assertEquals(['runtime_log_20260810'], $values);
    }

    /** PostgreSQL 驱动应返回共享锁语法 */
    public function testPgsqlSharedLock(): void
    {
        $driver = new PgsqlDriver();
        $this->assertEquals('FOR SHARE', $driver->sharedLockSql());
    }

    /** PostgreSQL 驱动应返回排他锁语法 */
    public function testPgsqlForUpdate(): void
    {
        $driver = new PgsqlDriver();
        $this->assertEquals('FOR UPDATE', $driver->forUpdateSql());
    }

    /** PostgreSQL 断线错误特征应包含 connection is closed */
    public function testPgsqlDisconnectMessages(): void
    {
        $driver = new PgsqlDriver();
        $messages = $driver->disconnectMessages();
        $this->assertIsArray($messages);
        $this->assertContains('connection is closed', $messages);
    }

    // ==================== MysqlDriver 引号处理测试 ====================

    /** MySQL 驱动应使用反引号作为标识符引号 */
    public function testMysqlGetQuoteChar(): void
    {
        $driver = new MysqlDriver();
        $this->assertEquals(['`', '`'], $driver->getQuoteChar());
    }

    /** MySQL 默认关闭标识符引用时应原样返回表名 */
    public function testMysqlQuoteTableNameDisabledByDefault(): void
    {
        $driver = new MysqlDriver();
        // 默认关闭，不加引号
        $this->assertEquals('users', $driver->quoteTableName('users'));
        $this->assertEquals('users AS u', $driver->quoteTableName('users AS u'));
        $this->assertEquals('users u', $driver->quoteTableName('users u'));
    }

    /** MySQL 开启标识符引用后应正确处理表名及别名 */
    public function testMysqlQuoteTableNameEnabled(): void
    {
        $driver = new MysqlDriver();
        $driver->setQuoteIdentifiers(true);
        $this->assertEquals('`users`', $driver->quoteTableName('users'));
        $this->assertEquals('`users` AS `u`', $driver->quoteTableName('users AS u'));
        $this->assertEquals('`users` `u`', $driver->quoteTableName('users u'));
        $this->assertEquals('`users`', $driver->quoteTableName('`users`'));
    }

    /** MySQL 默认关闭标识符引用时应原样返回字段名 */
    public function testMysqlQuoteColumnNameDisabledByDefault(): void
    {
        $driver = new MysqlDriver();
        // 默认关闭，不加引号
        $this->assertEquals('name', $driver->quoteColumnName('name'));
        $this->assertEquals('users.name', $driver->quoteColumnName('users.name'));
        $this->assertEquals('*', $driver->quoteColumnName('*'));
        $this->assertEquals('n.*', $driver->quoteColumnName('n.*'));
    }

    /** MySQL 开启标识符引用后应正确处理字段名和通配符 */
    public function testMysqlQuoteColumnNameEnabled(): void
    {
        $driver = new MysqlDriver();
        $driver->setQuoteIdentifiers(true);
        $this->assertEquals('`name`', $driver->quoteColumnName('name'));
        $this->assertEquals('`users`.`name`', $driver->quoteColumnName('users.name'));
        $this->assertEquals('`name`', $driver->quoteColumnName('`name`'));
        $this->assertEquals('*', $driver->quoteColumnName('*'));
        // 复杂表达式不处理
        $this->assertEquals('count(*) as mix_count', $driver->quoteColumnName('count(*) as mix_count'));
        $this->assertEquals('n.*, u.name', $driver->quoteColumnName('n.*, u.name'));
        $this->assertEquals('uid, COUNT(*) AS total', $driver->quoteColumnName('uid, COUNT(*) AS total'));
        // table.* 格式
        $this->assertEquals('`n`.*', $driver->quoteColumnName('n.*'));
    }

    // ==================== PgsqlDriver 引号处理测试 ====================

    /** PostgreSQL 驱动应使用双引号作为标识符引号 */
    public function testPgsqlGetQuoteChar(): void
    {
        $driver = new PgsqlDriver();
        $this->assertEquals(['"', '"'], $driver->getQuoteChar());
    }

    /** PostgreSQL 默认关闭标识符引用时应原样返回表名 */
    public function testPgsqlQuoteTableNameDisabledByDefault(): void
    {
        $driver = new PgsqlDriver();
        // 默认关闭，不加引号
        $this->assertEquals('users', $driver->quoteTableName('users'));
        $this->assertEquals('users AS u', $driver->quoteTableName('users AS u'));
        $this->assertEquals('users u', $driver->quoteTableName('users u'));
    }

    /** PostgreSQL 开启标识符引用后应正确处理表名及别名 */
    public function testPgsqlQuoteTableNameEnabled(): void
    {
        $driver = new PgsqlDriver();
        $driver->setQuoteIdentifiers(true);
        $this->assertEquals('"users"', $driver->quoteTableName('users'));
        $this->assertEquals('"users" AS "u"', $driver->quoteTableName('users AS u'));
        $this->assertEquals('"users" "u"', $driver->quoteTableName('users u'));
        $this->assertEquals('"users"', $driver->quoteTableName('"users"'));
    }

    /** PostgreSQL 默认关闭标识符引用时应原样返回字段名 */
    public function testPgsqlQuoteColumnNameDisabledByDefault(): void
    {
        $driver = new PgsqlDriver();
        // 默认关闭，不加引号
        $this->assertEquals('name', $driver->quoteColumnName('name'));
        $this->assertEquals('users.name', $driver->quoteColumnName('users.name'));
        $this->assertEquals('*', $driver->quoteColumnName('*'));
        $this->assertEquals('n.*', $driver->quoteColumnName('n.*'));
    }

    /** PostgreSQL 开启标识符引用后应正确处理字段名和通配符 */
    public function testPgsqlQuoteColumnNameEnabled(): void
    {
        $driver = new PgsqlDriver();
        $driver->setQuoteIdentifiers(true);
        $this->assertEquals('"name"', $driver->quoteColumnName('name'));
        $this->assertEquals('"users"."name"', $driver->quoteColumnName('users.name'));
        $this->assertEquals('"name"', $driver->quoteColumnName('"name"'));
        $this->assertEquals('*', $driver->quoteColumnName('*'));
        // 复杂表达式不处理
        $this->assertEquals('count(*) as mix_count', $driver->quoteColumnName('count(*) as mix_count'));
        $this->assertEquals('n.*, u.name', $driver->quoteColumnName('n.*, u.name'));
        $this->assertEquals('uid, COUNT(*) AS total', $driver->quoteColumnName('uid, COUNT(*) AS total'));
        // table.* 格式
        $this->assertEquals('"n".*', $driver->quoteColumnName('n.*'));
    }

    // ==================== MysqlDriver 特有方法测试 ====================

    /** MySQL 驱动应生成带参数的 REPLACE INTO 语句 */
    public function testMysqlBuildReplaceInsert(): void
    {
        $driver = new MysqlDriver();
        $driver->setQuoteIdentifiers(true);
        $result = $driver->buildReplaceInsert('users', ['name' => 'foo', 'balance' => 10]);
        $this->assertStringContainsString('REPLACE INTO `users`', $result['sql']);
        $this->assertStringContainsString('`name`', $result['sql']);
        $this->assertStringContainsString('`balance`', $result['sql']);
        $this->assertArrayHasKey('params', $result);
        $this->assertEquals(['name' => 'foo', 'balance' => 10], $result['params']);
    }

    /** MySQL 驱动应生成 ON DUPLICATE KEY UPDATE 语句 */
    public function testMysqlBuildInsertOnDuplicateKey(): void
    {
        $driver = new MysqlDriver();
        $driver->setQuoteIdentifiers(true);
        $result = $driver->buildInsertOnDuplicateKey('users', ['name' => 'foo', 'balance' => 10], ['balance']);
        $this->assertStringContainsString('INSERT INTO', $result['sql']);
        $this->assertStringContainsString('`users`', $result['sql']);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $result['sql']);
        $this->assertStringContainsString('`balance` = VALUES(`balance`)', $result['sql']);
        $this->assertArrayHasKey('values', $result);
        $this->assertEquals(['foo', 10], $result['values']);
    }

    // ==================== PgsqlDriver 特有方法测试 ====================

    /** PostgreSQL 驱动应生成冲突时忽略写入的语句 */
    public function testPgsqlBuildInsertOnConflictDoNothing(): void
    {
        $driver = new PgsqlDriver();
        $driver->setQuoteIdentifiers(true);
        $result = $driver->buildInsertOnConflict('users', ['name' => 'foo', 'balance' => 10], 'name');
        $this->assertStringContainsString('INSERT INTO', $result['sql']);
        $this->assertStringContainsString('"users"', $result['sql']);
        $this->assertStringContainsString('ON CONFLICT ("name") DO NOTHING', $result['sql']);
        $this->assertArrayHasKey('values', $result);
    }

    /** PostgreSQL 驱动应生成冲突时更新字段的语句 */
    public function testPgsqlBuildInsertOnConflictDoUpdate(): void
    {
        $driver = new PgsqlDriver();
        $driver->setQuoteIdentifiers(true);
        $result = $driver->buildInsertOnConflict('users', ['name' => 'foo', 'balance' => 10], 'name', ['balance']);
        $this->assertStringContainsString('ON CONFLICT ("name") DO UPDATE SET', $result['sql']);
        $this->assertStringContainsString('"balance" = EXCLUDED."balance"', $result['sql']);
    }

    /** PostgreSQL 驱动应支持多个冲突目标字段 */
    public function testPgsqlBuildInsertOnConflictMultipleColumns(): void
    {
        $driver = new PgsqlDriver();
        $driver->setQuoteIdentifiers(true);
        $result = $driver->buildInsertOnConflict('users', ['name' => 'foo', 'balance' => 10], ['name', 'balance']);
        $this->assertStringContainsString('ON CONFLICT ("name", "balance") DO NOTHING', $result['sql']);
    }

    /** PostgreSQL 驱动应生成带 RETURNING 字段的插入语句 */
    public function testPgsqlBuildInsertReturning(): void
    {
        $driver = new PgsqlDriver();
        $driver->setQuoteIdentifiers(true);
        $result = $driver->buildInsertReturning('users', ['name' => 'foo', 'balance' => 10], 'id');
        $this->assertStringContainsString('INSERT INTO', $result['sql']);
        $this->assertStringContainsString('"users"', $result['sql']);
        $this->assertStringContainsString('RETURNING "id"', $result['sql']);
        $this->assertArrayHasKey('params', $result);
    }

}
