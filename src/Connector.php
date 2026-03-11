<?php

namespace Haoa\MixDatabase;

use Haoa\MixDatabase\Driver\DriverFactory;
use Haoa\MixDatabase\Driver\DriverInterface;
use Haoa\ObjectPool\ObjectTrait;

/**
 * 连接器
 * 管理 PDO 连接生命周期 + 池回收
 */
class Connector
{

    use ObjectTrait;

    /**
     * @var string
     */
    protected $dsn = '';

    /**
     * @var string
     */
    protected $username = 'root';

    /**
     * @var string
     */
    protected $password = '';

    /**
     * @var array
     */
    protected $options = [];

    /**
     * @var \PDO
     */
    protected $pdo;

    /**
     * @var DriverInterface
     */
    protected $driver;

    /**
     * 默认连接选项
     * @var array
     */
    protected $defaultOptions = [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_OBJ,
        \PDO::ATTR_TIMEOUT => 5,
    ];

    /**
     * Connector constructor.
     * @param string $dsn
     * @param string $username
     * @param string $password
     * @param array $options
     * @throws \PDOException
     */
    public function __construct(string $dsn, string $username, string $password, array $options = [])
    {
        $this->dsn = $dsn;
        $this->username = $username;
        $this->password = $password;
        $this->options = $options;
        $this->driver = DriverFactory::create($dsn);
        $this->connect();
    }

    /**
     * 获取 PDO 实例
     * @return \PDO
     */
    public function instance(): \PDO
    {
        return $this->pdo;
    }

    /**
     * 获取数据库驱动
     * @return DriverInterface
     */
    public function driver(): DriverInterface
    {
        return $this->driver;
    }

    /**
     * 获取连接选项
     * @return array
     */
    public function options(): array
    {
        return $this->options + $this->defaultOptions;
    }

    /**
     * 建立连接
     * @throws \PDOException
     */
    public function connect()
    {
        $this->pdo = new \PDO(
            $this->dsn,
            $this->username,
            $this->password,
            $this->options()
        );
    }

    /**
     * 关闭连接
     */
    public function close()
    {
        $this->pdo = null;
    }

}
