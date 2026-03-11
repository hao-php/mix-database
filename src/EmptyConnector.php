<?php

namespace Haoa\MixDatabase;

use Haoa\MixDatabase\Driver\DriverInterface;

/**
 * 空连接器
 * 连接归还到池后的占位符，防止在已归还的连接上继续操作
 */
class EmptyConnector
{

    protected $errorMessage = 'The connection has been returned to the pool, the current operation cannot be performed';

    public function __construct()
    {
    }

    public function instance(): \PDO
    {
        throw new \RuntimeException($this->errorMessage);
    }

    public function driver(): DriverInterface
    {
        throw new \RuntimeException($this->errorMessage);
    }

    public function options(): array
    {
        throw new \RuntimeException($this->errorMessage);
    }

    public function connect()
    {
        throw new \RuntimeException($this->errorMessage);
    }

    public function close()
    {
        throw new \RuntimeException($this->errorMessage);
    }

}
