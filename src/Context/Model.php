<?php

namespace Haoa\MixDatabase\Context;

use Haoa\MixDatabase\ConnectionInterface;

/**
 *
 */
abstract class Model
{

    const WRITE = 1;

    const READ = 2;

    protected string $table;

    public static string $tableName;

    /**
     * 主数据库连接（必须设置）
     * 用于写操作和默认操作
     * @var Database|TransactionWrapper
     */
    protected Database|TransactionWrapper $database;

    /**
     * 读数据库连接（可选，用于读写分离）
     * @var Database|null
     */
    protected ?Database $readDatabase = null;

    /**
     * 当前连接类型（用于调试）
     * - 'default': 主连接
     * - 'write': 写连接
     * - 'read': 读连接
     * - 'transaction': 事务连接
     * @var string
     */
    protected string $lastConnectionType = '';

    /**
     * 是否检查事务（默认开启）
     * @var bool
     */
    private bool $checkTransaction = true;

    /**
     * 更新的时候自动写入修改时间
     * @var string
     */
    protected string $updateTimeField = 'updated_at';

    /**
     * 创建的时候自动写入创建时间
     * @var string
     */
    protected string $createTimeField = 'created_at';


    protected string $alias = '';

    protected array $wheres = [];

    protected array $ors = [];

    protected int $offset = 0;

    protected int $limit = 0;

    protected string $fields = '';

    protected array $havings = [];

    protected array $orders = [];

    protected array $group = [];

    protected array $joins = [];

    protected array $leftJoins = [];

    protected ?\Closure $debug;

    protected array $lastQueryLog = [];

    public function __construct()
    {
        $this->table = static::$tableName;
    }

    /**
     * 验证数据库和表名
     * @throws \LogicException
     */
    private function validateDatabaseAndTable(): void
    {
        if (empty($this->database)) {
            throw new \LogicException('Database connection is not set');
        }
        if (empty($this->table)) {
            throw new \LogicException('Table name is not set');
        }
    }

    /**
     * 构建带别名的表名
     */
    private function buildTableWithAlias(): string
    {
        return empty($this->alias)
            ? $this->table
            : "{$this->table} AS {$this->alias}";
    }

    /**
     * 根据连接类型选择数据库
     */
    private function selectConnectionByType(int $connType): Database|TransactionWrapper
    {
        switch ($connType) {
            case self::READ:
                // 读操作：优先使用读连接，如果没有则使用主连接
                $connection = $this->readDatabase ?? $this->database;
                $this->lastConnectionType = 'read';
                break;

            case self::WRITE:
            default:
                // 默认使用主连接
                $connection = $this->database;
                $this->lastConnectionType = 'default';
        }

        return $connection;
    }

    protected function buildUpdateTime($createTime = null)
    {
        // 创建的时候, 修改时间使用创建时间
        if (!empty($createTime)) {
            return $createTime;
        }
        return time();
    }

    protected function buildCreateTime()
    {
        return time();
    }

    protected function reset(): void
    {
        $this->alias = '';
        $this->wheres = [];
        $this->ors = [];
        $this->offset = 0;
        $this->limit = 0;
        $this->fields = '';
        $this->havings = [];
        $this->orders = [];
        $this->group = [];
        $this->joins = [];
        $this->leftJoins = [];
        $this->debug = null;
    }

    protected function buildWhere(...$where): array
    {
        $countWhere = count($where);
        if ($countWhere == 1) {
            if (isset($where[0][0]) && is_array($where[0][0])) {
                $where = $where[0];
            } else {
                throw new \Exception("where格式错误");
            }
        } elseif ($countWhere == 2) {
            $where = [$where];
        } elseif ($countWhere == 3) {
            $where = [$where];
        }
        $stringArr = [];
        $values = [];
        foreach ($where as $w) {
            $wCount = count($w);
            if ($wCount != 2 && $wCount != 3) {
                throw new \Exception("where格式错误");
            }

            if ($wCount == 2) {
                $field = $w[0];
                $option = '=';
                $value = $w[1];
            } else {
                $field = $w[0];
                $option = $w[1];
                $value = $w[2];
            }

            if (is_array($value)) {
                $stringArr[] = $field . ' ' . strtoupper($option) . ' (?)';
            } else {
                $stringArr[] = $field . ' ' . strtoupper($option) . ' ?';
            }

            $values[] = $value;
        }
        return ['(' . implode(' AND ', $stringArr) . ')', $values];
    }

