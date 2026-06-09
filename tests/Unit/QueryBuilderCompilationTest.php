<?php

declare(strict_types=1);

namespace G\Orm\Tests\Unit;

use G\Orm\Query\QueryBuilder;
use G\Orm\Tests\Fixture\Address;
use G\Orm\Tests\Fixture\User;
use G\Orm\Tests\OrmTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Compilation behaviour of the query builder across the clauses and statement
 * types not exercised by the entity-resolution and eager-hydration suites:
 * explicit projections, ordering, grouping, paging, the join variants, and the
 * INSERT/UPDATE/DELETE write builders (scalar and bulk assignment forms).
 *
 * Assertions go through the real compiler, checking both the emitted SQL and
 * the positional binding list.
 */
#[CoversClass(QueryBuilder::class)]
final class QueryBuilderCompilationTest extends OrmTestCase
{
    public function testExplicitProjection(): void
    {
        $sql = $this->builder()->select('id', 'name')->from('users')->toSql();

        self::assertSame('SELECT id, name FROM users', $sql);
    }

    public function testOrderByDefaultsToAscendingAndAccumulatesTerms(): void
    {
        $sql = $this->builder()
            ->from('users')
            ->orderBy('name', 'DESC')
            ->orderBy('id')
            ->toSql();

        self::assertSame('SELECT * FROM users ORDER BY name DESC, id ASC', $sql);
    }

    public function testGroupByAccumulatesColumns(): void
    {
        $sql = $this->builder()->from('orders')->groupBy('customer_id', 'status')->toSql();

        self::assertSame('SELECT * FROM orders GROUP BY customer_id, status', $sql);
    }

    public function testLimitAndOffsetPageTheResultSet(): void
    {
        $sql = $this->builder()->from('users')->limit(10)->offset(20)->toSql();

        self::assertSame('SELECT * FROM users LIMIT 10 OFFSET 20', $sql);
    }

    public function testManualJoinEmitsTheGivenOnCondition(): void
    {
        $sql = $this->builder()
            ->from('orders')
            ->join('customers', 'orders.customer_id = customers.id')
            ->toSql();

        self::assertSame(
            'SELECT * FROM orders INNER JOIN customers ON orders.customer_id = customers.id',
            $sql
        );
    }

    public function testInnerLeftAndRightJoinVariantsEmitTheirKeyword(): void
    {
        $inner = $this->mappingBuilder()->from(User::class, 'u')->innerJoin(Address::class, 'a')->toSql();
        $left = $this->mappingBuilder()->from(User::class, 'u')->leftJoin(Address::class, 'a')->toSql();
        $right = $this->mappingBuilder()->from(User::class, 'u')->rightJoin(Address::class, 'a')->toSql();

        self::assertSame(
            'SELECT * FROM users u INNER JOIN addresses a ON u.address_id = a.id',
            $inner
        );
        self::assertSame(
            'SELECT * FROM users u LEFT JOIN addresses a ON u.address_id = a.id',
            $left
        );
        self::assertSame(
            'SELECT * FROM users u RIGHT JOIN addresses a ON u.address_id = a.id',
            $right
        );
    }

    public function testInsertCompilesColumnsAndPlaceholdersFromBulkValues(): void
    {
        $builder = $this->builder()
            ->insert('users')
            ->values(['name' => 'Ada', 'email' => 'ada@test.com']);

        self::assertSame('INSERT INTO users (name, email) VALUES (?, ?)', $builder->toSql());
        self::assertSame(['Ada', 'ada@test.com'], $builder->getBindings());
    }

    public function testInsertAcceptsScalarColumnValuePairs(): void
    {
        $builder = $this->builder()->insert('users')->values('name', 'Grace');

        self::assertSame('INSERT INTO users (name) VALUES (?)', $builder->toSql());
        self::assertSame(['Grace'], $builder->getBindings());
    }

    public function testUpdateCompilesAssignmentsThenWhereBindings(): void
    {
        $builder = $this->builder()
            ->update('users')
            ->set(['name' => 'Ada', 'email' => 'ada@test.com'])
            ->where('id', '=', 7);

        self::assertSame('UPDATE users SET name = ?, email = ? WHERE id = ?', $builder->toSql());
        // Assignment bindings come first, then the WHERE bindings.
        self::assertSame(['Ada', 'ada@test.com', 7], $builder->getBindings());
    }

    public function testUpdateAcceptsScalarColumnValuePairs(): void
    {
        $builder = $this->builder()->update('users')->set('name', 'Grace')->where('id', '=', 1);

        self::assertSame('UPDATE users SET name = ? WHERE id = ?', $builder->toSql());
        self::assertSame(['Grace', 1], $builder->getBindings());
    }

    public function testDeleteCompilesWithWhereClause(): void
    {
        $builder = $this->builder()->from('users')->delete()->where('id', '=', 9);

        self::assertSame('DELETE FROM users WHERE id = ?', $builder->toSql());
        self::assertSame([9], $builder->getBindings());
    }

    public function testDebugReturnsSqlAndBindingsTogether(): void
    {
        $debug = $this->builder()
            ->from('users')
            ->where('age', '>=', 18)
            ->debug();

        self::assertSame(
            ['sql' => 'SELECT * FROM users WHERE age >= ?', 'bindings' => [18]],
            $debug
        );
    }

    public function testFullSelectPipelineComposesEveryClauseInOrder(): void
    {
        $builder = $this->mappingBuilder()
            ->select('u.id', 'a.city')
            ->from(User::class, 'u')
            ->innerJoin(Address::class, 'a')
            ->where('u.name', '=', 'Ada')
            ->groupBy('u.id')
            ->having('total', '>', 1)
            ->orderBy('u.id', 'DESC')
            ->limit(5)
            ->offset(10);

        self::assertSame(
            'SELECT u.id, a.city FROM users u INNER JOIN addresses a ON u.address_id = a.id '
            . 'WHERE u.name = ? GROUP BY u.id HAVING total > ? ORDER BY u.id DESC LIMIT 5 OFFSET 10',
            $builder->toSql()
        );
        self::assertSame(['Ada', 1], $builder->getBindings());
    }

    private function builder(): QueryBuilder
    {
        return new QueryBuilder($this->compiler, $this->connection);
    }

    private function mappingBuilder(): QueryBuilder
    {
        return new QueryBuilder($this->compiler, $this->connection, $this->metadata);
    }
}
