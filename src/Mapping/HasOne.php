<?php

declare(strict_types=1);

namespace G\Orm\Mapping;

use Attribute;

/**
 * Inverse-side singular relation: the target entity holds the foreign key
 * back to this entity.
 *
 * Relations are descriptive only. They expose metadata and assist query
 * generation; they NEVER trigger automatic or lazy loading. There is no
 * fetch-mode option by design.
 *
 * Usage:
 *
 *     #[HasOne(targetEntity: Profile::class, mappedBy: 'user')]
 *     private ?Profile $profile;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class HasOne
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
