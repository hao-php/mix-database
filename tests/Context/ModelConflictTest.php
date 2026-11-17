<?php

declare(strict_types=1);

use Haoa\MixDatabase\AbstractConnection;
use Haoa\MixDatabase\ConnectionInterface;
use Haoa\MixDatabase\Context\Model;
use Haoa\MixDatabase\Driver\DriverInterface;
use Haoa\MixDatabase\Driver\MysqlDriver;
use Haoa\MixDatabase\Driver\PgsqlDriver;
use PHPUnit\Framework\TestCase;

/** 冲突写入的无数据库单元测试，验证时间维护和实际驱动 SQL。 */
final class ModelConflictTest extends TestCase
{
    /** PostgreSQL 自动维护时间，指定冲突键且不更新创建时间。 */
    public function testPostgresUpdatesAndPreservesCreationTime(): void
    {
        $conn = new ModelConflictConnection(new PgsqlDriver());
        $model = new ModelConflictModel($conn);
        $result = $model->insertOnConflict(['id' => 1, 'name' => "O'Reilly"], 'id', ['name']);
        $this->assertSame($conn, $result);
        $this->assertStringContainsString('ON CONFLICT ("id") DO UPDATE SET "name" = EXCLUDED."name", "updated_at" = EXCLUDED."updated_at"', $conn->captured[0]);
        $this->assertSame([1, "O'Reilly", 100, 100], $conn->captured[1]);
        $this->assertSame(Model::WRITE, $model->connectionType);
        $this->assertSame($conn->queryLog(), $model->getLastQueryLog());
        $this->assertTrue($model->isClean());
    }

    /** PostgreSQL 空更新列保持 DO NOTHING，并支持复合冲突键。 */
    public function testPostgresDoNothingWithCompositeKey(): void
    {
        $conn = new ModelConflictConnection(new PgsqlDriver());
        (new ModelConflictModel($conn))->insertOnConflict(['id' => 1, 'name' => 'a'], ['id', 'name']);
        $this->assertStringContainsString('ON CONFLICT ("id", "name") DO NOTHING', $conn->captured[0]);
        $this->assertSame([1, 'a', 100, 100], $conn->captured[1]);
    }

    /** MySQL 使用自身冲突语法，自动更新修改时间且不重复列名。 */
    public function testMysqlAndExplicitTimes(): void
    {
        $conn = new ModelConflictConnection(new MysqlDriver());
        $model = new ModelConflictModel($conn);
        $model->insertOnDuplicateKey(['id' => 1, 'name' => 'b', 'created_at' => 10, 'updated_at' => 20], ['name', 'updated_at']);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `updated_at` = VALUES(`updated_at`)', $conn->captured[0]);
        $this->assertSame([1, 'b', 10, 20], $conn->captured[1]);
        $this->assertTrue($model->isClean());
    }

    /** 关闭时间字段时不生成时间列，连续使用同一实例不会残留数据。 */
    public function testDisabledTimestampsAndReuse(): void
    {
        $conn = new ModelConflictConnection(new PgsqlDriver());
        $model = new ModelConflictModel($conn, '', '');
        $model->insertOnConflict(['id' => 1, 'name' => 'a'], 'id', ['name']);
        $model->insertOnConflict(['id' => 2, 'name' => 'b'], 'id', ['name']);
        $this->assertStringNotContainsString('created_at', $conn->captured[0]);
        $this->assertStringNotContainsString('updated_at', $conn->captured[0]);
        $this->assertSame([2, 'b'], $conn->captured[1]);
    }

    /** 自定义时间字段遵循模型配置。 */
    public function testCustomTimestampFields(): void
    {
        $conn = new ModelConflictConnection(new PgsqlDriver());
        (new ModelConflictModel($conn, 'ctime', 'mtime'))->insertOnConflict(['id' => 1, 'name' => 'a'], 'id', ['name']);
        $this->assertStringContainsString('"mtime" = EXCLUDED."mtime"', $conn->captured[0]);
        $this->assertStringNotContainsString('"ctime" =', $conn->captured[0]);
    }

    /** 空数据、空冲突键、未知更新列及创建时间更新均应被拒绝。 */
    public function testInvalidArguments(): void
    {
        $conn = new ModelConflictConnection(new PgsqlDriver());
        $model = new ModelConflictModel($conn);
        $cases = [
            [[], 'id', []],
            [['id' => 1], [], []],
            [['id' => 1], [''], []],
            [['id' => 1], 'id', ['missing']],
            [['id' => 1, 'created_at' => 10], 'id', ['created_at']],
        ];
        foreach ($cases as $args) {
            try {
                $model->insertOnConflict(...$args);
                $this->fail('非法参数未被拒绝');
            } catch (InvalidArgumentException $ex) {
                $this->assertNotEmpty($ex->getMessage());
                $this->assertTrue($model->isClean());
            }
        }
        $this->assertSame([], $conn->captured);
    }

