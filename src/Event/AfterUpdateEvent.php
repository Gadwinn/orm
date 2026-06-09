<?php

declare(strict_types=1);

namespace G\Orm\Event;

/**
 * Dispatched just after an entity is UPDATEd, after its #[AfterUpdate] hooks
 * have run.
 *
 * Carries the final {@see Changeset} that was written.
 */
final class AfterUpdateEvent extends AbstractLifecycleEvent
{
    public function __construct(object $entity, private readonly Changeset $changeset)
    {
        parent::__construct($entity);
    }

    public function getChangeset(): Changeset
    {
        return $this->changeset;
    }
}
