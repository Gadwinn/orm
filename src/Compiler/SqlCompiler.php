<?php

declare(strict_types=1);

namespace G\Orm\Compiler;

use G\Orm\Compiler\Platform\PlatformInterface;
use G\Orm\Compiler\Platform\SqlitePlatform;
use G\Orm\Exception\QueryException;

/**
 * Default SQL compiler.
 *
 * Stateless and reusable. It parameterises every value with positional `?`
 * placeholders and emits a flat binding list in placeholder order. It never
 * quotes identifiers: identifiers are emitted exactly as provided, which
 * keeps expressions, aliases and qualified names ("t.col", "COUNT(*) AS n")
 * transparent and avoids breaking valid SQL. Identifier quoting, when needed,
 * is the connection's concern.
 *
 * Engine-neutral except where SQL genuinely diverges: those fragments
 * (regular-expression predicates today) are delegated to the injected
 * {@see PlatformInterface}, defaulting to the standard-conforming SQLite
 * platform.
 */
final class SqlCompiler implements SqlCompilerInterface
{
    public function __construct(
        private readonly PlatformInterface $platform = new SqlitePlatform(),
    ) {
    }

    public function compile(QueryState $state): CompiledQuery
    {
        return match ($state->type) {
            StatementType::Select => $this->compileSelect($state),
            StatementType::Insert => $this->compileInsert($state),
            StatementType::Update => $this->compileUpdate($state),
            StatementType::Delete => $this->compileDelete($state),
        };
    }

    private function compileSelect(QueryState $state): CompiledQuery
    {
        if ($state->from === []) {
            throw new QueryException('A SELECT statement requires at least one FROM table.');
        }

        $columns = $state->columns === [] ? '*' : implode(', ', $state->columns);

        $sql = 'SELECT ' . $columns . ' FROM ' . implode(', ', $state->from);
        $sql .= $this->compileJoins($state->joins);

        $bindings = [];

        [$whereSql, $whereBindings] = $this->compilePredicates($state->wheres);
        if ($whereSql !== '') {
            $sql .= ' WHERE ' . $whereSql;
            $bindings = array_merge($bindings, $whereBindings);
        }

        if ($state->groupBy !== []) {
            $sql .= ' GROUP BY ' . implode(', ', $state->groupBy);
        }

        [$havingSql, $havingBindings] = $this->compilePredicates($state->having);
        if ($havingSql !== '') {
            $sql .= ' HAVING ' . $havingSql;
            $bindings = array_merge($bindings, $havingBindings);
        }

        $sql .= $this->compileOrderBy($state->orderBy);
        $sql .= $this->platform->compileLimitOffset($state->limit, $state->offset, $state->orderBy !== []);

        return new CompiledQuery($sql, $bindings);
    }

    private function compileInsert(QueryState $state): CompiledQuery
    {
        if ($state->table === null) {
            throw new QueryException('An INSERT statement requires a target table.');
        }

        if ($state->values === []) {
            throw new QueryException('An INSERT statement requires at least one value.');
        }

        $columns = array_keys($state->values);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        $sql = 'INSERT INTO ' . $state->table
            . ' (' . implode(', ', $columns) . ')'
            . ' VALUES (' . $placeholders . ')';

        return new CompiledQuery($sql, array_values($state->values));
    }

    private function compileUpdate(QueryState $state): CompiledQuery
    {
        if ($state->table === null) {
            throw new QueryException('An UPDATE statement requires a target table.');
        }

        if ($state->values === []) {
            throw new QueryException('An UPDATE statement requires at least one assignment.');
        }

        $assignments = [];
        $bindings = [];
        foreach ($state->values as $column => $value) {
            $assignments[] = $column . ' = ?';
            $bindings[] = $value;
        }

        $sql = 'UPDATE ' . $state->table . ' SET ' . implode(', ', $assignments);

        [$whereSql, $whereBindings] = $this->compilePredicates($state->wheres);
        if ($whereSql !== '') {
            $sql .= ' WHERE ' . $whereSql;
            $bindings = array_merge($bindings, $whereBindings);
        }

        return new CompiledQuery($sql, $bindings);
    }

