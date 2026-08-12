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

    /** Database 应返回与 DSN 匹配的 MySQL 驱动 */
    public function testGetDriver(): void
    {
        $db = db();
        $driver = $db->getDriver();
        $this->assertInstanceOf(MysqlDriver::class, $driver);
    }

    /** 默认配置不应给表名和字段名添加标识符引号 */
    public function testQuoteIdentifiersDisabledByDefault(): void
    {
        $db = db();
        $driver = $db->getDriver();
        $this->assertEquals('users', $driver->quoteTableName('users'));
        $this->assertEquals('name', $driver->quoteColumnName('name'));
    }

    /** 开启配置后应给表名和字段名添加 MySQL 标识符引号 */
    public function testQuoteIdentifiersEnabled(): void
    {
        $db = new Database(MYSQL_DSN, MYSQL_USERNAME, MYSQL_PASSWORD, [], true);
        $driver = $db->getDriver();
        $this->assertEquals('`users`', $driver->quoteTableName('users'));
        $this->assertEquals('`name`', $driver->quoteColumnName('name'));
    }

    /** 连接标识应由 DSN 生成且不泄露用户名和密码 */
    public function testGetConnectionIdentifier(): void
    {
        $db = new Database(MYSQL_DSN, MYSQL_USERNAME, MYSQL_PASSWORD);
        $identifier = $db->getConnectionIdentifier();

        $this->assertSame(hash('sha256', MYSQL_DSN), $identifier);
        $this->assertStringNotContainsString(MYSQL_USERNAME, $identifier);
        $this->assertStringNotContainsString(MYSQL_PASSWORD, $identifier);
    }

    /** tableExists 应正确识别存在和不存在的数据表 */
    public function testTableExists(): void
    {
        $db = db();

        $this->assertTrue($db->tableExists('users'));
        $this->assertFalse($db->tableExists('table_that_does_not_exist'));
    }

    /** tableExists 应拒绝包含危险字符的非法表名 */
    public function testTableExistsRejectsInvalidTableName(): void
    {
        $db = db();
        $this->expectException(\InvalidArgumentException::class);

        $db->tableExists('users; DROP TABLE users');
    }

    /** 调用当前驱动不支持的方法时应抛出明确异常 */
    public function testCallUnsupportedMethodThrows(): void
    {
        $db = db();
        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('not supported by');
        // MySQL 驱动没有 buildInsertOnConflict 方法
        $db->insertOnConflict('users', ['name' => 'foo'], 'name');
    }

}
