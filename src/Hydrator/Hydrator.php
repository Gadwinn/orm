<?php

declare(strict_types=1);

namespace G\Orm\Hydrator;

use BackedEnum;
use G\Orm\Contract\EntityMetadataInterface;
use G\Orm\Contract\HydratorInterface;
use G\Orm\Contract\MetadataReaderInterface;
use ReflectionClass;

/**
 * Reflection-based hydrator bridging the column world and the entity world.
 *
 * It maps strictly through the entity metadata. Entities are built without
 * invoking their constructor so partial rows never require constructor
 * arguments. Native enums are converted to and from their backing value.
 *
 * PHP 8.3 safety: extraction never reads an uninitialized typed property
 * (it is skipped, i.e. treated as "unknown"), and hydration only writes the
 * properties actually present in the row, so partial selections never touch
 * absent fields. The primary key is always part of the loaded set.
 */
final class Hydrator implements HydratorInterface
{
    public function __construct(
        private readonly MetadataReaderInterface $metadata,
    ) {
    }

    public function hydrate(string $entityClass, array $row, ?array $loadedFields = null): object
    {
        $reflection = new ReflectionClass($entityClass);

        return $this->hydrateInto($reflection, $this->metadata->getSchemaFor($entityClass), $row, $loadedFields);
    }

    public function hydrateAll(string $entityClass, array $rows, ?array $loadedFields = null): array
    {
        $reflection = new ReflectionClass($entityClass);
        $schema = $this->metadata->getSchemaFor($entityClass);

        $entities = [];
        foreach ($rows as $row) {
            $entities[] = $this->hydrateInto($reflection, $schema, $row, $loadedFields);
        }

        return $entities;
    }

    public function extractState(object $entity, ?array $loadedFields = null): array
    {
        $schema = $this->metadata->getSchemaFor($entity::class);
        $reflection = new ReflectionClass($entity);
        $primaryKeyFields = $schema->getPrimaryKeyFields();

        $state = [];
        foreach ($schema->getProperties() as $propertyName => $propertyMetadata) {
            if (!$this->isWanted($propertyName, $loadedFields, $primaryKeyFields)) {
                continue;
            }

            $property = $reflection->getProperty($propertyName);
            if (!$property->isInitialized($entity)) {
                continue;
            }

            $state[$propertyMetadata->getColumnName()] = $this->toDatabaseValue($property->getValue($entity));
        }

        return $state;
    }

    public function extractIdentifier(object $entity): int|string|array|null
    {
        $schema = $this->metadata->getSchemaFor($entity::class);
        $reflection = new ReflectionClass($entity);

        $identifier = [];
        foreach ($schema->getPrimaryKeyFields() as $field) {
            $property = $reflection->getProperty($field);
            if (!$property->isInitialized($entity)) {
                return null;
            }

            $value = $this->toDatabaseValue($property->getValue($entity));
            if ($value === null || !is_scalar($value)) {
                return null;
            }

            $column = $schema->getProperty($field)?->getColumnName() ?? $field;
            $identifier[$column] = $value;
        }

        if (count($identifier) === 1) {
            $single = reset($identifier);

            return is_int($single) || is_string($single) ? $single : null;
        }

        return $identifier;
    }

    /**
     * @template T of object
     *
     * @param ReflectionClass<T>   $reflection
     * @param array<string, mixed> $row
     * @param list<string>|null    $loadedFields
     *
     * @return T
     */
    private function hydrateInto(
        ReflectionClass $reflection,
        EntityMetadataInterface $schema,
        array $row,
        ?array $loadedFields
    ): object {
        $entity = $reflection->newInstanceWithoutConstructor();
        $primaryKeyFields = $schema->getPrimaryKeyFields();

        foreach ($schema->getProperties() as $propertyName => $propertyMetadata) {
            if (!$this->isWanted($propertyName, $loadedFields, $primaryKeyFields)) {
                continue;
            }

            $column = $propertyMetadata->getColumnName();
            if (!array_key_exists($column, $row)) {
                continue;
            }

            $value = $this->toPhpValue($propertyMetadata->getEnumClass(), $row[$column]);
            $reflection->getProperty($propertyName)->setValue($entity, $value);
        }

        return $entity;
    }

    /**
     * Whether a property is in the loaded set. The primary key is always
     * considered loaded.
     *
     * @param list<string>|null $loadedFields
     * @param list<string>      $primaryKeyFields
     */
    private function isWanted(string $propertyName, ?array $loadedFields, array $primaryKeyFields): bool
    {
        if ($loadedFields === null) {
            return true;
        }

        return in_array($propertyName, $loadedFields, true)
            || in_array($propertyName, $primaryKeyFields, true);
    }

    /**
     * @param class-string<BackedEnum>|null $enumClass
     */
    private function toPhpValue(?string $enumClass, mixed $value): mixed
    {
        if ($enumClass !== null && (is_int($value) || is_string($value))) {
            return $enumClass::from($value);
        }

        return $value;
    }

    private function toDatabaseValue(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }
}
