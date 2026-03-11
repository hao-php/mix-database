<?php

namespace Haoa\MixDatabase;

/**
 * Class Connection
 * @package Haoa\MixDatabase
 */
class Connection extends AbstractConnection
{

    protected $exceptional = false;

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
            if ($this->isDisconnectException($ex) && !$this->inTransaction()) {
                $this->reconnect();
                $this->executed = false;
                return $this->call($name, $arguments);
            } else {
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
