<?php

declare(strict_types=1);

namespace G\Orm\Core;

use G\Orm\Contract\IdentityMapInterface;

/**
 * In-memory identity map backed by a flat array<string, object>.
 *
 * Both scalar and composite primary keys are folded into a single
 * deterministic string key of the form:
 *
 *   ClassName#field=value                 (scalar key)
 *   ClassName#field_a=1&field_b=2          (composite key, fields sorted)
 *
 * Composite identifiers are associative arrays; their fields are sorted
 * alphabetically before flattening so that two callers passing the same
 * key in a different order resolve to the same slot.
 *
 * A parallel reverse index (object-id => string key) lets contains() and
 * remove() operate by instance without re-deriving the identifier, so an
 * entity can be dropped even after its primary key fields change.
 */
final class IdentityMap implements IdentityMapInterface
{
    /**
     * @var array<string, object>
     */
    private array $entities = [];

    /**
     * Reverse index from spl_object_id() to the folded string key, so that
     * instance-based operations stay O(1).
     *
     * @var array<int, string>
     */
    private array $keysByObject = [];

    public function has(string $entityClass, int|string|array $id): bool
    {
        return isset($this->entities[$this->keyFor($entityClass, $id)]);
    }

    public function get(string $entityClass, int|string|array $id): ?object
    {
        $entity = $this->entities[$this->keyFor($entityClass, $id)] ?? null;

        return $entity instanceof $entityClass ? $entity : null;
    }

    public function add(object $entity, int|string|array $id): void
    {
        $key = $this->keyFor($entity::class, $id);

        $this->entities[$key] = $entity;
        $this->keysByObject[spl_object_id($entity)] = $key;
    }

    public function contains(object $entity): bool
    {
        return isset($this->keysByObject[spl_object_id($entity)]);
    }

    public function remove(object $entity): void
    {
        $objectId = spl_object_id($entity);
        $key = $this->keysByObject[$objectId] ?? null;

        if ($key === null) {
            return;
        }

        unset($this->entities[$key], $this->keysByObject[$objectId]);
    }

    public function clear(?string $entityClass = null): void
    {
        if ($entityClass === null) {
            $this->entities = [];
            $this->keysByObject = [];

            return;
        }

        $prefix = $entityClass . '#';
        foreach ($this->entities as $key => $entity) {
            if (str_starts_with($key, $prefix)) {
                unset($this->entities[$key], $this->keysByObject[spl_object_id($entity)]);
            }
        }
    }

    /**
     * Fold a class and identifier into a deterministic string key.
     *
     * @param class-string                     $entityClass
     * @param int|string|array<string, scalar> $id
     */
    private function keyFor(string $entityClass, int|string|array $id): string
    {
        return $entityClass . '#' . $this->foldIdentifier($id);
    }

    /**
     * @param int|string|array<string, scalar> $id
     */
    private function foldIdentifier(int|string|array $id): string
    {
        if (!is_array($id)) {
            return (string) $id;
        }

        ksort($id);

        $parts = [];
        foreach ($id as $field => $value) {
            $parts[] = $field . '=' . $this->scalarToString($value);
        }

        return implode('&', $parts);
    }

    private function scalarToString(string|int|float|bool $value): string
    {
        return is_bool($value) ? ($value ? '1' : '0') : (string) $value;
    }
}
