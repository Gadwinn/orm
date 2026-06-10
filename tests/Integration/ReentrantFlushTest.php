<?php

declare(strict_types=1);

namespace G\Orm\Tests\Integration;

use G\Orm\Core\UnitOfWork;
use G\Orm\Exception\OrmException;
use G\Orm\Registry\OrmRegistry;
use G\Orm\Tests\Fixture\ReentrantEntity;
use G\Orm\Tests\OrmTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The flush() re-entrancy guard: a lifecycle hook that triggers another flush
 * must fail explicitly rather than re-iterate the still-pending intention
 * queues (which would double-write rows and recurse without bound).
 */
#[CoversClass(UnitOfWork::class)]
final class ReentrantFlushTest extends OrmTestCase
{
    public function testReentrantFlushFromHookThrowsAndWritesNothing(): void
    {
        OrmRegistry::set($this->manager);

        $entity = new ReentrantEntity();
        $entity->name = 'recursive';
        $this->manager->persist($entity);

        try {
            $this->manager->flush();
            self::fail('Expected a re-entrant flush to be rejected.');
        } catch (OrmException $exception) {
            self::assertStringContainsString(
                'flush() was called while a flush is already in progress',
                $exception->getMessage()
            );
        }

        // The owning flush rolled back and purged state, so the row never landed.
        self::assertSame(0, $this->countRows('registry_aware'));
    }
}
