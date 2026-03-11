<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase as BaseTestCase;
use Haoa\MixDatabase\Context\Database;

/**
 * Context 层数据库测试基类，单独使用 test_users 表，避免影响其他用例。
 */
abstract class ContextTestCase extends BaseTestCase
{

    protected ?Database $db = null;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->db = new Database(MYSQL_DSN, MYSQL_USERNAME, MYSQL_PASSWORD, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_TIMEOUT => 5,
            ]);

            $this->createTestTable($this->db);
            $this->cleanupTestTable($this->db);
        } catch (\PDOException $e) {
            $this->markTestSkipped('Context database connection failed: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->db instanceof Database) {
            $this->cleanupTestTable($this->db);
        }
        parent::tearDown();
    }

    /**
     * 创建专用测试表：test_users
     */
    private function createTestTable(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS test_users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_name VARCHAR(100) NOT NULL,
                email VARCHAR(100),
                age INT,
                created_at DATETIME,
                updated_at DATETIME,
                INDEX idx_user_name (user_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ", []);
    }

    /**
     * 清理专用测试表数据
     */
    private function cleanupTestTable(Database $db): void
    {
        $db->exec("TRUNCATE TABLE test_users", []);
    }
}

