<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Haoa\MixDatabase\ConnectionInterface;
use Haoa\MixDatabase\Expr;

/**
 * CRUD 操作测试
 */
final class CrudTest extends TestCase
{

    // ==================== Insert 测试 ====================

    /** 插入单条记录并验证返回有效的自增 ID */
    public function testInsert(): void
    {
        $db = db();

        $id = $db->insert('users', [
            'name' => 'foo1',
            'balance' => 1,
        ])->lastInsertId();
        $this->assertGreaterThan(0, (int)$id);
    }

    /** 插入包含数据库表达式的记录并验证执行成功 */
    public function testInsertWithExpr(): void
    {
        $db = db();

        $data = [
            'name' => 'foo4',
            'balance' => 4,
            'add_time' => new Expr('CURRENT_TIMESTAMP()'),
        ];
        $id = $db->insert('users', $data)->lastInsertId();
        $this->assertGreaterThan(0, (int)$id);
    }

    /** 向不存在的数据表插入时应抛出异常 */
    public function testInsertNonExistentTable(): void
    {
        $db = db();

        $this->expectException(\Throwable::class);
        $db->insert('users_11111', [
            'name' => 'foo1',
            'balance' => 1,
        ])->lastInsertId();
    }

    /** 批量插入多条记录并验证返回有效的自增 ID */
    public function testBatchInsert(): void
    {
        $db = db();

        $data = [
            [
                'name' => 'foo2',
                'balance' => 2,
            ],
            [
                'name' => 'foo3',
                'balance' => 3,
            ]
        ];
        $id = $db->batchInsert('users', $data)->lastInsertId();
        $this->assertGreaterThan(0, (int)$id);
    }

    // ==================== Update 测试 ====================

    /** 更新单个字段并验证受影响行数 */
    public function testUpdateSingleField(): void
    {
        $db = db();

        $rowsAffected = $db->table('users')->where('id = ?', 1)->update('name', 'foo1')->rowCount();
        $this->assertGreaterThanOrEqual(0, $rowsAffected);
    }

    /** 一次更新多个字段并验证受影响行数 */
    public function testUpdateMultipleFields(): void
    {
        $db = db();

        $data = [
            'name' => 'foo2',
            'balance' => 100,
        ];
        $rowsAffected = $db->table('users')->where('id = ?', 1)->updates($data)->rowCount();
        $this->assertGreaterThanOrEqual(0, $rowsAffected);
    }

    /** 使用数据库表达式更新字段并验证各调用形式均可执行 */
    public function testUpdateWithExpr(): void
    {
        $db = db();

        $data = [
            'balance' => new Expr('balance + ?', 1),
        ];
        $rowsAffected = $db->table('users')->where('id = ?', 1)->updates($data)->rowCount();
        $this->assertGreaterThanOrEqual(0, $rowsAffected);

        $rowsAffected = $db->table('users')->where('id = ?', 1)->update('balance', new Expr('balance + ?', 1))->rowCount();
        $this->assertGreaterThanOrEqual(0, $rowsAffected);

        $rowsAffected = $db->table('users')->where('id = ?', 1)->update('add_time', new Expr('CURRENT_TIMESTAMP()'))->rowCount();
        $this->assertGreaterThanOrEqual(0, $rowsAffected);

        $data = [
            'add_time' => new Expr('CURRENT_TIMESTAMP()'),
        ];
        $rowsAffected = $db->table('users')->where('id = ?', 1)->updates($data)->rowCount();
        $this->assertGreaterThanOrEqual(0, $rowsAffected);
    }

    // ==================== Delete 测试 ====================

    /** 删除不存在的记录时受影响行数应为零 */
    public function testDelete(): void
    {
        $db = db();

        $rowsAffected = $db->table('users')->where('id = ?', 100000)->delete()->rowCount();
        $this->assertEquals(0, $rowsAffected);
    }

    // ==================== Raw/Exec 测试 ====================

    /** 使用 exec 执行原生写语句并读取受影响行数 */
    public function testExec(): void
    {
        $db = db();

        $rowsAffected = $db->exec('DELETE FROM users WHERE id = ?', 100000)->rowCount();
        $this->assertEquals(0, $rowsAffected);
    }

    /** 使用 raw 配合 get 获取多行结果并验证查询日志 */
    public function testRawQuery(): void
    {
        $db = db();
        $_this = $this;

        $res = $db->debug(function (ConnectionInterface $conn) use ($_this) {
            $log = $conn->queryLog();
            $_this->assertEquals('SELECT * FROM users WHERE id = ?', $log['sql']);
            $_this->assertEquals([1], $log['bindings']);
        })->raw('SELECT * FROM users WHERE id = ?', 1)->get();

        $obj = array_pop($res);
        $this->assertIsObject($obj);
        $this->assertTrue(isset($obj->id));
        $this->assertTrue(isset($obj->name));
        $this->assertTrue(isset($obj->balance));
        $this->assertTrue(isset($obj->add_time));
    }

    /** 使用 raw 配合 first 获取首行结果并验证查询日志 */
    public function testRawQueryFirst(): void
    {
        $db = db();
        $_this = $this;

        $obj = $db->debug(function (ConnectionInterface $conn) use ($_this) {
            $log = $conn->queryLog();
            $_this->assertEquals('SELECT * FROM users WHERE id = ?', $log['sql']);
            $_this->assertEquals([1], $log['bindings']);
        })->raw('SELECT * FROM users WHERE id = ?', 1)->first();

        $this->assertIsObject($obj);
        $this->assertTrue(isset($obj->id));
        $this->assertTrue(isset($obj->name));
        $this->assertTrue(isset($obj->balance));
        $this->assertTrue(isset($obj->add_time));
    }

}
