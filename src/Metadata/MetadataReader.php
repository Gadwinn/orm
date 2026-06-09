<?php

declare(strict_types=1);

namespace G\Orm\Metadata;

use BackedEnum;
use G\Orm\Contract\EntityMetadataInterface;
use G\Orm\Contract\MetadataReaderInterface;
use G\Orm\Contract\RelationMetadataInterface;
use G\Orm\Exception\MetadataException;
use G\Orm\Mapping\BelongsTo;
use G\Orm\Mapping\Column;
use G\Orm\Mapping\HasMany;
use G\Orm\Mapping\HasOne;
use G\Orm\Mapping\Id;
use G\Orm\Mapping\RelationType;
use G\Orm\Mapping\Repository;
use G\Orm\Mapping\Table;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Reads PHP 8.3 mapping attributes through native reflection and builds the
 * structured metadata consumed by the rest of the ORM.
 *
 * Results are memoised per class for the lifetime of the instance so a class
 * is reflected only once. A PSR-16 layer can be added later as a decorating
 * reader without changing this contract; the produced metadata objects hold
 * only plain data and are safe to cache.
 */
final class MetadataReader implements MetadataReaderInterface
{
    /**
     * @var array<class-string, EntityMetadataInterface>
     */
    private array $cache = [];

    public function getSchemaFor(string $entityClass): EntityMetadataInterface
    {
        return $this->cache[$entityClass] ??= $this->build($entityClass);
    }

