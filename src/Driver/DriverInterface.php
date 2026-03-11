<?php

namespace Haoa\MixDatabase\Driver;

/**
 * 数据库驱动接口
 * 封装不同数据库的 SQL 方言差异
 */
interface DriverInterface
{

    /**
     * 标识符引用
     * MySQL: `name`  PgSQL: "name"
     * @param string $identifier
     * @return string
     */
    public function quoteIdentifier(string $identifier): string;

    /**
     * 构建 LIMIT 子句
     * @param int $offset
     * @param int $limit
     * @return array [sql_fragment, values_array]
     */
    public function buildLimit(int $offset, int $limit): array;

    /**
     * 共享锁语法
     * MySQL: LOCK IN SHARE MODE  PgSQL: FOR SHARE
     * @return string
     */
    public function sharedLockSql(): string;

    /**
     * 排它锁语法
     * @return string
     */
    public function forUpdateSql(): string;

    /**
     * 断连异常消息关键词列表
     * @return array
     */
    public function disconnectMessages(): array;

}
