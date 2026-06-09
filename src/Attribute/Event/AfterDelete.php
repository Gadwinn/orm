<?php

declare(strict_types=1);

namespace G\Orm\Attribute\Event;

use Attribute;

/**
 * Marks an entity method to run immediately after its row is DELETEd.
 *
 * It receives no arguments and runs inside the flush transaction.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class AfterDelete
{
}
