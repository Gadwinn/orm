<?php

declare(strict_types=1);

namespace G\Orm\Query;

use G\Orm\Compiler\CompiledQuery;
use G\Orm\Compiler\QueryState;
use G\Orm\Compiler\SqlCompilerInterface;
use G\Orm\Compiler\StatementType;
use G\Orm\Contract\Driver\ConnectionInterface;
use G\Orm\Contract\HydratorInterface;
use G\Orm\Contract\MetadataReaderInterface;
use G\Orm\Contract\QueryBuilderInterface;
use G\Orm\Contract\QueryExpressionClauseInterface;
use G\Orm\Contract\UnitOfWorkInterface;
use G\Orm\Exception\QueryException;
use G\Orm\Mapping\RelationType;
use Generator;
use ReflectionProperty;

/**
 * Concrete SQL-first query builder.
 *
 * It accumulates a mutable QueryState while the developer chains calls, then
 * delegates compilation to a SqlCompilerInterface and execution to a
 * ConnectionInterface on the terminal methods.
 *
 * By default the builder is mapping-independent and only supports manual
 * joins (explicit ON condition). When constructed with a metadata reader it
 * also supports entity-style joins: calling from() with a mapped entity class
 * resolves its physical table and records it as the active entity context, so
 * a later join() on a related entity class can resolve the target table and
 * the "local.localKey = target.foreignKey" condition from relation metadata.
 */
final class QueryBuilder implements QueryBuilderInterface
{
    /**
     * Predicate destinations: a pushed predicate lands either in the WHERE
     * bucket or the HAVING bucket of the query state.
     */
    private const TARGET_WHERE = 'where';
    private const TARGET_HAVING = 'having';

    /**
     * The closed set of comparison operators the inline where()/having() form
     * accepts. Normalised to upper case, anything outside it is rejected before
     * reaching the compiler, so a caller-supplied operator string can never be
     * interpolated into the SQL verbatim.
     *
     * @var list<string>
     */
    private const ALLOWED_OPERATORS = [
        '=', '<>', '!=', '>', '>=', '<', '<=',
        'LIKE', 'NOT LIKE', 'REGEXP', 'NOT REGEXP', 'IN', 'NOT IN',
        'BETWEEN', 'IS NULL', 'IS NOT NULL',
    ];

    /**
     * The only sort directions ORDER BY accepts.
     *
     * @var list<string>
     */
    private const ALLOWED_DIRECTIONS = ['ASC', 'DESC'];

    /**
     * A bare or singly-qualified identifier ("col" or "alias.col"). It rejects
     * whitespace, punctuation, parentheses and operators - exactly the payload
     * an injected column or ORDER BY term needs. Raw SQL expressions
     * (aggregates, function calls) remain available through select() and
     * havingRaw(), which are the trusted SQL-first surfaces.
     *
     * Identifiers are emitted unquoted (SQL-first transparency), so a column
     * named after a reserved word ("order", "group") will not compile on most
     * engines; map such a column to a non-reserved name via #[Column(name:)]
     * or reach it through a raw surface with engine quoting.
     */
    private const IDENTIFIER_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/';

    private QueryState $state;

    /**
     * Active entity context (the from() entity), resolved natively by from()
     * when it is given a mapped entity class. Required for entity-style joins
     * and for SELECT-driven eager hydration.
     *
     * @var class-string|null
     */
    private ?string $currentEntityClass = null;

    /**
     * SQL alias of the from() entity, defaulting to its table name.
     */
    private ?string $rootAlias = null;

    /**
     * Every mapped entity participating in the query, keyed by its SQL alias
     * and recorded in declaration order (root first, then joins). Drives the
     * automatic SELECT expansion and the row ventilation.
     *
     * @var array<string, class-string>
     */
    private array $entityAliases = [];

    /**
     * The joined relations to ventilate, in declaration order. Each entry
     * carries the join alias, the joined entity class and the relation on the
     * root entity that receives the hydrated instance.
     *
     * @var list<array{
     *     alias: string,
     *     class: class-string,
     *     property: string,
     *     type: RelationType
     * }>
     */
    private array $eagerJoins = [];

