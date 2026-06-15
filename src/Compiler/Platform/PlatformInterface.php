<?php

declare(strict_types=1);

namespace G\Orm\Compiler\Platform;

/**
 * Encapsulates the SQL dialect variations of one database engine.
 *
 * Behaviour-driven by design: each method maps a single concrete compilation
 * operation (quote this identifier, compile this predicate) instead of
 * exposing speculative capability flags. The compiler and the connection stay
 * engine-neutral and delegate only the dialect-sensitive fragments here.
 */
interface PlatformInterface
{
    /**
     * Quote an identifier (table or column name) for this engine.
     *
     * Qualified names ("t.col") are split on the dot and each part is quoted
     * individually. An embedded closing delimiter is doubled, so an
     * identifier can never break out of its own quoting.
     */
    public function quoteIdentifier(string $identifier): string;

    /**
     * Compile a positive regular-expression predicate over an already
     * validated column and a positional placeholder.
     *
     * Architecture note - cross-platform semantics: the REGEXP operator is
     * case-insensitive by default on MySQL/MariaDB and SQLite. Platforms
     * whose engines offer a case-sensitivity choice must therefore select
     * their case-INSENSITIVE form (PostgreSQL: "~*", never "~") so the same
     * builder chain matches the same rows on every engine. A platform whose
     * engine has no reliable native syntax must throw a QueryException at
     * compile time rather than emit SQL that fails on the server.
     *
     * @throws \G\Orm\Exception\QueryException When the engine cannot compile
     *                                         the predicate reliably.
     */
    public function compileRegexp(string $column, string $placeholder): string;

    /**
     * Compile a negative regular-expression predicate over an already
     * validated column and a positional placeholder. The case-insensitivity
     * contract of compileRegexp() applies identically.
     *
     * @throws \G\Orm\Exception\QueryException When the engine cannot compile
     *                                         the predicate reliably.
     */
    public function compileNotRegexp(string $column, string $placeholder): string;
}
