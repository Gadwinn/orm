<?php

declare(strict_types=1);

namespace G\Orm\Tests\Fixture;

use DateTimeInterface;
use G\Orm\Mapping\Column;
use G\Orm\Mapping\Id;
use G\Orm\Mapping\Table;

/**
 * Entity fixture for the hydration edge cases: an int-backed enum (read back
 * as a string by some drivers), a property typed with DateTimeInterface, and
 * a static property that must never be mapped.
 */
#[Table('visits')]
final class Visit
{
    #[Column]
    public static int $instances = 0;

    #[Id]
    #[Column]
    public int $id;

    #[Column]
    public Level $level;

    #[Column(name: 'seen_at')]
    public ?DateTimeInterface $seenAt;
}
