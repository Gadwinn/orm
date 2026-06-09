<?php

declare(strict_types=1);

namespace G\Orm\Registry;

use G\Orm\Contract\DbManagerInterface;
use G\Orm\Contract\ManagerResolverInterface;

/**
 * Default resolver: holds a single manager instance for the whole process.
 *
 * Correct for one-request-per-process runtimes (PHP-FPM, CLI). It is NOT safe
 * for concurrent runtimes that reuse the worker across requests/coroutines;
 * install a context-aware ManagerResolverInterface there instead.
 */
final class StaticManagerResolver implements ManagerResolverInterface
{
    private ?DbManagerInterface $manager = null;

    public function get(): ?DbManagerInterface
    {
        return $this->manager;
    }

    public function set(DbManagerInterface $manager): void
    {
        $this->manager = $manager;
    }

    public function clear(): void
    {
        $this->manager = null;
    }
}
