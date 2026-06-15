<?php

declare(strict_types=1);

namespace G\Orm\Tests\Unit;

use G\Orm\Compiler\Platform\AbstractPlatform;
use G\Orm\Compiler\Platform\MySqlPlatform;
use G\Orm\Compiler\Platform\PlatformInterface;
use G\Orm\Compiler\Platform\PostgreSqlPlatform;
use G\Orm\Compiler\Platform\SqlitePlatform;
use G\Orm\Compiler\Platform\SqlServerPlatform;
use G\Orm\Compiler\SqlCompiler;
use G\Orm\Exception\QueryException;
use G\Orm\Query\QueryBuilder;
use G\Orm\Tests\OrmTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Dialect-sensitive SQL compilation through the platform seam: the same
 * builder chain produces the exact localized syntax of whichever platform the
 * compiler was constructed with, and identifier quoting follows each engine's
 * delimiters with embedded-delimiter doubling.
 */
#[CoversClass(SqlCompiler::class)]
#[CoversClass(AbstractPlatform::class)]
#[CoversClass(MySqlPlatform::class)]
#[CoversClass(PostgreSqlPlatform::class)]
#[CoversClass(SqlServerPlatform::class)]
#[CoversClass(SqlitePlatform::class)]
final class PlatformCompilationTest extends OrmTestCase
{
    public function testMySqlPlatformCompilesNativeRegexpOperators(): void
    {
        $builder = $this->builder(new MySqlPlatform())
            ->from('users')
            ->where('email', 'REGEXP', '^a')
            ->where('name', 'NOT REGEXP', 'bot$');

        self::assertSame(
            'SELECT * FROM users WHERE email REGEXP ? AND name NOT REGEXP ?',
            $builder->toSql()
        );
        self::assertSame(['^a', 'bot$'], $builder->getBindings());
    }

    public function testPostgreSqlPlatformCompilesCaseInsensitiveMatchOperators(): void
    {
        $builder = $this->builder(new PostgreSqlPlatform())
            ->from('users')
            ->where('email', 'REGEXP', '^a')
            ->where('name', 'NOT REGEXP', 'bot$');

        self::assertSame(
            'SELECT * FROM users WHERE email ~* ? AND name !~* ?',
            $builder->toSql()
        );
        self::assertSame(['^a', 'bot$'], $builder->getBindings());
    }

    /**
     * Fail-fast rule: REGEXP_LIKE() only exists on SQL Server 2025+/Azure, so
     * the platform refuses to compile regex predicates at all - a loud
     * compile-time QueryException instead of SQL that breaks at runtime on
     * the 2019/2022 servers still in production.
     */
    public function testSqlServerPlatformRejectsRegexpPredicatesAtCompileTime(): void
    {
        $cases = [
            'REGEXP' => '^a',
            'NOT REGEXP' => 'bot$',
        ];

        foreach ($cases as $operator => $pattern) {
            $builder = $this->builder(new SqlServerPlatform())
                ->from('users')
                ->where('email', $operator, $pattern);

            try {
                $builder->toSql();
                self::fail(sprintf('Expected the sqlsrv platform to reject the %s operator.', $operator));
            } catch (QueryException $exception) {
                self::assertSame(
                    sprintf('The %s operator is not supported on the "sqlsrv" platform.', $operator),
                    $exception->getMessage()
                );
            }
        }
    }

    public function testDefaultPlatformKeepsTheBareRegexpSyntax(): void
    {
        // A compiler built with no explicit platform speaks the SQLite /
        // standard-conforming fallback dialect.
        $builder = new QueryBuilder(new SqlCompiler(), $this->connection);
        $builder->from('users')->where('email', 'REGEXP', '^a');

        self::assertSame('SELECT * FROM users WHERE email REGEXP ?', $builder->toSql());
    }

    public function testIdentifierQuotingFollowsEachEngine(): void
    {
        self::assertSame('`t`.`col`', (new MySqlPlatform())->quoteIdentifier('t.col'));
        self::assertSame('"t"."col"', (new PostgreSqlPlatform())->quoteIdentifier('t.col'));
        self::assertSame('[t].[col]', (new SqlServerPlatform())->quoteIdentifier('t.col'));
        self::assertSame('"t"."col"', (new SqlitePlatform())->quoteIdentifier('t.col'));
    }

    public function testEmbeddedClosingDelimitersAreDoubled(): void
    {
        self::assertSame('`we``ird`', (new MySqlPlatform())->quoteIdentifier('we`ird'));
        self::assertSame('"we""ird"', (new PostgreSqlPlatform())->quoteIdentifier('we"ird'));
        self::assertSame('[we]]ird]', (new SqlServerPlatform())->quoteIdentifier('we]ird'));
    }

    private function builder(PlatformInterface $platform): QueryBuilder
    {
        return new QueryBuilder(new SqlCompiler($platform), $this->connection);
    }
}
