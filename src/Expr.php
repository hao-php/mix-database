<?php

namespace Haoa\MixDatabase;

/**
 * SQL 原生表达式
 *
 * 仅用于嵌入 SQL 函数和数值运算，例如:
 *   new Expr('CURRENT_TIMESTAMP()')
 *   new Expr('balance + ?', 1)
 *
 * 注意：不应传入未经处理的用户输入字符串，字符串值不会被自动转义，
 * 可能导致 SQL 注入风险。如需使用用户输入，请使用参数绑定（where/insert 等方法）。
 *
 * @package Haoa\MixDatabase
 */
class Expr
{

    /**
     * @var string
     */
    protected $expr;

    /**
     * @var array
     */
    protected $values;

    /**
     * Expr constructor.
     * @param string $expr
     * @param ...$values
     */
    public function __construct(string $expr, ...$values)
    {
        $this->expr = $expr;
        $this->values = $values;
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        $expr = $this->expr;
        foreach ($this->values as $value) {
            $expr = preg_replace('/\?/', is_string($value) ? "'%s'" : "%s", $expr, 1);
        }
        return vsprintf($expr, $this->values);
    }

}
