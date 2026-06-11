<?php

declare(strict_types=1);

namespace G\Orm\Compiler\Platform;

/**
 * SQLite dialect, doubling as the conservative fallback for engines without
 * a dedicated platform: SQL-standard double-quote identifier quoting and the
 * bare REGEXP / NOT REGEXP operator syntax.
 *
 * SQLite parses the REGEXP operator natively but only executes it when a
 * regexp() user function has been registered on the connection — a runtime
 * concern of the application, not of SQL compilation.
 */
final class SqlitePlatform extends AbstractPlatform
{
    public function compileRegexp(string $column, string $placeholder): string
    {
        return $column . ' REGEXP ' . $placeholder;
    }

    public function compileNotRegexp(string $column, string $placeholder): string
    {
        return $column . ' NOT REGEXP ' . $placeholder;
    }
}
