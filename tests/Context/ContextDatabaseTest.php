<?php
declare(strict_types=1);

use Haoa\MixDatabase\ConnectionInterface;
use Haoa\MixDatabase\Db\Database;
use Haoa\MixDatabase\Db\TransactionWrapper;

final class ContextDatabaseTest extends ContextTestCase
{

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

    public function testQueryLogToSqlWithNamedBindings(): void
    {
        $log = [
            'sql' => 'SELECT * FROM users WHERE name = :name',
            'bindings' => ['name' => 'test'],
        ];

        $sql = Database::queryLogToSql($log);
        $this->assertStringContainsString('"test"', $sql);
    }

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

    public function testQueryLogToSqlWithArrayBindings(): void
    {
        $log = [
            'sql' => 'SELECT * FROM users WHERE id IN (?)',
            'bindings' => [[1, 2, 3]],
        ];

        $sql = Database::queryLogToSql($log);
        $this->assertStringContainsString('"1","2","3"', $sql);
    }

    public function testQueryLogToSqlWithEmptyBindings(): void
    {
        $log = [
            'sql' => 'SELECT * FROM users',
            'bindings' => [],
        ];

        $sql = Database::queryLogToSql($log);
        $this->assertEquals('SELECT * FROM users', $sql);
    }

    public function testQueryLogToSqlWithoutBindings(): void
    {
        $log = [
            'sql' => 'SELECT * FROM users',
        ];

        $sql = Database::queryLogToSql($log);
        $this->assertEquals('SELECT * FROM users', $sql);
    }

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

    public function testGetContext(): void
    {
        $context = Database::getContext();
        $this->assertNotNull($context);
    }

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