    /** MySQL 未提供时间时自动补齐，冲突时仅更新业务列及修改时间。 */
    public function testMysqlAutomaticTimestamps(): void
    {
        $conn = new ModelConflictConnection(new MysqlDriver());
        (new ModelConflictModel($conn))->insertOnDuplicateKey(['id' => 1, 'name' => 'a'], ['name']);
        $this->assertSame([1, 'a', 100, 100], $conn->captured[1]);
        $this->assertStringContainsString('`updated_at` = VALUES(`updated_at`)', $conn->captured[0]);
        $this->assertStringNotContainsString('`created_at` =', $conn->captured[0]);
    }

    /** PostgreSQL 显式时间保持原值，不被自动时间覆盖。 */
    public function testPostgresExplicitTimes(): void
    {
        $conn = new ModelConflictConnection(new PgsqlDriver());
        (new ModelConflictModel($conn))->insertOnConflict(['id' => 1, 'name' => 'a', 'created_at' => 10, 'updated_at' => 20], 'id', ['name']);
        $this->assertSame([1, 'a', 10, 20], $conn->captured[1]);
        $this->assertStringNotContainsString('"created_at" =', $conn->captured[0]);
    }

    /** MySQL 空更新列表不得生成不完整 SQL。 */
    public function testMysqlRejectsEmptyUpdateColumns(): void
    {
        $model = new ModelConflictModel(new ModelConflictConnection(new MysqlDriver()));
        $this->expectException(InvalidArgumentException::class);
        $model->insertOnDuplicateKey(['id' => 1], []);
    }

    /** 查询条件不能混入 INSERT 参数，拒绝后模型可继续使用。 */
    public function testQueryConditionsAreRejectedAndReset(): void
    {
        $conn = new ModelConflictConnection(new PgsqlDriver());
        $model = new ModelConflictModel($conn);
        try {
            $model->where('id', 9)->insertOnConflict(['id' => 1], 'id');
            $this->fail('带查询条件的写入未被拒绝');
        } catch (LogicException $ex) {
            $this->assertTrue($model->isClean());
        }
        $model->insertOnConflict(['id' => 2], 'id');
        $this->assertSame([2, 100, 100], $conn->captured[1]);
    }

    /** 不支持的方言方法保留底层异常，模型仍恢复干净状态。 */
    public function testUnsupportedDriverResetsModel(): void
    {
        $model = new ModelConflictModel(new ModelConflictConnection(new MysqlDriver()));
        try {
            $model->insertOnConflict(['id' => 1], 'id');
            $this->fail('不支持的方言未被拒绝');
        } catch (BadMethodCallException $ex) {
            $this->assertTrue($model->isClean());
        }
    }

    /** 执行异常向上传递，不保留脏状态。 */
    public function testExecutionFailureResetsModel(): void
    {
        $conn = new ModelConflictConnection(new PgsqlDriver());
        $conn->fail = true;
        $model = new ModelConflictModel($conn);
        try {
            $model->insertOnConflict(['id' => 1], 'id');
            $this->fail('预期执行失败');
        } catch (RuntimeException $ex) {
            $this->assertSame('模拟执行失败', $ex->getMessage());
            $this->assertTrue($model->isClean());
        }
    }
    /** PostgreSQL 批量字段顺序不同时正确对齐，所有值保持参数绑定。 */
    public function testBatchPostgresAlignsFieldsAndTimes(): void
    {
        $conn = new ModelConflictConnection(new PgsqlDriver());
        $model = new ModelConflictModel($conn);
        $attack = "x'); DROP TABLE users; --";
        $ret = $model->batchInsertOnConflict([
            ['id' => 1, 'name' => 'a'],
            ['name' => $attack, 'id' => 2, 'updated_at' => 80],
        ], 'id', ['name']);
        $this->assertSame($conn, $ret);
        $this->assertStringContainsString('VALUES (?, ?, ?, ?), (?, ?, ?, ?) ON CONFLICT ("id") DO UPDATE SET', $conn->captured[0]);
        $this->assertSame([1, 'a', 100, 100, 2, $attack, 100, 80], $conn->captured[1]);
        $this->assertStringNotContainsString($attack, $conn->captured[0]);
        $this->assertStringNotContainsString('"created_at" =', $conn->captured[0]);
        $this->assertSame(Model::WRITE, $model->connectionType);
        $this->assertSame($conn->queryLog(), $model->getLastQueryLog());
        $this->assertTrue($model->isClean());
    }

