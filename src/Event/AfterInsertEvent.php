<?php

declare(strict_types=1);

namespace G\Orm\Event;

/**
 * Dispatched just after an entity is INSERTed and its generated identifier has
 * been written back, after its #[AfterInsert] hooks have run.
 */
final class AfterInsertEvent extends AbstractLifecycleEvent
{
}