    public function __construct(
        private readonly SqlCompilerInterface $compiler,
        private readonly ConnectionInterface $connection,
        private readonly ?MetadataReaderInterface $metadataReader = null,
        private readonly ?HydratorInterface $hydrator = null,
        private readonly ?UnitOfWorkInterface $unitOfWork = null,
    ) {
        $this->state = new QueryState();
    }

    public function select(string ...$columns): static
    {
        $this->state->type = StatementType::Select;
        $this->state->columns = array_values($columns);

        return $this;
    }

    public function from(string ...$tables): static
    {
        $this->currentEntityClass = null;
        $this->rootAlias = null;
        $this->entityAliases = [];
        $this->eagerJoins = [];

        $resolved = [];
        $count = count($tables);
        for ($index = 0; $index < $count; $index++) {
            $table = $tables[$index];

            if ($this->metadataReader === null || !class_exists($table)) {
                $resolved[] = $table;

                continue;
            }

            $physical = $this->metadataReader->getSchemaFor($table)->getTableName();

            // A mapped entity may be followed by its alias; a following token
            // that is itself a class is the next table, not an alias.
            $alias = $physical;
            if (isset($tables[$index + 1]) && !class_exists($tables[$index + 1])) {
                $alias = $tables[++$index];
            }

            $resolved[] = $this->aliasedTable($physical, $alias);
            $this->entityAliases[$alias] = $table;

            if ($this->currentEntityClass === null) {
                $this->currentEntityClass = $table;
                $this->rootAlias = $alias;
            }
        }

        $this->state->from = $resolved;

        return $this;
    }

    public function where(
        string $column,
        ?string $operator = null,
        mixed $value = null
    ): static|QueryExpressionClauseInterface {
        return $this->predicate(self::TARGET_WHERE, 'AND', $column, $operator, $value);
    }

    public function andWhere(
        string $column,
        ?string $operator = null,
        mixed $value = null
    ): static|QueryExpressionClauseInterface {
        return $this->predicate(self::TARGET_WHERE, 'AND', $column, $operator, $value);
    }

    public function orWhere(
        string $column,
        ?string $operator = null,
        mixed $value = null
    ): static|QueryExpressionClauseInterface {
        return $this->predicate(self::TARGET_WHERE, 'OR', $column, $operator, $value);
    }

    /**
     * @param literal-string $expression
     * @param list<mixed>    $bindings
     */
    public function whereRaw(string $expression, array $bindings = []): static
    {
        $this->assertRawExpression($expression);

        $this->state->wheres[] = $this->rawPredicate($expression, $bindings);

        return $this;
    }

    public function having(
        string $column,
        ?string $operator = null,
        mixed $value = null
    ): static|QueryExpressionClauseInterface {
        return $this->predicate(self::TARGET_HAVING, 'AND', $column, $operator, $value);
    }

    /**
     * @param literal-string $expression
     * @param list<mixed>    $bindings
     */
    public function havingRaw(string $expression, array $bindings = []): static
    {
        $this->assertRawExpression($expression);

        $this->state->having[] = $this->rawPredicate($expression, $bindings);

        return $this;
    }

    /**
     * Build a raw predicate entry for the WHERE or HAVING bucket: the
     * expression is emitted verbatim by the compiler with its own positional
     * bindings, combined with the preceding predicate using AND.
     *
     * @param list<mixed> $bindings
     *
     * @return array{boolean: string, column: string, operator: string, values: list<mixed>, raw: bool}
     */
    private function rawPredicate(string $expression, array $bindings): array
    {
        return [
            'boolean' => 'AND',
            'column' => $expression,
            'operator' => '',
            'values' => $bindings,
            'raw' => true,
        ];
    }

    public function join(string $tableOrEntity, ?string $aliasOrCondition = null): static
    {
        return $this->addJoin('INNER', $tableOrEntity, $aliasOrCondition);
    }

    public function innerJoin(string $tableOrEntity, ?string $aliasOrCondition = null): static
    {
        return $this->addJoin('INNER', $tableOrEntity, $aliasOrCondition);
    }

    public function leftJoin(string $tableOrEntity, ?string $aliasOrCondition = null): static
    {
        return $this->addJoin('LEFT', $tableOrEntity, $aliasOrCondition);
    }