    /** MySQL 批量冲突写入保持显式时间，追加修改时间更新列。 */
    public function testBatchMysqlMaintainsTimes(): void
    {
        $conn = new ModelConflictConnection(new MysqlDriver());
        (new ModelConflictModel($conn))->batchInsertOnDuplicateKey([
            ['id' => 1, 'name' => 'a', 'created_at' => 10, 'updated_at' => 20],
            ['name' => 'b', 'id' => 2],
        ], ['name']);
        $this->assertStringContainsString('VALUES (?, ?, ?, ?), (?, ?, ?, ?) ON DUPLICATE KEY UPDATE', $conn->captured[0]);
        $this->assertStringContainsString('`updated_at` = VALUES(`updated_at`)', $conn->captured[0]);
        $this->assertStringNotContainsString('`created_at` =', $conn->captured[0]);
        $this->assertSame([1, 'a', 10, 20, 2, 'b', 100, 100], $conn->captured[1]);
    }

    /** 批量 DO NOTHING 不会因自动时间变成更新。 */
    public function testBatchDoNothingAndCustomTimeFields(): void
    {
        $conn = new ModelConflictConnection(new PgsqlDriver());
        (new ModelConflictModel($conn, 'ctime', 'mtime'))->batchInsertOnConflict([
            ['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b'],
        ], ['id', 'name']);
        $this->assertStringContainsString('ON CONFLICT ("id", "name") DO NOTHING', $conn->captured[0]);
        $this->assertStringContainsString('"ctime", "mtime"', $conn->captured[0]);
        $this->assertSame([1, 'a', 100, 100, 2, 'b', 100, 100], $conn->captured[1]);
    }

    /** 时间维护关闭时不添加时间字段，可继续复用模型执行单条写入。 */
    public function testBatchDisabledTimestampsAndReuse(): void
    {
        $conn = new ModelConflictConnection(new MysqlDriver());
        $model = new ModelConflictModel($conn, '', '');
        $model->batchInsertOnDuplicateKey([['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']], ['name']);
        $this->assertSame([1, 'a', 2, 'b'], $conn->captured[1]);
        $this->assertStringNotContainsString('updated_at', $conn->captured[0]);
        $model->insertOnDuplicateKey(['id' => 3, 'name' => 'c'], ['name']);
        $this->assertSame([3, 'c'], $conn->captured[1]);
    }

    /** 空列表、空记录、非数组记录和不一致字段在执行前拒绝。 */
    public function testBatchRejectsInvalidRows(): void
    {
        foreach ([new PgsqlDriver(), new MysqlDriver()] as $driver) {
            $conn = new ModelConflictConnection($driver);
            $model = new ModelConflictModel($conn);
            foreach ([[], [[]], ['bad'], [['id' => 1, 'name' => 'a'], ['id' => 2]], [['id' => 1], ['id' => 2, 'extra' => 1]]] as $list) {
                try {
                    if ($driver instanceof PgsqlDriver) {
                        $model->batchInsertOnConflict($list, 'id', ['id']);
                    } else {
                        $model->batchInsertOnDuplicateKey($list, ['id']);
                    }
                    $this->fail('非法批量记录未被拒绝');
                } catch (InvalidArgumentException $ex) {
                    $this->assertTrue($model->isClean());
                    $this->assertSame([], $conn->captured);
                }
            }
        }
    }

    /** 批量写入同样禁止修改创建时间和引用不存在的更新列。 */
    public function testBatchRejectsInvalidUpdateColumns(): void
    {
        $conn = new ModelConflictConnection(new PgsqlDriver());
        $model = new ModelConflictModel($conn);
        foreach ([['created_at'], ['missing']] as $columns) {
            try {
                $model->batchInsertOnConflict([['id' => 1]], 'id', $columns);
                $this->fail('非法更新列未被拒绝');
            } catch (InvalidArgumentException $ex) {
                $this->assertTrue($model->isClean());
            }
        }
        $this->assertSame([], $conn->captured);
    }

    /** 带查询条件时批量写入拒绝执行并清理状态。 */
    public function testBatchRejectsQueryConditions(): void
    {
        $conn = new ModelConflictConnection(new PgsqlDriver());
        $model = new ModelConflictModel($conn);
        try {
            $model->where('id', 9)->batchInsertOnConflict([['id' => 1]], 'id');
            $this->fail('不应接受查询条件');
        } catch (LogicException $ex) {
            $this->assertTrue($model->isClean());
            $this->assertSame([], $conn->captured);
        }
    }

