<?php

declare(strict_types=1);

namespace G\Orm\Mapping;

use Attribute;

/**
 * Maps an entity property to a database column.
 *
 * All options are optional and reflect standard SQL capabilities only;
 * database-specific tuning is intentionally out of scope.
 *
 * Usage:
 *
 *     #[Column]
 *     private string $name;
 *
 *     #[Column(name: 'e-mail', length: 255, nullable: true, unique: true)]
 *     private ?string $email;
 *
 * When `name` is null, the implementation derives the column name from the
 * property name. When `type` is null, it is inferred from the property's PHP
 * type (including native enums).
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Column
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $type = null,
        public readonly bool $nullable = false,
        public readonly bool $unique = false,
        public readonly ?int $length = null,
    ) {
    }
}