    protected function buildQuery(ConnectionInterface &$conn, array $options = []): void
    {
        if (!empty($this->wheres)) {
            foreach ($this->wheres as $where) {
                $conn->where($where[0], ...$where[1]);
            }
        }

        if (!empty($this->ors)) {
            foreach ($this->ors as $or) {
                $conn->or($or[0], ...$or[1]);
            }
        }

        if (!empty($this->offset)) {
            $conn->offset($this->offset);
        }

        if (!empty($this->limit)) {
            $conn->limit($this->limit);
        }

        if (!empty($this->fields)) {
            $conn->select($this->fields);
        }

        if (!empty($this->havings)) {
            foreach ($this->havings as $having) {
                $conn->having($having[0], ...$having[1]);
            }
        }

        if (!empty($this->orders)) {
            foreach ($this->orders as $order) {
                $conn->order($order[0], $order[1]);
            }
        }

        if (!empty($this->group)) {
            $conn->group(...$this->group);
        }

        if (!empty($this->joins)) {
            foreach ($this->joins as $join) {
                $conn->join($join[0], $join[1], ...$join[2]);
            }
        }

        if (!empty($this->leftJoins)) {
            foreach ($this->leftJoins as $join) {
                $conn->leftJoin($join[0], $join[1], ...$join[2]);
            }
        }

        if (!empty($this->debug)) {
            $conn->debug($this->debug);
        }

        $this->reset();
    }

    /**
     * 获取数据库连接
     *
     * @param int $connType 连接类型：WRITE(1) 或 READ(2)
     * @return ConnectionInterface
     */
    public function getConn(int $connType = 0)
    {
        $this->validateDatabaseAndTable();

        $table = $this->buildTableWithAlias();

        //  如果在事务中, 则使用事务对象
        $transaction = Database::getContext()->get(
            Database::RUN_CONTEXT_TX_KEY . $this->database->getObjectHash()
        );
        if ($transaction !== null) {
            $this->lastConnectionType = 'transaction';
            return $transaction->table($table);
        }

        // 2. 根据连接类型选择数据库
        $connection = $this->selectConnectionByType($connType);

        return $connection->table($table);
    }

//    public function setDatabase(Database|TransactionWrapper $db): self
//    {
//        if ($this->database != null) {
//            $transaction = Database::getContext()->get(
//                Database::RUN_CONTEXT_TX_KEY . $this->database->getObjectHash()
//            );
//            if ($transaction !== null) {
//                throw new \LogicException('Cannot change database connection while in transaction');
//            }
//        }
//        $this->database = $db;
//        return $this;
//    }
//
//    public function setReadDatabase(Database $db): self
//    {
//        $this->readDatabase = $db;
//        return $this;
//    }

    /**
     * @param Database|TransactionWrapper $db
     * @param Database|TransactionWrapper|null $readDatabase
     * @return static
     */
    public static function newInstance(Database|TransactionWrapper $db, Database|TransactionWrapper $readDatabase = null): static
    {
        $obj = new static();
        $obj->database = $db;
        $obj->readDatabase = $readDatabase;
        return $obj;
    }

    public function getTable()
    {
        return $this->table;
    }

    public function setTable(string $table): void
    {
        $this->table = $table;
    }

    public function alias(string $alias)
    {
        $this->alias = $alias;
        return $this;
    }

    /**
     * @param mixed $where
     * e.g.二维数组
     * [
     *    [$field1, '=', $value1],
     *    [$field2, '>', $value2],
     *    [$field3, $value3]
     * ]
     * e.g. where($field, $value)
     * e.g. where($field, $option, $value)
     * @return $this
     */
    public function where(...$where)
    {
        if (empty($where) || empty($where[0])) {
            return $this;
        }
        list($string, $values) = $this->buildWhere(...$where);
        $this->wheres[] = [$string, $values];
        return $this;
    }

    public function whereRaw(string $whereString, ...$values)
    {
        $this->wheres[] = [$whereString, $values];
        return $this;
    }

