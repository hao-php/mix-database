<?php

namespace Haoa\MixDatabase\Context;

use Haoa\MixDatabase\ConnectionInterface;
use Haoa\MixDatabase\Database as BaseDatabase;
use Haoa\MixDatabase\LoggerInterface;
use Haoa\Util\Context\RunContext;

/**
 * @method startPool(int $maxOpen, int $maxIdle, int $maxLifetime = 0, float $waitTimeout = 0.0)
 * @method setMaxOpenConns(int $maxOpen)
 * @method setMaxIdleConns(int $maxIdle)
 * @method setConnMaxLifetime(int $maxLifetime)
 * @method setPoolWaitTimeout(float $waitTimeout)
 * @method array poolStats()
 * @method setLogger(LoggerInterface $logger)
 * @method ConnectionInterface debug(\Closure $func)
 */
class Database
{

    private BaseDatabase $db;

    public const RUN_CONTEXT_TX_KEY =  __CLASS__ . ':obj_transaction:';

    public function __construct(string $dsn, string $username, string $password, array $options = [])
    {
        $this->db = new MixDb($dsn, $username, $password, $options);
    }

    /**
     * @return TransactionWrapper
     */
    public function beginTransaction(): TransactionWrapper
    {
        $ctx = self::getContext();
        /** @var TransactionWrapper $obj */
        $obj = $ctx->get(self::RUN_CONTEXT_TX_KEY . $this->getObjectHash());
        if (empty($obj)) {
            $tx = $this->db->beginTransaction();
            $obj = new TransactionWrapper($tx, $this);
            $obj->incrementNestingLevel();
            $ctx->set(self::RUN_CONTEXT_TX_KEY . $this->getObjectHash(), $obj);
        } else {
            $obj->incrementNestingLevel();
        }
        return $obj;
    }

    public static function getContext(): \Haoa\Util\Context\BaseContext
    {
        return RunContext::getHandler();
    }

    public function delContextTx()
    {
        self::getContext()->delete(self::RUN_CONTEXT_TX_KEY . $this->getObjectHash());
    }

    /**
     * @return TransactionWrapper|null
     */
    public function getContextTx()
    {
        return self::getContext()->get(self::RUN_CONTEXT_TX_KEY . $this->getObjectHash());
    }


    public static function queryLogToSql($log)
    {
        $sql = $log['sql'];
        if (!empty($log['bindings'])) {
            reset($log['bindings']);
            $firstKey = key($log['bindings']);
            if (is_string($firstKey)) {
                foreach ($log['bindings'] as $key => $v) {
                    $sql = str_replace(':' . $key, '"' . $v . '"', $sql);
                }
            } else {
                foreach ($log['bindings'] as $key => $v) {
                    if (is_array($v)) {
                        foreach ($v as &$vv) {
                            $vv = addslashes($vv);
                        }
                        $v = implode('","', $v);
                    } else {
                        $v = addslashes($v);
                    }
                    $log['bindings'][$key] = '"' . $v . '"';
                }
                $sql = str_replace('?', '%s', $sql);
                $sql = sprintf($sql, ...$log['bindings']);
            }
        }
        return $sql;
    }

    public function getObjectHash()
    {
        return spl_object_hash($this);
    }

    /**
     * @return BaseDatabase|TransactionWrapper
     */
    private function getHandler()
    {
        // 如果在事务中, 则使用事务对象
        $obj = $this->getContextTx();
        if (!empty($obj)) {
            return $obj;
        }

        // 返回普通的db对象
        return $this->db;
    }

    /**
     * @return ConnectionInterface
     */
    public function raw(string $sql, array $values): ConnectionInterface
    {
        return $this->getHandler()->raw($sql, ...$values);
    }

    /**
     * @return ConnectionInterface
     */
    public function exec(string $sql, array $values): ConnectionInterface
    {
        return $this->getHandler()->exec($sql, ...$values);
    }

    /**
     * 插入
     * @param string $table
     * @param array $data
     * @param bool $useTran
     * @param string $insert
     * @return ConnectionInterface
     */
    public function insert(string $table, array $data, string $insert = 'INSERT INTO'): ConnectionInterface
    {
        return $this->getHandler()->insert($table, $data, $insert);
    }

    /**
     * 批量插入
     * @param string $table
     * @param array $data
     * @param string $insert
     * @return ConnectionInterface
     */
    public function batchInsert(string $table, array $data, string $insert = 'INSERT INTO'): ConnectionInterface
    {
        return $this->getHandler()->batchInsert($table, $data, $insert);
    }


    /**
     * 启动查询生成器
     * @param string $table
     * @return ConnectionInterface
     */
    public function table(string $table): ConnectionInterface
    {
        return $this->getHandler()->table($table);
    }

    public function __call($name, $arguments = [])
    {
        return call_user_func_array([$this->db, $name], $arguments);
    }

}