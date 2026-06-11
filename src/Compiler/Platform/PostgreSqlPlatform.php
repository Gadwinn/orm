<?php

declare(strict_types=1);

namespace G\Orm\Compiler\Platform;

/**
 * PostgreSQL dialect: SQL-standard double-quote identifier quoting and the
 * native case-insensitive regular-expression match operators (~* / !~*).
 */
final class PostgreSqlPlatform extends AbstractPlatform
{
    /**
     * Architecture note: "~*" is intentionally selected over the
     * case-sensitive "~". MySQL/MariaDB's REGEXP and SQLite's regexp() are
     * case-insensitive by default, so the insensitive variant is the only
     * choice that gives one unified cross-platform matching behaviour: the
     * same builder chain matches the same rows on every engine. Callers who
     * need PostgreSQL's case-sensitive "~" can reach it explicitly through
     * whereRaw().
     */
    public function compileRegexp(string $column, string $placeholder): string
    {
        return $column . ' ~* ' . $placeholder;
    }

    public function compileNotRegexp(string $column, string $placeholder): string
    {
        return $column . ' !~* ' . $placeholder;
    }
}