    public function rightJoin(string $tableOrEntity, ?string $aliasOrCondition = null): static
    {
        return $this->addJoin('RIGHT', $tableOrEntity, $aliasOrCondition);
    }

    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        $this->assertSafeIdentifier($column);

        $normalized = strtoupper($direction);
        if (!in_array($normalized, self::ALLOWED_DIRECTIONS, true)) {
            throw new QueryException(
                sprintf('Invalid sort direction "%s"; expected ASC or DESC.', $direction)
            );
        }

        $this->state->orderBy[] = ['column' => $column, 'direction' => $normalized, 'raw' => false];

        return $this;
    }

    /**
     * @param literal-string $expression
     */
    public function orderByRaw(string $expression): static
    {
        $this->assertRawExpression($expression);

        $this->state->orderBy[] = ['column' => $expression, 'direction' => '', 'raw' => true];

        return $this;
    }

    public function groupBy(string ...$columns): static
    {
        foreach ($columns as $column) {
            $this->assertSafeIdentifier($column);
        }

        $this->state->groupBy = array_merge($this->state->groupBy, array_values($columns));

        return $this;
    }

    /**
     * @param literal-string ...$expressions
     */
    public function groupByRaw(string ...$expressions): static
    {
        foreach ($expressions as $expression) {
            $this->assertRawExpression($expression);
        }

        $this->state->groupBy = array_merge($this->state->groupBy, array_values($expressions));

        return $this;
    }

    public function limit(int $limit): static
    {
        $this->state->limit = $limit;

        return $this;
    }

    public function offset(int $offset): static
    {
        $this->state->offset = $offset;

        return $this;
    }

    public function insert(string $table): static
    {
        $this->state->type = StatementType::Insert;
        $this->state->table = $table;

        return $this;
    }

    public function values(string|array $column, mixed $value = null): static
    {
        return $this->assign($column, $value);
    }

    public function update(string $table): static
    {
        $this->state->type = StatementType::Update;
        $this->state->table = $table;

        return $this;
    }

    public function set(string|array $column, mixed $value = null): static
    {
        return $this->assign($column, $value);
    }

    public function delete(): static
    {
        $this->state->type = StatementType::Delete;

        return $this;
    }

    public function get(): array
    {
        $compiled = $this->compile();
        $statement = $this->connection->prepare($compiled->sql);

        return $statement->execute($compiled->bindings)->fetchAll();
    }

    /**
     * Execute a SELECT and stream the rows one at a time instead of
     * materialising the whole result set, as get() does.
     *
     * The same compiled query and bindings as get(); only the consumption
     * differs. Use it for exports and batch jobs where the result set would
     * not fit comfortably in memory. The statement stays open for the life of
     * the generator, so iterate it to completion (or let it fall out of
     * scope) before issuing another query on an unbuffered connection.
     *
     * Memory note: fetching row by row only lowers the footprint when the
     * driver itself does not buffer the whole set client-side. SQLite streams
     * natively; MySQL buffers by default - pass
     * PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false through the connection
     * "options" to stream there too (no other query may run on that
     * connection until the cursor is drained).
     *
     * @return Generator<int, array<string, mixed>>
     *
     * @throws QueryException
     */
    public function cursor(): Generator
    {
        $compiled = $this->compile();
        $result = $this->connection->prepare($compiled->sql)->execute($compiled->bindings);

        while (($row = $result->fetchAssociative()) !== null) {
            yield $row;
        }
    }

    /**
     * Execute the SELECT and ventilate each flat row into hydrated entities.
     *
     * This is the SELECT-driven eager-loading terminal. There is no with(),
     * no proxy and no lazy loading: which related entities are hydrated is
     * decided strictly by the columns present in the result set.
     *
     *   - When the select is empty or a bare "*", it is expanded to every
     *     registered entity's columns, alias-prefixed ("u.id AS u__id") so the
     *     joined datasets never collide under PDO's associative fetch.
     *   - Each row is split per alias; the root entity is hydrated once per
     *     identity (enforced through the identity map) and each joined entity
     *     present in the row is injected into its matching relation property.
     *   - A joined entity whose columns are absent from the select is ignored
     *     entirely: the relation property is left untouched, never lazily
     *     fetched.
     *
     * Every hydrated entity (root and joined alike) is registered with the
     * Unit Of Work, so it is change-tracked exactly like a repository-loaded
     * one: mutate it and the next flush() writes the diff.
     *
     * @return list<object>
     *
     * @throws QueryException
     */
    public function getEntities(): array
    {
        $metadata = $this->metadataReader;
        $hydrator = $this->hydrator;
        $unitOfWork = $this->unitOfWork;

        if ($metadata === null || $hydrator === null || $unitOfWork === null) {
            throw new QueryException(
                'getEntities() requires a metadata reader, a hydrator and a unit of work; '
                . 'build the query through a repository or pass them to the constructor.'
            );
        }

        $rootClass = $this->currentEntityClass;
        $rootAlias = $this->rootAlias;
        if ($rootClass === null || $rootAlias === null) {
            throw new QueryException(
                'getEntities() requires from() to be called with a mapped entity class first.'
            );
        }

        // The wildcard expansion is scoped to this execution: the developer's
        // select is restored afterwards so a later get()/toSql() on the same
        // builder is not silently rewritten.
        $originalColumns = $this->state->columns;
        $this->expandWildcardSelect($metadata);

        try {
            $rows = $this->get();
        } finally {
            $this->state->columns = $originalColumns;
        }

        return $this->ventilate($rows, $rootClass, $rootAlias, $hydrator, $unitOfWork);
    }

    public function first(): ?array
    {
        $state = clone $this->state;
        $state->limit = 1;

        $compiled = $this->compiler->compile($state);
        $statement = $this->connection->prepare($compiled->sql);

        return $statement->execute($compiled->bindings)->fetchAssociative();
    }

    public function execute(): int
    {
        $compiled = $this->compile();
        $statement = $this->connection->prepare($compiled->sql);

        return $statement->execute($compiled->bindings)->rowCount();
    }

    public function toSql(): string
    {
        return $this->compile()->sql;
    }

    public function getBindings(): array
    {
        return $this->compile()->bindings;
    }

    public function debug(): array
    {
        $compiled = $this->compile();

        return ['sql' => $compiled->sql, 'bindings' => $compiled->bindings];
    }

    /**
     * Append a predicate from the expressive clause. Public for the
     * QueryExpressionClause callback only.
     *
     * @internal
     *
     * @param list<mixed> $values
     */
    public function pushPredicate(
        string $target,
        string $boolean,
        string $column,
        string $operator,
        array $values
    ): static {
        $predicate = [
            'boolean' => $boolean,
            'column' => $column,
            'operator' => $operator,
            'values' => $values,
            'raw' => false,
        ];

        if ($target === self::TARGET_HAVING) {
            $this->state->having[] = $predicate;
        } else {
            $this->state->wheres[] = $predicate;
        }

        return $this;
    }

    private function predicate(
        string $target,
        string $boolean,
        string $column,
        ?string $operator,
        mixed $value
    ): static|QueryExpressionClauseInterface {
        $this->assertSafeIdentifier($column);

        if ($operator === null) {
            return new QueryExpressionClause($this, $target, $boolean, $column);
        }

        $normalized = strtoupper(trim($operator));
        if (!in_array($normalized, self::ALLOWED_OPERATORS, true)) {
            throw new QueryException(sprintf('Unsupported SQL operator "%s".', $operator));
        }

        return $this->pushPredicate($target, $boolean, $column, $normalized, $this->manualValues($normalized, $value));
    }

    /**
     * Guard a column/identifier token bound for a clause that cannot be
     * parameterised (WHERE/HAVING column, ORDER BY / GROUP BY term). Accepts a
     * bare or singly-qualified identifier only; anything else is an injection
     * attempt or an expression that belongs in select()/havingRaw().
     */
    private function assertSafeIdentifier(string $identifier): void
    {
        if (preg_match(self::IDENTIFIER_PATTERN, $identifier) !== 1) {
            throw new QueryException(sprintf('Unsafe SQL identifier "%s".', $identifier));
        }
    }

    /**
     * Reject a blank raw expression up front: emitted verbatim, it would
     * compile into syntactically broken SQL ("WHERE  AND ...") that only fails
     * at execution time, far from its origin.
     */
    private function assertRawExpression(string $expression): void
    {
        if (trim($expression) === '') {
            throw new QueryException('A raw SQL expression must not be empty.');
        }
    }

    /**
     * @return list<mixed>
     */
    private function manualValues(string $operator, mixed $value): array
    {
        return match (strtoupper($operator)) {
            'IS NULL', 'IS NOT NULL' => [],
            'IN', 'NOT IN', 'BETWEEN' => array_values(is_array($value) ? $value : [$value]),
            default => [$value],
        };
    }

    private function addJoin(string $type, string $tableOrEntity, ?string $aliasOrCondition): static
    {
        if (class_exists($tableOrEntity)) {
            return $this->addEntityJoin($type, $tableOrEntity, $aliasOrCondition);
        }

        if ($aliasOrCondition === null) {
            throw new QueryException(
                sprintf('A manual join on table "%s" requires an explicit ON condition.', $tableOrEntity)
            );
        }

        $this->state->joins[] = ['type' => $type, 'table' => $tableOrEntity, 'condition' => $aliasOrCondition];

        return $this;
    }

    /**
     * @param class-string $targetEntity
     */
    private function addEntityJoin(string $type, string $targetEntity, ?string $alias): static
    {
        if ($this->metadataReader === null) {
            throw new QueryException(
                sprintf(
                    'Entity-style join on "%s" requires a metadata reader; '
                    . 'build the query through a repository or pass one to the constructor.',
                    $targetEntity
                )
            );
        }

        if ($this->currentEntityClass === null || $this->rootAlias === null) {
            throw new QueryException(
                sprintf(
                    'Entity-style join on "%s" requires from() to be called with a mapped entity class first.',
                    $targetEntity
                )
            );
        }

        $schema = $this->metadataReader->getSchemaFor($this->currentEntityClass);

        $relation = null;
        foreach ($schema->getRelations() as $candidate) {
            if ($candidate->getTargetEntity() === $targetEntity) {
                $relation = $candidate;

                break;
            }
        }

        if ($relation === null) {
            throw new QueryException(
                sprintf('No relation from %s to %s is declared.', $this->currentEntityClass, $targetEntity)
            );
        }

        $targetTable = $this->metadataReader->getSchemaFor($targetEntity)->getTableName();
        $alias ??= $targetTable;

        $condition = sprintf(
            '%s.%s = %s.%s',
            $this->rootAlias,
            $relation->getLocalKey(),
            $alias,
            $relation->getForeignKey()
        );

        $this->state->joins[] = [
            'type' => $type,
            'table' => $this->aliasedTable($targetTable, $alias),
            'condition' => $condition,
        ];

        $this->entityAliases[$alias] = $targetEntity;
        $this->eagerJoins[] = [
            'alias' => $alias,
            'class' => $targetEntity,
            'property' => $relation->getPropertyName(),
            'type' => $relation->getType(),
        ];

        return $this;
    }

    /**
     * Render a "table" or "table alias" fragment, omitting the alias when it
     * is identical to the physical table name so unaliased queries stay clean.
     */
    private function aliasedTable(string $table, string $alias): string
    {
        return $alias === $table ? $table : $table . ' ' . $alias;
    }

    /**
     * @param string|array<string, mixed> $column
     */
    private function assign(string|array $column, mixed $value): static
    {
        if (is_array($column)) {
            foreach ($column as $key => $assigned) {
                $this->state->values[$key] = $assigned;
            }

            return $this;
        }

        $this->state->values[$column] = $value;

        return $this;
    }

    /**
     * Expand an empty or bare-"*" select into every registered entity's
     * columns, alias-prefixed so joined datasets cannot collide under PDO's
     * associative fetch (e.g. "u.id AS u__id, a.id AS a__id"). An explicit
     * select is left exactly as the developer wrote it.
     */
    private function expandWildcardSelect(MetadataReaderInterface $metadata): void
    {
        if ($this->state->columns !== [] && $this->state->columns !== ['*']) {
            return;
        }

        $columns = [];
        foreach ($this->entityAliases as $alias => $class) {
            foreach ($metadata->getSchemaFor($class)->getProperties() as $property) {
                $column = $property->getColumnName();
                $columns[] = sprintf('%s.%s AS %s__%s', $alias, $column, $alias, $column);
            }
        }

        if ($columns !== []) {
            $this->state->columns = $columns;
        }
    }

    /**
     * Ventilate flat rows into a deduplicated list of root entities, injecting
     * each joined entity present in a row into its relation property.
     *
     * @param list<array<string, mixed>> $rows
     * @param class-string                $rootClass
     *
     * @return list<object>
     */
    private function ventilate(
        array $rows,
        string $rootClass,
        string $rootAlias,
        HydratorInterface $hydrator,
        UnitOfWorkInterface $unitOfWork
    ): array {
        /** @var array<int, true> $seen */
        $seen = [];
        $roots = [];

        foreach ($rows as $row) {
            $root = $this->hydrateAliased($rootClass, $rootAlias, $row, $hydrator, $unitOfWork);
            if ($root === null) {
                continue;
            }

            $key = spl_object_id($root);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $roots[] = $root;
            }

            foreach ($this->eagerJoins as $join) {
                $related = $this->hydrateAliased($join['class'], $join['alias'], $row, $hydrator, $unitOfWork);
                if ($related !== null) {
                    $this->link($root, $join['property'], $join['type'], $related);
                }
            }
        }

        return $roots;
    }

    /**
     * Hydrate one alias's slice of a row, resolving the instance through the
     * identity map so a row that repeats a key reuses the same object. A fresh
     * instance is registered with the Unit Of Work - identity map entry plus
     * original-state snapshot - so it is change-tracked from the moment it is
     * returned. Returns null when the alias contributed no columns (its dataset
     * was not selected) or only nulls (an unmatched outer join).
     *
     * @param class-string         $class
     * @param array<string, mixed> $row
     */
    private function hydrateAliased(
        string $class,
        string $alias,
        array $row,
        HydratorInterface $hydrator,
        UnitOfWorkInterface $unitOfWork
    ): ?object {
        $subRow = $this->subRowFor($alias, $row);
        if ($subRow === null) {
            return null;
        }

        $entity = $hydrator->hydrate($class, $subRow);
        $identifier = $hydrator->extractIdentifier($entity);
        if ($identifier === null) {
            return $entity;
        }

        $identityMap = $unitOfWork->identityMap();
        if ($identityMap->has($class, $identifier)) {
            return $identityMap->get($class, $identifier) ?? $entity;
        }

        $unitOfWork->register($entity, $identifier);

        return $entity;
    }

    /**
     * Extract one alias's columns from a flat row.
     *
     * Alias-prefixed keys ("u__id") are stripped to their bare column name.
     * When no prefixed key is present, only the root alias falls back to the
     * bare row (an explicit "u.*" selection returns unprefixed columns); a
     * joined alias absent from the row means its dataset was not requested. A
     * sub-row whose every value is null (an unmatched outer join) yields null.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>|null
     */
    private function subRowFor(string $alias, array $row): ?array
    {
        $prefix = $alias . '__';
        $sub = [];
        foreach ($row as $column => $value) {
            if (str_starts_with($column, $prefix)) {
                $sub[substr($column, strlen($prefix))] = $value;
            }
        }

        if ($sub === []) {
            return $alias === $this->rootAlias ? $row : null;
        }

        foreach ($sub as $value) {
            if ($value !== null) {
                return $sub;
            }
        }

        return null;
    }

    /**
     * Inject a hydrated relation instance into the root's relation property:
     * a single assignment for to-one relations, an accumulating append for
     * to-many collections.
     */
    private function link(object $root, string $property, RelationType $type, object $related): void
    {
        $reflection = new ReflectionProperty($root, $property);

        if ($type !== RelationType::HasMany) {
            $reflection->setValue($root, $related);

            return;
        }

        $collection = $reflection->isInitialized($root) ? $reflection->getValue($root) : [];
        if (!is_array($collection)) {
            $collection = [];
        }

        if (!in_array($related, $collection, true)) {
            $collection[] = $related;
        }

        $reflection->setValue($root, $collection);
    }

    private function compile(): CompiledQuery
    {
        return $this->compiler->compile($this->state);
    }
}
