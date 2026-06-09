<?php

declare(strict_types=1);

namespace G\Orm\Registry;

use G\Orm\Contract\DbManagerInterface;
use G\Orm\Contract\ManagerResolverInterface;
use G\Orm\Exception\OrmException;

/**
 * Static entry point used by entities to reach the active DbManager.
 *
 * The registry deliberately stores no naive global manager. The only static
 * state it holds is the ManagerResolverInterface, which owns the actual
 * binding and is responsible for execution-context isolation. By swapping the
 * resolver, the same registry works on PHP-FPM/CLI (default static resolver)
 * and on FrankenPHP/RoadRunner/Swoole (a coroutine/worker-aware resolver),
 * with no change to entity code.
 *
 * Typical bootstrap:
 *
 *     OrmRegistry::set($dbManager);            // bind for this context
 *     // ... handle request ...
 *     OrmRegistry::clear();                    // reset at the boundary
 *
 * On a concurrent runtime, install the isolation strategy once at worker
 * start:
 *
 *     OrmRegistry::useResolver(new CoroutineManagerResolver());
 */
final class OrmRegistry
{
    private static ?ManagerResolverInterface $resolver = null;

    private function __construct()
    {
    }

    /**
     * Install the resolver responsible for context isolation. Replaces any
     * previously installed resolver.
     */
    public static function useResolver(ManagerResolverInterface $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * Bind the active manager for the current execution context.
     */
    public static function set(DbManagerInterface $manager): void
    {
        self::resolver()->set($manager);
    }

    /**
     * Return the active manager for the current execution context.
     *
     * @throws OrmException When no manager is bound to the context.
     */
    public static function get(): DbManagerInterface
    {
        $manager = self::resolver()->get();

        if ($manager === null) {
            throw OrmException::managerNotFound();
        }

        return $manager;
    }

    /**
     * Whether a manager is bound to the current execution context.
     */
    public static function has(): bool
    {
        return self::resolver()->get() !== null;
    }

    /**
     * Unbind the manager from the current execution context. Call this at the
     * request/coroutine boundary to prevent state leaking across boundaries.
     */
    public static function clear(): void
    {
        self::resolver()->clear();
    }

    /**
     * Return the installed resolver, lazily defaulting to the process-local
     * StaticManagerResolver.
     */
    private static function resolver(): ManagerResolverInterface
    {
        return self::$resolver ??= new StaticManagerResolver();
    }
}
