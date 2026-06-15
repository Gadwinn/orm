<?php

declare(strict_types=1);

namespace G\Orm\Exception;

use RuntimeException;
use Throwable;

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
     * flush() was re-entered while a flush was already running - typically a
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

    /**
     * An UPDATE or DELETE was about to target an entity whose primary key is
     * not fully initialised. Emitting it would produce a "pk = NULL" predicate
     * that matches no row, turning the write into a silent no-op while hooks
     * and de-registration still ran.
     *
     * @param class-string $entityClass
     */
    public static function entityWithoutIdentifier(string $entityClass, string $operation): self
    {
        return new self(
            sprintf(
                'Cannot %s an entity of class %s: its primary key is not fully initialised.',
                $operation,
                $entityClass,
            )
        );
    }

    /**
     * A raw database value could not be converted into its rich PHP
     * representation - e.g. a malformed temporal string reaching a
     * DateTimeImmutable property.
     */
    public static function invalidTemporalValue(string $value, Throwable $previous): self
    {
        return new self(
            sprintf('Cannot hydrate "%s" into a DateTimeImmutable instance.', $value),
            0,
            $previous,
        );
    }

    /**
     * A flushed UPDATE or DELETE affected no row: the database row no longer
     * matches the entity's identity - typically deleted by a concurrent
     * transaction. The flush is aborted so the desync cannot pass silently.
     *
     * @param class-string $entityClass
     */
    public static function writeAffectedNoRow(string $entityClass, string $operation): self
    {
        return new self(
            sprintf(
                'The %s for an entity of class %s affected no row; '
                . 'it was deleted or modified concurrently.',
                $operation,
                $entityClass,
            )
        );
    }
}
