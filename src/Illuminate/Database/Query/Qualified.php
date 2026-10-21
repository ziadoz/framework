<?php

namespace Illuminate\Database\Query;

use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;

class Qualified implements ExpressionContract
{
    /**
     * Create a new qualified column expression.
     *
     * @param  string  $column
     * @param  string|null  $table
     */
    public function __construct(
        protected $column,
        protected $table = null,
    ) {
    }

    /**
     * Get a copy of the expression qualified by the given table, unless it already has one.
     *
     * @param  string  $table
     * @return static
     */
    public function qualifyUsing($table)
    {
        return new static($this->column, $this->table ?? $table);
    }

    /**
     * Get the value of the expression.
     *
     * @param  \Illuminate\Database\Grammar  $grammar
     * @return string
     */
    public function getValue(Grammar $grammar)
    {
        if (is_null($this->table) || str_contains($this->column, '.')) {
            return $grammar->wrap($this->column);
        }

        return $grammar->wrap($this->table.'.'.$this->column);
    }
}
