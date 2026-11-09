# mix-database

支持 MySQL、PostgreSQL 的 PDO 查询构建器和协程连接池，提供可选的轻量 Model。

- 本文：基础 `Haoa\MixDatabase\Database` 的 CRUD、连接池和事务。
- [Model 使用指南](MODEL.md)：`Context\Database`、Model、自动时间、读写分离和上下文事务。

## 安装

```
composer require haoa/mix-database
```

## 快速开始

以下示例假设已加载 Composer 自动加载文件，并存在相应数据表。
基础 DB 默认使用 `PDO::FETCH_OBJ`；如需关联数组，通过构造器第四个参数设置 `PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC`。

并发协程应启用连接池后共享 Database；不能跨并发请求共享查询链、Connection、Transaction 或可变的 Model 实例。未启用连接池时，不要让多个协程并发使用同一个 Database。

```php
$db = new Haoa\MixDatabase\Database('mysql:host=127.0.0.1;port=3306;charset=utf8mb4;dbname=test', 'username', 'password');
```

创建

```php
$db->insert('users', [
    'name' => 'foo',
    'balance' => 0,
]);
```

查询

```php
$db->table('users')->where('id = ?', 1)->first();
```

更新

```php
$db->table('users')->where('id = ?', 1)->update('name', 'foo1');
```

删除

```php
$db->table('users')->where('id = ?', 1)->delete();
```

PostgreSQL 连接示例（替代上面的 MySQL 连接）：

```php
$db = new Haoa\MixDatabase\Database(
    'pgsql:host=127.0.0.1;port=5432;dbname=test',
    'username',
    'password',
    [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
    true // 对普通表名、字段名加驱动对应的引号
);
```

### Model 快速示例

Model 必须绑定 `Context\Database`，不能直接绑定基础 Database。以下示例独立于上面的 `$db`：

```php
/** 用户数据模型。 */
class UserModel extends Haoa\MixDatabase\Context\Model
{
    public static string $tableName = 'users';

    // 示例表没有自动时间字段，关闭自动填充。
    protected string $createTimeField = '';
    protected string $updateTimeField = '';
}

$modelDb = new Haoa\MixDatabase\Context\Database(
    'pgsql:host=127.0.0.1;port=5432;dbname=test',
    'username',
    'password',
    [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$users = UserModel::db($modelDb)->where('id', 'IN', [1, 2])->get();
```

完整用法见 [Model 使用指南](MODEL.md)。

## 启动连接池 Pool

在 `Swoole` 协程环境中，启动连接池

```php
$maxOpen = 50;        // 最大开启连接数
$maxIdle = 20;        // 最大闲置连接数
$maxLifetime = 3600;  // 连接的最长生命周期
$waitTimeout = 0.0;   // 从池获取连接等待的时间, 0为一直等待
$db->startPool($maxOpen, $maxIdle, $maxLifetime, $waitTimeout);
Swoole\Runtime::enableCoroutine(); // 示例先初始化连接池，再开启协程 Hook
Swoole\Coroutine\run(function () use ($db) {
    $users = $db->table('users')->limit(10)->get();
});
```

连接池统计

```php
$db->poolStats(); // array, fields: total, idle, active
```

## 创建 Insert

创建

```php
$data = [
    'name' => 'foo',
    'balance' => 0,
];
$db->insert('users', $data);
```

获取 InsertId

```php
$data = [
    'name' => 'foo',
    'balance' => 0,
];
$insertId = $db->insert('users', $data)->lastInsertId();
```

替换创建（仅 MySQL；REPLACE 具有替换语义，不等同于普通 UPDATE）

```php
$data = [
    'name' => 'foo',
    'balance' => 0,
];
$db->insert('users', $data, 'REPLACE INTO');
```

批量创建

```php
$data = [
    [
        'name' => 'foo',
        'balance' => 0,
    ],
    [
        'name' => 'foo1',
        'balance' => 0,
    ]
];
$db->batchInsert('users', $data);
```

使用函数创建

```php
$data = [
    'name' => 'foo',
    'balance' => 0,
    'add_time' => new Haoa\MixDatabase\Expr('CURRENT_TIMESTAMP'),
];
$db->insert('users', $data);
```

## 查询 Select

### 获取结果

|  方法名称   | 描述  |
|  ----  | ----  |
| get(): array  | 获取多行 |
| first(): array or object or false  | 获取第一行，无记录返回 false |
| value(string $field): mixed  | 获取第一行某个字段 |
| statement(): \PDOStatement  | 仅能在 debug 回调中读取当前结果集，不作为独立查询终结方法 |

基础 DB 的 `value()` 在没有记录或找不到字段时抛出 `PDOException`；Model 的 `value()` 无记录时返回 `null`。

### Where

#### AND

```php
$db->table('users')->where('id = ? AND name = ?', 1, 'foo')->get();
```

```php
$db->table('users')->where('id = ?', 1)->where('name = ?', 'foo')->get();
```

#### OR

```php
$db->table('users')->where('id = ? OR id = ?', 1, 2)->get();
```

```php
$db->table('users')->where('id = ?', 1)->or('id = ?', 2)->get();
```

#### IN

```php
$db->table('users')->where('id IN (?)', [1, 2])->get();
```

```php
$db->table('users')->where('id NOT IN (?)', [1, 2])->get();
```

