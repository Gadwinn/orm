<?php

declare(strict_types=1);

namespace G\Orm\Hydrator;

use BackedEnum;
use DateTimeImmutable;
use G\Orm\Contract\EntityMetadataInterface;
use G\Orm\Contract\HydratorInterface;
use G\Orm\Contract\MetadataReaderInterface;
use G\Orm\Contract\PropertyMetadataInterface;
use G\Orm\Hydrator\Type\DateTimeImmutableConverter;
use G\Orm\Hydrator\Type\ValueConverterInterface;
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
 *
 * Rich types cross the boundary through {@see ValueConverterInterface}
 * converters, resolved by the property's declared (or inferred) type.
 * Conversion happens at extraction as well as at hydration, so snapshots,
 * change diffs and statement bindings all operate on the scalar database
 * form: an equal-but-distinct object instance is never a phantom change, and
 * no reference type is shared between an entity and its snapshot.
 * DateTimeImmutable ships as the built-in converter; additional ones can be
 * registered through the constructor, keyed by type name.
 */
final class Hydrator implements HydratorInterface
{
    /**
     * Converters by property type name, custom registrations taking
     * precedence over the built-in defaults.
     *
     * @var array<string, ValueConverterInterface>
     */
    private readonly array $converters;

    /**
     * @param array<string, ValueConverterInterface> $converters Additional or
     *        overriding converters, keyed by the property type they handle
     *        (e.g. DateTimeImmutable::class).
     */
    public function __construct(
        private readonly MetadataReaderInterface $metadata,
        array $converters = [],
    ) {
        $this->converters = $converters + [
            DateTimeImmutable::class => new DateTimeImmutableConverter(),
        ];
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

            $state[$propertyMetadata->getColumnName()] = $this->toDatabaseValue(
                $propertyMetadata,
                $property->getValue($entity),
            );
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

            $value = $this->toDatabaseValue($schema->getProperty($field), $property->getValue($entity));
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

            $value = $this->toPhpValue($propertyMetadata, $row[$column]);
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
     * Convert a raw database value to the property's PHP representation:
     * backed enums first, then any converter registered for the property's
     * type, otherwise the value as-is.
     */
    private function toPhpValue(PropertyMetadataInterface $property, mixed $value): mixed
    {
        $enumClass = $property->getEnumClass();
        if ($enumClass !== null && (is_int($value) || is_string($value))) {
            return $enumClass::from($value);
        }

        $converter = $this->converterFor($property);

        return $converter === null ? $value : $converter->fromDatabase($value);
    }

    /**
     * Convert a PHP property value to its scalar database representation:
     * backed enums first, then any converter registered for the property's
     * type, otherwise the value as-is.
     */
    private function toDatabaseValue(?PropertyMetadataInterface $property, mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        $converter = $property === null ? null : $this->converterFor($property);

        return $converter === null ? $value : $converter->toDatabase($value);
    }

    private function converterFor(PropertyMetadataInterface $property): ?ValueConverterInterface
    {
        $type = $property->getType();

        return $type === null ? null : ($this->converters[$type] ?? null);
    }
}
