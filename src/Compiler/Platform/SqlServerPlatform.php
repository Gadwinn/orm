<?php

declare(strict_types=1);

namespace G\Orm\Compiler\Platform;

use G\Orm\Exception\QueryException;

/**
 * SQL Server dialect: bracket identifier quoting (the idiomatic T-SQL form,
 * with embedded closing brackets doubled).
 *
 * Regular-expression predicates are rejected at compile time. REGEXP_LIKE()
 * only exists on SQL Server 2025+ and Azure SQL; emitting it would compile
 * fine here and explode at runtime on the 2019/2022 estates still in
 * production. Fail-fast rule: a loud QueryException at the call site beats a
 * silent structural syntax failure on the server. Targets running a build
 * with native regex support can reach it explicitly through whereRaw().
 */
final class SqlServerPlatform extends AbstractPlatform
{
    protected const string OPENING_QUOTE = '[';
    protected const string CLOSING_QUOTE = ']';

    /**
     * Platform name used in compile-time rejection messages.
     */
    private const PLATFORM_NAME = 'sqlsrv';

    public function compileRegexp(string $column, string $placeholder): string
    {
        throw QueryException::unsupportedOperator('REGEXP', self::PLATFORM_NAME);
    }

    public function compileNotRegexp(string $column, string $placeholder): string
    {
        throw QueryException::unsupportedOperator('NOT REGEXP', self::PLATFORM_NAME);
    }

    /**
     * T-SQL has no LIMIT: rows are limited with OFFSET ... FETCH, which only
     * exists as a suffix of ORDER BY. An unordered statement gets the neutral
     * "ORDER BY (SELECT NULL)" so the clause stays valid.
     */
    public function compileLimitOffset(?int $limit, ?int $offset, bool $ordered): string
    {
        if ($limit === null && $offset === null) {
            return '';
        }

        $sql = ($ordered ? '' : ' ORDER BY (SELECT NULL)') . ' OFFSET ' . ($offset ?? 0) . ' ROWS';

        if ($limit !== null) {
            $sql .= ' FETCH NEXT ' . $limit . ' ROWS ONLY';
        }

        return $sql;
    }
}
