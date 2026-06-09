<?php

declare(strict_types=1);

namespace G\Orm\Event;

/**
 * Dispatched just before an entity is DELETEd, after its #[BeforeDelete] hooks
 * have run.
 */
final class BeforeDeleteEvent extends AbstractLifecycleEvent
{
}
