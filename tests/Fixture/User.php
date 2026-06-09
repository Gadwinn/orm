<?php

declare(strict_types=1);

namespace G\Orm\Tests\Fixture;

use G\Orm\Mapping\BelongsTo;
use G\Orm\Mapping\Column;
use G\Orm\Mapping\Id;
use G\Orm\Mapping\Table;

/**
 * Single-key entity fixture with a BelongsTo relation to {@see Address}.
 *
 * The auto-increment identifier is intentionally left uninitialised on a fresh
 * instance so the Unit Of Work exercises its generated-identifier path.
 */
#[Table('users')]
final class User
{
    #[Id]
    #[Column]
    public int $id;

    #[Column]
    public string $name;

    #[Column(unique: true)]
    public string $email;

    /**
     * Descriptive relation only; never lazily loaded. It drives the
     * entity-style join resolution (users.address_id = addresses.id).
     */
    #[BelongsTo(targetEntity: Address::class, foreignKey: 'address_id')]
    public ?Address $address = null;
}
