<?php

declare(strict_types=1);

namespace G\Orm\Mapping;

use Attribute;

/**
 * Owning-side relation: this entity holds the foreign key pointing to the
 * target entity's primary key.
 *
 * Relations are descriptive only. They expose metadata and assist query
 * generation; they NEVER trigger automatic or lazy loading. There is no
 * fetch-mode option by design.
 *
 * Usage:
 *
 *     #[BelongsTo(targetEntity: Address::class, foreignKey: 'address_id')]
 *     private ?Address $address;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class BelongsTo
{
    /**
     * @param class-string $targetEntity The related entity class.
     * @param string       $foreignKey   Local column holding the target's key.
     */
    public function __construct(
        public readonly string $targetEntity,
        public readonly string $foreignKey,
    ) {
    }
}
