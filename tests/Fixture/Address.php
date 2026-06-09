<?php

declare(strict_types=1);

namespace G\Orm\Tests\Fixture;

use G\Orm\Mapping\Column;
use G\Orm\Mapping\HasMany;
use G\Orm\Mapping\Id;
use G\Orm\Mapping\Table;

/**
 * Single-key entity fixture used as the target of {@see User}'s relation.
 *
 * It also declares the inverse {@see HasMany} side back to {@see User}, so the
 * query builder's join resolution can be exercised in both directions.
 */
#[Table('addresses')]
final class Address
{
    #[Id]
    #[Column]
    public int $id;

    #[Column]
    public string $city;

    /**
     * Inverse relation; descriptive only, never lazily loaded. Drives the
     * symmetric join resolution (addresses.id = users.address_id).
     *
     * @var list<User>
     */
    #[HasMany(targetEntity: User::class, mappedBy: 'address_id')]
    public array $users = [];
}
