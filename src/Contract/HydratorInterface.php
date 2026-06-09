<?php

declare(strict_types=1);

namespace G\Orm\Contract;

/**
 * Converts raw database rows into entities and entities back into
 * normalised state arrays.
 *
 * The hydrator is the single bridge between the column world (associative
 * arrays of scalar/driver values) and the object world (pure PHP entities).
 * It maps fields strictly through the entity's mapping attributes; columns
 * with no mapped property are ignored and properties with no column are left
 * untouched.
 *
 * Partial hydration rules:
 *
 *   - $loadedFields names the property names the caller actually selected.
 *     null means a full row was loaded (every mapped property).
 *   - The primary key is always considered loaded so the entity remains
 *     trackable, even under a partial selection.
 *   - Encoding of "loaded" state is structural: a property is present in the
 *     produced state array only when it was loaded. A loaded column holding
 *     SQL NULL maps to a null value; an unknown (non-loaded) property is
 *     simply absent from the array. This lets the Unit Of Work distinguish a
 *     real NULL from "unknown" and avoid phantom updates.
 *
 * PHP 8.3 safety: scanning an entity must never trigger an "uninitialized
 * property" error. Typed properties that were never assigned are treated as
 * absent (not loaded) rather than read.
 */
interface HydratorInterface
{
    /**
     * Build and populate an entity from a single database row.
     *
     * Only the columns present in $row are assigned. When $loadedFields is
     * given, it constrains which mapped properties are considered loaded for
     * subsequent change tracking (the primary key is always included).
     *
     * @template T of object
     *
     * @param class-string<T>      $entityClass
     * @param array<string, mixed> $row          column => value pairs from the driver
     * @param list<string>|null    $loadedFields property names loaded, or null when full
     *
     * @return T
     *
     * @throws \G\Orm\Exception\MetadataException
     */
    public function hydrate(string $entityClass, array $row, ?array $loadedFields = null): object;

    /**
     * Hydrate a list of rows into a list of entities.
     *
     * @template T of object
     *
     * @param class-string<T>             $entityClass
     * @param list<array<string, mixed>>  $rows
     * @param list<string>|null           $loadedFields property names loaded, or null when full
     *
     * @return list<T>
     *
     * @throws \G\Orm\Exception\MetadataException
     */
    public function hydrateAll(string $entityClass, array $rows, ?array $loadedFields = null): array;

    /**
     * Extract the normalised state of an entity as a column => value array.
     *
     * Exports the internal entity state for Unit Of Work snapshot comparison
     * and for building write statements. Uninitialized typed properties are
     * skipped. When $loadedFields is given, the result is masked to those
     * properties (plus the primary key); a property absent from the result
     * is "unknown" and must not be diffed.
     *
     * @param list<string>|null $loadedFields property names to include, or null for all
     *                                        initialised mapped properties
     *
     * @return array<string, mixed>
     *
     * @throws \G\Orm\Exception\MetadataException
     */
    public function extractState(object $entity, ?array $loadedFields = null): array;

    /**
     * Read the primary key value of an entity.
     *
     * Returns a scalar for a single-field key, an associative
     * field => value array for a composite key, or null when the identifier
     * has not yet been assigned (e.g. a new entity awaiting insertion).
     *
     * @return int|string|array<string, scalar>|null
     *
     * @throws \G\Orm\Exception\MetadataException
     */
    public function extractIdentifier(object $entity): int|string|array|null;
}
