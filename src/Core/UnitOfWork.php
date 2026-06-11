<?php

declare(strict_types=1);

namespace G\Orm\Core;

use G\Orm\Attribute\Event\AfterDelete;
use G\Orm\Attribute\Event\AfterInsert;
use G\Orm\Attribute\Event\AfterUpdate;
use G\Orm\Attribute\Event\BeforeDelete;
use G\Orm\Attribute\Event\BeforeInsert;
use G\Orm\Attribute\Event\BeforeUpdate;
use G\Orm\Compiler\SqlCompilerInterface;
use G\Orm\Contract\Driver\ConnectionInterface;
use G\Orm\Contract\EntityMetadataInterface;
use G\Orm\Contract\HydratorInterface;
use G\Orm\Contract\IdentityMapInterface;
use G\Orm\Contract\MetadataReaderInterface;
use G\Orm\Contract\UnitOfWorkInterface;
use G\Orm\Event\AfterDeleteEvent;
use G\Orm\Event\AfterInsertEvent;
use G\Orm\Event\AfterUpdateEvent;
use G\Orm\Event\BeforeDeleteEvent;
use G\Orm\Event\BeforeInsertEvent;
use G\Orm\Event\BeforeUpdateEvent;
use G\Orm\Event\Changeset;
use G\Orm\Exception\OrmException;
use G\Orm\Query\QueryBuilder;
use Psr\EventDispatcher\EventDispatcherInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use Throwable;

/**
 * Snapshot-based Unit Of Work.
 *
 * Three intentions are tracked by instance: pending inserts, pending deletes
 * and the managed set (persistent entities with an original-state snapshot).
 * Updates are never stored as an intention: there are no dirty flags. At
 * flush time every managed entity's current state is diffed against its
 * snapshot and only the changed, non-key columns are written.
 *
 * Entities are keyed by spl_object_id() so identity is by instance, never by
 * value. Partial hydration is honoured through per-entity loaded-field masks:
 * a column absent from the mask is "unknown" and never diffed, which prevents
 * phantom updates from never-loaded columns.
 *
 * A dual-layer lifecycle wraps each write. Internal hooks — methods flagged
 * with the #[Before*]/#[After*] attributes — always fire; an optional PSR-14
 * dispatcher additionally emits the matching events. For updates the pipeline
 * is synchronised: the user-driven {@see Changeset} is handed to #[BeforeUpdate]
 * hooks, the entity is then re-diffed so columns the hook mutated join the SET
 * clause, and only that final change set is dispatched and written. Hooks for
 * a managed entity fire only when it actually has pending changes.
 *
 * Hook discovery is reflection-based but paid for once per class: the first
 * row of a type is scanned and its lifecycle methods indexed by attribute into
 * {@see self::$hookCache}; every subsequent row of that type runs straight from
 * the cache without touching reflection again.
 */
final class UnitOfWork implements UnitOfWorkInterface
{
    /**
     * The full set of lifecycle attributes a class is indexed against on its
     * first encounter.
     *
     * @var list<class-string>
     */
    private const LIFECYCLE_ATTRIBUTES = [
        BeforeInsert::class,
        AfterInsert::class,
        BeforeUpdate::class,
        AfterUpdate::class,
        BeforeDelete::class,
        AfterDelete::class,
    ];

    /**
     * Native scalar type name a generated identifier is cast to when the
     * mapped primary-key property is typed as int.
     */
    private const PHP_TYPE_INT = 'int';

    /**
     * @var array<int, object>
     */
    private array $inserts = [];

    /**
     * @var array<int, object>
     */
    private array $deletes = [];

    /**
     * @var array<int, object>
     */
    private array $managed = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $snapshots = [];

    /**
     * @var array<int, list<string>|null>
     */
    private array $loadedFields = [];

    /**
     * Memoised lifecycle-hook index: entity class => attribute class => the
     * reflected methods carrying that attribute, in declaration order. Built
     * lazily, once per class, on first use.
     *
     * @var array<class-string, array<class-string, list<ReflectionMethod>>>
     */
    private array $hookCache = [];

