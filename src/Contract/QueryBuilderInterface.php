<?php

declare(strict_types=1);

namespace G\Orm\Contract;

/**
 * SQL-first, fluent query builder.
 *
 * The builder structures SQL; it does not hide it. It is fully independent
 * from the entity/mapping layer and is usable on its own. Terminal methods
 * return plain associative-array rows (or an affected-row count for write
 * statements); hydration into entities is the repositories' concern.
 *
 * Two predicate styles are supported on where():
 *
 *   - manual:     ->where('id', '=', 1)
 *   - expressive: ->where('age')->gt(18)
 *
 * In the expressive form the builder delegates the comparison vocabulary to
 * a QueryExpressionClauseInterface and never implements that fluent API
 * itself.
 */
interface QueryBuilderInterface
{
    /**
     * Set the selected columns. Defaults to '*' when never called.
     */
    public function select(string ...$columns): static;

    /**
     * Set the target table(s) for SELECT and DELETE statements.
     *
     * Variadic to support multi-table statements (e.g. a multi-table
     * DELETE: "DELETE t1, t2 FROM table1 t1 JOIN table2 t2 ..."). For raw
     * tables, aliases are expressed inline within each entry ('users u'). For
     * a mapped entity class, an alias may be supplied as the immediately
     * following argument (from(User::class, 'u')); it defaults to the table
     * name. The first mapped entity becomes the active context for
     * entity-style joins and SELECT-driven eager hydration.
     *
     * UPDATE and INSERT do not use from(): they declare their target table
     * up front through update() and insert().
     */
    public function from(string ...$tables): static;

    /**
     * Add a WHERE predicate, combined with AND.
     *
     * When only the column is given, an expression clause is returned to
     * continue the predicate fluently (expressive form). When an operator
     * and value are given, the predicate is complete and the builder is
     * returned (manual form).
     *
     * @return ($operator is null ? QueryExpressionClauseInterface : static)
     */
    public function where(
        string $column,
        ?string $operator = null,
        mixed $value = null
    ): static|QueryExpressionClauseInterface;

    /**
     * Add a WHERE predicate combined with AND. Alias semantics of where().
     *
     * @return ($operator is null ? QueryExpressionClauseInterface : static)
     */
    public function andWhere(
        string $column,
        ?string $operator = null,
        mixed $value = null
    ): static|QueryExpressionClauseInterface;

    /**
     * Add a WHERE predicate combined with OR.
     *
     * @return ($operator is null ? QueryExpressionClauseInterface : static)
     */
    public function orWhere(
        string $column,
        ?string $operator = null,
        mixed $value = null
    ): static|QueryExpressionClauseInterface;

    /**
     * Append a raw WHERE expression with its own positional bindings, combined
     * with AND.
     *
     * Trusted surface: the expression is emitted verbatim and bypasses the
     * identifier whitelist that guards where(), so it can carry native
     * functions and database-specific syntax (e.g. "LOWER(email) = ?",
     * "JSON_EXTRACT(meta, '$.tier') = ?"). The caller owns its safety — it must
     * be a literal/developer-controlled fragment, never built from external
     * input — and every value must travel through a "?" placeholder via
     * $bindings rather than being interpolated.
     *
     * @param list<mixed> $bindings
     */
    public function whereRaw(string $expression, array $bindings = []): static;

    /**
     * Add an INNER JOIN.
     *
     * Dual mode: pass a raw table name together with an explicit ON
     * condition, or pass a mapped entity class so the builder resolves the
     * table and keys from relation metadata. For an entity join the second
     * argument is the join's SQL alias (defaulting to the table name); for a
     * manual join it is the required ON condition. Manual joins always remain
     * available.
     *
     * @param class-string|string $tableOrEntity
     * @param string|null         $aliasOrCondition Entity alias, or the ON
     *                                              condition for the manual form.
     */
    public function join(string $tableOrEntity, ?string $aliasOrCondition = null): static;

    /**
     * Add an INNER JOIN. Explicit alias of join() for readability.
     *
     * @param class-string|string $tableOrEntity
     */
    public function innerJoin(string $tableOrEntity, ?string $aliasOrCondition = null): static;

    /**
     * Add a LEFT JOIN. See join() for the dual-mode contract.
     *
     * @param class-string|string $tableOrEntity
     */
    public function leftJoin(string $tableOrEntity, ?string $aliasOrCondition = null): static;

    /**
     * Add a RIGHT JOIN. See join() for the dual-mode contract.
     *
     * @param class-string|string $tableOrEntity
     */
    public function rightJoin(string $tableOrEntity, ?string $aliasOrCondition = null): static;