    /** 批量执行失败保留原异常并清理模型状态。 */
    public function testBatchExecutionFailureResetsModel(): void
    {
        $conn = new ModelConflictConnection(new MysqlDriver());
        $conn->fail = true;
        $model = new ModelConflictModel($conn);
        try {
            $model->batchInsertOnDuplicateKey([['id' => 1]], ['id']);
            $this->fail('预期执行失败');
        } catch (RuntimeException $ex) {
            $this->assertSame('模拟执行失败', $ex->getMessage());
            $this->assertTrue($model->isClean());
        }
    }

    /** 两种驱动支持 DB 透传的批量构建入口，按首行对齐字段且不自动补时间。 */
    public function testDriverBatchEntryPoints(): void
    {
        foreach ([new PgsqlDriver(), new MysqlDriver()] as $driver) {
            $rows = [['id' => 1, 'name' => 'a'], ['name' => 'b', 'id' => 2]];
            $result = $driver instanceof PgsqlDriver
                ? $driver->buildBatchInsertOnConflict('users', $rows, 'id', ['name'])
                : $driver->buildBatchInsertOnDuplicateKey('users', $rows, ['name']);
            $this->assertSame([1, 'a', 2, 'b'], $result['values']);
            $this->assertStringContainsString('VALUES (?, ?), (?, ?)', $result['sql']);
            $this->assertStringNotContainsString('updated_at', $result['sql']);
            try {
                if ($driver instanceof PgsqlDriver) {
                    $driver->buildBatchInsertOnConflict('users', [['id' => 1], ['other' => 2]], 'id');
                } else {
                    $driver->buildBatchInsertOnDuplicateKey('users', [['id' => 1], ['other' => 2]], ['id']);
                }
                $this->fail('驱动不应接受字段不一致的记录');
            } catch (InvalidArgumentException $ex) {
                $this->assertNotEmpty($ex->getMessage());
            }
        }
    }

    /** MySQL 批量更新列不能为空，不受自动时间填充影响。 */
    public function testBatchMysqlRejectsEmptyUpdateColumns(): void
    {
        $model = new ModelConflictModel(new ModelConflictConnection(new MysqlDriver()));
        $this->expectException(InvalidArgumentException::class);
        $model->batchInsertOnDuplicateKey([['id' => 1]], []);
    }

}

/** 运行真实驱动生成 SQL，仅捕获结果而不连接数据库。 */
final class ModelConflictConnection extends AbstractConnection
{
    public array $captured = [];
    public bool $fail = false;
    private DriverInterface $testDriver;

    /**
     * @param DriverInterface $driver 测试 SQL 方言
     */
    public function __construct(DriverInterface $driver)
    {
        $driver->setQuoteIdentifiers(true);
        $this->testDriver = $driver;
    }

    /** 返回测试方言。 */
    protected function getDriver(): DriverInterface { return $this->testDriver; }

    /**
     * 捕获 SQL 及值，不执行数据库操作。
     * @param string $sql SQL 模板
     * @param mixed ...$values 绑定值
     */
    public function exec(string $sql, ...$values): ConnectionInterface
    {
        if ($this->fail) {
            throw new RuntimeException('模拟执行失败');
        }
        $this->captured = [$sql, $values];
        return $this;
    }

    /** 返回捕获的查询日志。 */
    public function queryLog(): array
    {
        return ['sql' => $this->captured[0] ?? '', 'bindings' => $this->captured[1] ?? [], 'time' => 0];
    }
}

/** 固定时间和连接的测试模型。 */
final class ModelConflictModel extends Model
{
    public static string $tableName = 'users';
    public int $connectionType = 0;
    private ConnectionInterface $testConnection;

    /**
     * @param ConnectionInterface $connection 测试连接
     * @param string $createField 创建时间字段
     * @param string $updateField 更新时间字段
     */
    public function __construct(ConnectionInterface $connection, string $createField = 'created_at', string $updateField = 'updated_at')
    {
        parent::__construct();
        $this->testConnection = $connection;
        $this->createTimeField = $createField;
        $this->updateTimeField = $updateField;
    }

    /**
     * 返回替身并记录连接类型。
     * @param int $connType 连接类型
     */
    public function getConn(int $connType = 0)
    {
        $this->connectionType = $connType;
        return $this->testConnection;
    }

    /** 返回固定创建时间。 */
    protected function buildCreateTime() { return 100; }

    /**
     * 返回可预测的修改时间。
     * @param mixed $createTime 创建时间
     */
    protected function buildUpdateTime($createTime = null) { return $createTime ?? 200; }
}
