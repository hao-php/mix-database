<?php

namespace Haoa\MixDatabase\Driver;

/**
 * 数据库驱动接口
 * 封装不同数据库的 SQL 方言差异
 */
interface DriverInterface
{

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

    /**
     * 获取标识符引号字符
     * MySQL 返回 ['`', '`']，PostgreSQL 返回 ['"', '"']
     * @return array [左引号, 右引号]
     */
    public function getQuoteChar(): array;

    /**
     * 引号表名（处理别名）
     * @param string $table 表名，可能包含别名如 "users AS u" 或 "users u"
     * @return string 引号后的表名
     */
    public function quoteTableName(string $table): string;

    /**
     * 引号列名（处理表名前缀）
     * @param string $column 列名，可能包含表名前缀如 "users.name"
     * @return string 引号后的列名
     */
    public function quoteColumnName(string $column): string;

    /**
     * 设置是否对标识符加引号
     * @param bool $enabled 是否启用引号
     */
    public function setQuoteIdentifiers(bool $enabled): void;

}
