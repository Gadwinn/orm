<?php

declare(strict_types=1);

namespace G\Orm\Tests\Integration;

use G\Orm\Core\IdentityMap;
use G\Orm\Query\QueryBuilder;
use G\Orm\Tests\Fixture\Address;
use G\Orm\Tests\Fixture\User;
use G\Orm\Tests\OrmTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * SELECT-driven eager hydration: relation hydration is governed strictly by the
 * columns present in the compiled SELECT. There is no with(), no proxy and no
 * lazy loading.
 *
 *   - An explicit root-only select ("u.*") returns the roots alone; the joined
 *     dataset is ignored and the relation property is never populated.
 *   - An empty/"*" select is auto-expanded to alias-prefixed entity columns,
 *     and each flat row is ventilated into the root entity and its relations.
 */
#[CoversClass(QueryBuilder::class)]
final class EagerJoinHydrationTest extends OrmTestCase
{
    public function testWildcardSelectExpandsToAliasPrefixedEntityColumns(): void
    {
        $builder = $this->builder()
            ->from(User::class, 'u')
            ->join(Address::class, 'a');

        // Executing the eager terminal rewrites the bare "*" into every
        // registered entity's columns, alias-prefixed to dodge PDO collisions.
        $builder->getEntities();

        self::assertSame(
            'SELECT u.id AS u__id, u.name AS u__name, u.email AS u__email, '
            . 'a.id AS a__id, a.city AS a__city '
            . 'FROM users u INNER JOIN addresses a ON u.address_id = a.id',
            $builder->toSql()
        );
    }

    public function testExplicitRootSelectIgnoresJoinedColumns(): void
    {
        $this->seedRow("INSERT INTO addresses (id, city) VALUES (1, 'Paris')");
        $this->seedRow("INSERT INTO users (id, name, email, address_id) VALUES (1, 'John', 'john@test.com', 1)");

        $users = $this->builder()
            ->select('u.*')
            ->from(User::class, 'u')
            ->join(Address::class, 'a')
            ->where('a.city', '=', 'Paris')
            ->getEntities();

        self::assertCount(1, $users);
        $user = $users[0];
        self::assertInstanceOf(User::class, $user);
        self::assertSame('John', $user->name);

        // The join filtered the rows but selected no address columns, so the
        // relation stays untouched: no hidden lazy query backfills it.
        self::assertNull($user->address);
    }

    public function testImplicitSelectExpandsAndVentilatesToOneRelation(): void
    {
        $this->seedRow("INSERT INTO addresses (id, city) VALUES (1, 'Paris')");
        $this->seedRow("INSERT INTO users (id, name, email, address_id) VALUES (1, 'John', 'john@test.com', 1)");

        $users = $this->builder()
            ->from(User::class, 'u')
            ->join(Address::class, 'a')
            ->getEntities();

        self::assertCount(1, $users);
        $user = $users[0];
        self::assertInstanceOf(User::class, $user);

        // The single flat row was ventilated: the Address slice hydrated into
        // the BelongsTo relation property in the same pass.
        $address = $user->address;
        self::assertInstanceOf(Address::class, $address);
        self::assertSame('Paris', $address->city);
    }

    public function testToManyVentilationAccumulatesCollectionUnderOneRoot(): void
    {
        $this->seedRow("INSERT INTO addresses (id, city) VALUES (1, 'Paris')");
        $this->seedRow("INSERT INTO users (id, name, email, address_id) VALUES (1, 'John', 'john@test.com', 1)");
        $this->seedRow("INSERT INTO users (id, name, email, address_id) VALUES (2, 'Jane', 'jane@test.com', 1)");

        $addresses = $this->builder()
            ->from(Address::class, 'a')
            ->join(User::class, 'u')
            ->getEntities();

        // Two joined rows collapse to a single Address (identity-map enforced),
        // with both users accumulated into its HasMany collection.
        self::assertCount(1, $addresses);
        $address = $addresses[0];
        self::assertInstanceOf(Address::class, $address);
        self::assertCount(2, $address->users);
        self::assertSame('John', $address->users[0]->name);
        self::assertSame('Jane', $address->users[1]->name);
    }

    public function testUnmatchedLeftJoinHydratesRootWithoutPhantomRelation(): void
    {
        // A user with no address at all: the foreign key is NULL, so the LEFT
        // JOIN produces a row whose every address column is NULL.
        $this->seedRow("INSERT INTO users (id, name, email, address_id) VALUES (1, 'Solo', 'solo@test.com', NULL)");

        $identityMap = new IdentityMap();
        $users = $this->builder($identityMap)
            ->from(User::class, 'u')
            ->leftJoin(Address::class, 'a')
            ->getEntities();

        self::assertCount(1, $users);
        $user = $users[0];
        self::assertInstanceOf(User::class, $user);
        self::assertSame('Solo', $user->name);

        // The all-NULL address slice is recognised as an unmatched outer join:
        // the relation is left at its default and no phantom Address is built.
        self::assertNull($user->address);
        self::assertFalse($identityMap->has(Address::class, 0));
        self::assertNull($identityMap->get(Address::class, 0));
    }

    /**
     * A mapping-aware builder wired with the hydrator and an identity map, so
     * the eager terminal can ventilate rows and enforce single instances. A
     * specific map can be supplied so a test can inspect what was registered.
     */
    private function builder(?IdentityMap $identityMap = null): QueryBuilder
    {
        return new QueryBuilder(
            $this->compiler,
            $this->connection,
            $this->metadata,
            $this->hydrator,
            $identityMap ?? new IdentityMap(),
        );
    }
}
