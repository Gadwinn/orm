<?php

declare(strict_types=1);

namespace G\Orm\Metadata;

use G\Orm\Contract\PropertyMetadataInterface;

/**
 * Immutable property metadata value object.
 */
final class PropertyMetadata implements PropertyMetadataInterface
{
    /**
     * @param class-string<\BackedEnum>|null $enumClass
     */
    public function __construct(
        private readonly string $propertyName,
        private readonly string $columnName,
        private readonly ?string $type,
        private readonly bool $nullable,
        private readonly bool $unique,
        private readonly ?int $length,
        private readonly bool $identifier,
        private readonly ?string $enumClass,
    ) {
    }

    public function getPropertyName(): string
    {
        return $this->propertyName;
    }

    public function getColumnName(): string
    {
        return $this->columnName;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function isNullable(): bool
    {
        return $this->nullable;
    }

    public function isUnique(): bool
    {
        return $this->unique;
    }

    public function getLength(): ?int
    {
        return $this->length;
    }

    public function isIdentifier(): bool
    {
        return $this->identifier;
    }

    public function getEnumClass(): ?string
    {
        return $this->enumClass;
    }
}
