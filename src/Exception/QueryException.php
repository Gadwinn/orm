<?php

declare(strict_types=1);

namespace G\Orm\Exception;

use RuntimeException;

/**
 * Thrown when preparing or executing a statement fails (invalid SQL, binding
 * mismatch, constraint violation surfaced at execution time, ...).
 *
 * Prefer the named static constructors over plain instantiation so messages
 * stay consistent across the package.
 */
final class QueryException extends RuntimeException implements OrmExceptionInterface
{
    /**
     * A single statement mixed element-by-element bind() calls with an
     * array of parameters passed to execute().
     */
    public static function mixedBindingStrategies(): self
    {
        return new self(
            'Do not mix bind() and execute($params) on the same statement: '
            . 'either bind values individually or pass them all to execute().'
        );
    }

    /**
     * A whitelisted operator reached a platform whose engine has no reliable
     * native syntax for it. Failing at compile time beats emitting SQL that
     * only breaks once it reaches a production server.
     */
    public static function unsupportedOperator(string $operator, string $platform): self
    {
        return new self(
            sprintf('The %s operator is not supported on the "%s" platform.', $operator, $platform)
        );
    }
}
