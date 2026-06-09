<?php

declare(strict_types=1);

namespace G\Orm\Mapping;

use Attribute;

/**
 * Inverse-side plural relation: many target entities hold the foreign key
 * back to this entity.
 *
 * Relations are descriptive only. They expose metadata and assist query
 * generation; they NEVER trigger automatic or lazy loading. There is no
 * fetch-mode option by design.
 *
 * Usage:
 *
 *     #[HasMany(targetEntity: Order::class, mappedBy: 'customer')]
 *     private array $orders;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class HasMany
{
    /**
     * @param class-string $targetEntity The related entity class.
     * @param string       $mappedBy     The owning property on the target entity.
     */
    public function __construct(
        public readonly string $targetEntity,
        public readonly string $mappedBy,
    ) {
    }
}
