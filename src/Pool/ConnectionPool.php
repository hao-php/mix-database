<?php

namespace Haoa\MixDatabase\Pool;

use Haoa\MixDatabase\Connector;
use Haoa\ObjectPool\AbstractObjectPool;

/**
 * Class ConnectionPool
 * @package Haoa\MixDatabase\Pool
 */
class ConnectionPool extends AbstractObjectPool
{

    /**
     * 借用连接
     * @return Connector
     */
    public function borrow(): object
    {
        return parent::borrow();
    }

}
