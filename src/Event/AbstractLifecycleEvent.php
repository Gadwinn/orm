<?php

declare(strict_types=1);

namespace G\Orm\Event;

/**
 * Base for the entity lifecycle events.
 *
 * These are plain, PSR-14 compatible event objects: they implement no marker
 * interface and are dispatched as-is through any
 * Psr\EventDispatcher\EventDispatcherInterface. Each one wraps the entity the
 * flush is acting upon.
 */
abstract class AbstractLifecycleEvent
{
    public function __construct(
        private readonly object $entity,
    ) {
    }

    /**
     * The entity this lifecycle event concerns.
     */
    public function getEntity(): object
    {
        return $this->entity;
    }
}