    public function orWhere(...$where)
    {
        if (empty($where)) {
            return $this;
        }
        list($string, $values) = $this->buildWhere(...$where);
        $this->ors[] = [$string, $values];
        return $this;
    }

    public function offset(int $offset)
    {
        $this->offset = $offset;
        return $this;
    }

    public function limit(int $limit)
    {
        $this->limit = $limit;
        return $this;
    }

    public function page(int $page, int $limit)
    {
        $this->offset = ($page - 1) * $limit;
        $this->limit = $limit;
        return $this;
    }

    public function buildField(string $fields)
    {
        $fields = str_replace('`', '', $fields);
        $fields = explode(',', $fields);
        foreach ($fields as &$field) {
            $fieldArr = explode('.', $field);
            foreach ($fieldArr as &$v) {
                $v = '`' . trim($v, '` ') . '`';
            }
            $field = implode('.', $fieldArr);
        }
        $fields = implode(',', $fields);

        return $fields;
    }

    public function select(string $fields)
    {
        // $fields = $this->buildField($fields);
        $this->fields = $fields;
        return $this;
    }

    public function having(...$where)
    {
        if (empty($where)) {
            return $this;
        }
        list($string, $values) = $this->buildWhere(...$where);
        $this->havings[] = [$string, $values];
        return $this;
    }

    public function havingRaw(string $expr, ...$values)
    {
        $this->havings[] = [$expr, $values];
        return $this;
    }

    public function group(string ...$fields)
    {
        $this->group = $fields;
        return $this;
    }

    public function order(string $field, string $order)
    {
        $this->orders[] = [$field, $order];
        return $this;
    }

    public function join(string $table, string $on, ...$values)
    {
        $this->joins[] = [$table, $on, $values];
        return $this;
    }

    public function leftJoin(string $table, string $on, ...$values)
    {
        $this->leftJoins[] = [$table, $on, $values];
        return $this;
    }


    /**
     * $return['time'] 执行时间, 毫秒
     * $return['sql'] sql
     * $return['bindings'] 绑定的数据
     * @return array
     */
    public function getLastQueryLog(): array
    {
        return $this->lastQueryLog;
    }

    public function getLastSql(): string
    {
        $log = $this->getLastQueryLog();
        if (empty($log)) {
            return '';
        }
        return Database::queryLogToSql($log);
    }

    /**
     * 获取最后使用的连接类型
     * - 'default': 主连接
     * - 'read': 读连接
     * - 'transaction': 事务连接
     */
    public function getLastConnectionType(): string
    {
        return $this->lastConnectionType;
    }


    /**
     * @return int 受影响行数
     */
    public function update(string $field, $value)
    {
        $data = [
            $field => $value
        ];
        return $this->updates($data);
    }

    /**
     * @return int 受影响行数
     */
    public function updates(array $data)
    {
        if (empty($this->wheres)) {
            throw new \Exception('update操作必须带条件');
        }
        if ($this->updateTimeField && !isset($data[$this->updateTimeField])) {
            $data[$this->updateTimeField] = $this->buildUpdateTime();
        }
        $conn = $this->getConn(self::WRITE);
        $this->buildQuery($conn);
        $ret = $conn->updates($data)->rowCount();
        $this->lastQueryLog = $conn->queryLog();
        return $ret;
    }

    public function insert(array $data, string $insert = 'INSERT INTO'): ConnectionInterface
    {
        $createTime = null;
        if ($this->createTimeField && !isset($data[$this->createTimeField])) {
            $createTime = $data[$this->createTimeField] = $this->buildCreateTime();
        }

        if ($this->updateTimeField && !isset($data[$this->updateTimeField])) {
            $data[$this->updateTimeField] = $this->buildUpdateTime($createTime);
        }
        $conn = $this->getConn(self::WRITE);
        $this->buildQuery($conn);
        $ret = $conn->insert($this->table, $data, $insert);
        $this->lastQueryLog = $conn->queryLog();
        return $ret;
    }

