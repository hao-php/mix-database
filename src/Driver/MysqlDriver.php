<?php

namespace Haoa\MixDatabase\Driver;

/**
 * MySQL 驱动
 */
class MysqlDriver implements DriverInterface
{

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
        return strpos($identifier, '`') !== false;
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
        // 转义标识符中的反引号
        $identifier = str_replace('`', '``', $identifier);
        return "`{$identifier}`";
    }

    /**
     * 构建 REPLACE INTO 语句（MySQL 特有）
     * @param string $table
     * @param array $data
     * @return array ['sql' => ..., 'params' => ...]
     */
    public function buildReplaceInsert(string $table, array $data): array
    {
        $quotedTable = $this->quoteTableName($table);
        $keys = array_keys($data);
        $quotedKeys = array_map([$this, 'quoteColumnName'], $keys);
        $fields = array_map(function ($key) {
            return ":{$key}";
        }, $keys);
        $sql = "REPLACE INTO {$quotedTable} (" . implode(', ', $quotedKeys) . ") VALUES (" . implode(', ', $fields) . ")";
        return ['sql' => $sql, 'params' => $data];
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
        $quotedKeys = array_map([$this, 'quoteColumnName'], $keys);
        $placeholders = implode(', ', array_fill(0, count($keys), '?'));
        $values = array_values($data);

        $updateParts = [];
        foreach ($updateColumns as $col) {
            $quotedCol = $this->quoteColumnName($col);
            $updateParts[] = "{$quotedCol} = VALUES({$quotedCol})";
        }

        $sql = "INSERT INTO {$quotedTable} (" . implode(', ', $quotedKeys) . ") VALUES ({$placeholders}) ON DUPLICATE KEY UPDATE " . implode(', ', $updateParts);
        return ['sql' => $sql, 'values' => $values];
    }

}
