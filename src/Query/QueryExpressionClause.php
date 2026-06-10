<?php

declare(strict_types=1);

namespace G\Orm\Query;

use G\Orm\Contract\QueryBuilderInterface;
use G\Orm\Contract\QueryExpressionClauseInterface;

/**
 * Concrete expressive clause produced by QueryBuilder::where()/having() when
 * called with a column only.
 *
 * It carries the pending column together with the boolean connector and the
 * target clause (WHERE or HAVING) chosen by the originating call. Each
 * operator finalises the predicate on the owning builder and returns that
 * builder so the fluent chain continues.
 */
final class QueryExpressionClause implements QueryExpressionClauseInterface
{
    public function __construct(
        private readonly QueryBuilder $builder,
        private readonly string $target,
        private readonly string $boolean,
        private readonly string $column,
    ) {
    }

    public function eq(mixed $value): QueryBuilderInterface
    {
        return $this->push('=', [$value]);
    }

    public function neq(mixed $value): QueryBuilderInterface
    {
        return $this->push('<>', [$value]);
    }

    public function gt(mixed $value): QueryBuilderInterface
    {
        return $this->push('>', [$value]);
    }

    public function gte(mixed $value): QueryBuilderInterface
    {
        return $this->push('>=', [$value]);
    }

    public function lt(mixed $value): QueryBuilderInterface
    {
        return $this->push('<', [$value]);
    }

    public function lte(mixed $value): QueryBuilderInterface
    {
        return $this->push('<=', [$value]);
    }

    public function like(string $pattern): QueryBuilderInterface
    {
        return $this->push('LIKE', [$pattern]);
    }

    public function notLike(string $value): QueryBuilderInterface
    {
        return $this->push('NOT LIKE', [$value]);
    }

    public function regexp(string $pattern): QueryBuilderInterface
    {
        return $this->push('REGEXP', [$pattern]);
    }

    public function notRegexp(string $pattern): QueryBuilderInterface
    {
        return $this->push('NOT REGEXP', [$pattern]);
    }

    public function in(array $values): QueryBuilderInterface
    {
        return $this->push('IN', $values);
    }

    public function notIn(array $values): QueryBuilderInterface
    {
        return $this->push('NOT IN', $values);
    }

    public function between(mixed $min, mixed $max): QueryBuilderInterface
    {
        return $this->push('BETWEEN', [$min, $max]);
    }

    public function isNull(): QueryBuilderInterface
    {
        return $this->push('IS NULL', []);
    }

    public function isNotNull(): QueryBuilderInterface
    {
        return $this->push('IS NOT NULL', []);
    }

    /**
     * @param list<mixed> $values
     */
    private function push(string $operator, array $values): QueryBuilderInterface
    {
        return $this->builder->pushPredicate($this->target, $this->boolean, $this->column, $operator, $values);
    }
}
