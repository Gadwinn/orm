<?php

declare(strict_types=1);

namespace G\Orm\Repository;

use G\Orm\Compiler\SqlCompilerInterface;
use G\Orm\Contract\Driver\ConnectionInterface;
use G\Orm\Contract\HydratorInterface;
use G\Orm\Contract\MetadataReaderInterface;
use G\Orm\Contract\QueryBuilderInterface;
use G\Orm\Contract\RepositoryInterface;
use G\Orm\Contract\UnitOfWorkInterface;
use G\Orm\Query\QueryBuilder;

/**
 * Generic, entity-typed repository.
 *
 * Lookups by primary key consult the identity map before issuing SQL so a row
 * is represented by a single instance for the lifetime of the Unit Of Work.
 * Every hydrated row is registered with the Unit Of Work, which seeds the
 * identity map and captures the original-state snapshot. Persistence is
 * delegated wholesale to the Unit Of Work; the repository never emits write
 * SQL on its own.
 *
 * Custom repositories extend this class so they inherit the constructor and
 * can be auto-wired by the DbManager.
 *
 * @template T of object
 *
 * @implements RepositoryInterface<T>
 */
class Repository implements RepositoryInterface
{
    /**
     * @param class-string<T> $entityClass
     */
    public function __construct(
        private readonly string $entityClass,
        private readonly ConnectionInterface $connection,
        private readonly SqlCompilerInterface $compiler,
        private readonly MetadataReaderInterface $metadata,
        private readonly HydratorInterface $hydrator,
        private readonly UnitOfWorkInterface $unitOfWork,
    ) {
    }

    public function find(int|string|array $id): ?object
    {
        $identityMap = $this->unitOfWork->identityMap();
        if ($identityMap->has($this->entityClass, $id)) {
            return $identityMap->get($this->entityClass, $id);
        }

        $builder = $this->query();
        foreach ($this->identifierCriteria($id) as $column => $value) {
            $builder->where($column, '=', $value);
        }

        $row = $builder->first();
        if ($row === null) {
            return null;
        }

        return $this->hydrateAndTrack($row);
    }

    public function findAll(): array
    {
        return $this->hydrateAndTrackAll($this->query()->get());
    }

    public function findBy(array $criteria): array
    {
        return $this->hydrateAndTrackAll($this->applyCriteria($this->query(), $criteria)->get());
    }

    public function findOneBy(array $criteria): ?object
    {
        $row = $this->applyCriteria($this->query(), $criteria)->first();

        return $row === null ? null : $this->hydrateAndTrack($row);
    }

    public function query(): QueryBuilderInterface
    {
        return (new QueryBuilder($this->compiler, $this->connection, $this->metadata))
            ->from($this->entityClass);
    }

    public function save(object $entity): void
    {
        $this->unitOfWork->persist($entity);
        $this->unitOfWork->flush();
    }

    public function remove(object $entity): void
    {
        $this->unitOfWork->remove($entity);
        $this->unitOfWork->flush();
    }

    /**
     * Build the column => value WHERE map for a primary key lookup.
     *
     * @param int|string|array<string, scalar> $id
     *
     * @return array<string, scalar>
     */
    private function identifierCriteria(int|string|array $id): array
    {
        if (is_array($id)) {
            return $id;
        }

        $schema = $this->metadata->getSchemaFor($this->entityClass);
        $field = $schema->getPrimaryKeyFields()[0];
        $column = $schema->getProperty($field)?->getColumnName() ?? $field;

        return [$column => $id];
    }

    /**
     * @param array<string, mixed> $criteria
     */
    private function applyCriteria(QueryBuilderInterface $builder, array $criteria): QueryBuilderInterface
    {
        foreach ($criteria as $column => $value) {
            if ($value === null) {
                $builder->where($column, 'IS NULL');

                continue;
            }

            if (is_array($value)) {
                $builder->where($column, 'IN', $value);

                continue;
            }

            $builder->where($column, '=', $value);
        }

        return $builder;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<T>
     */
    private function hydrateAndTrackAll(array $rows): array
    {
        $entities = [];
        foreach ($rows as $row) {
            $entities[] = $this->hydrateAndTrack($row);
        }

        return $entities;
    }

    /**
     * Hydrate a row, preferring an already-managed instance to preserve
     * identity, otherwise registering the fresh one with the Unit Of Work.
     *
     * @param array<string, mixed> $row
     *
     * @return T
     */
    private function hydrateAndTrack(array $row): object
    {
        $entity = $this->hydrator->hydrate($this->entityClass, $row);
        $identifier = $this->hydrator->extractIdentifier($entity);

        if ($identifier === null) {
            return $entity;
        }

        $identityMap = $this->unitOfWork->identityMap();
        if ($identityMap->has($this->entityClass, $identifier)) {
            return $identityMap->get($this->entityClass, $identifier) ?? $entity;
        }

        $this->unitOfWork->register($entity, $identifier);

        return $entity;
    }
}
