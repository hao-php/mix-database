# Model 使用指南

[返回 README](README.md)

`Haoa\MixDatabase\Context\Model` 是轻量的数据访问封装，不读取表结构，不提供实体属性映射或业务字段权限管理。查询结果是数组或对象，不是 Model 实体。业务筛选、权限和事务编排由调用方负责。

## 定义模型与连接

Model 应通过 `db($db)` 绑定 `Context\Database`，不接受基础 `Haoa\MixDatabase\Database`。事务也通过同一个 Context Database 自动接入，见下文。

以下示例假设存在 `users` 表，包含 `id`、`name`、`balance` 字段；不执行建表操作。

```php
use Haoa\MixDatabase\Context\Database;
use Haoa\MixDatabase\Context\Model;
use Haoa\MixDatabase\Expr;

/** 用户数据模型。 */
class UserModel extends Model
{
    public static string $tableName = 'users';

    // 示例表没有时间字段，关闭自动填充。
    protected string $createTimeField = '';
    protected string $updateTimeField = '';
}

$db = new Database(
    'pgsql:host=127.0.0.1;port=5432;dbname=test',
    'username',
    'password',
    [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
    true
);

$user = UserModel::db($db)->where('id', 1)->first();
```

MySQL 可将 DSN 替换为 `mysql:host=127.0.0.1;port=3306;charset=utf8mb4;dbname=test`。
Model 使用的类型语法要求 PHP 8.0 及以上；运行环境还须满足已安装依赖的版本要求。

`UserModel::db($db)` 每次创建新实例。`make()` 只创建实例，不会自动注入连接，只有自行在子类构造器等位置配置连接后才能查询。

