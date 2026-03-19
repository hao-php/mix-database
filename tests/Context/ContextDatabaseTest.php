<?php
declare(strict_types=1);

require_once __DIR__ . '/ContextTestCase.php';

use Haoa\MixDatabase\ConnectionInterface;
use Haoa\MixDatabase\Context\Database;
use Haoa\MixDatabase\Context\TransactionWrapper;
use Haoa\Util\Context\RunContext;

final class ContextDatabaseTest extends ContextTestCase
{

    /** 构造函数是否能正常创建 Context\Database */
    public function testConstruct(): void
    {
        $this->assertInstanceOf(Database::class, $this->db);
    }

    public function testBeginTransaction(): void
    {
        $tx = $this->db->beginTransaction();
        $this->assertInstanceOf(TransactionWrapper::class, $tx);
        $tx->rollback();
    }

    /** 多次 beginTransaction 应该复用同一个 TransactionWrapper 实例（嵌套事务） */
    public function testNestedTransaction(): void
    {
        $tx1 = $this->db->beginTransaction();

        try {
            $tx2 = $this->db->beginTransaction();

            $this->assertSame($tx1, $tx2);

            $tx2->rollback();
        } catch (\Throwable $e) {
            $tx1->rollback();
            throw $e;
        }
    }

    /** 嵌套事务：只有最外层 commit 才会真正提交 */
    public function testNestedTransactionRealCommitOnOuter(): void
    {
        $tx1 = $this->db->beginTransaction();

        try {
            $tx2 = $this->db->beginTransaction();
            $this->assertSame($tx1, $tx2);

            $id = $this->db->insert('test_users', [
                'user_name' => 'nested_commit_test',
                'email' => 'tx@example.com',
            ])->lastInsertId();
            $this->assertNotEmpty($id);

            // 内层 commit：此时只会减少嵌套层级，不会真正提交
            $tx2->commit();

            // 在同一事务上下文中，应当可以读到刚插入的数据
            $userInTx = $this->db->table('test_users')->where('id = ?', $id)->first();
            $this->assertNotFalse($userInTx);

            // 最外层 commit：此时才真正提交到数据库
            $tx1->commit();

            // 提交后再次查询，应当仍然能读到该数据
            $userAfterCommit = $this->db->table('test_users')->where('id = ?', $id)->first();
            $this->assertNotFalse($userAfterCommit);
        } catch (\Throwable $e) {
            $tx1->rollback();
            throw $e;
        }
    }

    /** 从运行上下文中获取当前事务对象 */
    public function testGetContextTx(): void
    {
        $tx = $this->db->beginTransaction();

        try {
            $contextTx = $this->db->getContextTx();
            $this->assertInstanceOf(TransactionWrapper::class, $contextTx);
            $this->assertSame($tx, $contextTx);

            $tx->rollback();
        } catch (\Throwable $e) {
            $tx->rollback();
            throw $e;
        }
    }

    /** 删除运行上下文中的事务对象 */
    public function testDelContextTx(): void
    {
        $tx = $this->db->beginTransaction();

        try {
            $this->db->delContextTx();
            $contextTx = $this->db->getContextTx();
            $this->assertNull($contextTx);

            $tx->rollback();
        } catch (\Throwable $e) {
            $tx->rollback();
            throw $e;
        }
    }

    /** queryLogToSql：命名绑定参数转换为可直接执行的 SQL */
    public function testQueryLogToSqlWithNamedBindings(): void
    {
        $log = [
            'sql' => 'SELECT * FROM users WHERE name = :name',
            'bindings' => ['name' => 'test'],
        ];

        $sql = Database::queryLogToSql($log);
        $this->assertStringContainsString('"test"', $sql);
    }

    /** queryLogToSql：位置占位符绑定参数转换为可直接执行的 SQL */
    public function testQueryLogToSqlWithPositionalBindings(): void
    {
        $log = [
            'sql' => 'SELECT * FROM users WHERE id = ? AND name = ?',
            'bindings' => [1, 'test'],
        ];

        $sql = Database::queryLogToSql($log);
        $this->assertStringContainsString('"1"', $sql);
        $this->assertStringContainsString('"test"', $sql);
    }

    /** queryLogToSql：数组绑定（IN (?)）展开为 "1","2","3" 形式 */
    public function testQueryLogToSqlWithArrayBindings(): void
    {
        $log = [
            'sql' => 'SELECT * FROM users WHERE id IN (?)',
            'bindings' => [[1, 2, 3]],
        ];

        $sql = Database::queryLogToSql($log);
        $this->assertStringContainsString('"1","2","3"', $sql);
    }

    /** queryLogToSql：空绑定时保持原 SQL 不变 */
    public function testQueryLogToSqlWithEmptyBindings(): void
    {
        $log = [
            'sql' => 'SELECT * FROM users',
            'bindings' => [],
        ];

        $sql = Database::queryLogToSql($log);
        $this->assertEquals('SELECT * FROM users', $sql);
    }

