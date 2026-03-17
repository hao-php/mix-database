<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Haoa\MixDatabase\Database;
use Haoa\MixDatabase\ConnectionInterface;
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

    public function testFactoryCreatesMysqlDriver(): void
    {
        $driver = DriverFactory::create('mysql:host=127.0.0.1;dbname=test');
        $this->assertInstanceOf(MysqlDriver::class, $driver);
        $this->assertInstanceOf(DriverInterface::class, $driver);
    }

    public function testFactoryCreatesPgsqlDriver(): void
    {
        $driver = DriverFactory::create('pgsql:host=127.0.0.1;dbname=test');
        $this->assertInstanceOf(PgsqlDriver::class, $driver);
        $this->assertInstanceOf(DriverInterface::class, $driver);
    }

    public function testFactoryThrowsOnUnsupportedDsn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DriverFactory::create('unsupported:host=127.0.0.1');
    }

    public function testFactoryRegisterCustomDriver(): void
    {
        DriverFactory::register('custom', MysqlDriver::class);
        $driver = DriverFactory::create('custom:host=127.0.0.1');
        $this->assertInstanceOf(MysqlDriver::class, $driver);
    }

    // ==================== MysqlDriver 方言测试 ====================

    public function testMysqlBuildLimit(): void
    {
        $driver = new MysqlDriver();
        list($sql, $values) = $driver->buildLimit(10, 5);
        $this->assertEquals('LIMIT ?, ?', $sql);
        $this->assertEquals([10, 5], $values);
    }

    public function testMysqlSharedLock(): void
    {
        $driver = new MysqlDriver();
        $this->assertEquals('LOCK IN SHARE MODE', $driver->sharedLockSql());
    }

    public function testMysqlForUpdate(): void
    {
        $driver = new MysqlDriver();
        $this->assertEquals('FOR UPDATE', $driver->forUpdateSql());
    }

    public function testMysqlDisconnectMessages(): void
    {
        $driver = new MysqlDriver();
        $messages = $driver->disconnectMessages();
        $this->assertIsArray($messages);
        $this->assertContains('server has gone away', $messages);
    }

    // ==================== PgsqlDriver 方言测试 ====================

    public function testPgsqlBuildLimit(): void
    {
        $driver = new PgsqlDriver();
        list($sql, $values) = $driver->buildLimit(10, 5);
        $this->assertEquals('LIMIT ? OFFSET ?', $sql);
        // PgSQL: LIMIT count OFFSET offset
        $this->assertEquals([5, 10], $values);
    }

    public function testPgsqlSharedLock(): void
    {
        $driver = new PgsqlDriver();
        $this->assertEquals('FOR SHARE', $driver->sharedLockSql());
    }

    public function testPgsqlForUpdate(): void
    {
        $driver = new PgsqlDriver();
        $this->assertEquals('FOR UPDATE', $driver->forUpdateSql());
    }

    public function testPgsqlDisconnectMessages(): void
    {
        $driver = new PgsqlDriver();
        $messages = $driver->disconnectMessages();
        $this->assertIsArray($messages);
        $this->assertContains('connection is closed', $messages);
    }

    // ==================== MysqlDriver 引号处理测试 ====================

    public function testMysqlGetQuoteChar(): void
    {
        $driver = new MysqlDriver();
        $this->assertEquals(['`', '`'], $driver->getQuoteChar());
    }

    public function testMysqlQuoteTableName(): void
    {
        $driver = new MysqlDriver();
        $this->assertEquals('`users`', $driver->quoteTableName('users'));
        $this->assertEquals('`users` AS `u`', $driver->quoteTableName('users AS u'));
        $this->assertEquals('`users` `u`', $driver->quoteTableName('users u'));
        $this->assertEquals('`users`', $driver->quoteTableName('`users`'));
    }

    public function testMysqlQuoteColumnName(): void
    {
        $driver = new MysqlDriver();
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

    public function testPgsqlGetQuoteChar(): void
    {
        $driver = new PgsqlDriver();
        $this->assertEquals(['"', '"'], $driver->getQuoteChar());
    }

    public function testPgsqlQuoteTableName(): void
    {
        $driver = new PgsqlDriver();
        $this->assertEquals('"users"', $driver->quoteTableName('users'));
        $this->assertEquals('"users" AS "u"', $driver->quoteTableName('users AS u'));
        $this->assertEquals('"users" "u"', $driver->quoteTableName('users u'));
        $this->assertEquals('"users"', $driver->quoteTableName('"users"'));
    }

    public function testPgsqlQuoteColumnName(): void
    {
        $driver = new PgsqlDriver();
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

    public function testMysqlBuildReplaceInsert(): void
    {
        $driver = new MysqlDriver();
        $result = $driver->buildReplaceInsert('users', ['name' => 'foo', 'balance' => 10]);
        $this->assertStringContainsString('REPLACE INTO `users`', $result['sql']);
        $this->assertStringContainsString('`name`', $result['sql']);
        $this->assertStringContainsString('`balance`', $result['sql']);
        $this->assertArrayHasKey('params', $result);
        $this->assertEquals(['name' => 'foo', 'balance' => 10], $result['params']);
    }

    public function testMysqlBuildInsertOnDuplicateKey(): void
    {
        $driver = new MysqlDriver();
        $result = $driver->buildInsertOnDuplicateKey('users', ['name' => 'foo', 'balance' => 10], ['balance']);
        $this->assertStringContainsString('INSERT INTO', $result['sql']);
        $this->assertStringContainsString('`users`', $result['sql']);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $result['sql']);
        $this->assertStringContainsString('`balance` = VALUES(`balance`)', $result['sql']);
        $this->assertArrayHasKey('values', $result);
        $this->assertEquals(['foo', 10], $result['values']);
    }

    // ==================== PgsqlDriver 特有方法测试 ====================

    public function testPgsqlBuildInsertOnConflictDoNothing(): void
    {
        $driver = new PgsqlDriver();
        $result = $driver->buildInsertOnConflict('users', ['name' => 'foo', 'balance' => 10], 'name');
        $this->assertStringContainsString('INSERT INTO', $result['sql']);
        $this->assertStringContainsString('"users"', $result['sql']);
        $this->assertStringContainsString('ON CONFLICT ("name") DO NOTHING', $result['sql']);
        $this->assertArrayHasKey('values', $result);
    }

    public function testPgsqlBuildInsertOnConflictDoUpdate(): void
    {
        $driver = new PgsqlDriver();
        $result = $driver->buildInsertOnConflict('users', ['name' => 'foo', 'balance' => 10], 'name', ['balance']);
        $this->assertStringContainsString('ON CONFLICT ("name") DO UPDATE SET', $result['sql']);
        $this->assertStringContainsString('"balance" = EXCLUDED."balance"', $result['sql']);
    }

    public function testPgsqlBuildInsertOnConflictMultipleColumns(): void
    {
        $driver = new PgsqlDriver();
        $result = $driver->buildInsertOnConflict('users', ['name' => 'foo', 'balance' => 10], ['name', 'balance']);
        $this->assertStringContainsString('ON CONFLICT ("name", "balance") DO NOTHING', $result['sql']);
    }

    public function testPgsqlBuildInsertReturning(): void
    {
        $driver = new PgsqlDriver();
        $result = $driver->buildInsertReturning('users', ['name' => 'foo', 'balance' => 10], 'id');
        $this->assertStringContainsString('INSERT INTO', $result['sql']);
        $this->assertStringContainsString('"users"', $result['sql']);
        $this->assertStringContainsString('RETURNING "id"', $result['sql']);
        $this->assertArrayHasKey('params', $result);
    }

    // ==================== Database getDriver() 测试 ====================

    public function testDatabaseGetDriver(): void
    {
        $db = db();
        $driver = $db->getDriver();
        $this->assertInstanceOf(MysqlDriver::class, $driver);
    }

    // ==================== __call 透传测试 ====================

    public function testCallUnsupportedMethodThrows(): void
    {
        $db = db();
        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('not supported by');
        // MySQL 驱动没有 buildInsertOnConflict 方法
        $db->insertOnConflict('users', ['name' => 'foo'], 'name');
    }

}
