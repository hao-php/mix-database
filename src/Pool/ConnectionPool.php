<?php

namespace Haoa\MixDatabase\Pool;

use Haoa\MixDatabase\Driver;
use Mix\ObjectPool\AbstractObjectPool;

/**
 * Class ConnectionPool
 * @package Haoa\MixDatabase\Pool
 * @author liu,jian <coder.keda@gmail.com>
 */
class ConnectionPool extends AbstractObjectPool
{

    /**
     * 借用连接
     * @return Driver
     */
    public function borrow(): object
    {
        return parent::borrow();
    }

}
