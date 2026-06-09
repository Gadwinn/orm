<?php

declare(strict_types=1);

namespace G\Orm\Tests\Unit;

use G\Orm\Exception\QueryException;
use G\Orm\Query\QueryBuilder;
use G\Orm\Tests\Fixture\Address;
use G\Orm\Tests\Fixture\User;
use G\Orm\Tests\OrmTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Contextual entity-resolution behaviour of the query builder: from() records
 * the active entity context that a later entity-style join() resolves against,
 * and the guard rails that protect that contract.
 */
#[CoversClass(QueryBuilder::class)]
final class QueryBuilderTest extends OrmTestCase
{
    public function testEntityJoinResolvesTablesAndOnClause(): void
    {
        $sql = $this->builder()
            ->from(User::class)
            ->join(Address::class)
            ->toSql();

        self::assertSame(
            'SELECT * FROM users INNER JOIN addresses ON users.address_id = addresses.id',
            $sql
        );
    }

    /**
     * Join resolution is symmetric: the same relation, declared on the owning
     * side (BelongsTo) and the inverse side (HasMany), yields a correct ON
     * clause whichever entity is the active from() context. localKey and
     * foreignKey adapt to that context rather than to a fixed direction.
     */
    public function testEntityJoinResolvesInverseDirection(): void
    {
        $sql = $this->builder()
            ->from(Address::class)
            ->join(User::class)
            ->toSql();

        self::assertSame(
            'SELECT * FROM addresses INNER JOIN users ON addresses.id = users.address_id',
            $sql
        );
    }

    public function testEntityJoinBeforeFromThrows(): void
    {
        $builder = $this->builder();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('requires from() to be called with a mapped entity class first');

        $builder->join(Address::class);
    }

    public function testEntityJoinAfterRawTableThrows(): void
    {
        $builder = $this->builder()->from('users');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('requires from() to be called with a mapped entity class first');

        $builder->join(Address::class);
    }

    public function testManualJoinWithoutConditionThrows(): void
    {
        $builder = $this->builder()->from('users');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('requires an explicit ON condition');

        $builder->join('addresses');
    }

    private function builder(): QueryBuilder
    {
        return new QueryBuilder($this->compiler, $this->connection, $this->metadata);
    }
}
