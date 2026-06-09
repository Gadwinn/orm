<?php

declare(strict_types=1);

namespace G\Orm\Metadata;

use G\Orm\Contract\RelationMetadataInterface;
use G\Orm\Mapping\RelationType;

/**
 * Immutable relation metadata value object.
 */
final class RelationMetadata implements RelationMetadataInterface
{
    /**
     * @param class-string $targetEntity
     */
    public function __construct(
        private readonly RelationType $type,
        private readonly string $targetEntity,
        private readonly string $propertyName,
        private readonly string $localKey,
        private readonly string $foreignKey,
    ) {
    }

    public function getType(): RelationType
    {
        return $this->type;
    }

    public function getTargetEntity(): string
    {
        return $this->targetEntity;
    }

    public function getPropertyName(): string
    {
        return $this->propertyName;
    }

    public function getLocalKey(): string
    {
        return $this->localKey;
    }

    public function getForeignKey(): string
    {
        return $this->foreignKey;
    }
}
