<?php

declare(strict_types=1);

namespace G\Orm\Metadata;

use G\Orm\Contract\EntityMetadataInterface;
use G\Orm\Contract\PropertyMetadataInterface;
use G\Orm\Contract\RelationMetadataInterface;

/**
 * Immutable structural metadata for a mapped entity.
 */
final class EntityMetadata implements EntityMetadataInterface
{
    /**
     * @param class-string                              $entityClass
     * @param list<string>                              $primaryKeyFields
     * @param class-string|null                         $repositoryClass
     * @param array<string, PropertyMetadataInterface>  $properties
     * @param array<string, RelationMetadataInterface>  $relations
     */
    public function __construct(
        private readonly string $entityClass,
        private readonly string $tableName,
        private readonly array $primaryKeyFields,
        private readonly ?string $repositoryClass,
        private readonly array $properties,
        private readonly array $relations,
    ) {
    }

    public function getEntityClass(): string
    {
        return $this->entityClass;
    }

    public function getTableName(): string
    {
        return $this->tableName;
    }

    public function getPrimaryKeyFields(): array
    {
        return $this->primaryKeyFields;
    }

    public function getRepositoryClass(): ?string
    {
        return $this->repositoryClass;
    }

    public function getProperties(): array
    {
        return $this->properties;
    }

    public function getProperty(string $propertyName): ?PropertyMetadataInterface
    {
        return $this->properties[$propertyName] ?? null;
    }

    public function getRelations(): array
    {
        return $this->relations;
    }

    public function getRelation(string $propertyName): ?RelationMetadataInterface
    {
        return $this->relations[$propertyName] ?? null;
    }
}
