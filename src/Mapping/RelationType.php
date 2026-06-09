<?php

declare(strict_types=1);

namespace G\Orm\Mapping;

/**
 * The supported relation kinds.
 *
 * String-backed so metadata can be cached/serialised cleanly (PSR-16).
 */
enum RelationType: string
{
    case BelongsTo = 'belongsTo';
    case HasOne = 'hasOne';
    case HasMany = 'hasMany';
}
