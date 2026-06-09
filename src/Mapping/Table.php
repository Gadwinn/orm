<?php

declare(strict_types=1);

namespace G\Orm\Mapping;

use Attribute;

/**
 * Maps an entity class to its database table.
 *
 * Usage:
 *
 *     #[Table('users')]
 *     final class User { ... }
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Table
{
    public function __construct(
        public readonly string $name,
    ) {
    }
}
