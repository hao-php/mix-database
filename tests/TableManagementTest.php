<?php
declare(strict_types=1);

use Haoa\MixDatabase\ConnectionInterface;
use Haoa\MixDatabase\Context\Database as ContextDatabase;
use PHPUnit\Framework\TestCase;

final class TableManagementTest extends TestCase
{
    private string $table = 'mixdb_test_table_management';

    protected function tearDown(): void
    {
        db()->dropTable($this->table, true);
        parent::tearDown();
    }

    private function createTable(): void
    {
        db()->exec('CREATE TABLE ' . $this->table . ' (id INTEGER PRIMARY KEY)');
    }

    public function testListTablesAndLike(): void
    {
        $this->createTable();
        $this->assertContains($this->table, db()->listTables());
        $this->assertSame([$this->table], db()->listTables(str_replace('_', '\\_', $this->table)));
        $this->assertSame([], db()->listTables('missing\\_%'));
    }

    public function testDropTableAndIfExists(): void
    {
        $this->createTable();
        $this->assertInstanceOf(ConnectionInterface::class, db()->dropTable($this->table));
        $this->assertNotContains($this->table, db()->listTables());
        db()->dropTable($this->table, true);
    }

    public function testDropMissingTableWithoutIfExistsThrows(): void
    {
        db()->dropTable($this->table, true);
        $this->expectException(PDOException::class);
        db()->dropTable($this->table);
    }

    public function testDropTableRejectsInvalidName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        db()->dropTable('mixdb_test_users; DROP TABLE mixdb_test_users');
    }

    public function testTransactionUsesSameConnection(): void
    {
        $this->createTable();
        $tx = db()->beginTransaction();
        $this->assertContains($this->table, $tx->listTables());
        if (TEST_DRIVER === 'pgsql') {
            $tx->dropTable($this->table);
            $tx->commit();
        } else {
            $tx->rollback();
            db()->dropTable($this->table);
        }
        $this->assertNotContains($this->table, db()->listTables());
    }

    public function testContextTransactionEntry(): void
    {
        $context = new ContextDatabase(TEST_DSN, TEST_USERNAME, TEST_PASSWORD);
        $this->createTable();
        $tx = $context->beginTransaction();
        $this->assertContains($this->table, $context->listTables());
        if (TEST_DRIVER === 'pgsql') {
            $context->dropTable($this->table);
            $tx->commit();
        } else {
            $tx->rollback();
            $context->dropTable($this->table);
        }
        $this->assertNotContains($this->table, $context->listTables());
    }

    public function testContextEntry(): void
    {
        $context = new ContextDatabase(TEST_DSN, TEST_USERNAME, TEST_PASSWORD);
        $this->createTable();
        $this->assertContains($this->table, $context->listTables());
        $context->dropTable($this->table);
        $this->assertNotContains($this->table, $context->listTables());
    }
}
