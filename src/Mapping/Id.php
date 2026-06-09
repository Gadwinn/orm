<?php

declare(strict_types=1);

namespace G\Orm\Mapping;

use Attribute;

/**
 * Marks a property as part of the entity's primary key.
 *
 * Apply it once per key property. Several #[Id] attributes on different
 * properties of the same entity declare a composite primary key, in
 * declaration order.
 *
 * A property carrying #[Id] is still mapped through #[Column]; #[Id] only
 * flags its participation in the identifier.
 *
 * Usage (single key):
 *
 *     #[Id]
 *     #[Column]
 *     private int $id;
 *
 * Usage (composite key):
 *
 *     #[Id]
 *     #[Column(name: 'user_id')]
 *     private int $userId;
 *
 *     #[Id]
 *     #[Column(name: 'role_id')]
 *     private int $roleId;
 *
 * Set `generated: true` when the database assigns the value (auto-increment,
 * identity, sequence). Only single-column primary keys may be generated; a
 * composite key with a generated member is rejected at metadata build time.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Id
{
    public function __construct(
        public readonly bool $generated = false,
    ) {
    }
}
