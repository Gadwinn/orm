<?php

declare(strict_types=1);

namespace G\Orm\Exception;

use RuntimeException;

/**
 * General-purpose ORM exception for failures that do not belong to a more
 * specific category (connection, driver, query, transaction, metadata).
 *
 * Prefer the named static constructors over plain instantiation so messages
 * stay consistent across the package.
 */
final class OrmException extends RuntimeException implements OrmExceptionInterface
{
    /**
     * No DbManager is bound to the current execution context when an entity
     * helper or the registry is used.
     */
    public static function managerNotFound(): self
    {
        return new self(
            'No DbManager is bound to the current execution context. '
            . 'Call OrmRegistry::set($manager) during bootstrap.'
        );
    }
}
