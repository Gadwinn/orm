<?php

declare(strict_types=1);

namespace G\Orm\Contract;

/**
 * High-level, entity-typed access layer.
 *
 * A repository wraps the Query Builder and the mapping layer to expose
 * convenient lookup and persistence operations for a single entity class.
 *
 * Identity Map cooperation (mandatory): lookups that resolve an entity by
 * its primary key (find()) must consult the Identity Map before issuing any
 * SQL. A hit returns the existing managed instance, preserving object
 * identity and avoiding a duplicate SELECT. Rows fetched from the database
 * are hydrated and then registered with the Unit Of Work (which seeds the
 * Identity Map and captures the original-state snapshot).
 *
 * Unit Of Work cooperation: persistence wrappers (save(), remove()) delegate
 * scheduling and change detection to the Unit Of Work; no hidden state
 * synchronisation happens outside the documented lifecycle.
 *
 * @template T of object
 */
interface RepositoryInterface
{
    /**
     * Find a single entity by its primary key.
     *
     * The Identity Map is consulted first; the database is queried only on a
     * miss. Returns null when no row matches.
     *
     * For a composite key, pass an associative array of column => value
     * pairs covering every key field.
     *
     * @param int|string|array<string, scalar> $id
     *
     * @return T|null
     *
     * @throws \G\Orm\Exception\QueryException
     */
    public function find(int|string|array $id): ?object;

    /**
     * Return every entity of the managed class.
     *
     * @return list<T>
     *
     * @throws \G\Orm\Exception\QueryException
     */
    public function findAll(): array;

    /**
     * Return all entities matching the given equality criteria.
     *
     * Lookups are intentionally minimalist; ordering, limiting and other
     * query mechanics belong to the Query Builder.
     *
     * @param array<string, mixed> $criteria column => value equality filters
     *
     * @return list<T>
     *
     * @throws \G\Orm\Exception\QueryException
     */
    public function findBy(array $criteria): array;

    /**
     * Return the first entity matching the given equality criteria, or null.
     *
     * @param array<string, mixed> $criteria column => value equality filters
     *
     * @return T|null
     *
     * @throws \G\Orm\Exception\QueryException
     */
    public function findOneBy(array $criteria): ?object;

    /**
     * Return a Query Builder pre-targeted at this entity's table.
     *
     * Lets callers drop down to Level 2 for query mechanics beyond the
     * minimalist finders, while staying scoped to the repository's entity.
     */
    public function query(): QueryBuilderInterface;

    /**
     * Persist an entity through the Unit Of Work.
     *
     * This is a thin wrapper that delegates to the underlying Unit Of Work:
     * the entity is scheduled and the pending changes are flushed. No SQL is
     * issued directly by the repository, so all snapshots, transactions and
     * state tracking stay on the single unified persistence pipeline. Inside
     * an active transaction this applies the write without committing it.
     *
     * @param T $entity
     *
     * @throws \G\Orm\Exception\QueryException
     * @throws \G\Orm\Exception\TransactionException
     */
    public function save(object $entity): void;

    /**
     * Schedule an entity for deletion through the Unit Of Work and flush.
     *
     * Like save(), this delegates entirely to the Unit Of Work; deletion is
     * never performed directly by the repository. Inside an active
     * transaction this applies the delete without committing it.
     *
     * @param T $entity
     *
     * @throws \G\Orm\Exception\QueryException
     * @throws \G\Orm\Exception\TransactionException
     */
    public function remove(object $entity): void;
}