    /**
     *
     */
    public function insertGetId(array $data, string $insert = 'INSERT INTO'): string
    {
        $createTime = null;
        if ($this->createTimeField && !isset($data[$this->createTimeField])) {
            $createTime = $data[$this->createTimeField] = $this->buildCreateTime();
        }

        if ($this->updateTimeField && !isset($data[$this->updateTimeField])) {
            $data[$this->updateTimeField] = $this->buildUpdateTime($createTime);
        }
        $conn = $this->getConn(self::WRITE);
        $this->buildQuery($conn);
        $ret = $conn->insert($this->table, $data, $insert);
        $id = $ret->lastInsertId();
        $this->lastQueryLog = $conn->queryLog();
        return $id;
    }

    /**
     * @return int 受影响行数
     */
    public function batchInsert(array $list, string $insert = 'INSERT INTO'): int
    {
        foreach ($list as &$data) {
            $createTime = $this->buildCreateTime();
            if ($this->createTimeField && !isset($data[$this->createTimeField])) {
                $data[$this->createTimeField] = $createTime;
            }

            if ($this->updateTimeField && !isset($data[$this->updateTimeField])) {
                $data[$this->updateTimeField] = $this->buildUpdateTime($createTime);
            }
        }
        $conn = $this->getConn(self::WRITE);
        $this->buildQuery($conn);
        $ret = $conn->batchInsert($this->table, $list, $insert)->rowCount();
        $this->lastQueryLog = $conn->queryLog();
        return $ret;
    }

    /**
     * @return int
     */
    public function delete()
    {
        if (empty($this->wheres)) {
            throw new \Exception('delete操作必须带条件');
        }
        $conn = $this->getConn(self::WRITE);
        $this->buildQuery($conn);
        $ret = $conn->delete();
        $this->lastQueryLog = $conn->queryLog();
        return $ret->rowCount();
    }

    public function debug(\Closure $debug)
    {
        $this->debug = $debug;
        return $this;
    }

    /**
     * @return int
     */
    public function count()
    {
        $conn = $this->getConn(self::READ);
        unset($this->fields);
        $this->buildQuery($conn);
        $ret = $conn->select('count(*) as mix_count')->first();
        if (empty($ret)) {
            return 0;
        }
        $this->lastQueryLog = $conn->queryLog();
        $ret = (array)$ret;
        return (int)($ret['mix_count'] ?? 0);
    }

    /**
     * 获取一行数据并取对应字段的值
     * @param string $field
     * @return mixed|null
     */
    public function value(string $field)
    {
        $conn = $this->getConn(self::READ);
        unset($this->fields);
        $this->fields = $field;
        $this->buildQuery($conn);
        $result = $conn->first();
        if (empty($result)) {
            return null;
        }
        $this->lastQueryLog = $conn->queryLog();
        $isArray = is_array($result);
        if ($isArray) {
            return $result[$field] ?? null;
        }
        return $result->$field ?? null;
    }

    /**
     * 提取一维数组中对应字段的值
     * @param string $field
     * @return array
     */
    public function column(string $field)
    {
        $conn = $this->getConn(self::READ);
        unset($this->fields);
        $this->fields = $field;
        $this->buildQuery($conn);
        $result = (array)$conn->get();
        $this->lastQueryLog = $conn->queryLog();
        return array_column($result, $field);
    }

    /**
     * 返回多行
     * @return array
     */
    public function get()
    {
        $conn = $this->getConn(self::READ);
        $this->buildQuery($conn);
        $ret = $conn->get();
        $this->lastQueryLog = $conn->queryLog();
        return $ret;
    }

    /**
     * 返回一行, 数据为空返回false
     * @return array|object|false
     */
    public function first()
    {
        $conn = $this->getConn(self::READ);
        $this->buildQuery($conn);
        $ret = $conn->first();
        $this->lastQueryLog = $conn->queryLog();
        return $ret;
    }

    public function statement()
    {
        $conn = $this->getConn(self::READ);
        $this->buildQuery($conn);
        $ret = $conn->statement();
        $this->lastQueryLog = $conn->queryLog();
        return $ret;
    }


    // public function __call($name, $arguments = [])
    // {
    //     $conn = $this->getConn();
    //     $this->buildQuery($conn);
    //     $ret = call_user_func_array([$conn, $name], $arguments);
    //     $this->lastQueryLog = $conn->queryLog();
    //     return $ret;
    // }


}