IN 数组逐项绑定，支持与普通参数混用。空数组使用空集子查询；不支持嵌套数组，数组占位符须写为 `IN (?)` 或 `NOT IN (?)`。

### Select

```php
$db->table('users')->select('id, name')->get();
```

```php
$db->table('users')->select('id', 'name')->get();
```

```php
$db->table('users')->select('name AS n')->get();
```

### Order

```php
$db->table('users')->order('id', 'desc')->get();
```

```php
$db->table('users')->order('id', 'desc')->order('name', 'asc')->get();
```

### Limit

```php
$db->table('users')->limit(5)->get();
```

```php
$db->table('users')->offset(10)->limit(5)->get();
```

### Group & Having

```php
$db->table('news')->select('uid, COUNT(*) AS total')->group('uid')->having('COUNT(*) > ?', 0)->get();
```

```php
$db->table('news')->select('uid, COUNT(*) AS total')->group('uid')->having('COUNT(*) > ? AND COUNT(*) < ?', 0, 10)->get();
```

### Join

```php
$db->table('news AS n')->select('n.*, u.name')->join('users AS u', 'n.uid = u.id')->get();
```

```php
$db->table('news AS n')->select('n.*, u.name')->leftJoin('users AS u', 'n.uid = u.id AND u.balance > ?', 10)->get();
```

## 更新 Update

更新单个字段

```php
$db->table('users')->where('id = ?', 1)->update('name', 'foo1');
```

获取影响行数

```php
$rowsAffected = $db->table('users')->where('id = ?', 1)->update('name', 'foo1')->rowCount();
```

更新多个字段

```php
$data = [
    'name' => 'foo1',
    'balance' => 100,
];
$db->table('users')->where('id = ?', 1)->updates($data);
```

使用表达式更新

```php
$db->table('users')->where('id = ?', 1)->update('balance', new Haoa\MixDatabase\Expr('balance + ?', 1));
```

```php
$data = [
    'balance' => new Haoa\MixDatabase\Expr('balance + ?', 1),
];
$db->table('users')->where('id = ?', 1)->updates($data);
```

使用函数更新

```php
$db->table('users')->where('id = ?', 1)->update('add_time', new Haoa\MixDatabase\Expr('CURRENT_TIMESTAMP'));
```

```php
$data = [
    'add_time' => new Haoa\MixDatabase\Expr('CURRENT_TIMESTAMP'),
];
$db->table('users')->where('id = ?', 1)->updates($data);
```

## 删除 Delete

删除

```php
$db->table('users')->where('id = ?', 1)->delete();
```

获取影响行数

```php
$rowsAffected = $db->table('users')->where('id = ?', 1)->delete()->rowCount();
```

## SQL 安全边界

- 普通值、IN 数组和 Expr 参数通过 PDO 绑定；不要提前拼入 SQL。
- 表名、别名、写入字段和排序字段校验标识符；字段是否存在仍由数据库判断，不读取表结构。
- `where` 的 SQL 模板、`select`/`group` 复杂表达式、JOIN ON、HAVING、原生 SQL 和 Expr 的 SQL 模板必须由开发者提供，不能直接接收用户输入。
- Expr 的 `?` 参数真正绑定；带参数 Expr 不可直接转换成字符串。
- 请求允许读写、排序的字段仍由业务层控制；合法字段名不代表有业务访问权限。

## 原生 Raw

```php
$db->raw('SELECT * FROM users WHERE id = ?', 1)->first();
```

```php
$db->exec('DELETE FROM users WHERE id = ?', 1)->rowCount();
```

## 事务 Transaction

手动事务

```php
$tx = $db->beginTransaction();
try {
    $data = [
        'name' => 'foo',
        'balance' => 0,
    ];
    $tx->insert('users', $data);
    $tx->commit();
} catch (\Throwable $ex) {
    $tx->rollback();
    throw $ex;
}
```

自动事务，执行异常自动回滚并抛出异常

```php
$db->transaction(function (Haoa\MixDatabase\Transaction $tx) {
    $data = [
        'name' => 'foo',
        'balance' => 0,
    ];
    $tx->insert('users', $data);
});
```

## 调试 Debug

```php
$db->debug(function (Haoa\MixDatabase\ConnectionInterface $conn) {
        var_dump($conn->queryLog()); // array, fields: time, sql, bindings
    })
    ->table('users')
    ->where('id = ?', 1)
    ->get();
```

`statement()` 也仅在 debug 回调中使用；不要在回调里消费结果集后，又期待外层 `get()` 返回相同记录。`queryLog()` 的 `sql` 与 `bindings` 是调试依据，插值后的展示 SQL 不应拿来再次执行。

## 日志 Logger

日志记录器，配置后可打印全部SQL信息

```php
$db->setLogger($logger);
```

`$logger` 需实现 `Haoa\MixDatabase\LoggerInterface`

```php
interface LoggerInterface
{
    public function trace(float $time, string $sql, array $bindings, int $rowCount, ?\Throwable $exception): void;
}
```

## 测试

无数据库测试（不会运行建表及数据初始化）：

```sh
./vendor/bin/phpunit -c phpunit.unit.xml
```

数据库集成测试会建表及写入数据，配置与运行方式见 [测试说明](tests/README.md)。

## License

Apache License Version 2.0, http://www.apache.org/licenses/
