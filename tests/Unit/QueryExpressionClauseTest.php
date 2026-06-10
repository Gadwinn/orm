<?php

declare(strict_types=1);

namespace G\Orm\Tests\Unit;

use G\Orm\Query\QueryBuilder;
use G\Orm\Query\QueryExpressionClause;
use G\Orm\Tests\OrmTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The expressive predicate clause produced by where()/andWhere()/orWhere()/
 * having() when called with a column only. Each operator finalises the pending
 * predicate on the owning builder and hands the builder back, so the fluent
 * chain continues and compiles to the expected parametrised SQL.
 *
 * These assertions go through the real compiler, so they prove both the clause
 * wiring and the SQL it ultimately produces (placeholders, IN expansion,
 * BETWEEN, and the NULL operators that bind nothing).
 */
#[CoversClass(QueryExpressionClause::class)]
#[CoversClass(QueryBuilder::class)]
final class QueryExpressionClauseTest extends OrmTestCase
{
    public function testWhereWithColumnOnlyReturnsAnExpressiveClause(): void
    {
        $clause = $this->builder()->from('users')->where('age');

        self::assertInstanceOf(QueryExpressionClause::class, $clause);
    }

    public function testEqualityAndComparisonOperatorsCompileWithBindings(): void
    {
        $builder = $this->builder()
            ->from('users')
            ->where('age')->gte(18)
            ->andWhere('score')->gt(10)
            ->andWhere('level')->lte(99)
            ->andWhere('rank')->lt(5)
            ->andWhere('status')->eq('active')
            ->andWhere('role')->neq('banned');

        self::assertSame(
            'SELECT * FROM users WHERE age >= ? AND score > ? AND level <= ? '
            . 'AND rank < ? AND status = ? AND role <> ?',
            $builder->toSql()
        );
        self::assertSame([18, 10, 99, 5, 'active', 'banned'], $builder->getBindings());
    }

    public function testLikeInAndBetweenOperatorsExpandPlaceholders(): void
    {
        $builder = $this->builder()
            ->from('products')
            ->where('name')->like('%phone%')
            ->andWhere('category')->in(['a', 'b', 'c'])
            ->andWhere('vendor')->notIn(['x', 'y'])
            ->andWhere('price')->between(100, 200);

        self::assertSame(
            'SELECT * FROM products WHERE name LIKE ? AND category IN (?, ?, ?) '
            . 'AND vendor NOT IN (?, ?) AND price BETWEEN ? AND ?',
            $builder->toSql()
        );
        self::assertSame(['%phone%', 'a', 'b', 'c', 'x', 'y', 100, 200], $builder->getBindings());
    }

    public function testPatternMatchingOperatorsCompileWithBindings(): void
    {
        $builder = $this->builder()
            ->from('users')
            ->where('name')->notLike('%test%')
            ->andWhere('email')->regexp('^[a-z]+@')
            ->andWhere('handle')->notRegexp('[0-9]$');

        self::assertSame(
            'SELECT * FROM users WHERE name NOT LIKE ? AND email REGEXP ? AND handle NOT REGEXP ?',
            $builder->toSql()
        );
        self::assertSame(['%test%', '^[a-z]+@', '[0-9]$'], $builder->getBindings());
    }

    public function testNullOperatorsBindNoValues(): void
    {
        $builder = $this->builder()
            ->from('users')
            ->where('deleted_at')->isNull()
            ->andWhere('email')->isNotNull();

        self::assertSame(
            'SELECT * FROM users WHERE deleted_at IS NULL AND email IS NOT NULL',
            $builder->toSql()
        );
        self::assertSame([], $builder->getBindings());
    }

    public function testMixedAndOrConnectorsArePreservedInOrder(): void
    {
        $builder = $this->builder()
            ->from('users')
            ->where('country')->eq('FR')
            ->orWhere('country')->eq('BE')
            ->andWhere('age')->gte(21);

        // The boolean connector of each predicate (AND vs OR) is carried by the
        // clause that produced it, so the compiled chain keeps caller order.
        self::assertSame(
            'SELECT * FROM users WHERE country = ? OR country = ? AND age >= ?',
            $builder->toSql()
        );
        self::assertSame(['FR', 'BE', 21], $builder->getBindings());
    }

    public function testExpressiveHavingTargetsTheHavingClause(): void
    {
        $builder = $this->builder()
            ->from('orders')
            ->groupBy('customer_id')
            ->where('status')->eq('paid')
            ->having('total')->gt(1000);

        self::assertSame(
            'SELECT * FROM orders WHERE status = ? GROUP BY customer_id HAVING total > ?',
            $builder->toSql()
        );
        self::assertSame(['paid', 1000], $builder->getBindings());
    }

    public function testEmptyInFallsBackToAlwaysFalsePredicate(): void
    {
        $builder = $this->builder()
            ->from('users')
            ->where('id')->in([]);

        // An empty IN () is invalid SQL; the compiler emits a constant-false
        // predicate that keeps the intended truth value with no bindings.
        self::assertSame('SELECT * FROM users WHERE 1 = 0', $builder->toSql());
        self::assertSame([], $builder->getBindings());
    }

    private function builder(): QueryBuilder
    {
        return new QueryBuilder($this->compiler, $this->connection);
    }
}