    /** queryLogToSql：没有 bindings 字段时保持原 SQL 不变 */
    public function testQueryLogToSqlWithoutBindings(): void
    {
        $log = [
            'sql' => 'SELECT * FROM users',
        ];

        $sql = Database::queryLogToSql($log);
        $this->assertEquals('SELECT * FROM users', $sql);
    }

    /** 事务提交后，插入的数据应当可以被查询到 */
    public function testInsertAndCommit(): void
    {
        $tx = $this->db->beginTransaction();

        try {
            $id = $this->db->insert('test_users', [
                'user_name' => 'tx_commit_test',
                'email' => 'tx@example.com',
            ])->lastInsertId();

            $this->assertNotEmpty($id);

            $tx->commit();

            $user = $this->db->table('test_users')->where('id = ?', $id)->first();
            $this->assertNotNull($user);
            $this->assertEquals('tx_commit_test', $user['user_name']);
        } catch (\Throwable $e) {
            $tx->rollback();
            throw $e;
        }
    }

    /** 事务回滚后，插入的数据应当查询不到 */
    public function testInsertAndRollback(): void
    {
        $tx = $this->db->beginTransaction();

        try {
            $id = $this->db->insert('test_users', [
                'user_name' => 'tx_rollback_test',
                'email' => 'tx@example.com',
            ])->lastInsertId();

            $this->assertNotEmpty($id);

            $tx->rollback();

            $user = $this->db->table('test_users')->where('id = ?', $id)->first();
            $this->assertFalse($user);
        } catch (\Throwable $e) {
            $tx->rollback();
            throw $e;
        }
    }

    /** 嵌套事务：只有最外层 rollback 才会真正回滚 */
    public function testNestedTransactionRealRollbackOnOuter(): void
    {
        $tx1 = $this->db->beginTransaction();

        try {
            $tx2 = $this->db->beginTransaction();
            $this->assertSame($tx1, $tx2);

            $id = $this->db->insert('test_users', [
                'user_name' => 'nested_rollback_test',
                'email' => 'tx@example.com',
            ])->lastInsertId();
            $this->assertNotEmpty($id);

            // 内层 rollback：此时只会减少嵌套层级，不会真正回滚
            $tx2->rollback();

            // 在同一事务上下文中，应当仍然可以读到刚插入的数据
            $userInTx = $this->db->table('test_users')->where('id = ?', $id)->first();
            $this->assertNotFalse($userInTx);

            // 最外层 rollback：此时才真正回滚
            $tx1->rollback();

            // 回滚后再次查询，应当读不到该数据
            $userAfterRollback = $this->db->table('test_users')->where('id = ?', $id)->first();
            $this->assertFalse($userAfterRollback);
        } catch (\Throwable $e) {
            $tx1->rollback();
            throw $e;
        }
    }

    /** 提交事务时，应当依次触发已注册的 commit 回调 */
    public function testTransactionWithCallback(): void
    {
        $callbackCalled = false;

        $tx = $this->db->beginTransaction();
        $tx->addCommitCallback(function () use (&$callbackCalled) {
            $callbackCalled = true;
        });

        try {
            $this->db->insert('test_users', [
                'user_name' => 'tx_callback_test',
                'email' => 'tx@example.com',
            ]);

            $tx->commit();

            $this->assertTrue($callbackCalled);
        } catch (\Throwable $e) {
            $tx->rollback();
            throw $e;
        }
    }

    /** 不同 Database 实例的事务上下文应当相互隔离 */
    public function testTransactionContextIsolation(): void
    {
        $db1 = new Database(MYSQL_DSN, MYSQL_USERNAME, MYSQL_PASSWORD, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);

        $db2 = new Database(MYSQL_DSN, MYSQL_USERNAME, MYSQL_PASSWORD, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);

        $tx1 = $db1->beginTransaction();

        try {
            $tx2 = $db2->beginTransaction();

            $this->assertNotSame($tx1, $tx2);

            $tx2->rollback();
            $tx1->rollback();
        } catch (\Throwable $e) {
            $tx1->rollback();
            $tx2->rollback();
            throw $e;
        }
    }

    /** 在 TransactionWrapper 中使用 exec/raw 执行 SQL */
    public function testTransactionWrapperExecAndRaw(): void
    {
        $tx = $this->db->beginTransaction();

        try {
            $result = $tx->exec('SELECT 1 as test_value');
            $this->assertNotNull($result);

            $result = $tx->raw('SELECT 1 as test_value');
            $this->assertNotNull($result);

            $tx->rollback();
        } catch (\Throwable $e) {
            $tx->rollback();
            throw $e;
        }
    }

    /** 在 TransactionWrapper 中使用 debug 回调，收集最后一次查询的调试信息 */
    public function testTransactionWrapperDebug(): void
    {
        $tx = $this->db->beginTransaction();

        try {
            $debugCalled = false;

            $tx->debug(function (ConnectionInterface $conn) use (&$debugCalled) {
                $debugCalled = true;
            });

            $tx->table('test_users')->first();
            $this->assertTrue($debugCalled);
            $tx->rollback();
        } catch (\Throwable $e) {
            $tx->rollback();
            throw $e;
        }
    }
}

