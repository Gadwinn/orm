<?php

declare(strict_types=1);

namespace G\Orm\Tests\Fixture;

/**
 * Integer-backed enum fixture used to exercise the hydrator's native enum
 * conversion when the backing type is int (the is_int() branch of toPhpValue),
 * complementing the string-backed {@see Status}.
 */
enum Level: int
{
    case Guest = 0;
    case Admin = 1;
}