    /**
     * @param class-string $entityClass
     */
    private function build(string $entityClass): EntityMetadataInterface
    {
        $reflection = new ReflectionClass($entityClass);

        $tableName = $this->readTableName($reflection, $entityClass);
        $repositoryClass = $this->readRepositoryClass($reflection);

        $properties = [];
        $primaryKeyFields = [];
        $generatedFields = [];
        $relationProperties = [];
        $localKeyColumn = null;

        foreach ($reflection->getProperties() as $property) {
            $name = $property->getName();

            if ($this->isRelation($property)) {
                $relationProperties[] = $property;

                continue;
            }

            $column = $this->firstAttribute($property, Column::class);
            if ($column === null) {
                continue;
            }

            $columnName = $column->name ?? $name;
            $id = $this->firstAttribute($property, Id::class);
            $isIdentifier = $id !== null;
            if ($isIdentifier) {
                $primaryKeyFields[] = $name;
                $localKeyColumn ??= $columnName;

                if ($id->generated) {
                    $generatedFields[] = $name;
                }
            }

            $properties[$name] = new PropertyMetadata(
                propertyName: $name,
                columnName: $columnName,
                type: $column->type ?? $this->inferType($property),
                nullable: $column->nullable,
                unique: $column->unique,
                length: $column->length,
                identifier: $isIdentifier,
                enumClass: $this->resolveEnumClass($property),
            );
        }

        if ($primaryKeyFields === [] || $localKeyColumn === null) {
            throw new MetadataException(
                sprintf('Entity %s declares no #[Id] property.', $entityClass)
            );
        }

        if (count($primaryKeyFields) > 1 && $generatedFields !== []) {
            throw new MetadataException(
                sprintf(
                    'Entity %s declares a composite primary key (%s) with a database-generated '
                    . 'column (%s); generated identifiers are only supported on single-column keys.',
                    $entityClass,
                    implode(', ', $primaryKeyFields),
                    implode(', ', $generatedFields),
                )
            );
        }

        $relations = [];
        foreach ($relationProperties as $property) {
            $relations[$property->getName()] = $this->buildRelation($property, $localKeyColumn);
        }

        return new EntityMetadata(
            $entityClass,
            $tableName,
            $primaryKeyFields,
            $repositoryClass,
            $properties,
            $relations,
        );
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @param class-string            $entityClass
     */
    private function readTableName(ReflectionClass $reflection, string $entityClass): string
    {
        $table = $this->firstClassAttribute($reflection, Table::class);

        if ($table === null) {
            throw new MetadataException(
                sprintf('Entity %s is missing the #[Table] attribute.', $entityClass)
            );
        }

        return $table->name;
    }

    /**
     * @param ReflectionClass<object> $reflection
     *
     * @return class-string|null
     */
    private function readRepositoryClass(ReflectionClass $reflection): ?string
    {
        $repository = $this->firstClassAttribute($reflection, Repository::class);

        return $repository?->className;
    }

    private function isRelation(ReflectionProperty $property): bool
    {
        return $property->getAttributes(BelongsTo::class) !== []
            || $property->getAttributes(HasOne::class) !== []
            || $property->getAttributes(HasMany::class) !== [];
    }

    /**
     * Build a relation, normalising the attribute options into local/foreign
     * database columns.
     *
     * - BelongsTo: localKey is the declared foreign-key column on this table;
     *   foreignKey is the target's primary-key column.
     * - HasOne/HasMany: localKey is this entity's primary-key column;
     *   foreignKey is the column on the target table (the mappedBy value).
     */
    private function buildRelation(ReflectionProperty $property, string $localKeyColumn): RelationMetadataInterface
    {
        $belongsTo = $this->firstAttribute($property, BelongsTo::class);
        if ($belongsTo !== null) {
            return new RelationMetadata(
                RelationType::BelongsTo,
                $belongsTo->targetEntity,
                $property->getName(),
                $belongsTo->foreignKey,
                $this->primaryKeyColumn($belongsTo->targetEntity),
            );
        }

        $hasOne = $this->firstAttribute($property, HasOne::class);
        if ($hasOne !== null) {
            return new RelationMetadata(
                RelationType::HasOne,
                $hasOne->targetEntity,
                $property->getName(),
                $localKeyColumn,
                $hasOne->mappedBy,
            );
        }

        $hasMany = $this->firstAttribute($property, HasMany::class);
        if ($hasMany !== null) {
            return new RelationMetadata(
                RelationType::HasMany,
                $hasMany->targetEntity,
                $property->getName(),
                $localKeyColumn,
                $hasMany->mappedBy,
            );
        }

        throw new MetadataException(
            sprintf('Property %s carries no supported relation attribute.', $property->getName())
        );
    }

    /**
     * Resolve the primary-key column of a target entity without building its
     * full schema, keeping relation resolution free of cross-entity cycles.
     *
     * @param class-string $entityClass
     */
    private function primaryKeyColumn(string $entityClass): string
    {
        $reflection = new ReflectionClass($entityClass);

        foreach ($reflection->getProperties() as $property) {
            if ($property->getAttributes(Id::class) === []) {
                continue;
            }

            $column = $this->firstAttribute($property, Column::class);
            if ($column !== null && $column->name !== null) {
                return $column->name;
            }

            return $property->getName();
        }

        throw new MetadataException(
            sprintf('Target entity %s declares no #[Id] property for relation resolution.', $entityClass)
        );
    }

    private function inferType(ReflectionProperty $property): ?string
    {
        $type = $property->getType();

        return $type instanceof ReflectionNamedType ? $type->getName() : null;
    }

    /**
     * @return class-string<BackedEnum>|null
     */
    private function resolveEnumClass(ReflectionProperty $property): ?string
    {
        $type = $property->getType();

        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $name = $type->getName();

        return is_subclass_of($name, BackedEnum::class) ? $name : null;
    }

    /**
     * @template TAttribute of object
     *
     * @param class-string<TAttribute> $attribute
     *
     * @return TAttribute|null
     */
    private function firstAttribute(ReflectionProperty $property, string $attribute): ?object
    {
        $attributes = $property->getAttributes($attribute);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    /**
     * @template TAttribute of object
     *
     * @param ReflectionClass<object>  $reflection
     * @param class-string<TAttribute> $attribute
     *
     * @return TAttribute|null
     */
    private function firstClassAttribute(ReflectionClass $reflection, string $attribute): ?object
    {
        $attributes = $reflection->getAttributes($attribute);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }
}
