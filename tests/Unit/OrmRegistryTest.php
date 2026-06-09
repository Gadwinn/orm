<?php

declare(strict_types=1);

namespace G\Orm\Tests\Unit;

use G\Orm\Exception\OrmException;
use G\Orm\Registry\OrmRegistry;
use G\Orm\Registry\StaticManagerResolver;
use G\Orm\Tests\OrmTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The static registry that entities reach the active DbManager through, and its
 * default process-local resolver. The registry holds no manager itself: it
 * delegates binding, lookup and clearing to the installed resolver, so context
 * isolation is a swap-in concern.
 *
 * OrmTestCase::tearDown() calls OrmRegistry::clear() after every test, keeping
 * the static binding from leaking across cases.
 */
#[CoversClass(OrmRegistry::class)]
#[CoversClass(StaticManagerResolver::class)]
final class OrmRegistryTest extends OrmTestCase
{
    public function testGetThrowsWhenNoManagerIsBound(): void
    {
        OrmRegistry::clear();

        self::assertFalse(OrmRegistry::has());

        $this->expectException(OrmException::class);
        $this->expectExceptionMessage('No DbManager is bound to the current execution context');

        OrmRegistry::get();
    }

    public function testSetBindsTheManagerForTheContext(): void
    {
        OrmRegistry::clear();
        OrmRegistry::set($this->manager);

        self::assertTrue(OrmRegistry::has());
        self::assertSame($this->manager, OrmRegistry::get());
    }

    public function testClearUnbindsTheManager(): void
    {
        OrmRegistry::set($this->manager);
        self::assertTrue(OrmRegistry::has());

        OrmRegistry::clear();

        self::assertFalse(OrmRegistry::has());
    }

    public function testUseResolverInstallsACustomIsolationStrategy(): void
    {
        $resolver = new StaticManagerResolver();
        OrmRegistry::useResolver($resolver);

        OrmRegistry::set($this->manager);

        // The registry now reads and writes through the supplied resolver.
        self::assertSame($this->manager, $resolver->get());
        self::assertSame($this->manager, OrmRegistry::get());

        $resolver->clear();
        self::assertNull($resolver->get());
        self::assertFalse(OrmRegistry::has());
    }
}
