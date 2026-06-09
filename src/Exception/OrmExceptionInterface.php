<?php

declare(strict_types=1);

namespace G\Orm\Exception;

use Throwable;

/**
 * Common contract implemented by every exception thrown by the ORM.
 *
 * Callers can catch G\Orm\Exception\OrmExceptionInterface to handle any ORM
 * failure regardless of the concrete native base class (\RuntimeException,
 * \LogicException, ...) each specialised exception extends.
 */
interface OrmExceptionInterface extends Throwable
{
}
