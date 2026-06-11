<?php

declare(strict_types=1);

namespace G\Orm\Tests\Unit;

use DateTimeImmutable;
use G\Orm\Exception\OrmException;
use G\Orm\Hydrator\Type\DateTimeImmutableConverter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The reference value converter: DateTimeImmutable to the engine-portable
 * "Y-m-d H:i:s" string and back, null/unknown passthrough in both directions,
 * and a typed failure for malformed database temporal strings.
 */
#[CoversClass(DateTimeImmutableConverter::class)]
#[CoversClass(OrmException::class)]
final class DateTimeImmutableConverterTest extends TestCase
{
    public function testConvertsToTheIsoDatabaseString(): void
    {
        $converter = new DateTimeImmutableConverter();

        self::assertSame(
            '2026-06-10 09:30:00',
            $converter->toDatabase(new DateTimeImmutable('2026-06-10 09:30:00'))
        );
    }

    public function testRoundTripPreservesTheValue(): void
    {
        $converter = new DateTimeImmutableConverter();
        $original = new DateTimeImmutable('2026-06-10 09:30:00');

        $hydrated = $converter->fromDatabase($converter->toDatabase($original));

        self::assertInstanceOf(DateTimeImmutable::class, $hydrated);
        // A fresh instance carrying the same value: never the same reference.
        self::assertNotSame($original, $hydrated);
        self::assertSame('2026-06-10 09:30:00', $hydrated->format('Y-m-d H:i:s'));
    }

    public function testNullPassesThroughInBothDirections(): void
    {
        $converter = new DateTimeImmutableConverter();

        self::assertNull($converter->toDatabase(null));
        self::assertNull($converter->fromDatabase(null));
    }

    public function testUnrecognisedValuesPassThroughUnchanged(): void
    {
        $converter = new DateTimeImmutableConverter();

        // A non-DateTimeImmutable value heading to the database is not this
        // converter's business; a non-string value coming back is not either.
        self::assertSame(42, $converter->toDatabase(42));
        self::assertSame(42, $converter->fromDatabase(42));
    }

    public function testMalformedTemporalStringRaisesATypedException(): void
    {
        $this->expectException(OrmException::class);
        $this->expectExceptionMessage('Cannot hydrate "not-a-date"');

        (new DateTimeImmutableConverter())->fromDatabase('not-a-date');
    }
}
