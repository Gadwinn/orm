<?php

declare(strict_types=1);

namespace G\Orm\Compiler;

/**
 * Immutable result of compiling a QueryState: a parameterised SQL string and
 * the flat, ordered list of positional bindings that go with it.
 */
final class CompiledQuery
{
    /**
     * @param list<mixed> $bindings
     */
    public function __construct(
        public readonly string $sql,
        public readonly array $bindings,
    ) {
    }
}
