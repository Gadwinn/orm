<?php

declare(strict_types=1);

namespace G\Orm\Compiler;

/**
 * Structural carrier of an accumulated query.
 *
 * This is the decoupling boundary between the Query Builder and the SQL
 * compiler: the builder fills a QueryState while the developer chains calls,
 * and the compiler reads it to produce SQL and bindings. It holds no
 * behaviour and performs no escaping; identifiers are stored exactly as the
 * developer provided them (SQL-first, no hidden rewriting), and values are
 * kept raw so the compiler can parameterise them.
 *
 * A "predicate" entry (used by both WHERE and HAVING) has the shape:
 *   array{boolean: 'AND'|'OR', column: string, operator: string, values: list<mixed>}
 * where `values` holds 0 (IS [NOT] NULL), 1 (most operators), 2 (BETWEEN) or
 * more (IN / NOT IN) raw values.
 */
final class QueryState
{
    public StatementType $type = StatementType::Select;

    /**
     * Selected columns/expressions. Empty means "*".
     *
     * @var list<string>
     */
    public array $columns = [];

    /**
     * Source table(s) for SELECT and DELETE (aliases inline).
     *
     * @var list<string>
     */
    public array $from = [];

    /**
     * Target table for INSERT and UPDATE.
     */
    public ?string $table = null;

    /**
     * Column => value payload for INSERT and UPDATE.
     *
     * @var array<string, mixed>
     */
    public array $values = [];

    /**
     * @var list<array{type: string, table: string, condition: string}>
     */
    public array $joins = [];

    /**
     * @var list<array{boolean: string, column: string, operator: string, values: list<mixed>}>
     */
    public array $wheres = [];

    /**
     * @var list<string>
     */
    public array $groupBy = [];

    /**
     * @var list<array{boolean: string, column: string, operator: string, values: list<mixed>}>
     */
    public array $having = [];

    /**
     * @var list<array{column: string, direction: string}>
     */
    public array $orderBy = [];

    public ?int $limit = null;

    public ?int $offset = null;
}
