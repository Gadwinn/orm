<?php

declare(strict_types=1);

namespace G\Orm\Attribute\Event;

use Attribute;

/**
 * Marks an entity method to run immediately before its row is UPDATEd.
 *
 * The hook is handed the {@see \G\Orm\Event\Changeset} of user-driven changes,
 * so it can react conditionally (e.g. only re-hash a password when it actually
 * changed). Any column the hook itself mutates is re-diffed afterwards and
 * folded into the final SET clause. Declare it as:
 *
 *     #[BeforeUpdate]
 *     public function touch(Changeset $changes): void
 *
 * It runs inside the flush transaction.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class BeforeUpdate
{
}
