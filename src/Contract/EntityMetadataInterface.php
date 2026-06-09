<?php

declare(strict_types=1);

namespace G\Orm\Contract;

/**
 * Read-only structural description of a mapped entity.
 *
 * Aggregates the table mapping, the primary key, an optional custom
 * repository, and the property/relation metadata collections. This is the
 * single source of truth consumed by the hydrator, the query builder and the
 * repositories. It is designed to be reusable by future packages (e.g. a
 * migrations generator).
 */
interface EntityMetadataInterface
{
    /**
     * The fully-qualified entity class this metadata describes.
     *
     * @return class-string
     */
    public function getEntityClass(): string;

    /**
     * The mapped table name (from #[Table]).
     */
    public function getTableName(): string;

    /**
     * The PHP property name(s) forming the primary key, in declaration
     * order. A single-element array denotes a scalar key; more than one
     * element denotes a composite key.
     *
     * @return list<string>
     */
    public function getPrimaryKeyFields(): array;

    /**
     * The custom repository class declared via #[Repository], or null when
     * the generic repository is used.
     *
     * @return class-string|null
     */
    public function getRepositoryClass(): ?string;

    /**
     * All persisted properties, keyed by PHP property name.
     *
     * @return array<string, PropertyMetadataInterface>
     */
    public function getProperties(): array;

    /**
     * Metadata for a single property by name, or null when it is not mapped.
     */
    public function getProperty(string $propertyName): ?PropertyMetadataInterface;

    /**
     * All declared relations, keyed by PHP property name.
     *
     * @return array<string, RelationMetadataInterface>
     */
    public function getRelations(): array;

    /**
     * Metadata for a single relation by property name, or null when none is
     * declared on that property.
     */
    public function getRelation(string $propertyName): ?RelationMetadataInterface;
}
