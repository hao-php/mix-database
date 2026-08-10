<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Haoa\MixDatabase\Database;
use Haoa\MixDatabase\Driver\MysqlDriver;

/**
 * Database 入口类测试
 */
final class DatabaseTest extends TestCase
{

    public function testGetDriver(): void
    {
        $db = db();
        $driver = $db->getDriver();
        $this->assertInstanceOf(MysqlDriver::class, $driver);
    }

    public function testQuoteIdentifiersDisabledByDefault(): void
    {
        $db = db();
        $driver = $db->getDriver();
        $this->assertEquals('users', $driver->quoteTableName('users'));
        $this->assertEquals('name', $driver->quoteColumnName('name'));
    }

    public function testQuoteIdentifiersEnabled(): void
    {
        $db = new Database(MYSQL_DSN, MYSQL_USERNAME, MYSQL_PASSWORD, [], true);
        $driver = $db->getDriver();
        $this->assertEquals('`users`', $driver->quoteTableName('users'));
        $this->assertEquals('`name`', $driver->quoteColumnName('name'));
    }

    public function testGetConnectionIdentifier(): void
    {
        $dsn = 'mysql:host=127.0.0.1;port=3306;dbname=mix';
        $db = new Database($dsn, 'user_a', 'password_a');
        $otherCredentials = new Database($dsn, 'user_b', 'password_b');

        $this->assertSame(hash('sha256', $dsn), $db->getConnectionIdentifier());
        $this->assertSame($db->getConnectionIdentifier(), $otherCredentials->getConnectionIdentifier());
        $this->assertStringNotContainsString('127.0.0.1', $db->getConnectionIdentifier());
    }

    public function testCallUnsupportedMethodThrows(): void
    {
        $db = db();
        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('not supported by');
        // MySQL 驱动没有 buildInsertOnConflict 方法
        $db->insertOnConflict('users', ['name' => 'foo'], 'name');
    }

}
