<?php

declare(strict_types=1);

namespace G\Orm\Mapping;

use Attribute;

/**
 * Links an entity to a custom repository class.
 *
 * Without this attribute the ORM provides a generic repository. The named
 * class is expected to implement G\Orm\Contract\RepositoryInterface.
 *
 * Usage:
 *
 *     #[Repository(UserRepository::class)]
 *     #[Table('users')]
 *     final class User { ... }
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Repository
{
    /**
     * @param class-string $className Fully-qualified repository class name.
     */
    public function __construct(
        public readonly string $className,
    ) {
    }
}
