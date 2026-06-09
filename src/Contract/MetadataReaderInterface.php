<?php

declare(strict_types=1);

namespace G\Orm\Contract;

/**
 * Reads entity mapping attributes and exposes them as structured metadata.
 *
 * Implementations are expected to be the only component that touches
 * reflection and attribute parsing, and to cache their results (PSR-16) so
 * that hot paths never re-reflect. The rest of the ORM depends on this
 * abstraction rather than on reflection directly.
 */
interface MetadataReaderInterface
{
    /**
     * Return the structural metadata for the given entity class.
     *
     * @param class-string $entityClass
     *
     * @throws \G\Orm\Exception\MetadataException When the class is not a valid
     *         mapped entity, e.g. it lacks a #[Table] attribute or declares no
     *         #[Id] property.
     */
    public function getSchemaFor(string $entityClass): EntityMetadataInterface;
}