启用连接池的方式与基础 Database 相同，参见 [连接池说明](README.md#启动连接池-pool)。可以共享已启用池的 Database，不能跨并发协程共享可变 Model、查询链或事务。

## 查询与条件

### 普通条件

Model 使用结构化条件，与基础 DB 的 `where('id = ?', 1)` 不同：

```php
$user = UserModel::db($db)->where('id', 1)->first();
$users = UserModel::db($db)->where('balance', '>=', 100)->get();

$where = [
    ['balance', '>=', 100],
    ['name', 'LIKE', '张%'],
    ['id', 'IN', [1, 2, 3]],
];
$users = UserModel::db($db)->where($where)->order('id', 'desc')->get();
```

同一 `where($where)` 数组内部用 AND 连接，并用括号包裹；多次 `where()` 也用 AND 连接。数组条件必须是二维数组，不是 `['id' => 1]`。

支持操作符：`=、!=、<>、>、>=、<、<=、LIKE、NOT LIKE、IN、NOT IN、IS、IS NOT、ILIKE、NOT ILIKE`。大小写和操作符内部空白会统一；`ILIKE`、`NOT ILIKE` 仅适用于 PostgreSQL。

- IN/NOT IN 必须传数组；元素逐项绑定，空数组表示空集合，不支持嵌套数组或对象元素。
- IS/IS NOT 只接受 `null` 或布尔值，并生成对应 SQL 常量。
- 字段支持 `id`、`u.id` 等合法标识符；函数表达式请使用 Raw。引用字段名时须使用数据库对应的引用字符。
- 需要 NULL 语义时用 `where('字段', 'IS', null)`，不要用等号比较 NULL。

### OR 与分组

```php
$users = UserModel::db($db)
    ->where('id', 1)
    ->orWhere('id', 2)
    ->get();

$users = UserModel::db($db)
    ->whereRaw('(name = ? OR name = ?) AND balance >= ?', '张三', '李四', 100)
    ->get();
```

当前实现先添加全部 `where()` 条件，再添加全部 `orWhere()` 条件，不按交替调用的顺序拼接。复杂 AND/OR 分组请写固定 `whereRaw()` 模板，值仍通过参数绑定。

### 字段、分页与统计

```php
$users = UserModel::db($db)
    ->select('id, name, balance')
    ->order('id', 'desc')
    ->page(1, 20)
    ->get();

$count = UserModel::db($db)->where('balance', '>=', 100)->count();
$name = UserModel::db($db)->where('id', 1)->value('name');
$names = UserModel::db($db)->order('id', 'asc')->column('name');
```

Model 的 `select()` 接受一个字段列表字符串，不支持基础 DB 的 `select('id', 'name')` 可变参数形式。`page()` 页码从 1 开始，分页参数由调用方保证为有效正整数；不会自动查询总数。

`count()` 不会自动移除已有的 GROUP、LIMIT 等条件；分页总数应重新创建查询，仅添加筛选条件，不要复用分页链。分组后的统计请显式编写聚合查询。

### JOIN、GROUP 与 HAVING

以下示例额外假设存在 `news(id, uid)` 表：

```php
$users = UserModel::db($db)
    ->alias('u')
    ->select('u.id, u.name, COUNT(n.id) AS total')
    ->leftJoin('news AS n', 'n.uid = u.id')
    ->group('u.id', 'u.name')
    ->havingRaw('COUNT(n.id) > ?', 0)
    ->order('u.id', 'desc')
    ->get();
```

`join()`、`leftJoin()` 的 ON 模板支持后续绑定参数。`having()` 与 `where()` 使用相同结构化格式；包含聚合函数时使用 `havingRaw()`。`select`、`group` 的复杂表达式以及 Raw、ON 模板必须由开发者提供。

### 返回值

| 方法 | 返回值 |
| --- | --- |
| `get()` | 多行数组；每行类型取决于 PDO fetch 配置 |
| `first()` | 单行数组或对象，无记录返回 `false` |
| `value('name')` | 单字段值，无记录或无法取得字段时返回 `null` |
| `column('name')` | 单列值数组，无记录返回 `[]` |
| `count()` | 整数；普通未分组查询返回记录数 |
| `insert($data)` | `ConnectionInterface`，可继续取 `lastInsertId()`、`rowCount()` |
| `insertGetId($data)` | 字符串形式的自增 ID |
| `batchInsert($list)` | 受影响行数 |
| `update()` / `updates()` / `delete()` | 受影响行数 |

不建议将 Model 的 `statement()` 作为查询终结方法：底层 statement 仅供 debug 回调使用，该 Model 方法不会主动执行 SELECT。常规查询使用 `get()`、`first()`。

## 新增、更新与删除

```php
$id = UserModel::db($db)->insertGetId([
    'name' => '张三',
    'balance' => 100,
]);

$affected = UserModel::db($db)->batchInsert([
    ['name' => '李四', 'balance' => 20],
    ['name' => '王五', 'balance' => 30],
]);

$affected = UserModel::db($db)->where('id', $id)->update('name', '张三丰');
$affected = UserModel::db($db)->where('id', $id)->updates([
    'name' => '张三',
    'balance' => new Expr('balance + ?', 10),
]);
$affected = UserModel::db($db)->where('id', $id)->delete();
```

Model 更新、删除必须有非空的 `where()` 或 `whereRaw()` 条件，仅有 `orWhere()` 仍会被拒绝。这是防误操作检查，不是权限控制；固定真条件仍可能影响全表。基础 DB 没有同样的无条件更新、删除保护。

Expr SQL 模板必须可信，用户值放在后续参数中；带参数 Expr 不支持直接转字符串。批量写入须提供非空列表，各行使用一致字段。

## 自动时间字段

基类默认启用 `created_at`、`updated_at`，值为 `time()` 的 Unix 秒数。表中没有这些字段时，应像前面的 UserModel 一样设为空字符串关闭，不会自动检查表结构。

如果数据表包含 `created_at`、`updated_at`，且类型为 PostgreSQL timestamp 或 MySQL datetime，可定义专用模型：

```php
/** 使用日期时间字段的用户模型。 */
class TimedUserModel extends UserModel
{
    protected string $createTimeField = 'created_at';
    protected string $updateTimeField = 'updated_at';

    /** 生成创建时间，使用应用当前时区。 */
    protected function buildCreateTime()
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * 生成更新时间。
     * @param mixed $createTime 本次创建时间，更新时为 null
     */
    protected function buildUpdateTime($createTime = null)
    {
        return $createTime ?? date('Y-m-d H:i:s');
    }
}
```

插入和批量插入自动补充时间字段，更新只补充更新时间；使用 `isset()` 判断是否已有值，所以显式传入 `null` 也会触发自动补充。自定义字段名可覆盖相应属性。

## 读写分离

`$readDb` 是另外创建的 `Context\Database`，配置为只读副本连接；协程并发使用时，主从连接均应分别启用连接池。

```php
$readDb = new Database(
    'pgsql:host=127.0.0.1;port=5433;dbname=test',
    'readonly_user',
    'password',
    [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$users = UserModel::db($db, $readDb)->get();

// 刚写入后要求从主库读取时，不传读库。
$user = UserModel::db($db)->where('id', 1)->first();
```

- 查询优先使用读库；未配置时回退主库。
- 写入使用主库；主库的上下文事务存在时，Model 查询也复用该事务。
- 行锁查询强制使用主库，须在事务中使用；不会自动添加数据库锁。
- 可通过 `getLastConnectionType()` 查看最近一次连接选择，主库普通操作通常为 `default`，读操作为 `read`，事务为 `transaction`；`read` 也可能是未配置读库时回退主库。

## 上下文事务

使用同一个 `Context\Database` 实例开始事务，再通过它创建 Model，即可自动复用当前上下文事务：

```php
$tx = $db->beginTransaction();
try {
    UserModel::db($db)->where('id', 1)->update('balance', new Expr('balance - ?', 10));
    UserModel::db($db)->where('id', 2)->update('balance', new Expr('balance + ?', 10));
    $tx->commit();
} catch (Throwable $ex) {
    $tx->rollback();
    throw $ex;
}
```

这里仅演示事务连接复用，不包含余额校验等业务规则。不要直接使用 `UserModel::db($tx)`：虽然参数类型允许事务包装器，但当前 Model 连接选择依赖 `getObjectHash()`，事务包装器未提供该接口。这里统一绑定 `$db`，由上下文接入事务。

注意以下区别：

- 基础 Database 的 `transaction(function ($tx) {...})` 必须在回调里使用传入的 `$tx`。
- `Context\Database` 没有独立的上下文自动事务实现，`transaction()` 会透传给基础 DB，不能据此假设回调中的 Model 自动加入事务；Model 推荐使用上面的显式 `beginTransaction()`。
- 同一 Context Database 多次 `beginTransaction()` 使用嵌套计数，不是 savepoint；内层 rollback 不会立即撤销内层 SQL。需要内层独立回滚的业务不能依赖它。
- 事务必须在同一上下文内完成，不能跨并发协程共享。非协程环境的上下文生命周期应由应用管理。

## 实例状态与调试

查询条件在构建查询时重置，不会自动保留到下次操作。多次修改 `select`、`group`、`page` 时，后一次设置覆盖前一次；`where`、`order` 等则累加。

```php
$model = UserModel::db($db);
$users = $model->where('balance', '>=', 100)->get();
$log = $model->getLastQueryLog(); // 包含 sql、bindings、time 等信息

$users = UserModel::db($db)
    ->debug(function (Haoa\MixDatabase\ConnectionInterface $conn) {
        var_dump($conn->queryLog());
    })
    ->where('id', 1)
    ->get();
```

`getLastSql()` 是插值后的展示字符串，不保证可以重新执行；以 `getLastQueryLog()` 的 SQL 与绑定参数为准。空结果的部分快捷查询可能不会刷新日志；构建或校验异常后的实例不要继续复用，重新调用 `db()` 创建即可。

业务层应明确选取允许写入、筛选和排序的字段，不要直接将整个请求数组传给 `updates()`。标识符校验只能限制 SQL 结构，不能代替业务权限。
