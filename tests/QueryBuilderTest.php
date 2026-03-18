<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Haoa\MixDatabase\ConnectionInterface;

/**
 * QueryBuilder 测试
 */
final class QueryBuilderTest extends TestCase
{

    // ==================== SELECT 测试 ====================

    public function testSelectOrder(): void
    {
        $db = db();
        $_this = $this;

        $db->debug(function (ConnectionInterface $conn) use ($_this) {
            $log = $conn->queryLog();
            $_this->assertEquals('SELECT * FROM users ORDER BY id DESC', $log['sql']);
        })->table('users')->order('id', 'desc')->get();

        $db->debug(function (ConnectionInterface $conn) use ($_this) {
            $log = $conn->queryLog();
            $_this->assertEquals('SELECT * FROM users ORDER BY id DESC, name ASC', $log['sql']);
        })->table('users')->order('id', 'desc')->order('name', 'asc')->get();
    }

    public function testSelectLimit(): void
    {
        $db = db();
        $_this = $this;

        $db->debug(function (ConnectionInterface $conn) use ($_this) {
            $log = $conn->queryLog();
            $_this->assertEquals('SELECT * FROM users LIMIT ?, ?', $log['sql']);
            $_this->assertEquals([0, 5], $log['bindings']);
        })->table('users')->limit(5)->get();

        $db->debug(function (ConnectionInterface $conn) use ($_this) {
            $log = $conn->queryLog();
            $_this->assertEquals('SELECT * FROM users LIMIT ?, ?', $log['sql']);
            $_this->assertEquals([10, 5], $log['bindings']);
        })->table('users')->offset(10)->limit(5)->get();
    }

    public function testSelectGroupHaving(): void
    {
        $db = db();
        $_this = $this;

        $db->debug(function (ConnectionInterface $conn) use ($_this) {
            $log = $conn->queryLog();
            $_this->assertEquals('SELECT uid, COUNT(*) AS total FROM news GROUP BY uid HAVING COUNT(*) > ?', $log['sql']);
            $_this->assertEquals([0], $log['bindings']);
        })->table('news')->select('uid, COUNT(*) AS total')->group('uid')->having('COUNT(*) > ?', 0)->get();

        $db->debug(function (ConnectionInterface $conn) use ($_this) {
            $log = $conn->queryLog();
            $_this->assertEquals('SELECT uid, COUNT(*) AS total FROM news GROUP BY uid HAVING COUNT(*) > ? AND COUNT(*) < ?', $log['sql']);
            $_this->assertEquals([0, 10], $log['bindings']);
        })->table('news')->select('uid, COUNT(*) AS total')->group('uid')->having('COUNT(*) > ? AND COUNT(*) < ?', 0, 10)->get();
    }

    public function testSelectJoin(): void
    {
        $db = db();
        $_this = $this;

        $db->debug(function (ConnectionInterface $conn) use ($_this) {
            $log = $conn->queryLog();
            $_this->assertEquals('SELECT n.*, u.name FROM news AS n LEFT JOIN users AS u ON n.uid = u.id AND u.balance > ?', $log['sql']);
            $_this->assertEquals([0], $log['bindings']);
        })->table('news AS n')->select('n.*, u.name')->leftJoin('users AS u', 'n.uid = u.id AND u.balance > ?', 0)->get();
    }

    // ==================== WHERE 测试 ====================

    public function testWhereAnd(): void
    {
        $db = db();
        $_this = $this;

        $db->table('users')
            ->where('id = ?', 1)
            ->where('name = ?', 'test1')
            ->debug(function (ConnectionInterface $conn) use ($_this) {
                $log = $conn->queryLog();
                $_this->assertEquals('SELECT * FROM users WHERE id = ? AND name = ?', $log['sql']);
                $_this->assertEquals([1, 'test1'], $log['bindings']);
            })
            ->get();

        $db->table('users')
            ->where('id = ? and name = ?', 1, 'test1')
            ->debug(function (ConnectionInterface $conn) use ($_this) {
                $log = $conn->queryLog();
                $_this->assertEquals('SELECT * FROM users WHERE id = ? and name = ?', $log['sql']);
                $_this->assertEquals([1, 'test1'], $log['bindings']);
            })
            ->get();
    }

    public function testWhereOr(): void
    {
        $db = db();
        $_this = $this;

        $db->table('users')
            ->where('id = ? or id = ?', 1, 2)
            ->debug(function (ConnectionInterface $conn) use ($_this) {
                $log = $conn->queryLog();
                $_this->assertEquals('SELECT * FROM users WHERE id = ? or id = ?', $log['sql']);
                $_this->assertEquals([1, 2], $log['bindings']);
            })
            ->get();
    }

    public function testWhereIn(): void
    {
        $db = db();
        $_this = $this;

        // 全部都是 IN
        $db->table('users')
            ->where('id IN (?) or id IN (?)', [1, 2], [3, 4])
            ->debug(function (ConnectionInterface $conn) use ($_this) {
                $log = $conn->queryLog();
                $_this->assertEquals('SELECT * FROM users WHERE id IN (1,2) or id IN (3,4)', $log['sql']);
                $_this->assertEquals([], $log['bindings']);
            })
            ->get();

        // 包含不是 IN 的条件，并且 IN 位置在前面
        $db->table('users')
            ->where('id IN (?) or id = ?', [1, 2], 3)
            ->debug(function (ConnectionInterface $conn) use ($_this) {
                $log = $conn->queryLog();
                $_this->assertEquals('SELECT * FROM users WHERE id IN (1,2) or id = ?', $log['sql']);
                $_this->assertEquals([3], $log['bindings']);
            })
            ->get();

        // 包含不是 IN 的条件，并且 IN 位置在后面
        $db->table('users')
            ->where('id = ? or id IN (?)', 3, [1, 2])
            ->debug(function (ConnectionInterface $conn) use ($_this) {
                $log = $conn->queryLog();
                $_this->assertEquals('SELECT * FROM users WHERE id = ? or id IN (1,2)', $log['sql']);
                $_this->assertEquals([3], $log['bindings']);
            })
            ->get();
    }

}