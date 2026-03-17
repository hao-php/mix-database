<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Haoa\MixDatabase\ConnectionInterface;

/**
 * MySQL 集成测试 -- 仅包含老测试未覆盖的场景
 */
final class MysqlIntegrationTest extends TestCase
{

    // ==================== Select 补充 ====================

    public function testSelectFirst(): void
    {
        $db = db();
        $row = $db->table('users')->where('id = ?', 1)->first();
        $this->assertIsObject($row);
        $this->assertEquals(1, $row->id);
    }

    public function testSelectValue(): void
    {
        $db = db();
        $name = $db->table('users')->where('id = ?', 1)->value('name');
        $this->assertIsString($name);
    }

    public function testSelectWithFields(): void
    {
        $db = db();
        $_this = $this;
        $db->debug(function (ConnectionInterface $conn) use ($_this) {
            $log = $conn->queryLog();
            $_this->assertStringContainsString('SELECT `id`, `name`', $log['sql']);
        })->table('users')->select('id', 'name')->where('id = ?', 1)->get();
    }

    // ==================== MySQL 特有方法 (__call 透传) ====================

    public function testReplaceInsert(): void
    {
        $db = db();
        $db->replaceInsert('users', ['name' => 'replace_test', 'balance' => 50]);
        $this->assertTrue(true);
    }

    public function testInsertOnDuplicateKey(): void
    {
        $db = db();
        $id = $db->insert('users', ['name' => 'dup_test', 'balance' => 10])->lastInsertId();

        $db->exec("INSERT INTO users (id, name, balance) VALUES (?, 'dup_test2', 20) ON DUPLICATE KEY UPDATE balance = 20", (int)$id);

        $balance = $db->table('users')->where('id = ?', (int)$id)->value('balance');
        $this->assertEquals(20, $balance);
    }

    public function testCallPgsqlMethodOnMysqlThrows(): void
    {
        $db = db();
        $this->expectException(\BadMethodCallException::class);
        $db->insertOnConflict('users', ['name' => 'foo'], 'name');
    }

    // ==================== insert 集成验证 ====================

    public function testInsertSql(): void
    {
        $db = db();
        $_this = $this;
        $db->debug(function (ConnectionInterface $conn) use ($_this) {
            $log = $conn->queryLog();
            $_this->assertStringContainsString('INSERT INTO `users`', $log['sql']);
            $_this->assertStringContainsString('`name`', $log['sql']);
            $_this->assertStringContainsString('`balance`', $log['sql']);
        })->insert('users', ['name' => 'quote_test', 'balance' => 0]);
    }

    // ==================== Lock 语法验证 ====================

    public function testSharedLockSql(): void
    {
        $db = db();
        $_this = $this;
        $db->debug(function (ConnectionInterface $conn) use ($_this) {
            $log = $conn->queryLog();
            $_this->assertStringContainsString('LOCK IN SHARE MODE', $log['sql']);
        })->table('users')->where('id = ?', 1)->sharedLock()->get();
    }

    public function testLockForUpdateSql(): void
    {
        $db = db();
        $_this = $this;
        $db->debug(function (ConnectionInterface $conn) use ($_this) {
            $log = $conn->queryLog();
            $_this->assertStringContainsString('FOR UPDATE', $log['sql']);
        })->table('users')->where('id = ?', 1)->lockForUpdate()->get();
    }

}
