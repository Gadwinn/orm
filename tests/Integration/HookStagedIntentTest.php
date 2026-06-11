<?php

declare(strict_types=1);

namespace G\Orm\Tests\Integration;

use G\Orm\Core\UnitOfWork;
use G\Orm\Registry\OrmRegistry;
use G\Orm\Tests\Fixture\AuditingEntity;
use G\Orm\Tests\OrmTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Granular intent de-registration: synchronise() unsets exactly the object ids
 * the flush processed, so an entity persisted by a lifecycle hook *during* the
 * flush stays queued and is written by the next flush. Before the fix, the
 * bulk queue reset silently destroyed it.
 */
#[CoversClass(UnitOfWork::class)]
final class HookStagedIntentTest extends OrmTestCase
{
    public function testEntityPersistedByHookSurvivesUntilTheNextFlush(): void
    {
        OrmRegistry::set($this->manager);

        $entity = new AuditingEntity();
        $entity->name = 'main';
        $this->manager->persist($entity);
        $this->manager->flush();

        // The audit row staged by #[AfterInsert] was not part of the iterated
        // queue snapshot; it must still be pending, not written and not lost.
        self::assertSame(1, $this->countRows('registry_aware'));

        $this->manager->flush();

        self::assertSame(2, $this->countRows('registry_aware'));
        self::assertSame(
            [['name' => 'main'], ['name' => 'audit:main']],
            $this->manager->query('SELECT name FROM registry_aware ORDER BY id')
        );
    }

    public function testFlushWithNoNewIntentionsAfterCatchUpIsANoOp(): void
    {
        OrmRegistry::set($this->manager);

        $entity = new AuditingEntity();
        $entity->name = 'main';
        $this->manager->persist($entity);
        $this->manager->flush();
        $this->manager->flush();

        // A third flush has nothing staged: the audit row's own hook returned
        // early, so the queue is genuinely empty and row count is stable.
        $this->manager->flush();

        self::assertSame(2, $this->countRows('registry_aware'));
    }
}
