<?php

declare(strict_types=1);

namespace G\Orm\Hydrator\Type;

use DateMalformedStringException;
use DateTimeImmutable;
use G\Orm\Exception\OrmException;

/**
 * Reference converter for DateTimeImmutable properties.
 *
 * Persists the value as the ISO "Y-m-d H:i:s" string every engine's temporal
 * column accepts, and rebuilds a fresh DateTimeImmutable instance from raw
 * database temporal strings. A malformed database string surfaces as a typed
 * OrmException instead of a bare date parser error.
 */
final class DateTimeImmutableConverter implements ValueConverterInterface
{
    /**
     * The engine-portable storage format for date-time values.
     */
    private const DATABASE_FORMAT = 'Y-m-d H:i:s';

    public function toDatabase(mixed $value): mixed
    {
        return $value instanceof DateTimeImmutable ? $value->format(self::DATABASE_FORMAT) : $value;
    }

    public function fromDatabase(mixed $value): mixed
    {
        if (!is_string($value)) {
            // Null (a nullable column) and already-rich values pass through.
            return $value;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (DateMalformedStringException $exception) {
            throw OrmException::invalidTemporalValue($value, $exception);
        }
    }
}
