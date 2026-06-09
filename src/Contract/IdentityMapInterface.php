<?php

declare(strict_types=1);

namespace G\Orm\Contract;

/**
 * In-memory registry of managed entities, keyed by entity class and
 * primary key value.
 *
 * Repositories must consult the Identity Map before generating any SQL so
 * that a given database row is represented by a single object instance for
 * the lifetime of the Unit Of Work. This preserves object identity and
 * prevents duplicate SELECT statements.
 *
 * Both scalar and composite primary keys are supported. To keep a fast,
 * flat array<string, object> backing store, the implementation folds the
 * identifier into a single internal string key. Scalar ids map directly;
 * composite ids (associative array of field => value) are compounded into a
 * deterministic form (e.g. "UserRole#role_id=12&user_id=5", fields sorted
 * for stability). Callers always pass natural identifiers; the string
 * folding is an internal concern.
 */
interface IdentityMapInterface
{
    /**
     * Whether an entity of the given class and identifier is present.
     *
     * @param class-string                     $entityClass
     * @param int|string|array<string, scalar> $id Scalar id, or an associative
     *                                            field => value map for a composite key.
     */
    public function has(string $entityClass, int|string|array $id): bool;

    /**
     * Return the managed entity for the given class and identifier, or null
     * when it is not present.
     *
     * @template T of object
     *
     * @param class-string<T>                  $entityClass
     * @param int|string|array<string, scalar> $id Scalar id, or an associative
     *                                            field => value map for a composite key.
     *
     * @return T|null
     */
    public function get(string $entityClass, int|string|array $id): ?object;

    /**
     * Store an entity under its class and primary key value.
     *
     * @param int|string|array<string, scalar> $id Scalar id, or an associative
     *                                            field => value map for a composite key.
     */
    public function add(object $entity, int|string|array $id): void;

    /**
     * Whether the given instance is registered, regardless of its class.
     */
    public function contains(object $entity): bool;

    /**
     * Remove the given instance from the map. A no-op when absent.
     */
    public function remove(object $entity): void;

    /**
     * Clear the map.
     *
     * When an entity class is provided, only entries of that class are
     * removed; otherwise the whole map is reset.
     *
     * @param class-string|null $entityClass
     */
    public function clear(?string $entityClass = null): void;
}
