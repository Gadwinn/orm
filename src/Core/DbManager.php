<?php

declare(strict_types=1);

namespace G\Orm\Core;

use G\Orm\Compiler\SqlCompiler;
use G\Orm\Compiler\SqlCompilerInterface;
use G\Orm\Contract\DbManagerInterface;
use G\Orm\Contract\Driver\ConnectionInterface;
use G\Orm\Contract\HydratorInterface;
use G\Orm\Contract\MetadataReaderInterface;
use G\Orm\Contract\QueryBuilderInterface;
use G\Orm\Contract\RepositoryInterface;
use G\Orm\Contract\UnitOfWorkInterface;
use G\Orm\Hydrator\Hydrator;
use G\Orm\Metadata\MetadataReader;
use G\Orm\Query\QueryBuilder;
use G\Orm\Repository\Repository;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;

/**
 * Central orchestration hub and primary entry point of the ORM.
 *
 * It wires the connection, metadata, hydrator and Unit Of Work together and
 * exposes the four usage levels: raw SQL, query builder, repositories and
 * Unit Of Work writes. Repositories are instantiated lazily and cached per
 * entity class. The identity map is owned by the Unit Of Work and reached
 * through it, so a single instance backs the whole manager.
 */
final class DbManager implements DbManagerInterface
{
    /**
     * @var array<class-string, RepositoryInterface<object>>
     */
    private array $repositories = [];

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly MetadataReaderInterface $metadata,
        private readonly HydratorInterface $hydrator,
        private readonly SqlCompilerInterface $compiler,
        private readonly UnitOfWorkInterface $unitOfWork,
    ) {
    }

    /**
     * Wire a manager with the default engine-neutral components around an
     * open connection. Construction stays a concrete concern kept out of the
     * contract.
     *
     * An optional PSR-14 dispatcher enables external lifecycle events; the
     * in-entity attribute hooks fire regardless of whether one is supplied.
     */
    public static function create(ConnectionInterface $connection, ?EventDispatcherInterface $dispatcher = null): self
    {
        $metadata = new MetadataReader();
        $hydrator = new Hydrator($metadata);
        // The compiler speaks the dialect of the engine this connection is
        // bound to; the platform was resolved once at connection time.
        $compiler = new SqlCompiler($connection->getPlatform());
        $identityMap = new IdentityMap();
        $unitOfWork = new UnitOfWork($connection, $compiler, $metadata, $hydrator, $identityMap, $dispatcher);

        return new self($connection, $metadata, $hydrator, $compiler, $unitOfWork);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $entityClass
     *
     * @return RepositoryInterface<T>
     */
    public function repository(string $entityClass): RepositoryInterface
    {
        $repository = $this->repositories[$entityClass] ?? $this->createRepository($entityClass);
        $this->repositories[$entityClass] = $repository;

        /** @var RepositoryInterface<T> $repository */
        return $repository;
    }

    public function query(?string $sql = null, array $bindings = []): QueryBuilderInterface|array|int
    {
        if ($sql === null) {
            return new QueryBuilder(
                $this->compiler,
                $this->connection,
                $this->metadata,
                $this->hydrator,
                $this->unitOfWork,
            );
        }

        $result = $this->connection->prepare($sql)->execute($bindings);

        // The driver, not a keyword heuristic, decides between a fetched
        // result set and an affected-row count — so leading comments,
        // parenthesised SELECTs and data-modifying CTEs are all classified
        // by what they actually produced.
        return $result->isResultSet() ? $result->fetchAll() : $result->rowCount();
    }

    public function persist(object $entity): void
    {
        $this->unitOfWork->persist($entity);
    }

    public function remove(object $entity): void
    {
        $this->unitOfWork->remove($entity);
    }

    public function flush(): void
    {
        $this->unitOfWork->flush();
    }

    public function unitOfWork(): UnitOfWorkInterface
    {
        return $this->unitOfWork;
    }

    public function transaction(callable $work): mixed
    {
        // Same ownership rule as the Unit Of Work's flush(): only the
        // outermost call opens and resolves the physical transaction, so
        // transaction() calls nest by participating in the enclosing one. An
        // inner failure propagates to the owner, which rolls back and purges.
        $ownsTransaction = !$this->connection->inTransaction();
        if ($ownsTransaction) {
            $this->connection->beginTransaction();
        }

        try {
            $result = $work($this);

            if ($ownsTransaction) {
                $this->connection->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                if ($this->connection->inTransaction()) {
                    $this->connection->rollBack();
                }

                $this->purgeStaleState();
            }

            throw $exception;
        }

        return $result;
    }

    public function beginTransaction(): void
    {
        $this->connection->beginTransaction();
    }

    public function commit(): void
    {
        $this->connection->commit();
    }

    public function rollBack(): void
    {
        $this->connection->rollBack();
        $this->purgeStaleState();
    }

    /**
     * Purge in-memory state after a database rollback.
     *
     * A rollback reverts the database but leaves the PHP entities holding
     * their mutated, now-uncommitted values. Detaching every managed entity
     * (which also empties the identity map) forces the next operation to
     * re-fetch clean rows, eliminating the memory-vs-database desync.
     */
    private function purgeStaleState(): void
    {
        $this->unitOfWork->clear();
        $this->unitOfWork->identityMap()->clear();
    }

    /**
     * @param class-string $entityClass
     *
     * @return RepositoryInterface<object>
     */
    private function createRepository(string $entityClass): RepositoryInterface
    {
        $repositoryClass = $this->metadata->getSchemaFor($entityClass)->getRepositoryClass();

        if ($repositoryClass !== null && is_subclass_of($repositoryClass, Repository::class)) {
            return new $repositoryClass(
                $entityClass,
                $this->connection,
                $this->compiler,
                $this->metadata,
                $this->hydrator,
                $this->unitOfWork,
            );
        }

        return new Repository(
            $entityClass,
            $this->connection,
            $this->compiler,
            $this->metadata,
            $this->hydrator,
            $this->unitOfWork,
        );
    }
}
