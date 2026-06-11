<?php

declare(strict_types=1);

namespace G\Orm\Tests\Integration;

use DateTimeImmutable;
use G\Orm\Hydrator\Hydrator;
use G\Orm\Hydrator\Type\DateTimeImmutableConverter;
use G\Orm\Tests\Fixture\Appointment;
use G\Orm\Tests\OrmTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * End-to-end life of a DateTimeImmutable column through the converter seam:
 * bound as a scalar string on INSERT, hydrated back into a fresh instance,
 * and change-tracked against its scalar snapshot — so replacing the object
 * with an equal instance is silent, and replacing it with a different one
 * diffs into a single-column UPDATE.
 */
#[CoversClass(Hydrator::class)]
#[CoversClass(DateTimeImmutableConverter::class)]
final class DateTimeHydrationTest extends OrmTestCase
{
    public function testPersistsTheTemporalPropertyAsAnIsoString(): void
    {
        $appointment = new Appointment();
        $appointment->scheduledAt = new DateTimeImmutable('2026-06-10 09:30:00');

        $this->manager->persist($appointment);
        $this->manager->flush();

        // The parameterised INSERT carried the scalar form, not the object.
        $rows = $this->manager->query('SELECT scheduled_at FROM appointments WHERE id = ?', [$appointment->id]);
        self::assertSame([['scheduled_at' => '2026-06-10 09:30:00']], $rows);
    }

    public function testHydratesTheTemporalColumnIntoADateTimeImmutable(): void
    {
        $this->seedRow("INSERT INTO appointments (id, scheduled_at) VALUES (1, '2026-06-10 09:30:00')");

        $appointment = $this->manager->repository(Appointment::class)->find(1);

        self::assertInstanceOf(Appointment::class, $appointment);
        self::assertInstanceOf(DateTimeImmutable::class, $appointment->scheduledAt);
        self::assertSame('2026-06-10 09:30:00', $appointment->scheduledAt->format('Y-m-d H:i:s'));
    }

    public function testEqualReplacementInstanceIsNotAPhantomChange(): void
    {
        [$manager, $recording] = $this->recordingManager();
        $this->seedRow("INSERT INTO appointments (id, scheduled_at) VALUES (1, '2026-06-10 09:30:00')");

        $appointment = $manager->repository(Appointment::class)->find(1);
        self::assertInstanceOf(Appointment::class, $appointment);

        // A different object, the same value: the snapshot holds the scalar
        // form, so the diff sees no change and the flush emits nothing.
        $recording->reset();
        $appointment->scheduledAt = new DateTimeImmutable('2026-06-10 09:30:00');
        $manager->flush();

        self::assertSame([], $recording->statements());
    }

    public function testChangedTemporalValueDiffsIntoASingleColumnUpdate(): void
    {
        [$manager, $recording] = $this->recordingManager();
        $this->seedRow("INSERT INTO appointments (id, scheduled_at) VALUES (1, '2026-06-10 09:30:00')");

        $appointment = $manager->repository(Appointment::class)->find(1);
        self::assertInstanceOf(Appointment::class, $appointment);

        $recording->reset();
        $appointment->scheduledAt = new DateTimeImmutable('2027-01-05 18:00:00');
        $manager->flush();

        self::assertSame(
            ['UPDATE appointments SET scheduled_at = ? WHERE id = ?'],
            $recording->statements()
        );

        $rows = $this->manager->query('SELECT scheduled_at FROM appointments WHERE id = ?', [1]);
        self::assertSame([['scheduled_at' => '2027-01-05 18:00:00']], $rows);
    }
}
