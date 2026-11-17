<?php

declare(strict_types=1);

namespace G\Orm\Trait;

use G\Orm\Contract\DbManagerInterface;
use G\Orm\Contract\QueryBuilderInterface;
use G\Orm\Contract\RepositoryInterface;
use G\Orm\Registry\OrmRegistry;

/**
 * Optional active-record style convenience helpers for entities.
 *
 * Using this trait keeps entities pure POPOs: there is no base class, no
 * injected manager, and no tracking state. The trait resolves the active
 * DbManager dynamically through OrmRegistry, so the same entity works in any
 * runtime that has bound a manager to the current context.
 *
 * Persistence always flows through the manager's Unit Of Work pipeline.
 * save() and remove() never issue SQL directly, so snapshots, change
 * detection and transactions stay unified with the rest of the ORM.
 *
 * Relation-hydration guideline:
 *
 * Relations remain explicit - the trait provides no magic loading. Write
 * relation accessors by hand as concise wrappers around a registry-resolved
 * repository, using the protected orm() helper:
 *
 *     public function address(): ?Address
 *     {
 *         return $this->orm()->repository(Address::class)->find($this->addressId);
 *     }
 *
 *     // HasMany returns the rows for the inverse side explicitly:
 *     public function orders(): array
 *     {
 *         return $this->orm()->repository(Order::class)->findBy(['customer_id' => $this->id]);
 *     }
 *
 * Keep such methods minimal, predictable and fully visible; never hide a
 * query behind property access.
 */
trait EntityTrait
{
    /**
     * Persist this entity through the active Unit Of Work and flush.
     *
     * Delegates to the manager's persistence pipeline (persist + flush);
     * inside an active transaction the write is applied but not committed.
     *
     * @throws \G\Orm\Exception\OrmException
     * @throws \G\Orm\Exception\QueryException
     * @throws \G\Orm\Exception\TransactionException
     */
    public function save(): void
    {
        $orm = OrmRegistry::get();
        $orm->persist($this);
        $orm->flush();
    }

    /**
     * Schedule this entity for deletion through the active Unit Of Work and
     * flush.
     *
     * @throws \G\Orm\Exception\OrmException
     * @throws \G\Orm\Exception\QueryException
     * @throws \G\Orm\Exception\TransactionException
     */
    public function remove(): void
    {
        $orm = OrmRegistry::get();
        $orm->remove($this);
        $orm->flush();
    }

    /**
     * Find an instance of this entity by its primary key.
     *
     * @param int|string|array<string, scalar> $id
     *
     * @return static|null
     *
     * @throws \G\Orm\Exception\OrmException
     * @throws \G\Orm\Exception\QueryException
     */
    public static function find(int|string|array $id): ?static
    {
        return self::repository()->find($id);
    }

    /**
     * Return a Query Builder for this entity, routed through its repository
     * so the builder stays scoped to the entity rather than bypassing it.
     *
     * @throws \G\Orm\Exception\OrmException
     */
    public static function query(): QueryBuilderInterface
    {
        return static::repository()->query();
    }

    /**
     * Return the repository for this entity class.
     *
     * @return RepositoryInterface<static>
     *
     * @throws \G\Orm\Exception\OrmException
     */
    public static function repository(): RepositoryInterface
    {
        return OrmRegistry::get()->repository(static::class);
    }

    /**
     * Access the active DbManager for hand-written relation accessors.
     */
    protected function orm(): DbManagerInterface
    {
        return OrmRegistry::get();
    }
}
