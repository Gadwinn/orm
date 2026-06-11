<?php

declare(strict_types=1);

namespace G\Orm\Compiler\Platform;

/**
 * Shared identifier-quoting mechanics for every platform.
 *
 * Concrete platforms only declare their delimiters; the splitting of
 * qualified names and the doubling of embedded closing delimiters is
 * identical across engines and lives here once.
 */
abstract class AbstractPlatform implements PlatformInterface
{
    /**
     * Identifier delimiters, defaulting to the SQL-standard double quote.
     * Engines with asymmetric delimiters (SQL Server brackets) or a custom
     * character (MySQL backticks) override the pair.
     */
    protected const string OPENING_QUOTE = '"';
    protected const string CLOSING_QUOTE = '"';

    public function quoteIdentifier(string $identifier): string
    {
        $quoted = array_map(
            static fn (string $part): string => static::OPENING_QUOTE
                . str_replace(static::CLOSING_QUOTE, static::CLOSING_QUOTE . static::CLOSING_QUOTE, $part)
                . static::CLOSING_QUOTE,
            explode('.', $identifier)
        );

        return implode('.', $quoted);
    }
}
