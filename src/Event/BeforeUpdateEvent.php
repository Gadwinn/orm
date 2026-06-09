<?php

declare(strict_types=1);

namespace G\Orm\Event;

/**
 * Dispatched just before an entity is UPDATEd, after its #[BeforeUpdate] hooks
 * have run and the change set has been re-evaluated.
 *
 * Carries the final {@see Changeset} — the columns actually about to be
 * written, including any a hook contributed.
 */
final class BeforeUpdateEvent extends AbstractLifecycleEvent
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
