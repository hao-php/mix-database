<?php

namespace Haoa\MixDatabase\Driver;

/**
 * PostgreSQL 驱动
 */
class PgsqlDriver implements DriverInterface
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
     * PostgreSQL LIMIT 语法: LIMIT count OFFSET offset
     * @param int $offset
     * @param int $limit
     * @return array [sql_fragment, values_array]
     */
    public function buildLimit(int $offset, int $limit): array
    {
        return ['LIMIT ? OFFSET ?', [$limit, $offset]];
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
        return "CREATE TABLE IF NOT EXISTS {$quotedTable} (LIKE {$quotedBaseTable} INCLUDING ALL)";
    }

    /**
     * @param string $table
     * @return array
     */
    public function buildTableExistsSql(string $table): array
    {
        return [
            'SELECT CASE WHEN to_regclass(?) IS NULL THEN 0 ELSE 1 END AS table_exists',
            [$table],
        ];
    }

    /** 构建当前数据库或 schema 中账号可见的实体表名查询。 */
    public function buildListTablesSql(?string $like = null): array
    {
        $sql = "SELECT table_name FROM information_schema.tables "
            . "WHERE table_schema = current_schema() AND table_type = 'BASE TABLE'";
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
        return 'FOR SHARE';
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
            'server closed the connection unexpectedly',
            'SSL connection has been closed unexpectedly',
            'Error writing data to the connection',
            'connection is closed',
            'connection has been closed unexpectedly',
            'no connection to the server',
            'Resource deadlock avoided',
            'SSL error',
            'terminating connection due to administrator command',
        ];
    }

    /**
     * 获取标识符引号字符
     * @return array
     */
    public function getQuoteChar(): array
    {
        return ['"', '"'];
    }

    /**
     * 引号表名（处理别名）
     * @param string $table
     * @return string
     */
    public function quoteTableName(string $table): string
    {
        return \Haoa\MixDatabase\Sql::table($table, '"', $this->quoteIdentifiers);
    }

    /**
     * 校验并引用普通字段，不接受 SQL 表达式。
     * @param string $column 字段路径
     */
    public function quoteFieldName(string $column): string
    {
        return \Haoa\MixDatabase\Sql::identifier($column, '"', $this->quoteIdentifiers);
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
        return strpos($identifier, '"') !== false;
    }

    /**
     * 引号单个标识符
     * @param string $identifier
     * @return string
     */
    protected function quoteIdentifier(string $identifier): string
    {
        return \Haoa\MixDatabase\Sql::identifier($identifier, '"', true);
    }

    /**
     * 构建 INSERT ... ON CONFLICT 语句（PostgreSQL 特有）
     * @param string $table
     * @param array $data
     * @param string|array $conflictTarget 冲突列名或列名数组
     * @param array $updateColumns 冲突时更新的列名列表，为空则 DO NOTHING
     * @return array ['sql' => ..., 'values' => ...]
     */
    public function buildInsertOnConflict(string $table, array $data, $conflictTarget, array $updateColumns = []): array
    {
        $quotedTable = $this->quoteTableName($table);
        $keys = array_keys($data);
        $quotedKeys = array_map([$this, 'quoteFieldName'], $keys);
        $placeholders = implode(', ', array_fill(0, count($keys), '?'));
        $values = array_values($data);

        if (is_string($conflictTarget)) {
            $conflictTarget = [$conflictTarget];
        }
        $quotedConflict = array_map([$this, 'quoteFieldName'], $conflictTarget);
        $conflictList = implode(', ', $quotedConflict);

        if (empty($updateColumns)) {
            $sql = "INSERT INTO {$quotedTable} (" . implode(', ', $quotedKeys) . ") VALUES ({$placeholders}) ON CONFLICT ({$conflictList}) DO NOTHING";
        } else {
            $updateParts = [];
            foreach ($updateColumns as $col) {
                $quotedCol = $this->quoteFieldName($col);
                $updateParts[] = "{$quotedCol} = EXCLUDED.{$quotedCol}";
            }
            $sql = "INSERT INTO {$quotedTable} (" . implode(', ', $quotedKeys) . ") VALUES ({$placeholders}) ON CONFLICT ({$conflictList}) DO UPDATE SET " . implode(', ', $updateParts);
        }

        return ['sql' => $sql, 'values' => $values];
    }

    /**
     * 构建 INSERT ... RETURNING 语句（PostgreSQL 特有）
     * @param string $table
     * @param array $data
     * @param string $returning RETURNING 子句内容，如 '*' 或 'id'
     * @return array ['sql' => ..., 'values' => ...]
     */
    public function buildInsertReturning(string $table, array $data, string $returning = '*'): array
    {
        $quotedTable = $this->quoteTableName($table);
        $keys = array_keys($data);
        $quotedKeys = array_map([$this, 'quoteFieldName'], $keys);
        $fields = array_map(function ($key) {
            return '?';
        }, $keys);

        // 处理 RETURNING 子句
        if ($returning !== '*') {
            $returning = implode(', ', array_map([$this, 'quoteFieldName'], array_map('trim', explode(',', $returning))));
        }

        $sql = "INSERT INTO {$quotedTable} (" . implode(', ', $quotedKeys) . ") VALUES (" . implode(', ', $fields) . ") RETURNING {$returning}";
        return ['sql' => $sql, 'values' => array_values($data)];
    }

}