    /**
     * Re-entrancy guard. Lifecycle hooks and PSR-14 listeners run inside the
     * flush loop while the intention queues are still populated; a hook that
     * called flush() again would re-iterate those queues — double-writing rows
     * and, for a self-triggering hook, recursing without bound until memory is
     * exhausted. The guard turns that into an explicit OrmException instead of
     * silent state duplication.
     */
    private bool $flushing = false;

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly SqlCompilerInterface $compiler,
        private readonly MetadataReaderInterface $metadata,
        private readonly HydratorInterface $hydrator,
        private readonly IdentityMapInterface $identityMap,
        private readonly ?EventDispatcherInterface $dispatcher = null,
    ) {
    }

    public function persist(object $entity): void
    {
        $objectId = spl_object_id($entity);
        unset($this->deletes[$objectId]);

        if (isset($this->managed[$objectId]) || isset($this->inserts[$objectId])) {
            return;
        }

        $this->inserts[$objectId] = $entity;
    }

    public function remove(object $entity): void
    {
        $objectId = spl_object_id($entity);

        if (isset($this->inserts[$objectId])) {
            unset($this->inserts[$objectId]);

            return;
        }

        $this->deletes[$objectId] = $entity;
    }

    public function register(object $entity, int|string|array $id, ?array $loadedFields = null): void
    {
        $objectId = spl_object_id($entity);

        $this->identityMap->add($entity, $id);
        $this->managed[$objectId] = $entity;
        $this->snapshots[$objectId] = $this->hydrator->extractState($entity, $loadedFields);
        $this->loadedFields[$objectId] = $loadedFields;
    }

    public function detach(object $entity): void
    {
        $objectId = spl_object_id($entity);

        unset(
            $this->inserts[$objectId],
            $this->deletes[$objectId],
            $this->managed[$objectId],
            $this->snapshots[$objectId],
            $this->loadedFields[$objectId],
        );

        $this->identityMap->remove($entity);
    }

    public function isManaged(object $entity): bool
    {
        $objectId = spl_object_id($entity);

        return isset($this->managed[$objectId])
            || isset($this->inserts[$objectId])
            || isset($this->deletes[$objectId]);
    }

    public function flush(): void
    {
        if ($this->flushing) {
            throw OrmException::reentrantFlush();
        }

        $updates = $this->computeUpdates();

        if ($this->inserts === [] && $updates === [] && $this->deletes === []) {
            return;
        }

        $ownsTransaction = !$this->connection->inTransaction();
        if ($ownsTransaction) {
            $this->connection->beginTransaction();
        }

        $this->flushing = true;

        try {
            $insertSync = $this->executeInserts();
            $updateSync = $this->executeUpdates($updates);
            $deleteSync = $this->executeDeletes();

            if ($ownsTransaction) {
                $this->connection->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                if ($this->connection->inTransaction()) {
                    $this->connection->rollBack();
                }

                // The local transaction this flush owned has been rolled
                // back; purge the now-stale in-memory state so the next
                // operation re-fetches clean rows. When participating in an
                // outer transaction, the owner handles the rollback and the
                // purge instead.
                $this->clear();
            }

            throw $exception;
        } finally {
            // Released even on failure so a rolled-back flush never leaves the
            // unit of work permanently wedged in the flushing state.
            $this->flushing = false;
        }

        $this->synchronise($insertSync, $updateSync, $deleteSync);
    }

    public function clear(): void
    {
        $this->inserts = [];
        $this->deletes = [];
        $this->managed = [];
        $this->snapshots = [];
        $this->loadedFields = [];

        $this->identityMap->clear();
    }

    public function identityMap(): IdentityMapInterface
    {
        return $this->identityMap;
    }

    /**
     * Diff every managed entity against its snapshot and return the pending
     * UPDATE descriptors. Primary-key columns are never part of the SET.
     *
     * @return list<array{
     *     objectId: int,
     *     table: string,
     *     changes: array<string, mixed>,
     *     where: array<string, mixed>
     * }>
     */
    private function computeUpdates(): array
    {
        $updates = [];

        foreach ($this->managed as $objectId => $entity) {
            if (isset($this->deletes[$objectId])) {
                continue;
            }

            $schema = $this->metadata->getSchemaFor($entity::class);
            $current = $this->hydrator->extractState($entity, $this->loadedFields[$objectId] ?? null);
            $changes = $this->changedColumns($schema, $current, $this->snapshots[$objectId] ?? []);

            if ($changes === []) {
                continue;
            }

            $where = [];
            foreach ($this->primaryKeyColumns($schema) as $column) {
                // A null or missing key would compile to "pk = NULL", which
                // matches no row: the UPDATE would silently write nothing.
                if (!isset($current[$column])) {
                    throw OrmException::entityWithoutIdentifier($entity::class, 'update');
                }

                $where[$column] = $current[$column];
            }

            $updates[] = [
                'objectId' => $objectId,
                'table' => $schema->getTableName(),
                'changes' => $changes,
                'where' => $where,
            ];
        }

        return $updates;
    }

    /**
     * Diff a freshly extracted state against a snapshot, returning only the
     * changed, non-key columns. Shared by the initial dirty scan and the
     * post-#[BeforeUpdate] re-evaluation.
     *
     * @param array<string, mixed> $current  state keyed by column name
     * @param array<string, mixed> $snapshot baseline state keyed by column name
     *
     * @return array<string, mixed>
     */
    private function changedColumns(EntityMetadataInterface $schema, array $current, array $snapshot): array
    {
        $primaryKeyColumns = $this->primaryKeyColumns($schema);

        $changes = [];
        foreach ($current as $column => $value) {
            if (in_array($column, $primaryKeyColumns, true)) {
                continue;
            }

            if (!array_key_exists($column, $snapshot) || $snapshot[$column] !== $value) {
                $changes[$column] = $value;
            }
        }

        return $changes;
    }

    /**
     * Execute pending INSERTs, firing the insert lifecycle hooks and events
     * around each row and writing back any generated identifier so #[AfterInsert]
     * hooks observe it.
     *
     * @return list<array{objectId: int, entity: object}>
     */
    private function executeInserts(): array
    {
        $sync = [];

        foreach ($this->inserts as $objectId => $entity) {
            // Before reading state, so a hook that sets a creation timestamp or
            // derived column has it persisted by this very INSERT.
            $this->runHooks($entity, BeforeInsert::class);
            $this->dispatch(new BeforeInsertEvent($entity));

            $schema = $this->metadata->getSchemaFor($entity::class);
            $state = $this->hydrator->extractState($entity, null);

            $this->writeBuilder()
                ->insert($schema->getTableName())
                ->values($state)
                ->execute();

            // Engine note: lastInsertId() without a sequence name covers
            // MySQL/MariaDB/SQLite. PostgreSQL requires the sequence name (or
            // INSERT ... RETURNING); generated identifiers are not yet
            // supported there — assign the key explicitly instead.
            $primaryKeyFields = $schema->getPrimaryKeyFields();
            if (count($primaryKeyFields) === 1 && $this->hydrator->extractIdentifier($entity) === null) {
                $this->assignGeneratedId($entity, $primaryKeyFields[0], $this->connection->lastInsertId());
            }

            // Register identity the moment the id is known, inside the same
            // transaction, so an #[AfterInsert] hook or PSR-14 listener that
            // resolves the entity by id receives this very instance rather than
            // hydrating a duplicate. A later rollback purges the map via clear().
            $identifier = $this->hydrator->extractIdentifier($entity);
            if ($identifier !== null) {
                $this->identityMap->add($entity, $identifier);
            }

            $this->runHooks($entity, AfterInsert::class);
            $this->dispatch(new AfterInsertEvent($entity));

            $sync[] = ['objectId' => $objectId, 'entity' => $entity];
        }

        return $sync;
    }

    /**
     * Execute pending UPDATEs through the synchronised hook pipeline:
     *
     *   1. Hand the user-driven change set to #[BeforeUpdate] hooks.
     *   2. Re-diff the entity so any column a hook mutated (e.g. an audit
     *      timestamp) joins the SET clause.
     *   3. Dispatch BeforeUpdateEvent with that final change set.
     *   4. Write the row, then run #[AfterUpdate] hooks and dispatch
     *      AfterUpdateEvent.
     *
     * Returns the post-flush snapshot for each touched entity.
     *
     * @param list<array{
     *     objectId: int,
     *     table: string,
     *     changes: array<string, mixed>,
     *     where: array<string, mixed>
     * }> $updates
     *
     * @return list<array{objectId: int, current: array<string, mixed>}>
     */
    private function executeUpdates(array $updates): array
    {
        $sync = [];

        foreach ($updates as $update) {
            $objectId = $update['objectId'];
            $entity = $this->managed[$objectId] ?? null;
            if ($entity === null) {
                continue;
            }

            $schema = $this->metadata->getSchemaFor($entity::class);

            // The hook reacts to what the caller actually changed.
            $this->runHooks($entity, BeforeUpdate::class, [new Changeset($update['changes'])]);

            // Re-evaluate against the same snapshot so the hook's own mutations
            // are folded in.
            $current = $this->hydrator->extractState($entity, $this->loadedFields[$objectId] ?? null);
            $changes = $this->changedColumns($schema, $current, $this->snapshots[$objectId] ?? []);

            $sync[] = ['objectId' => $objectId, 'current' => $current];

            if ($changes === []) {
                continue;
            }

            $changeset = new Changeset($changes);
            $this->dispatch(new BeforeUpdateEvent($entity, $changeset));

            $builder = $this->writeBuilder()
                ->update($update['table'])
                ->set($changes);

            foreach ($update['where'] as $column => $value) {
                $builder->where($column, '=', $value);
            }

            if ($builder->execute() === 0) {
                // The row vanished (or already drifted to these exact values)
                // between load and flush: aborting surfaces the lost update
                // instead of refreshing the snapshot over a fiction.
                throw OrmException::writeAffectedNoRow($entity::class, 'UPDATE');
            }

            $this->runHooks($entity, AfterUpdate::class, [$changeset]);
            $this->dispatch(new AfterUpdateEvent($entity, $changeset));
        }

        return $sync;
    }

    /**
     * Execute pending DELETEs and return the sync descriptor of each processed
     * entity, so synchronise() can de-register exactly those — a removal staged
     * by a hook during this very loop stays queued for the next flush.
     *
     * @return list<array{objectId: int, entity: object}>
     */
    private function executeDeletes(): array
    {
        $sync = [];

        foreach ($this->deletes as $objectId => $entity) {
            // Guarded before any hook fires: an incomplete primary key would
            // compile to "pk = NULL", a DELETE that matches nothing while
            // hooks still ran and the entity was still de-registered as if it
            // had been removed.
            if ($this->hydrator->extractIdentifier($entity) === null) {
                throw OrmException::entityWithoutIdentifier($entity::class, 'delete');
            }

            $this->runHooks($entity, BeforeDelete::class);
            $this->dispatch(new BeforeDeleteEvent($entity));

            $schema = $this->metadata->getSchemaFor($entity::class);
            $state = $this->hydrator->extractState($entity, null);

            $builder = $this->writeBuilder()
                ->delete()
                ->from($schema->getTableName());

            foreach ($this->primaryKeyColumns($schema) as $column) {
                $builder->where($column, '=', $state[$column] ?? null);
            }

            if ($builder->execute() === 0) {
                throw OrmException::writeAffectedNoRow($entity::class, 'DELETE');
            }

            $this->runHooks($entity, AfterDelete::class);
            $this->dispatch(new AfterDeleteEvent($entity));

            $sync[] = ['objectId' => $objectId, 'entity' => $entity];
        }

        return $sync;
    }

    /**
     * Apply in-memory synchronisation after a successful flush: promote inserts
     * into the managed set, refresh snapshots and de-register exactly the
     * processed intentions. De-registration is targeted by object id — never a
     * bulk queue reset — so an intention staged by a lifecycle hook or PSR-14
     * listener *during* the flush survives in its queue and is executed by the
     * next flush instead of being silently destroyed. Generated identifiers are
     * written back during the insert pass, and the identity map is already
     * seeded there too; the add() below is a safe, idempotent re-affirmation
     * (same key, same instance) covering any entity whose identifier only
     * became resolvable after its hooks ran.
     *
     * @param list<array{objectId: int, entity: object}>                    $insertSync
     * @param list<array{objectId: int, current: array<string, mixed>}>     $updateSync
     * @param list<array{objectId: int, entity: object}>                    $deleteSync
     */
    private function synchronise(array $insertSync, array $updateSync, array $deleteSync): void
    {
        foreach ($insertSync as $sync) {
            $entity = $sync['entity'];

            $identifier = $this->hydrator->extractIdentifier($entity);
            if ($identifier !== null) {
                $this->identityMap->add($entity, $identifier);
            }

            $objectId = $sync['objectId'];
            $this->managed[$objectId] = $entity;
            $this->snapshots[$objectId] = $this->hydrator->extractState($entity, null);
            $this->loadedFields[$objectId] = null;

            unset($this->inserts[$objectId]);
        }

        foreach ($updateSync as $update) {
            $this->snapshots[$update['objectId']] = $update['current'];
        }

        foreach ($deleteSync as $sync) {
            $objectId = $sync['objectId'];

            $this->identityMap->remove($sync['entity']);
            unset(
                $this->deletes[$objectId],
                $this->managed[$objectId],
                $this->snapshots[$objectId],
                $this->loadedFields[$objectId],
            );
        }
    }

    private function assignGeneratedId(object $entity, string $primaryKeyField, int|string $generatedId): void
    {
        $property = new ReflectionProperty($entity, $primaryKeyField);
        $type = $property->getType();

        $value = $type instanceof ReflectionNamedType && $type->getName() === self::PHP_TYPE_INT
            ? (int) $generatedId
            : $generatedId;

        $property->setValue($entity, $value);
    }

    /**
     * @return list<string>
     */
    private function primaryKeyColumns(EntityMetadataInterface $schema): array
    {
        $columns = [];
        foreach ($schema->getPrimaryKeyFields() as $field) {
            $columns[] = $schema->getProperty($field)?->getColumnName() ?? $field;
        }

        return $columns;
    }

    /**
     * Invoke every method on the entity carrying the given lifecycle attribute,
     * in declaration order, forwarding the supplied arguments. The methods are
     * resolved from the per-class cache, so only the first row of each type
     * pays for reflection.
     *
     * @param class-string $attribute
     * @param list<mixed>  $arguments
     */
    private function runHooks(object $entity, string $attribute, array $arguments = []): void
    {
        foreach ($this->hooksFor($entity::class, $attribute) as $method) {
            $method->invokeArgs($entity, $arguments);
        }
    }

    /**
     * The cached hook methods declared for the given attribute on a class,
     * indexing the class on first request.
     *
     * @param class-string $class
     * @param class-string $attribute
     *
     * @return list<ReflectionMethod>
     */
    private function hooksFor(string $class, string $attribute): array
    {
        if (!isset($this->hookCache[$class])) {
            $this->hookCache[$class] = $this->indexHooks($class);
        }

        return $this->hookCache[$class][$attribute] ?? [];
    }

    /**
     * Scan a class exactly once, mapping each lifecycle attribute to the
     * methods carrying it. Classes with no hooks still produce an entry (with
     * empty method lists), so the scan never repeats for them either.
     *
     * @param class-string $class
     *
     * @return array<class-string, list<ReflectionMethod>>
     */
    private function indexHooks(string $class): array
    {
        /** @var array<class-string, list<ReflectionMethod>> $index */
        $index = array_fill_keys(self::LIFECYCLE_ATTRIBUTES, []);

        $reflection = new ReflectionClass($class);
        foreach ($reflection->getMethods() as $method) {
            foreach ($method->getAttributes() as $attribute) {
                $name = $attribute->getName();
                if (array_key_exists($name, $index)) {
                    $index[$name][] = $method;
                }
            }
        }

        return $index;
    }

    /**
     * Dispatch a lifecycle event when a PSR-14 dispatcher is configured.
     */
    private function dispatch(object $event): void
    {
        $this->dispatcher?->dispatch($event);
    }

    private function writeBuilder(): QueryBuilder
    {
        return new QueryBuilder($this->compiler, $this->connection, $this->metadata);
    }
}
