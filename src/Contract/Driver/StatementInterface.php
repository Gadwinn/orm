<?php

declare(strict_types=1);

namespace G\Orm\Contract\Driver;

/**
 * Wraps a prepared statement.
 *
 * Values may be bound one by one with bind() and/or supplied in bulk at
 * execution time via execute(). Positional (1-based int) and named (string)
 * parameters are both supported, matching the placeholders used in the SQL.
 */
interface StatementInterface
{
    /**
     * Bind a single value to a parameter, returning the statement for
     * chaining.
     *
     * @param string|int $param Named placeholder, or 1-based positional index.
     *
     * @throws \G\Orm\Exception\QueryException
     */
    public function bind(string|int $param, mixed $value): self;

    /**
     * Execute the statement, optionally supplying parameters in bulk. Bulk
     * parameters are merged with any previously bound values.
     *
     * @param array<string|int, mixed> $params
     *
     * @throws \G\Orm\Exception\QueryException When execution fails.
     */
    public function execute(array $params = []): ResultInterface;
}
