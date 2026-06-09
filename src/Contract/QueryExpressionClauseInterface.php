<?php

declare(strict_types=1);

namespace G\Orm\Contract;

/**
 * Fluent expression clause for the expressive Query Builder syntax.
 *
 * A clause is produced by the builder when a predicate is opened with a
 * column only, e.g.:
 *
 *     $query->where('age')->gt(18);
 *     $query->having('total')->between(10, 100);
 *
 * The clause carries the pending column and the boolean context (AND / OR)
 * decided by the originating where()/having() call. Each operator finalises
 * the predicate against that column and returns the builder so the fluent
 * chain continues. This keeps the comparison vocabulary out of the builder
 * itself, as required by the architecture.
 *
 * Operators map to plain SQL; no value is interpreted or coerced beyond the
 * driver's normal binding behaviour.
 */
interface QueryExpressionClauseInterface
{
    /**
     * column = value
     */
    public function eq(mixed $value): QueryBuilderInterface;

    /**
     * column <> value
     */
    public function neq(mixed $value): QueryBuilderInterface;

    /**
     * column > value
     */
    public function gt(mixed $value): QueryBuilderInterface;

    /**
     * column >= value
     */
    public function gte(mixed $value): QueryBuilderInterface;

    /**
     * column < value
     */
    public function lt(mixed $value): QueryBuilderInterface;

    /**
     * column <= value
     */
    public function lte(mixed $value): QueryBuilderInterface;

    /**
     * column LIKE pattern
     */
    public function like(string $pattern): QueryBuilderInterface;

    /**
     * column IN (...values)
     *
     * @param list<mixed> $values
     */
    public function in(array $values): QueryBuilderInterface;

    /**
     * column NOT IN (...values)
     *
     * @param list<mixed> $values
     */
    public function notIn(array $values): QueryBuilderInterface;

    /**
     * column BETWEEN min AND max
     */
    public function between(mixed $min, mixed $max): QueryBuilderInterface;

    /**
     * column IS NULL
     */
    public function isNull(): QueryBuilderInterface;

    /**
     * column IS NOT NULL
     */
    public function isNotNull(): QueryBuilderInterface;
}
