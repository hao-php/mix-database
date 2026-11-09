<?php

namespace Haoa\MixDatabase;

/**
 * SQL 原生表达式
 *
 * 仅用于嵌入 SQL 函数和数值运算，例如:
 *   new Expr('CURRENT_TIMESTAMP()')
 *   new Expr('balance + ?', 1)
 *
 * 表达式 SQL 必须由开发者提供；用户输入只能通过后续参数传入，
 * 由 SQL 构建器统一交给 PDO 绑定，不进行字符串插值。
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

    /** 获取原始表达式 SQL。 */
    public function getSql(): string
    {
        return $this->expr;
    }

    /** 获取表达式绑定参数。 */
    public function getValues(): array
    {
        return $this->values;
    }

    /** 获取固定表达式；带参数表达式必须经 SQL 构建器绑定。 */
    public function __toString(): string
    {
        if ($this->values) {
            throw new \LogicException('带参数的 Expr 必须通过参数绑定执行');
        }
        return $this->expr;
    }

}
