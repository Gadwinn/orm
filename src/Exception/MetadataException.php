<?php

declare(strict_types=1);

namespace G\Orm\Exception;

use LogicException;

/**
 * Thrown when entity mapping metadata is missing or invalid (no #[Table],
 * no #[Id], an unmapped property referenced, ...).
 *
 * Extends \LogicException because mapping errors are programming/configuration
 * mistakes meant to be fixed in development, not recoverable runtime faults.
 */
final class MetadataException extends LogicException implements OrmExceptionInterface
{
}
