<?php

declare(strict_types=1);

namespace G\Orm\Attribute\Event;

use Attribute;

/**
 * Marks an entity method to run immediately before its row is INSERTed.
 *
 * The hook fires before the entity state is read, so any column it sets
 * (a creation timestamp, a derived value) is part of the INSERT. It receives
 * no arguments and runs inside the flush transaction.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class BeforeInsert
{
}
