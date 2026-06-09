<?php

declare(strict_types=1);

namespace G\Orm\Contract;

/**
 * Coordinates entity persistence.
 *
 * The Unit Of Work owns three logical queues (insert / update / delete),
 * the Identity Map and the original-state snapshots used for change
 * detection.
 *
 * Change tracking relies exclusively on normalised snapshot arrays taken
 * when an entity becomes managed. No dirty flags, no tracking properties
 * and no entity inheritance are involved. At flush time the current state
 * of every managed entity is compared against its snapshot and only the
 * modified columns are written.
 *
 * Partial hydration is supported. When an entity is hydrated from a partial
 * column selection, the snapshot records which fields were actually loaded
 * (state masking). Change detection then compares only those loaded fields;
 * fields skipped during hydration are ignored unless the developer
 * explicitly initialises or modifies them. The primary key is always part
 * of the loaded set so the entity can be tracked.
 */
interface UnitOfWorkInterface
{
    /**
     * Schedule a new entity for insertion.
     *
     * Calling persist() on an already managed entity is a no-op; its
     * changes are detected automatically at flush time.
     */
    public function persist(object $entity): void;

    /**
     * Schedule a managed entity for deletion.
     */
    public function remove(object $entity): void;

    /**
     * Register an already persistent entity as managed.
     *
     * Used by repositories after hydration: the entity is added to the
     * Identity Map and its original-state snapshot is captured so that
     * subsequent changes can be detected.
     *
     * For partial hydration, $loadedFields lists the property names that
     * were actually populated by the query (the primary key is always
     * implied). The snapshot is masked to those fields and change detection
     * ignores any field outside the set. Passing null marks the entity as
     * fully hydrated.
     *
     * A field absent from $loadedFields is "unknown" and is never diffed; a
     * field present in $loadedFields whose value is null is a real database
     * NULL. This structural distinction prevents phantom updates.
     *
     * @param int|string|array<string, scalar> $id           Primary key value; a scalar
     *                                                        for a single key or an
     *                                                        associative field => value
     *                                                        map for a composite key.
     * @param list<string>|null                $loadedFields Property names populated by the
     *                                                        query, or null when fully hydrated.
     */
    public function register(object $entity, int|string|array $id, ?array $loadedFields = null): void;

    /**
     * Stop tracking an entity. Its snapshot and Identity Map entry are
     * dropped; it is removed from every pending queue.
     */
    public function detach(object $entity): void;

    /**
     * Whether the given entity instance is currently managed.
     */
    public function isManaged(object $entity): bool;

    /**
     * Compute change sets and synchronise all queues with the database.
     *
     * Implementations coordinate the necessary SQL transaction so that the
     * whole flush is atomic.
     *
     * @throws \G\Orm\Exception\QueryException
     * @throws \G\Orm\Exception\TransactionException
     */
    public function flush(): void;

    /**
     * Detach every managed entity and reset all queues and snapshots.
     *
     * Intended for batch boundaries and for state isolation between
     * requests/coroutines on persistent runtimes.
     */
    public function clear(): void;

    /**
     * Return the Identity Map backing this Unit Of Work.
     */
    public function identityMap(): IdentityMapInterface;
}
