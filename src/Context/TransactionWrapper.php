<?php

namespace Haoa\MixDatabase\Context;

use Haoa\MixDatabase\AbstractConnection;
use Haoa\MixDatabase\ConnectionInterface;
use Haoa\MixDatabase\Transaction;

/**
 * @implements AbstractConnection
 * @method ConnectionInterface debug(\Closure $func)
 * @method ConnectionInterface raw(string $sql, ...$values)
 * @method ConnectionInterface exec(string $sql, ...$values)
 * @method ConnectionInterface table(string $table)
 * @method ConnectionInterface select(string ...$fields)
 * @method ConnectionInterface join(string $table, string $on, ...$values)
 * @method ConnectionInterface leftJoin(string $table, string $on, ...$values)
 * @method ConnectionInterface rightJoin(string $table, string $on, ...$values)
 * @method ConnectionInterface fullJoin(string $table, string $on, ...$values)
 * @method ConnectionInterface where(string $expr, ...$values)
 * @method ConnectionInterface or (string $expr, ...$values)
 * @method ConnectionInterface order(string $field, string $order)
 * @method ConnectionInterface group(string ...$fields)
 * @method ConnectionInterface having(string $expr, ...$values)
 * @method ConnectionInterface offset(int $length)
 * @method ConnectionInterface limit(int $length)
 * @method ConnectionInterface lockForUpdate()
 * @method ConnectionInterface sharedLock()
 * @method array get()
 * @method first()
 * @method value(string $field)
 * @method ConnectionInterface updates(array $data)
 * @method ConnectionInterface update(string $field, $value)
 * @method ConnectionInterface delete()
 * @method \PDOStatement statement()
 * @method string lastInsertId()
 * @method int rowCount()
 * @method array queryLog()
 * @method string queryLogSql()
 * @method ConnectionInterface batchInsert(string $table, array $data, string $insert = 'INSERT INTO')
 */
class TransactionWrapper
{

    protected Transaction $tx;

    protected Database $db;

    /**
     * @var int 嵌套等级
     */
    private int $nestingLevel = 0;

    public array $commitCallbacks = [];

    public function __construct(Transaction $tx, Database $db)
    {
        $this->tx = $tx;
        $this->db = $db;
    }

    /**
     * 提交事务
     * @throws \PDOException
     */
    public function commit()
    {
        $this->nestingLevel--;
        if ($this->nestingLevel > 0) {
            return;
        }
        $this->tx->commit();
        $this->db->delContextTx();
        if (!empty($this->commitCallbacks)) {
            foreach ($this->commitCallbacks as $callback) {
                $callback();
            }
        }
    }

    /**
     * 回滚事务
     * @throws \PDOException
     */
    public function rollback()
    {
        $this->nestingLevel--;
        if ($this->nestingLevel > 0) {
            return;
        }
        $this->tx->rollback();
        $this->db->delContextTx();
    }

    public function __call($name, $arguments = [])
    {
        return call_user_func_array([$this->tx, $name], $arguments);
    }

    public function incrementNestingLevel()
    {
        $this->nestingLevel++;
    }

    /**
     * 添加事务提交后执行的回调函数, 将在commit后执行
     * @param callable $callback
     * @return void
     */
    public function addCommitCallback(callable $callback)
    {
        $this->commitCallbacks[] = $callback;
    }

}