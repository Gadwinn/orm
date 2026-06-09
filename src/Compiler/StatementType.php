<?php

declare(strict_types=1);

namespace G\Orm\Compiler;

/**
 * The kind of statement a QueryState represents.
 */
enum StatementType
{
    case Select;
    case Insert;
    case Update;
    case Delete;
}
