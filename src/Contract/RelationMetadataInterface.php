<?php

declare(strict_types=1);

namespace G\Orm\Contract;

use G\Orm\Mapping\RelationType;

/**
 * Read-only description of a single relation declared on an entity, derived
 * from a #[BelongsTo] / #[HasOne] / #[HasMany] attribute.
 *
 * Relation metadata is purely descriptive: it carries no fetch mode and no
 * lazy-loading switch. By design there is no way to configure automatic
 * loading; resolving a relation is always an explicit operation performed by
 * the developer or a repository.
 */
interface RelationMetadataInterface
{
    /**
     * The relation kind.
     */
    public function getType(): RelationType;

    /**
     * The related entity class.
     *
     * @return class-string
     */
    public function getTargetEntity(): string;

    /**
     * The PHP property on the owning entity that exposes this relation.
     */
    public function getPropertyName(): string;

    /**
     * The database column on the current entity's table used to match the
     * relation.
     *
     * For BelongsTo this is the local foreign-key column; for HasOne/HasMany
     * it is the current entity's primary-key column.
     */
    public function getLocalKey(): string;

    /**
     * The database column on the target entity's table used to match the
     * relation.
     *
     * For BelongsTo this is the target's primary-key column; for HasOne/HasMany
     * it is the foreign-key column on the target table.
     */
    public function getForeignKey(): string;
}
