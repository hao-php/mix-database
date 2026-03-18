<?php

namespace Haoa\MixDatabase\Pool;

use Haoa\MixDatabase\Connector;
use Haoa\ObjectPool\DialerInterface;

/**
 * Class Dialer
 * @package Haoa\MixDatabase\Pool
 */
class Dialer implements DialerInterface
{

    /**
     * 数据源格式
     * @var string
     */
    protected $dsn = '';

    /**
     * 数据库用户名
     * @var string
     */
    protected $username = 'root';

    /**
     * 数据库密码
     * @var string
     */
    protected $password = '';

    /**
     * 驱动连接选项
     * @var array
     */
    protected $options = [];

    /**
     * 是否对标识符加引号
     * @var bool
     */
    protected $quoteIdentifiers = false;

    /**
     * Dialer constructor.
     * @param string $dsn
     * @param string $username
     * @param string $password
     * @param array $options
     * @param bool $quoteIdentifiers
     */
    public function __construct(string $dsn, string $username, string $password, array $options = [], bool $quoteIdentifiers = false)
    {
        $this->dsn = $dsn;
        $this->username = $username;
        $this->password = $password;
        $this->options = $options;
        $this->quoteIdentifiers = $quoteIdentifiers;
    }

    /**
     * Dial
     * @return Connector
     */
    public function dial(): object
    {
        return new Connector(
            $this->dsn,
            $this->username,
            $this->password,
            $this->options,
            $this->quoteIdentifiers
        );
    }

}
