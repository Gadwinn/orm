<?php

declare(strict_types=1);

namespace G\Orm\Attribute\Event;

use Attribute;

/**
 * Marks an entity method to run immediately before its row is DELETEd.
 *
 * It receives no arguments and runs inside the flush transaction, so throwing
 * from it aborts the deletion.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class BeforeDelete
{
}
