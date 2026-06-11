<?php

declare(strict_types=1);

namespace G\Orm\Tests\Fixture;

use G\Orm\Mapping\BelongsTo;
use G\Orm\Mapping\Column;
use G\Orm\Mapping\Id;
use G\Orm\Mapping\Table;

/**
 * Invalid fixture: it declares a BelongsTo relation towards {@see UserRole},
 * whose primary key is composite. Relations join on exactly one key column,
 * so building this schema must be rejected by the metadata reader instead of
 * silently joining on the target's first #[Id] column.
 */
#[Table('composite_relation')]
final class CompositeRelationEntity
{
    #[Id(generated: true)]
    #[Column]
    public int $id;

    #[BelongsTo(targetEntity: UserRole::class, foreignKey: 'user_role_id')]
    public ?UserRole $role = null;
}
