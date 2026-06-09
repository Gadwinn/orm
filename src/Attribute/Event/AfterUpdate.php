<?php

declare(strict_types=1);

namespace G\Orm\Attribute\Event;

use Attribute;

/**
 * Marks an entity method to run immediately after its row is UPDATEd.
 *
 * The hook is handed the final {@see \G\Orm\Event\Changeset} that was written
 * (including any columns a #[BeforeUpdate] hook added). It runs inside the
 * flush transaction.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class AfterUpdate
{
}
