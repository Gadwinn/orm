<?php

declare(strict_types=1);

namespace G\Orm\Attribute\Event;

use Attribute;

/**
 * Marks an entity method to run immediately after its row is INSERTed.
 *
 * The generated identifier has already been written back, so the hook can read
 * it. It receives no arguments and runs inside the flush transaction.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class AfterInsert
{
}
