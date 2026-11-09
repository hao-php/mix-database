<?php

namespace Haoa\MixDatabase\Driver;

/**
 * MySQL 驱动
 */
class MysqlDriver implements DriverInterface
{

    /**
     * 是否对标识符加引号
     * @var bool
     */
    protected $quoteIdentifiers = false;

    /**
     * 设置是否对标识符加引号
     * @param bool $enabled
     */
    public function setQuoteIdentifiers(bool $enabled): void
    {
        $this->quoteIdentifiers = $enabled;
    }

    /**
     * MySQL LIMIT 语法: LIMIT offset, count
     * @param int $offset
     * @param int $limit
     * @return array [sql_fragment, values_array]
     */
    public function buildLimit(int $offset, int $limit): array
    {
        return ['LIMIT ?, ?', [$offset, $limit]];
    }

    /**
     * @param string $baseTable
     * @param string $table
     * @return string
     */
    public function buildCreateTableLikeSql(string $baseTable, string $table): string
    {
        $quotedBaseTable = $this->quoteIdentifier($baseTable);
        $quotedTable = $this->quoteIdentifier($table);
        return "CREATE TABLE IF NOT EXISTS {$quotedTable} LIKE {$quotedBaseTable}";
    }

    /**
     * @param string $table
     * @return array
     */
    public function buildTableExistsSql(string $table): array
    {
        $sql = 'SELECT EXISTS('
            . 'SELECT 1 FROM information_schema.tables '
            . 'WHERE table_schema = DATABASE() AND table_name = ?'
            . ') AS table_exists';
        return [$sql, [$table]];
    }

    /** 构建当前数据库或 schema 中账号可见的实体表名查询。 */
    public function buildListTablesSql(?string $like = null): array
    {
        $sql = "SELECT table_name FROM information_schema.tables "
            . "WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'";
        $values = [];
        if ($like !== null) {
            // 显式指定反斜线转义，统一两种数据库的 LIKE 模式语义。
            $sql .= ' AND table_name LIKE ? ESCAPE ?';
            $values = [$like, '\\'];
        }
        return [$sql . ' ORDER BY table_name', $values];
    }

    /** 构建删除表的 SQL，表名始终作为标识符引用。 */
    public function buildDropTableSql(string $table, bool $ifExists = false): string
    {
        return 'DROP TABLE ' . ($ifExists ? 'IF EXISTS ' : '') . $this->quoteIdentifier($table);
    }

    /**
     * @return string
     */
    public function sharedLockSql(): string
    {
        return 'LOCK IN SHARE MODE';
    }

    /**
     * @return string
     */
    public function forUpdateSql(): string
    {
        return 'FOR UPDATE';
    }

    /**
     * @return array
     */
    public function disconnectMessages(): array
    {
        return [
            'server has gone away',
            'no connection to the server',
            'Lost connection',
            'is dead or not enabled',
            'Error while sending',
            'decryption failed or bad record mac',
            'server closed the connection unexpectedly',
            'SSL connection has been closed unexpectedly',
            'Error writing data to the connection',
            'Resource deadlock avoided',
            'failed with errno',
        ];
    }

    /**
     * 获取标识符引号字符
     * @return array
     */
    public function getQuoteChar(): array
    {
        return ['`', '`'];
    }

    /**
     * 引号表名（处理别名）
     * @param string $table
     * @return string
     */
    public function quoteTableName(string $table): string
    {
        return \Haoa\MixDatabase\Sql::table($table, '`', $this->quoteIdentifiers);
    }

    /**
     * 校验并引用普通字段，不接受 SQL 表达式。
     * @param string $column 字段路径
     */
    public function quoteFieldName(string $column): string
    {
        return \Haoa\MixDatabase\Sql::identifier($column, '`', $this->quoteIdentifiers);
    }

    /**
     * 引号列名（处理表名前缀）
     * @param string $column
     * @return string
     */
    public function quoteColumnName(string $column): string
    {
        // 未启用引号功能时直接返回
        if (!$this->quoteIdentifiers) {
            return $column;
        }

        // 快速返回：已引号的列名直接返回，避免后续检查
        if ($this->isQuoted($column)) {
            return $column;
        }

        // 处理 "*" 特殊情况
        if ($column === '*') {
            return $column;
        }

        // 如果包含特殊字符（逗号、括号、空格），则为复杂表达式，不处理
        if (strpbrk($column, ',() ') !== false) {
            return $column;
        }

        // 检查是否包含 AS 关键字（大小写不敏感）
        $upperColumn = strtoupper($column);
        if (strpos($upperColumn, ' AS ') !== false || strpos($upperColumn, ' AS(') !== false) {
            return $column;
        }

        // 处理 "table.column" 或 "table.*" 格式
        if (strpos($column, '.') !== false) {
            $parts = explode('.', $column);
            $quotedParts = [];
            foreach ($parts as $part) {
                if ($part === '*') {
                    $quotedParts[] = $part;
                } else {
                    // quoteIdentifier 内部会检查 isQuoted（处理 split 后可能已引号的情况）
                    $quotedParts[] = $this->quoteIdentifier($part);
                }
            }
            return implode('.', $quotedParts);
        }

        // 简单列名
        return $this->quoteIdentifier($column);
    }

    /**
     * 检查标识符是否已被引号（只要包含引号就认为已处理）
     * @param string $identifier
     * @return bool
     */
    protected function isQuoted(string $identifier): bool
    {
        return strpos($identifier, '`') !== false;
    }

    /**
     * 引号单个标识符
     * @param string $identifier
     * @return string
     */
    protected function quoteIdentifier(string $identifier): string
    {
        return \Haoa\MixDatabase\Sql::identifier($identifier, '`', true);
    }

    /**
     * 构建 REPLACE INTO 语句（MySQL 特有）
     * @param string $table
     * @param array $data
     * @return array ['sql' => ..., 'values' => ...]
     */
    public function buildReplaceInsert(string $table, array $data): array
    {
        $quotedTable = $this->quoteTableName($table);
        $keys = array_keys($data);
        $quotedKeys = array_map([$this, 'quoteFieldName'], $keys);
        $fields = array_map(function ($key) {
            return '?';
        }, $keys);
        $sql = "REPLACE INTO {$quotedTable} (" . implode(', ', $quotedKeys) . ") VALUES (" . implode(', ', $fields) . ")";
        return ['sql' => $sql, 'values' => array_values($data)];
    }

    /**
     * 构建 INSERT ... ON DUPLICATE KEY UPDATE 语句（MySQL 特有）
     * @param string $table
     * @param array $data
     * @param array $updateColumns 冲突时更新的列名列表
     * @return array ['sql' => ..., 'values' => ...]
     */
    public function buildInsertOnDuplicateKey(string $table, array $data, array $updateColumns): array
    {
        $quotedTable = $this->quoteTableName($table);
        $keys = array_keys($data);
        $quotedKeys = array_map([$this, 'quoteFieldName'], $keys);
        $placeholders = implode(', ', array_fill(0, count($keys), '?'));
        $values = array_values($data);

        $updateParts = [];
        foreach ($updateColumns as $col) {
            $quotedCol = $this->quoteFieldName($col);
            $updateParts[] = "{$quotedCol} = VALUES({$quotedCol})";
        }

        $sql = "INSERT INTO {$quotedTable} (" . implode(', ', $quotedKeys) . ") VALUES ({$placeholders}) ON DUPLICATE KEY UPDATE " . implode(', ', $updateParts);
        return ['sql' => $sql, 'values' => $values];
    }

}
