<?php
declare(strict_types=1);

require_once __DIR__ . '/ContextTestCase.php';

use Haoa\MixDatabase\Context\Model as BaseModel;
use Haoa\MixDatabase\Context\Database;

final class ContextModelTest extends ContextTestCase
{

    /** 创建一个绑定了 Context\Database 的测试模型 */
    private function createModel(): ContextTestUserModel
    {
        return ContextTestUserModel::db($this->db);
    }

    /** where 条件与 getLastSql 生成的 SQL 片段校验 */
    public function testWhereAndGetLastSql(): void
    {
        $model = $this->createModel();
        $model->where('user_name', 'test_user');
        $model->get();
        $sql = $model->getLastSql();
        $this->assertStringContainsString('WHERE', $sql);
        $this->assertStringContainsString('user_name', $sql);
    }

    /** insert 返回值非空即可（具体插入结果由其他用例覆盖） */
    public function testInsert(): void
    {
        $model = $this->createModel();
        $result = $model->insert([
            'user_name' => 'test_insert',
            'email' => 'test@example.com',
            'age' => 25,
        ]);
        $this->assertNotNull($result);
    }

    /** insertGetId：返回自增主键，并且不为空 */
    public function testInsertGetId(): void
    {
        $model = $this->createModel();
        $id = $model->insertGetId([
            'user_name' => 'test_insert_id',
            'email' => 'test@example.com',
            'age' => 25,
        ]);
        $this->assertIsString($id);
        $this->assertNotEmpty($id);
    }

    /** batchInsert：批量插入返回受影响行数 */
    public function testBatchInsert(): void
    {
        $model = $this->createModel();
        $list = [
            ['user_name' => 'batch1', 'email' => 'batch1@example.com', 'age' => 20],
            ['user_name' => 'batch2', 'email' => 'batch2@example.com', 'age' => 25],
        ];
        $result = $model->batchInsert($list);
        $this->assertIsInt($result);
        $this->assertEquals(2, $result);
    }

    /** update：更新单个字段，受影响行数为 1 */
    public function testUpdate(): void
    {
        $model = $this->createModel();
        $id = $model->insertGetId(['user_name' => 'update_test']);

        $result = $model->where('id', $id)->update('user_name', 'updated_name');
        $this->assertIsInt($result);
        $this->assertEquals(1, $result);
    }

    /** updates：更新多个字段，受影响行数为 1 */
    public function testUpdateMultiple(): void
    {
        $model = $this->createModel();
        $id = $model->insertGetId(['user_name' => 'updates_test']);

        $result = $model->where('id', $id)->updates([
            'user_name' => 'updated_name',
            'email' => 'updated@example.com',
        ]);
        $this->assertIsInt($result);
        $this->assertEquals(1, $result);
    }

    /** delete：按主键删除单行 */
    public function testDelete(): void
    {
        $model = $this->createModel();
        $id = $model->insertGetId(['user_name' => 'delete_test']);

        $result = $model->where('id', $id)->delete();
        $this->assertIsInt($result);
        $this->assertEquals(1, $result);
    }

    /** get：获取多行结果，当前只插入一条，数量应为 1 */
    public function testGetAll(): void
    {
        $model = $this->createModel();
        $model->insertGetId(['user_name' => 'get_test']);

        $result = $model->get();
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
    }

    /** first：获取第一行记录，并检查字段值 */
    public function testGetFirst(): void
    {
        $model = $this->createModel();
        $model->insertGetId(['user_name' => 'first_test']);

        $result = $model->first();
        $this->assertIsArray($result);
        $this->assertEquals('first_test', $result['user_name']);
    }

    /** count：统计记录数量 */
    public function testCount(): void
    {
        $model = $this->createModel();
        $model->insertGetId(['user_name' => 'count_test']);

        $count = $model->count();
        $this->assertIsInt($count);
        $this->assertEquals(1, $count);
    }

    /** value：获取单字段的值 */
    public function testGetValue(): void
    {
        $model = $this->createModel();
        $model->insertGetId(['user_name' => 'value_test']);

        $value = $model->value('user_name');
        $this->assertEquals('value_test', $value);
    }

    /** column：获取某一列的所有值 */
    public function testGetColumn(): void
    {
        $model = $this->createModel();
        $model->insertGetId(['user_name' => 'column_test']);

        $column = $model->column('user_name');
        $this->assertIsArray($column);
        $this->assertCount(1, $column);
    }

    /** 复杂查询：多条件 + 排序 + limit */
    public function testComplexQuery(): void
    {
        $model = $this->createModel();

        $model->insertGetId(['user_name' => 'complex1', 'age' => 20]);
        $model->insertGetId(['user_name' => 'complex2', 'age' => 25]);

        $result = $model
            ->where('age', '>', 18)
            ->where('user_name', 'LIKE', '%complex%')
            ->order('age', 'desc')
            ->limit(10)
            ->get();

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
    }

    /** update 自动维护 updated_at 字段（带 sleep，确保时间有变化） */
    public function testUpdateWithAutoTimestamp(): void
    {
        $model = $this->createModel();
        $id = $model->insertGetId(['user_name' => 'timestamp_test']);

        sleep(1);

        $result = $model->where('id', $id)->update('user_name', 'updated_timestamp');
        $this->assertIsInt($result);

        $user = $model->where('id', $id)->first();
        $this->assertNotNull($user['updated_at']);
    }

    /** insert 自动维护 created_at 与 updated_at 字段 */
    public function testInsertWithAutoTimestamp(): void
    {
        $model = $this->createModel();
        $id = $model->insertGetId(['user_name' => 'auto_timestamp_test']);

        $user = $model->where('id', $id)->first();
        $this->assertNotNull($user['created_at']);
        $this->assertNotNull($user['updated_at']);
    }

    /** where 传入非法格式时，应抛出 where格式错误 异常 */
    public function testWhereException(): void
    {
        $model = $this->createModel();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('where格式错误');

        $model->where('invalid_format');
    }

    /** update 在没有 where 条件时，应抛出异常 */
    public function testUpdateWithoutWhereException(): void
    {
        $model = $this->createModel();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('update操作必须带条件');

        $model->update('user_name', 'test');
    }

    /** delete 在没有 where 条件时，应抛出异常 */
    public function testDeleteWithoutWhereException(): void
    {
        $model = $this->createModel();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('delete操作必须带条件');

        $model->delete();
    }
}

/**
 * 专用测试模型，使用 test_users 表，并覆写时间字段构造逻辑。
 */
class ContextTestUserModel extends BaseModel
{
    public static string $tableName = 'test_users';

    protected function buildUpdateTime($time = null)
    {
        if (!empty($time)) {
            return $time;
        }
        return date('Y-m-d H:i:s');
    }

    protected function buildCreateTime()
    {
        return date('Y-m-d H:i:s');
    }
}