    /**
     * Add an ORDER BY clause.
     *
     * The column is validated against the identifier whitelist and the
     * direction against {ASC, DESC} (case-insensitive) at runtime, so a
     * free-string sort parameter can be passed safely; an unknown direction or
     * an unsafe identifier raises a QueryException.
     */
    public function orderBy(string $column, string $direction = 'ASC'): static;

    /**
     * Append a raw ORDER BY expression, emitted verbatim.
     *
     * Trusted surface: it bypasses the identifier/direction whitelist that
     * guards orderBy(), so it can carry native ordering expressions such as
     * "LOWER(name) DESC" or "FIELD(status, 'a', 'b')". The caller owns its
     * safety — it must never be assembled from external input.
     */
    public function orderByRaw(string $expression): static;

    /**
     * Add one or more GROUP BY columns.
     */
    public function groupBy(string ...$columns): static;

    /**
     * Append one or more raw GROUP BY expressions, emitted verbatim.
     *
     * Trusted surface: it bypasses the identifier whitelist that guards
     * groupBy(), so it can carry native grouping expressions such as
     * "YEAR(created_at)". The caller owns its safety — it must never be
     * assembled from external input.
     */
    public function groupByRaw(string ...$expressions): static;

    /**
     * Add a HAVING predicate, combined with AND.
     *
     * Same dual contract as where(): the expressive form (column only)
     * returns an expression clause, the manual form (operator + value)
     * returns the builder.
     *
     * @return ($operator is null ? QueryExpressionClauseInterface : static)
     */
    public function having(
        string $column,
        ?string $operator = null,
        mixed $value = null
    ): static|QueryExpressionClauseInterface;

    /**
     * Append a raw HAVING expression with its own positional bindings.
     *
     * Trusted surface: the expression is emitted verbatim and is not validated
     * against the identifier whitelist that guards having(), so it can carry
     * aggregate expressions such as "COUNT(*) > ?". The caller owns its safety —
     * it must be a literal/developer-controlled fragment, never built from
     * external input — and every value must travel through a "?" placeholder via
     * $bindings rather than being interpolated.
     *
     * @param list<mixed> $bindings
     */
    public function havingRaw(string $expression, array $bindings = []): static;

    /**
     * Limit the number of returned rows.
     */
    public function limit(int $limit): static;

    /**
     * Offset the returned rows.
     */
    public function offset(int $offset): static;

    /**
     * Mark the statement as an INSERT targeting the given table.
     *
     * Row data is supplied separately through values(); insert() never
     * accepts a payload itself.
     */
    public function insert(string $table): static;

    /**
     * Add column => value data to a staged INSERT.
     *
     * Polymorphic: pass a single column name and its value, or a batch
     * column => value array. May be called repeatedly to accumulate data.
     *
     * @param string|array<string, mixed> $column A column name, or a batch of
     *                                            column => value pairs.
     * @param mixed                        $value The value, when $column is a
     *                                            single column name.
     */
    public function values(string|array $column, mixed $value = null): static;

    /**
     * Mark the statement as an UPDATE targeting the given table.
     *
     * Assignments are supplied separately through set(); update() never
     * accepts a payload itself.
     */
    public function update(string $table): static;

    /**
     * Add column => value assignments to a staged UPDATE.
     *
     * Polymorphic: pass a single column name and its value, or a batch
     * column => value array. May be called repeatedly to accumulate
     * assignments.
     *
     * @param string|array<string, mixed> $column A column name, or a batch of
     *                                            column => value pairs.
     * @param mixed                        $value The value, when $column is a
     *                                            single column name.
     */
    public function set(string|array $column, mixed $value = null): static;

    /**
     * Mark the statement as a DELETE. Target table(s) come from from(),
     * which supports multi-table deletes.
     */
    public function delete(): static;

    /**
     * Execute a SELECT and return every matching row.
     *
     * @return list<array<string, mixed>>
     *
     * @throws \G\Orm\Exception\QueryException
     */
    public function get(): array;

    /**
     * Execute a SELECT and return the first matching row, or null.
     *
     * @return array<string, mixed>|null
     *
     * @throws \G\Orm\Exception\QueryException
     */
    public function first(): ?array;

    /**
     * Execute a staged write statement and return the number of affected
     * rows.
     *
     * @throws \G\Orm\Exception\QueryException
     */
    public function execute(): int;

    /**
     * Return the SQL string the builder currently represents.
     */
    public function toSql(): string;

    /**
     * Return the positional bindings collected so far.
     *
     * @return list<mixed>
     */
    public function getBindings(): array;

    /**
     * Return the SQL and its bindings for debugging.
     *
     * @return array{sql: string, bindings: list<mixed>}
     */
    public function debug(): array;
}
