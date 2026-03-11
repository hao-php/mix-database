<?php

namespace Haoa\MixDatabase;

/**
 * Class Connection
 * @package Haoa\MixDatabase
 */
class Connection extends AbstractConnection
{

    protected $exceptional = false;

    /**
     * 重连重试次数
     * @var int
     */
    protected $retryCount = 0;

    /**
     * 最大重连重试次数
     * @var int
     */
    protected $maxRetries = 2;

    public function queryOne(int $fetchStyle = null)
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function queryAll(int $fetchStyle = null): array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function queryColumn(int $columnNumber = 0): array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function queryScalar()
    {
        return $this->call(__FUNCTION__);
    }

    public function execute(): ConnectionInterface
    {
        return $this->call(__FUNCTION__);
    }

    public function beginTransaction(): Transaction
    {
        return $this->call(__FUNCTION__);
    }

    public function rowCount(): int
    {
        return $this->call(__FUNCTION__);
    }

    protected function call($name, $arguments = [])
    {
        try {
            return call_user_func_array(parent::class . "::{$name}", $arguments);
        } catch (\Throwable $ex) {
            if ($this->retryCount < $this->maxRetries && $this->isDisconnectException($ex) && !$this->inTransaction()) {
                $this->retryCount++;
                $this->reconnect();
                // 重连后允许再次执行
                $this->executed = false;
                return $this->call($name, $arguments);
            } else {
                // 不可在这里处理丢弃连接，会影响用户 try/catch 事务处理业务逻辑
                // 会导致 commit rollback 时为 EmptyDriver
                $this->exceptional = true;
                throw $ex;
            }
        }
    }

    public function __destruct()
    {
        $this->executed = true;

        if (!$this->connector || $this->connector instanceof EmptyConnector) {
            return;
        }
        if ($this->exceptional || $this->inTransaction()) {
            $this->connector->__discard();
            $this->connector = new EmptyConnector();
            return;
        }
        $this->connector->__return();
        $this->connector = new EmptyConnector();
    }

}
