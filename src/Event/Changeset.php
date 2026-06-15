<?php

declare(strict_types=1);

namespace G\Orm\Event;

/**
 * Immutable view over the columns a flush is about to write for one entity.
 *
 * It wraps the column => value diff the Unit Of Work computed against the
 * entity's snapshot and is handed to #[BeforeUpdate]/#[AfterUpdate] hooks and
 * the matching events. Keys are mapped column names (which equal the property
 * name for a default mapping), so a hook can guard its behaviour on whether a
 * field genuinely changed - the canonical use being to avoid rewriting a value
 * the caller never touched.
 */
final class Changeset
{
    /**
     * @param array<string, mixed> $changes column => new value
     */
    public function __construct(
        private readonly array $changes,
    ) {
    }

    /**
     * Whether the given field is part of this change set.
     */
    public function has(string $property): bool
    {
        return array_key_exists($property, $this->changes);
    }

    /**
     * The staged new value for a field, or null when it is not in the set.
     */
    public function get(string $property): mixed
    {
        return $this->changes[$property] ?? null;
    }

    /**
     * Every staged change as a column => value map.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->changes;
    }

    /**
     * Whether nothing is staged to change.
     */
    public function isEmpty(): bool
    {
        return $this->changes === [];
    }
}
