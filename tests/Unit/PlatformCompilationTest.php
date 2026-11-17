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

    /**
     * @return array<string, array{PlatformInterface, string, string, string}>
     */
    public static function limitOffsetProvider(): array
    {
        return [
            'mysql' => [
                new MySqlPlatform(),
                ' LIMIT 10',
                ' LIMIT 10 OFFSET 20',
                ' LIMIT 18446744073709551615 OFFSET 20',
            ],
            'pgsql' => [new PostgreSqlPlatform(), ' LIMIT 10', ' LIMIT 10 OFFSET 20', ' OFFSET 20'],
            'sqlite' => [new SqlitePlatform(), ' LIMIT 10', ' LIMIT 10 OFFSET 20', ' LIMIT -1 OFFSET 20'],
            'sqlsrv' => [
                new SqlServerPlatform(),
                ' OFFSET 0 ROWS FETCH NEXT 10 ROWS ONLY',
                ' OFFSET 20 ROWS FETCH NEXT 10 ROWS ONLY',
                ' OFFSET 20 ROWS',
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('limitOffsetProvider')]
    public function testEachPlatformCompilesItsOwnLimitAndOffset(
        PlatformInterface $platform,
        string $limitOnly,
        string $limitAndOffset,
        string $offsetOnly
    ): void {
        $base = 'SELECT * FROM users ORDER BY id ASC';

        self::assertSame($base, $this->builder($platform)->from('users')->orderBy('id')->toSql());
        self::assertSame(
            $base . $limitOnly,
            $this->builder($platform)->from('users')->orderBy('id')->limit(10)->toSql()
        );
        self::assertSame(
            $base . $limitAndOffset,
            $this->builder($platform)->from('users')->orderBy('id')->limit(10)->offset(20)->toSql()
        );
        self::assertSame(
            $base . $offsetOnly,
            $this->builder($platform)->from('users')->orderBy('id')->offset(20)->toSql()
        );
    }

    /**
     * T-SQL only accepts OFFSET ... FETCH after an ORDER BY: an unordered
     * statement receives a neutral one instead of failing on the server.
     */
    public function testSqlServerAddsANeutralOrderByToAnUnorderedLimitedSelect(): void
    {
        self::assertSame(
            'SELECT * FROM users ORDER BY (SELECT NULL) OFFSET 0 ROWS FETCH NEXT 5 ROWS ONLY',
            $this->builder(new SqlServerPlatform())->from('users')->limit(5)->toSql()
        );
    }

    public function testNegativeLimitAndOffsetAreRejected(): void
    {
        foreach (['limit', 'offset'] as $method) {
            try {
                $this->builder(new SqlitePlatform())->from('users')->{$method}(-1);
                self::fail('Expected a QueryException for a negative ' . $method . '.');
            } catch (QueryException $exception) {
                self::assertStringContainsString('must not be negative', $exception->getMessage());
            }
        }
    }

    private function builder(PlatformInterface $platform): QueryBuilder
    {
        return new QueryBuilder(new SqlCompiler($platform), $this->connection);
    }
}
