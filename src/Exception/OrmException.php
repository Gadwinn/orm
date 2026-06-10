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

    /**
     * flush() was re-entered while a flush was already running — typically a
     * lifecycle hook or PSR-14 listener calling flush()/save() from inside the
     * write pipeline, which would re-process the still-pending intention queues.
     */
    public static function reentrantFlush(): self
    {
        return new self(
            'flush() was called while a flush is already in progress; a lifecycle '
            . 'hook or event listener must not trigger another flush.'
        );
    }
}
