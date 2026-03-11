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
     * 构建 REPLACE INTO 语句（MySQL 特有）
     * @param string $table
     * @param array $data
     * @return array ['sql' => ..., 'params' => ...]
     */
    public function buildReplaceInsert(string $table, array $data): array
    {
        $keys = array_keys($data);
        $fields = array_map(function ($key) {
            return ":{$key}";
        }, $keys);
        $sql = "REPLACE INTO {$table} (" . implode(', ', $keys) . ") VALUES (" . implode(', ', $fields) . ")";
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
        $keys = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($keys), '?'));
        $values = array_values($data);

        $updateParts = [];
        foreach ($updateColumns as $col) {
            $updateParts[] = "{$col} = VALUES({$col})";
        }

        $sql = "INSERT INTO {$table} (" . implode(', ', $keys) . ") VALUES ({$placeholders}) ON DUPLICATE KEY UPDATE " . implode(', ', $updateParts);
        return ['sql' => $sql, 'values' => $values];
    }

}
