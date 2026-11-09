<?php

use Haoa\MixDatabase\AbstractConnection;
use Haoa\MixDatabase\ConnectionInterface;
use Haoa\MixDatabase\Context\Model;
use Haoa\MixDatabase\Driver\DriverInterface;
use Haoa\MixDatabase\Driver\MysqlDriver;
use Haoa\MixDatabase\Driver\PgsqlDriver;
use Haoa\MixDatabase\Expr;
use Haoa\MixDatabase\Sql;
use PHPUnit\Framework\TestCase;

/** 不创建数据库连接的 SQL 安全回归测试。 */
final class SqlSecurityTest extends TestCase
{
    /** 数组值必须绑定，且与普通参数保持原有顺序。 */
    public function testArrayBindings(): void
    {
        $attack = "x') OR 1=1 --";
        $conn = new SecurityConnection();
        $conn->table('users')->where('id = ? AND name IN (?) OR id IN (?)', 7, ["O'Reilly", $attack], [8, 9])->delete();
        $this->assertSame('DELETE FROM users WHERE id = ? AND name IN (?, ?) OR id IN (?, ?)', $conn->captured[0]);
        $this->assertSame([7, "O'Reilly", $attack, 8, 9], $conn->captured[1]);
    }

    /** 空集合使用空集查询，IN 与 NOT IN 均不生成非法空括号。 */
    public function testEmptyArrays(): void
    {
        $this->assertSame(['x IN (SELECT NULL WHERE 1 = 0) OR y NOT IN (SELECT NULL WHERE 1 = 0)', []], Sql::expand('x IN (?) OR y NOT IN (?)', [[], []]));
    }