    private function compileDelete(QueryState $state): CompiledQuery
    {
        if ($state->from === []) {
            throw new QueryException('A DELETE statement requires at least one FROM table.');
        }

        $sql = 'DELETE FROM ' . implode(', ', $state->from);
        $sql .= $this->compileJoins($state->joins);

        $bindings = [];

        [$whereSql, $whereBindings] = $this->compilePredicates($state->wheres);
        if ($whereSql !== '') {
            $sql .= ' WHERE ' . $whereSql;
            $bindings = array_merge($bindings, $whereBindings);
        }

        return new CompiledQuery($sql, $bindings);
    }

    /**
     * @param list<array{type: string, table: string, condition: string}> $joins
     */
    private function compileJoins(array $joins): string
    {
        $sql = '';
        foreach ($joins as $join) {
            $sql .= ' ' . strtoupper($join['type']) . ' JOIN ' . $join['table']
                . ' ON ' . $join['condition'];
        }

        return $sql;
    }

    /**
     * Compile a predicate list (WHERE or HAVING) into a clause body and its
     * bindings. The body excludes the leading keyword.
     *
     * @param list<array{boolean: string, column: string, operator: string, values: list<mixed>, raw: bool}> $predicates
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function compilePredicates(array $predicates): array
    {
        if ($predicates === []) {
            return ['', []];
        }

        $sql = '';
        $bindings = [];

        foreach ($predicates as $index => $predicate) {
            if ($index > 0) {
                $sql .= ' ' . strtoupper($predicate['boolean']) . ' ';
            }

            [$fragment, $values] = $this->compilePredicate($predicate);
            $sql .= $fragment;
            $bindings = array_merge($bindings, $values);
        }

        return [$sql, $bindings];
    }

    /**
     * @param array{boolean: string, column: string, operator: string, values: list<mixed>, raw: bool} $predicate
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function compilePredicate(array $predicate): array
    {
        if ($predicate['raw']) {
            // A trusted havingRaw() fragment: the placeholders already live in
            // the expression, so it is emitted verbatim with its own bindings.
            return [$predicate['column'], $predicate['values']];
        }

        $column = $predicate['column'];
        $operator = strtoupper($predicate['operator']);
        $values = $predicate['values'];

        return match ($operator) {
            'IS NULL' => [$column . ' IS NULL', []],
            'IS NOT NULL' => [$column . ' IS NOT NULL', []],
            'IN' => $this->compileInPredicate($column, $values, false),
            'NOT IN' => $this->compileInPredicate($column, $values, true),
            'BETWEEN' => [$column . ' BETWEEN ? AND ?', $values],
            'REGEXP' => [$this->platform->compileRegexp($column, '?'), $values],
            'NOT REGEXP' => [$this->platform->compileNotRegexp($column, '?'), $values],
            default => [$column . ' ' . $operator . ' ?', $values],
        };
    }

    /**
     * @param list<mixed> $values
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function compileInPredicate(string $column, array $values, bool $negated): array
    {
        if ($values === []) {
            // An empty IN () is invalid SQL; fall back to a constant predicate
            // that preserves the intended truth value.
            return [$negated ? '1 = 1' : '1 = 0', []];
        }

        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $keyword = $negated ? 'NOT IN' : 'IN';

        return [$column . ' ' . $keyword . ' (' . $placeholders . ')', $values];
    }

    /**
     * @param list<array{column: string, direction: string, raw: bool}> $orderBy
     */
    private function compileOrderBy(array $orderBy): string
    {
        if ($orderBy === []) {
            return '';
        }

        $parts = [];
        foreach ($orderBy as $term) {
            // A raw term (orderByRaw) is a trusted, verbatim expression that
            // already carries its own direction; a standard term pairs the
            // validated column with its whitelisted direction.
            $parts[] = $term['raw']
                ? $term['column']
                : $term['column'] . ' ' . strtoupper($term['direction']);
        }

        return ' ORDER BY ' . implode(', ', $parts);
    }
}
