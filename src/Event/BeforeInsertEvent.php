<?php

declare(strict_types=1);

namespace G\Orm\Event;

/**
 * Dispatched just before an entity is INSERTed, after its #[BeforeInsert]
 * hooks have run.
 */
final class BeforeInsertEvent extends AbstractLifecycleEvent
{
}
