<?php

declare(strict_types=1);

namespace G\Orm\Event;

/**
 * Dispatched just after an entity is DELETEd, after its #[AfterDelete] hooks
 * have run.
 */
final class AfterDeleteEvent extends AbstractLifecycleEvent
{
}
