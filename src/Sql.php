<?php

namespace Haoa\MixDatabase;

/** SQL 标识符校验与参数展开。 */
final class Sql
{
    /**
     * 校验并按需引用字段路径，不接受 SQL 表达式。
     * @param string $name 字段路径
     * @param string $quote 驱动引用字符
     * @param bool $enabled 是否补充引用
     * @param bool $qualified 是否允许带前缀路径
     */
    public static function identifier(string $name, string $quote = '', bool $enabled = false, bool $qualified = true): string
    {
        if (strpos($name, '\\') !== false) {
            throw new \InvalidArgumentException('标识符不支持反斜线');
        }
        $part = '(?:[a-zA-Z_\x80-\xff][a-zA-Z0-9_$\x80-\xff]*|"(?:[^"\x00]|"")+"|`(?:[^`\x00]|``)+`)';
        if (!preg_match('/^' . $part . '(?:\.' . $part . ')*$/D', $name)) {
            throw new \InvalidArgumentException('非法 SQL 标识符：' . $name);
        }
        // 引用标识符必须使用当前驱动的引用字符。
        preg_match_all('/' . $part . '/', $name, $matches);
        if (!$qualified && count($matches[0]) !== 1) {
            throw new \InvalidArgumentException('别名不能包含字段路径');
        }
        $parts = [];
        foreach ($matches[0] as $segment) {
            $first = $segment[0];
            if ($first === '"' || $first === '`') {
                if ($quote !== '' && $first !== $quote) {
                    throw new \InvalidArgumentException('标识符引用字符与驱动不匹配');
                }
                $parts[] = $segment;
            } else {
                $parts[] = $enabled ? $quote . $segment . $quote : $segment;
            }
        }
        return implode('.', $parts);
    }

    /**
     * 校验表路径及可选别名。
     * @param string $table 表名及别名
     * @param string $quote 驱动引用字符
     * @param bool $enabled 是否补充引用
     */
    public static function table(string $table, string $quote, bool $enabled): string
    {
        $part = '(?:[a-zA-Z_\x80-\xff][a-zA-Z0-9_$\x80-\xff]*|"(?:[^"\x00]|"")+"|`(?:[^`\x00]|``)+`)';
        if (!preg_match('/^(' . $part . '(?:\.' . $part . ')*)(?:\s+(?:(AS)\s+)?(' . $part . '))?$/iD', $table, $m)) {
            throw new \InvalidArgumentException('非法 SQL 表名：' . $table);
        }
        $sql = self::identifier($m[1], $quote, $enabled);
        if (isset($m[3]) && $m[3] !== '') {
            $sql .= (!empty($m[2]) ? ' AS ' : ' ') . self::identifier($m[3], $quote, $enabled, false);
        }
        return $sql;
    }

