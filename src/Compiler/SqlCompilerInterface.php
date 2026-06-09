<?php

declare(strict_types=1);

namespace G\Orm\Compiler;

/**
 * Turns a QueryState into parameterised SQL and bindings.
 *
 * Implementations must be stateless and safe to share: compiling never
 * mutates the given state. The produced SQL uses positional `?` placeholders
 * and the returned bindings are a flat list in placeholder order, so values
 * are never interpolated into the SQL string.
 */
interface SqlCompilerInterface
{
    /**
     * @throws \G\Orm\Exception\QueryException When the state is incomplete
     *         (e.g. a write with no target table or no values).
     */
    public function compile(QueryState $state): CompiledQuery;
}
