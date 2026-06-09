<?php

declare(strict_types=1);

namespace G\Orm\Contract;

/**
 * Resolves the DbManager that is active for the current execution context.
 *
 * This is the isolation seam used by OrmRegistry. The registry itself never
 * hardcodes a global instance; it delegates "which manager is active" to a
 * resolver. The default resolver keeps a single in-process instance, which
 * is correct for traditional one-request-per-process runtimes (PHP-FPM, CLI).
 *
 * On persistent, concurrent runtimes (FrankenPHP, RoadRunner, Swoole) a
 * context-aware resolver must be installed so that each request/coroutine
 * sees its own manager and state never leaks across boundaries. Such an
 * implementation typically keys the manager by coroutine/worker id.
 */
interface ManagerResolverInterface
{
    /**
     * Return the manager bound to the current context, or null when none is
     * bound.
     */
    public function get(): ?DbManagerInterface;

    /**
     * Bind a manager to the current context.
     */
    public function set(DbManagerInterface $manager): void;

    /**
     * Unbind the manager from the current context.
     */
    public function clear(): void;
}
