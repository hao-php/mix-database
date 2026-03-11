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
     * 构建 INSERT ... ON CONFLICT 语句（PostgreSQL 特有）
     * @param string $table
     * @param array $data
     * @param string|array $conflictTarget 冲突列名或列名数组
     * @param array $updateColumns 冲突时更新的列名列表，为空则 DO NOTHING
     * @return array ['sql' => ..., 'values' => ...]
     */
    public function buildInsertOnConflict(string $table, array $data, $conflictTarget, array $updateColumns = []): array
    {
        $keys = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($keys), '?'));
        $values = array_values($data);

        if (is_string($conflictTarget)) {
            $conflictTarget = [$conflictTarget];
        }
        $conflictList = implode(', ', $conflictTarget);

        if (empty($updateColumns)) {
            $sql = "INSERT INTO {$table} (" . implode(', ', $keys) . ") VALUES ({$placeholders}) ON CONFLICT ({$conflictList}) DO NOTHING";
        } else {
            $updateParts = [];
            foreach ($updateColumns as $col) {
                $updateParts[] = "{$col} = EXCLUDED.{$col}";
            }
            $sql = "INSERT INTO {$table} (" . implode(', ', $keys) . ") VALUES ({$placeholders}) ON CONFLICT ({$conflictList}) DO UPDATE SET " . implode(', ', $updateParts);
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
        $keys = array_keys($data);
        $fields = array_map(function ($key) {
            return ":{$key}";
        }, $keys);
        $sql = "INSERT INTO {$table} (" . implode(', ', $keys) . ") VALUES (" . implode(', ', $fields) . ") RETURNING {$returning}";
        return ['sql' => $sql, 'params' => $data];
    }

}
