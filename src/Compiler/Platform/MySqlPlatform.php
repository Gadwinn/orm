<?php

declare(strict_types=1);

namespace G\Orm\Compiler\Platform;

/**
 * MySQL / MariaDB dialect: backtick identifier quoting and the native
 * REGEXP / NOT REGEXP comparison operators.
 */
final class MySqlPlatform extends AbstractPlatform
{
    protected const string OPENING_QUOTE = '`';
    protected const string CLOSING_QUOTE = '`';

    /**
     * MySQL has no "no limit" keyword: its manual recommends the largest BIGINT UNSIGNED.
     */
    protected const ?string UNLIMITED = '18446744073709551615';

    public function compileRegexp(string $column, string $placeholder): string
    {
        return $column . ' REGEXP ' . $placeholder;
    }

    public function compileNotRegexp(string $column, string $placeholder): string
    {
        return $column . ' NOT REGEXP ' . $placeholder;
    }
}
