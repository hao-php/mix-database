<?php

namespace Haoa\MixDatabase\Driver;

/**
 * 驱动工厂
 * 根据 DSN 前缀自动创建对应的数据库驱动
 */
class DriverFactory
{

    /**
     * DSN 前缀 → 驱动类映射
     * @var array
     */
    protected static $drivers = [
        'mysql' => MysqlDriver::class,
        'pgsql' => PgsqlDriver::class,
    ];

    /**
     * 根据 DSN 创建驱动实例
     * @param string $dsn
     * @return DriverInterface
     * @throws \InvalidArgumentException
     */
    public static function create(string $dsn): DriverInterface
    {
        $prefix = strstr($dsn, ':', true);
        if ($prefix === false || !isset(static::$drivers[$prefix])) {
            throw new \InvalidArgumentException(
                sprintf('Unsupported database DSN prefix: %s', $prefix ?: $dsn)
            );
        }
        $class = static::$drivers[$prefix];
        return new $class();
    }

    /**
     * 注册自定义驱动
     * @param string $prefix DSN 前缀
     * @param string $driverClass 驱动类名（需实现 DriverInterface）
     */
    public static function register(string $prefix, string $driverClass): void
    {
        static::$drivers[$prefix] = $driverClass;
    }

}
