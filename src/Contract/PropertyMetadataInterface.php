<?php

declare(strict_types=1);

namespace G\Orm\Contract;

/**
 * Read-only description of a single persisted entity property and its
 * backing column, derived from the #[Column] attribute and the property's
 * PHP type.
 */
interface PropertyMetadataInterface
{
    /**
     * The PHP property name on the entity.
     */
    public function getPropertyName(): string;

    /**
     * The database column name (resolved from #[Column(name:)] or derived
     * from the property name).
     */
    public function getColumnName(): string;

    /**
     * The declared database type, or null when left to be inferred from the
     * PHP type.
     */
    public function getType(): ?string;

    /**
     * Whether the column accepts SQL NULL.
     */
    public function isNullable(): bool;

    /**
     * Whether the column carries a UNIQUE constraint.
     */
    public function isUnique(): bool;

    /**
     * The column length, or null when not applicable.
     */
    public function getLength(): ?int;

    /**
     * Whether this property is part of the primary key (carries #[Id]).
     * Several properties may return true for a composite key.
     */
    public function isIdentifier(): bool;

    /**
     * The backed-enum class this property maps to, enabling automatic
     * conversion between the enum and its scalar backing value; null when
     * the property is not a native enum.
     *
     * @return class-string<\BackedEnum>|null
     */
    public function getEnumClass(): ?string;
}
