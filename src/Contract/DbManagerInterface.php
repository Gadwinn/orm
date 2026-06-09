<?php

declare(strict_types=1);

namespace G\Orm\Contract;

/**
 * Primary entry point of the ORM.
 *
 * The DbManager exposes the four usage levels of the package:
 *
 *   1. Raw SQL              -> query(string $sql, array $bindings)
 *   2. Query Builder        -> query()
 *   3. Repositories         -> repository(EntityClass::class)
 *   4. Unit Of Work writes  -> persist() / remove() / flush()
 *
 * The implementation is responsible for wiring the active connection,
 * the Unit Of Work and the metadata layer together. Construction is a
 * concrete concern (e.g. DbManager::create(...)) and is intentionally
 * kept out of this contract.
 */
interface DbManagerInterface
{
    /**
     * Return the repository associated with the given entity class.
     *
     * A generic repository is returned unless the entity declares a custom
     * one through the #[Repository(...)] attribute.
     *
     * @template T of object
     *
     * @param class-string<T> $entityClass
     *
     * @return RepositoryInterface<T>
     *
     * @throws \G\Orm\Exception\MetadataException When the class is not a mapped entity.
     */
    public function repository(string $entityClass): RepositoryInterface;

    /**
     * Access the SQL layer.
     *
     * When called without arguments, a fresh Query Builder is returned
     * (Level 2). When a raw SQL string is provided, the statement is
     * executed immediately (Level 1) and the resulting rows are returned
     * as a list of associative arrays. Write statements return an int
     * holding the number of affected rows.
     *
     * @param string|null       $sql      Raw SQL statement, or null to obtain a builder.
     * @param array<int, mixed> $bindings Positional bindings for the raw statement.
     *
     * @return ($sql is null
     *     ? QueryBuilderInterface
     *     : list<array<string, mixed>>|int)
     *
     * @throws \G\Orm\Exception\QueryException When a raw statement fails.
     */
    public function query(?string $sql = null, array $bindings = []): QueryBuilderInterface|array|int;

    /**
     * Schedule an entity for insertion (or mark it as managed) within the
     * current Unit Of Work. No SQL is emitted until flush() is called.
     */
    public function persist(object $entity): void;

    /**
     * Schedule a managed entity for deletion within the current Unit Of
     * Work. No SQL is emitted until flush() is called.
     */
    public function remove(object $entity): void;

    /**
     * Synchronise all pending changes with the database.
     *
     * Inserts, updates (modified columns only) and deletes computed by the
     * Unit Of Work are executed. This does NOT commit an SQL transaction.
     *
     * Lifecycle rule: flush() may be called several times inside a single
     * transaction block before commit() or rollBack() is invoked. When no
     * transaction is active, a flush() applies its changes to the database
     * immediately.
     *
     * @throws \G\Orm\Exception\QueryException
     * @throws \G\Orm\Exception\TransactionException
     */
    public function flush(): void;

    /**
     * Return the Unit Of Work bound to this manager.
     */
    public function unitOfWork(): UnitOfWorkInterface;

    /**
     * Execute the given callback inside a single SQL transaction.
     *
     * The transaction is committed when the callback returns normally and
     * rolled back if it throws. The callback's return value is forwarded.
     *
     * @template TReturn
     *
     * @param callable(self): TReturn $work
     *
     * @return TReturn
     *
     * @throws \G\Orm\Exception\TransactionException
     */
    public function transaction(callable $work): mixed;

    /**
     * Begin an explicit SQL transaction.
     *
     * @throws \G\Orm\Exception\TransactionException
     */
    public function beginTransaction(): void;

    /**
     * Commit the active SQL transaction.
     *
     * @throws \G\Orm\Exception\TransactionException
     */
    public function commit(): void;

    /**
     * Roll back the active SQL transaction.
     *
     * @throws \G\Orm\Exception\TransactionException
     */
    public function rollBack(): void;
}