    /** 嵌套数组必须拒绝。 */
    public function testNestedArrayRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Sql::expand('x IN (?)', [[[1]]]);
    }

    /** 表达式在插入、更新和批量写入中均传递独立绑定参数。 */
    public function testExpressionWritePaths(): void
    {
        $attack = "x', admin = true --";
        $conn = new SecurityConnection();
        $conn->insert('users', ['name' => new Expr('COALESCE(?, ?)', $attack, 'fallback'), 'age' => 8]);
        $this->assertSame(['INSERT INTO users (name, age) VALUES (COALESCE(?, ?), ?)', [$attack, 'fallback', 8]], $conn->captured);
        $conn->table('users')->where('id = ?', 9)->updates(['age' => new Expr('age + ?', 1), 'name' => new Expr('UPPER(?)', $attack)]);
        $this->assertSame(['UPDATE users SET age = age + ?, name = UPPER(?) WHERE id = ?', [1, $attack, 9]], $conn->captured);
        $conn->batchInsert('users', [['name' => new Expr('UPPER(?)', $attack), 'age' => 8], ['name' => 'normal', 'age' => new Expr('1 + ?', 2)]]);
        $this->assertSame(['INSERT INTO users (name, age) VALUES (UPPER(?), ?), (?, 1 + ?)', [$attack, 8, 'normal', 2]], $conn->captured);
        $conn->insert('users', ['created_at' => new Expr('CURRENT_TIMESTAMP')]);
        $this->assertSame(['INSERT INTO users (created_at) VALUES (CURRENT_TIMESTAMP)', []], $conn->captured);
    }

    /** 字符串、引用标识符、注释和 dollar 字符串中的占位符不展开。 */
    public function testQuotedMarkers(): void
    {
        $sql = "SELECT '?', \"?\", `?`, $$?$$, \$tag\$?\$tag\$, 'it''s ?' /* ? /* ? */ ? */ -- ?\nWHERE x IN (?) AND y = ?";
        $expected = str_replace('x IN (?)', 'x IN (?, ?)', $sql);
        $this->assertSame([$expected, [1, 2, 3]], Sql::expand($sql, [[1, 2], 3]));
        $this->assertSame(['SELECT ??, ?::int', [1]], Sql::expand('SELECT ??, ?::int', [1]));
    }

    /** 两种方言分别识别反斜线字符串、井号注释和 JSON 运算符。 */
    public function testDialectMarkers(): void
    {
        $pgsql = "SELECT '\\', data #>> '{name}' FROM users WHERE id IN (?)";
        $this->assertSame([str_replace('IN (?)', 'IN (?, ?)', $pgsql), [1, 2]], Sql::expand($pgsql, [[1, 2]]));
        $mysql = "SELECT 'it\\'s ?' # ?\nWHERE id IN (?)";
        $this->assertSame([str_replace('IN (?)', 'IN (?, ?)', $mysql), [1, 2]], Sql::expand($mysql, [[1, 2]], true));
        $pgsql = "SELECT E'it\\'s ?' WHERE id = ?";
        $this->assertSame([$pgsql, [1]], Sql::expand($pgsql, [1]));
    }

    /** 命名参数不能误替换字符串或其他参数名前缀。 */
    public function testNamedExpression(): void
    {
        $this->assertSame(["SELECT ':a', COALESCE(?, ?), ?::text", ['x', 'y', 'z']], Sql::named("SELECT ':a', :a, :ab::text", ['a' => new Expr('COALESCE(?, ?)', 'x', 'y'), 'ab' => 'z']));
    }

    /** 表字段在引号开关两种配置下均校验，复杂查询表达式仍保留。 */
    public function testIdentifiers(): void
    {
        foreach ([new MysqlDriver(), new PgsqlDriver()] as $driver) {
            foreach ([false, true] as $enabled) {
                $driver->setQuoteIdentifiers($enabled);
                $q = $driver->getQuoteChar()[0];
                $this->assertSame($enabled ? "$q" . "public$q.$q" . "users$q AS {$q}u$q" : 'public.users AS u', $driver->quoteTableName('public.users AS u'));
                $this->assertSame($enabled ? "{$q}u$q.{$q}name$q" : 'u.name', $driver->quoteFieldName('u.name'));
                $this->assertSame("{$q}odd name$q", $driver->quoteFieldName("{$q}odd name$q"));
                $this->assertSame('COUNT(*) AS total', $driver->quoteColumnName('COUNT(*) AS total'));
                foreach (['name DESC; SELECT 1', "{$q}name$q OR 1=1", 'id, name', 'COUNT(*)'] as $bad) {
                    try { $driver->quoteFieldName($bad); $this->fail('恶意字段未被拒绝'); }
                    catch (InvalidArgumentException $ex) { $this->assertNotEmpty($ex->getMessage()); }
                }
                try { $driver->quoteTableName('users; DELETE FROM users'); $this->fail('恶意表名未被拒绝'); }
                catch (InvalidArgumentException $ex) { $this->assertNotEmpty($ex->getMessage()); }
            }
        }
    }

    /** Model 普通条件校验字段、操作符并保留 Raw 入口。 */
    public function testModelConditions(): void
    {
        $model = new SecurityModel();
        $this->assertSame(['(u.id NOT IN (?))', [[1, 2]]], $model->condition('u.id', ' not   in ', [1, 2]));
        $this->assertSame(['(deleted_at IS NULL)', []], $model->condition('deleted_at', 'is', null));
        $model->whereRaw('LOWER(name) = ?', 'x')->havingRaw('COUNT(*) > ?', 1);
        $this->assertFalse($model->isClean());
        foreach ([['id OR 1=1', '=', 1], ['id', '= ? OR 1=1 --', 1], ['id', '=', [1]]] as $condition) {
            try { $model->condition(...$condition); $this->fail('非法条件未被拒绝'); }
            catch (InvalidArgumentException $ex) { $this->assertNotEmpty($ex->getMessage()); }
        }
        $conn = new SecurityConnection();
        $this->expectException(InvalidArgumentException::class);
        $conn->order('id DESC, (SELECT 1)', 'asc');
    }

    /** HAVING、JOIN 与 Raw 的值同样绑定，可信表达式结构保持不变。 */
    public function testTrustedSqlBindings(): void
    {
        $conn = new SecurityConnection();
        $conn->table('users AS u')->leftJoin('logs AS l', 'l.user_id = u.id AND l.kind = ?', 'login')
            ->where('u.id IN (?)', [1, 2])->group('u.id')->having('COUNT(*) IN (?)', [3, 4])->delete();
        $this->assertSame(['DELETE FROM users AS u LEFT JOIN logs AS l ON l.user_id = u.id AND l.kind = ? WHERE u.id IN (?, ?) GROUP BY u.id HAVING COUNT(*) IN (?, ?)', ['login', 1, 2, 3, 4]], $conn->captured);
        $conn->raw('SELECT LOWER(?)', "x') OR 1=1 --");
        $this->assertSame(['SELECT LOWER(?)', ["x') OR 1=1 --"]], $conn->captured);
    }

    /** 驱动扩展写入中的表达式也经统一绑定。 */
    public function testDriverWrites(): void
    {
        $conn = new SecurityConnection();
        $conn->insertReturning('users', ['name' => new Expr('LOWER(?)', "O'Reilly")], 'id');
        $this->assertSame(['INSERT INTO users (name) VALUES (LOWER(?)) RETURNING id', ["O'Reilly"]], $conn->captured);
        $conn->insertOnConflict('users', ['id' => 1, 'name' => new Expr('UPPER(?)', 'x')], 'id', ['name']);
        $this->assertSame([1, 'x'], $conn->captured[1]);
        $driver = new MysqlDriver();
        $result = $driver->buildReplaceInsert('users', ['name' => new Expr('LOWER(?)', 'x')]);
        $this->assertSame(['REPLACE INTO users (name) VALUES (LOWER(?))', ['x']], Sql::expand($result['sql'], $result['values']));
        $result = $driver->buildInsertOnDuplicateKey('users', ['name' => new Expr('LOWER(?)', 'x')], ['name']);
        $this->assertSame(['x'], Sql::expand($result['sql'], $result['values'])[1]);
    }

    /** 写入字段和别名不能进入 SQL 结构。 */
    public function testInvalidWriteFieldsAndAliases(): void
    {
        $conn = new SecurityConnection();
        foreach (['insert', 'batchInsert', 'updates'] as $method) {
            try {
                $data = ['name) VALUES (1); --' => 'x'];
                if ($method === 'updates') { $conn->table('users')->updates($data); }
                elseif ($method === 'batchInsert') { $conn->batchInsert('users', [$data]); }
                else { $conn->insert('users', $data); }
                $this->fail('非法写入字段未被拒绝');
            } catch (InvalidArgumentException $ex) { $this->assertNotEmpty($ex->getMessage()); }
        }
        $this->expectException(InvalidArgumentException::class);
        (new SecurityModel())->alias('u JOIN secrets ON 1=1');
    }

    /** 带参数表达式不允许通过字符串转换绕过绑定。 */
    public function testExpressionStringConversionRejected(): void
    {
        $this->expectException(LogicException::class);
        (string) new Expr('UPPER(?)', 'x');
    }

    /** 非 IN 位置不允许数组参数展开。 */
    public function testArrayOutsideInRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Sql::expand('SELECT ?', [[1, 2]]);
    }

    /** 通过 PDO 替身确认最终值传入 bindValue，而不是 SQL 字符串。 */
    public function testPdoBindingBoundary(): void
    {
        $attack = "x') OR 1=1 --";
        $bound = [];
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects($this->exactly(3))->method('bindValue')->willReturnCallback(
            static function ($index, $value, $type) use (&$bound) {
                $bound[$index] = [$value, $type];
                return true;
            }
        );
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())->method('prepare')->with('UPDATE users SET name = UPPER(?) WHERE id IN (?, ?)')->willReturn($statement);
        $connector = $this->createMock(\Haoa\MixDatabase\Connector::class);
        $connector->method('instance')->willReturn($pdo);
        $conn = new SecurityConnection($connector);
        $conn->table('users')->where('id IN (?)', [1, 2])->updates(['name' => new Expr('UPPER(?)', $attack)]);
        $conn->prepareCaptured();
        $this->assertSame([1 => [$attack, PDO::PARAM_STR], 2 => [1, PDO::PARAM_INT], 3 => [2, PDO::PARAM_INT]], $bound);
    }

    /** 参数数量不匹配不能静默产生错误 SQL。 */
    public function testParameterMismatch(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Sql::expand('SELECT ?, ?', [1]);
    }
}

/** 仅捕获 SQL，不创建 PDO 或执行数据库语句。 */
final class SecurityConnection extends AbstractConnection
{
    public array $captured = [];

    /**
     * 使用无连接驱动或 PDO 替身。
     * @param \Haoa\MixDatabase\Connector|null $connector 测试连接器
     */
    public function __construct(?\Haoa\MixDatabase\Connector $connector = null)
    {
        $this->connector = $connector;
    }

    /** 调用实际参数绑定流程，不执行 SQL。 */
    public function prepareCaptured(): void
    {
        $this->prepare();
    }

    /** 获取无连接的 SQL 方言。 */
    protected function getDriver(): DriverInterface { return new PgsqlDriver(); }

    /** 捕获构建后的 SQL 和绑定参数。 */
    public function execute(): ConnectionInterface
    {
        $this->captured = [$this->sql, $this->values];
        return $this;
    }
}

/** 暴露纯条件构建用于测试。 */
final class SecurityModel extends Model
{
    public static string $tableName = 'users';

    /**
     * 获取条件 SQL 和参数。
     * @param mixed ...$where 条件参数
     */
    public function condition(...$where): array { return $this->buildWhere(...$where); }
}
