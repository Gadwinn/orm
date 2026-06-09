<?php

declare(strict_types=1);

namespace G\Orm\Exception;

use RuntimeException;

/**
 * Thrown when establishing or using a database connection fails.
 */
final class ConnectionException extends RuntimeException implements OrmExceptionInterface
{
}
