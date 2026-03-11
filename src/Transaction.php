<?php

namespace Haoa\MixDatabase;

/**
 * Class Transaction
 * @package Haoa\MixDatabase
 */
class Transaction extends Connection
{

    /**
     * Transaction constructor.
     * @param Connector $connector
     * @param LoggerInterface|null $logger
     */
    public function __construct(Connector $connector, ?LoggerInterface $logger)
    {
        parent::__construct($connector, $logger);
        if (!$this->connector->instance()->beginTransaction()) {
            throw new \PDOException('Begin transaction failed');
        }
    }

    /**
     * 提交事务
     * @throws \PDOException
     */
    public function commit()
    {
        if ($this->connector instanceof EmptyConnector) {
            return;
        }
        if (!$this->connector->instance()->commit()) {
            throw new \PDOException('Commit transaction failed');
        }
        $this->__destruct();
    }

    /**
     * 回滚事务
     * @throws \PDOException
     */
    public function rollback()
    {
        if ($this->connector instanceof EmptyConnector) {
            return;
        }
        if (!$this->connector->instance()->rollBack()) {
            throw new \PDOException('Rollback transaction failed');
        }
        $this->__destruct();
    }

}
