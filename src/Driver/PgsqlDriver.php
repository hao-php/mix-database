<?php

namespace Haoa\MixDatabase\Driver;

/**
 * PostgreSQL 驱动
 */
class PgsqlDriver implements DriverInterface
{

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
        // 检查是否已有引号
        if ($this->isQuoted($table)) {
            return $table;
        }

        // 处理别名：支持 "table AS alias" 和 "table alias" 两种格式
        // 使用字符串函数替代正则，性能更好
        $upperTable = strtoupper($table);
        $asPos = strpos($upperTable, ' AS ');
        if ($asPos !== false) {
            // "table AS alias" 格式
            $tableName = trim(substr($table, 0, $asPos));
            $alias = trim(substr($table, $asPos + 4));
            return $this->quoteIdentifier($tableName) . ' AS ' . $this->quoteIdentifier($alias);
        }

        // 检查是否有空格（"table alias" 格式）
        $spacePos = strpos($table, ' ');
        if ($spacePos !== false) {
            $tableName = trim(substr($table, 0, $spacePos));
            $alias = trim(substr($table, $spacePos + 1));
            if ($tableName !== '' && $alias !== '') {
                return $this->quoteIdentifier($tableName) . ' ' . $this->quoteIdentifier($alias);
            }
        }

        // 无别名，直接引号
        return $this->quoteIdentifier($table);
    }

    /**
     * 引号列名（处理表名前缀）
     * @param string $column
     * @return string
     */
    public function quoteColumnName(string $column): string
    {
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
        // 如果已被引号，直接返回
        if ($this->isQuoted($identifier)) {
            return $identifier;
        }
        // 转义标识符中的双引号
        $identifier = str_replace('"', '""', $identifier);
        return "\"{$identifier}\"";
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
        $quotedKeys = array_map([$this, 'quoteColumnName'], $keys);
        $placeholders = implode(', ', array_fill(0, count($keys), '?'));
        $values = array_values($data);

        if (is_string($conflictTarget)) {
            $conflictTarget = [$conflictTarget];
        }
        $quotedConflict = array_map([$this, 'quoteColumnName'], $conflictTarget);
        $conflictList = implode(', ', $quotedConflict);

        if (empty($updateColumns)) {
            $sql = "INSERT INTO {$quotedTable} (" . implode(', ', $quotedKeys) . ") VALUES ({$placeholders}) ON CONFLICT ({$conflictList}) DO NOTHING";
        } else {
            $updateParts = [];
            foreach ($updateColumns as $col) {
                $quotedCol = $this->quoteColumnName($col);
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
     * @return array ['sql' => ..., 'params' => ...]
     */
    public function buildInsertReturning(string $table, array $data, string $returning = '*'): array
    {
        $quotedTable = $this->quoteTableName($table);
        $keys = array_keys($data);
        $quotedKeys = array_map([$this, 'quoteColumnName'], $keys);
        $fields = array_map(function ($key) {
            return ":{$key}";
        }, $keys);

        // 处理 RETURNING 子句
        if ($returning !== '*') {
            $returning = $this->quoteColumnName($returning);
        }

        $sql = "INSERT INTO {$quotedTable} (" . implode(', ', $quotedKeys) . ") VALUES (" . implode(', ', $fields) . ") RETURNING {$returning}";
        return ['sql' => $sql, 'params' => $data];
    }

}
