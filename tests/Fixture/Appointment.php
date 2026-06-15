<?php

declare(strict_types=1);

namespace G\Orm\Tests\Fixture;

use DateTimeImmutable;
use G\Orm\Mapping\Column;
use G\Orm\Mapping\Id;
use G\Orm\Mapping\Table;

/**
 * Fixture with a rich temporal column: the DateTimeImmutable property crosses
 * the database boundary through the built-in value converter - persisted as a
 * "Y-m-d H:i:s" string, hydrated back into a fresh instance, and snapshot as
 * its scalar form so change tracking diffs values, never references.
 */
#[Table('appointments')]
final class Appointment
{
    #[Id(generated: true)]
    #[Column]
    public int $id;

    #[Column(name: 'scheduled_at')]
    public DateTimeImmutable $scheduledAt;
}
