<?php

declare(strict_types=1);

namespace G\Orm\Tests\Unit;

use G\Orm\Exception\QueryException;
use G\Orm\Query\QueryBuilder;
use G\Orm\Tests\OrmTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Injection-prevention guards on the query builder: operators and sort
 * directions are held to closed whitelists, and every column reaching a clause
 * that cannot be parameterised is validated against the identifier pattern.
 * Raw aggregate HAVING expressions remain reachable through the explicit
 * havingRaw() trusted surface.
 */
#[CoversClass(QueryBuilder::class)]
final class QueryBuilderSecurityTest extends OrmTestCase
{
    public function testRejectsOperatorOutsideTheWhitelist(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Unsupported SQL operator');

        $this->builder()->from('users')->where('id', 'UNION SELECT', 1);
    }

    public function testRejectsSortDirectionOutsideTheWhitelist(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Invalid sort direction');

        $this->builder()->from('users')->orderBy('id', 'ASC; DROP TABLE users');
    }

    public function testRejectsUnsafeIdentifierInWhere(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Unsafe SQL identifier');

        $this->builder()->from('users')->where('id); DROP TABLE users; --', '=', 1);
    }

    public function testRejectsUnsafeIdentifierInOrderBy(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Unsafe SQL identifier');

        $this->builder()->from('users')->orderBy('(SELECT password FROM users)');
    }

    public function testRejectsUnsafeIdentifierInGroupBy(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Unsafe SQL identifier');

        $this->builder()->from('users')->groupBy('id, name FROM users; --');
    }

    public function testNormalisesLowercaseOperatorToCanonicalSql(): void
    {
        $builder = $this->builder()->from('users')->where('name', 'like', '%ada%');

        self::assertSame('SELECT * FROM users WHERE name LIKE ?', $builder->toSql());
        self::assertSame(['%ada%'], $builder->getBindings());
    }

    public function testQualifiedIdentifierIsAccepted(): void
    {
        $builder = $this->builder()
            ->from('users u')
            ->where('u.id', '=', 1)
            ->orderBy('u.name', 'DESC');

        self::assertSame('SELECT * FROM users u WHERE u.id = ? ORDER BY u.name DESC', $builder->toSql());
        self::assertSame([1], $builder->getBindings());
    }

    public function testRejectsNativeFunctionInStandardOrderBy(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Unsafe SQL identifier');

        $this->builder()->from('users')->orderBy('LOWER(name)', 'DESC');
    }

    public function testRegexpOperatorIsAcceptedOnTheInlineForm(): void
    {
        $builder = $this->builder()->from('users')->where('email', 'REGEXP', '^[a-z]+@');

        self::assertSame('SELECT * FROM users WHERE email REGEXP ?', $builder->toSql());
        self::assertSame(['^[a-z]+@'], $builder->getBindings());
    }

    public function testHavingRawCarriesTrustedAggregateExpression(): void
    {
        $builder = $this->builder()
            ->select('customer_id')
            ->from('orders')
            ->groupBy('customer_id')
            ->havingRaw('COUNT(*) > ?', [5]);

        self::assertSame(
            'SELECT customer_id FROM orders GROUP BY customer_id HAVING COUNT(*) > ?',
            $builder->toSql()
        );
        self::assertSame([5], $builder->getBindings());
    }

    public function testWhereRawCarriesNativeFunctionWithBindings(): void
    {
        $builder = $this->builder()
            ->from('users')
            ->where('active', '=', 1)
            ->whereRaw('LOWER(email) = ?', ['ada@example.com']);

        self::assertSame(
            'SELECT * FROM users WHERE active = ? AND LOWER(email) = ?',
            $builder->toSql()
        );
        self::assertSame([1, 'ada@example.com'], $builder->getBindings());
    }

    public function testWhereRawCarriesJsonPathLookup(): void
    {
        $builder = $this->builder()
            ->from('users')
            ->whereRaw("JSON_EXTRACT(meta, '$.tier') = ?", ['gold']);

        self::assertSame(
            "SELECT * FROM users WHERE JSON_EXTRACT(meta, '\$.tier') = ?",
            $builder->toSql()
        );
        self::assertSame(['gold'], $builder->getBindings());
    }

    public function testOrderByRawAndGroupByRawEmitNativeExpressionsVerbatim(): void
    {
        $builder = $this->builder()
            ->select('YEAR(created_at) AS y')
            ->from('orders')
            ->groupByRaw('YEAR(created_at)')
            ->orderByRaw('LOWER(status) DESC');

        self::assertSame(
            'SELECT YEAR(created_at) AS y FROM orders GROUP BY YEAR(created_at) ORDER BY LOWER(status) DESC',
            $builder->toSql()
        );
        self::assertSame([], $builder->getBindings());
    }

    private function builder(): QueryBuilder
    {
        return new QueryBuilder($this->compiler, $this->connection);
    }
}
