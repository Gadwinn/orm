<?php

declare(strict_types=1);

namespace G\Orm\Hydrator\Type;

/**
 * Bidirectional conversion seam between a rich PHP property value and its
 * scalar database representation.
 *
 * Converters are resolved by the property's declared (or inferred) type and
 * applied at the hydration boundary in both directions: fromDatabase() when a
 * row is materialised into an entity, toDatabase() whenever entity state is
 * extracted - hydration snapshots, change-detection diffs and statement
 * bindings alike. Because snapshots therefore hold the scalar form, two
 * equal-but-distinct object instances never register as a phantom change and
 * no reference type is ever shared between an entity and its snapshot.
 *
 * Implementations must pass through values they do not recognise (null above
 * all) unchanged, so nullable columns and already-converted values flow
 * safely through the same path.
 */
interface ValueConverterInterface
{
    /**
     * Convert a PHP property value to its scalar database representation.
     */
    public function toDatabase(mixed $value): mixed;

    /**
     * Convert a raw database value to its rich PHP representation.
     */
    public function fromDatabase(mixed $value): mixed;
}
