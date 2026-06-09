<?php

declare(strict_types=1);

namespace G\Orm\Exception;

use RuntimeException;

/**
 * Thrown when a transaction control operation fails (begin, commit, roll
 * back) or is used in an invalid sequence.
 */
final class TransactionException extends RuntimeException implements OrmExceptionInterface
{
}