    /**
     * 遍历真正的占位符，跳过字符串、标识符、注释及 PostgreSQL dollar 字符串。
     * @param string $sql 原始 SQL
     * @param callable $replace 占位符替换函数
     * @param bool $mysql 是否使用 MySQL 字符串及注释语法
     */
    private static function markers(string $sql, callable $replace, bool $mysql): string
    {
        $out = '';
        $length = strlen($sql);
        for ($i = 0; $i < $length;) {
            $start = $i;
            $c = $sql[$i];
            if ($c === "'" || $c === '"' || $c === '`') {
                $escape = ($mysql && $c !== '`') || ($c === "'" && $i > 0 && ($sql[$i - 1] === 'E' || $sql[$i - 1] === 'e') && ($i < 2 || !preg_match('/[a-zA-Z0-9_]/', $sql[$i - 2])));
                $i++;
                while ($i < $length) {
                    if ($sql[$i] === $c) {
                        $i++;
                        if ($i < $length && $sql[$i] === $c) {
                            $i++;
                            continue;
                        }
                        break;
                    }
                    if ($escape && $sql[$i] === '\\') {
                        $i++;
                    }
                    $i++;
                }
            } elseif ((substr($sql, $i, 2) === '--' && (!$mysql || $i + 2 >= $length || ord($sql[$i + 2]) <= 32)) || ($mysql && $c === '#')) {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end + 1;
            } elseif (substr($sql, $i, 2) === '/*') {
                $i += 2;
                $depth = 1;
                while ($i < $length && $depth) {
                    if (!$mysql && substr($sql, $i, 2) === '/*') {
                        $depth++;
                        $i += 2;
                    } elseif (substr($sql, $i, 2) === '*/') {
                        $depth--;
                        $i += 2;
                    } else {
                        $i++;
                    }
                }
            } elseif (!$mysql && $c === '$' && preg_match('/\G\$(?:[a-zA-Z_][a-zA-Z0-9_]*)?\$/', $sql, $m, 0, $i)) {
                $end = strpos($sql, $m[0], $i + strlen($m[0]));
                $i = $end === false ? $length : $end + strlen($m[0]);
            } elseif (substr($sql, $i, 2) === '??' || substr($sql, $i, 2) === '::') {
                $i += 2;
            } elseif ($c === '?') {
                $out .= $replace('?', $i);
                $i++;
                continue;
            } elseif ($c === ':' && preg_match('/\G:[a-zA-Z_][a-zA-Z0-9_]*/', $sql, $m, 0, $i)) {
                $out .= $replace($m[0], $i);
                $i += strlen($m[0]);
                continue;
            } else {
                $i++;
            }
            $out .= substr($sql, $start, $i - $start);
        }
        return $out;
    }

    /**
     * 将数组和表达式展开为 SQL 与顺序绑定参数。
     * @param string $sql 原始 SQL
     * @param array $values 顺序参数
     * @param bool $mysql 是否使用 MySQL 方言
     */
    public static function expand(string $sql, array $values, bool $mysql = false): array
    {
        $index = 0;
        $bindings = [];
        $sql = self::markers($sql, static function ($marker, $position) use (&$index, &$bindings, $values, $sql, $mysql) {
            if ($marker !== '?') {
                return $marker;
            }
            if (!array_key_exists($index, $values)) {
                throw new \InvalidArgumentException('SQL 占位符与参数数量不匹配');
            }
            $value = $values[$index++];
            if ($value instanceof Expr) {
                list($fragment, $params) = self::expand($value->getSql(), $value->getValues(), $mysql);
                array_push($bindings, ...$params);
                return $fragment;
            }
            if (is_array($value)) {
                if (!preg_match('/\\bIN\\s*\\(\\s*$/i', substr($sql, 0, $position)) || !preg_match('/^\\s*\\)/', substr($sql, $position + 1))) {
                    throw new \InvalidArgumentException('数组参数仅支持 IN (?) 或 NOT IN (?)');
                }
                foreach ($value as $item) {
                    if (is_array($item) || is_object($item)) {
                        throw new \InvalidArgumentException('IN 参数不支持嵌套数组或对象');
                    }
                    $bindings[] = $item;
                }
                return $value ? implode(', ', array_fill(0, count($value), '?')) : 'SELECT NULL WHERE 1 = 0';
            }
            $bindings[] = $value;
            return '?';
        }, $mysql);
        if ($index !== count($values)) {
            throw new \InvalidArgumentException('SQL 占位符与参数数量不匹配');
        }
        return [$sql, $bindings];
    }

    /**
     * 将命名参数转为顺序参数，避免表达式替换误匹配参数前缀。
     * @param string $sql 原始 SQL
     * @param array $params 命名参数
     * @param bool $mysql 是否使用 MySQL 方言
     */
    public static function named(string $sql, array $params, bool $mysql = false): array
    {
        $values = [];
        $sql = self::markers($sql, static function ($marker) use ($params, &$values) {
            if ($marker === '?') {
                throw new \InvalidArgumentException('不支持混用命名和顺序参数');
            }
            $key = substr($marker, 1);
            if (!array_key_exists($key, $params) && !array_key_exists($marker, $params)) {
                throw new \InvalidArgumentException('缺少 SQL 命名参数：' . $marker);
            }
            $values[] = array_key_exists($key, $params) ? $params[$key] : $params[$marker];
            return '?';
        }, $mysql);
        return self::expand($sql, $values, $mysql);
    }
}
