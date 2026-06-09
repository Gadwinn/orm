<?php

declare(strict_types=1);

namespace G\Orm\Tests\Fixture;

/**
 * String-backed enum fixture used to exercise the hydrator's native enum
 * conversion in both directions.
 */
enum Status: string
{
    case Draft = 'draft';
    case Published = 'published';
}
