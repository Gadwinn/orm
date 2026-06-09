<?php

declare(strict_types=1);

namespace G\Orm\Exception;

use RuntimeException;

/**
 * Thrown when a database driver reports a low-level failure that is not a
 * connection or query concern.
 */
final class DriverException extends RuntimeException implements OrmExceptionInterface
{
}
