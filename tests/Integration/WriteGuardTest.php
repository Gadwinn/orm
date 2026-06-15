<?php

declare(strict_types=1);

namespace G\Orm\Tests\Integration;

use G\Orm\Core\UnitOfWork;
use G\Orm\Exception\OrmException;
use G\Orm\Tests\Fixture\User;
use G\Orm\Tests\OrmTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Write-integrity guards of the flush pipeline.
 *
 * A predicate "pk = NULL" matches no row in SQL, so an UPDATE or DELETE built
 * from an incomplete primary key would be a silent no-op while hooks still
 * fired and the entity was still de-registered. Likewise, a write that
 * affects zero rows means the database row vanished between load and flush -
 * a lost update. Both conditions must abort the flush loudly instead of
 * letting memory and database drift apart.
 */
#[CoversClass(UnitOfWork::class)]
#[CoversClass(OrmException::class)]
final class WriteGuardTest extends OrmTestCase
{
    public function testDeletingAnEntityWithoutIdentifierFailsLoudly(): void
    {
        $this->seedRow("INSERT INTO users (id, name, email) VALUES (1, 'Ada', 'ada@test.com')");

        // A fresh entity whose auto-increment id was never assigned: the
        // DELETE it would produce ("id = NULL") could never match a row.
        $ghost = $this->makeUser('Ghost', 'ghost@test.com');
        $this->manager->unitOfWork()->remove($ghost);

        try {
            $this->manager->flush();
            self::fail('Deleting an entity without an identifier should abort the flush.');
        } catch (OrmException $exception) {
            self::assertStringContainsString('primary key is not fully initialised', $exception->getMessage());
        }

        // Nothing was written and no unrelated row was harmed.
        self::assertSame(1, $this->countRows('users'));
    }

    public function testUpdatingAnEntityWhoseIdentifierWasLostFailsLoudly(): void
    {
        $user = $this->makeUser('Ada', 'ada@test.com');
        $this->manager->repository(User::class)->save($user);

        // Simulate a broken mutation that de-initialises the typed key, then
        // dirty the entity so an UPDATE is actually attempted.
        unset($user->id);
        $user->name = 'Grace';

        $this->expectException(OrmException::class);
        $this->expectExceptionMessage('primary key is not fully initialised');

        $this->manager->flush();
    }

    public function testUpdateOfAConcurrentlyDeletedRowAbortsTheFlush(): void
    {
        $user = $this->makeUser('Ada', 'ada@test.com');
        $this->manager->repository(User::class)->save($user);

        // Another session deletes the row between load and flush.
        $this->seedRow('DELETE FROM users WHERE id = 1');

        $user->name = 'Grace';

        try {
            $this->manager->flush();
            self::fail('An UPDATE affecting no row should abort the flush.');
        } catch (OrmException $exception) {
            self::assertStringContainsString('affected no row', $exception->getMessage());
        }

        // The failed flush rolled back and purged the now-stale in-memory
        // state, so the next operation re-fetches clean rows.
        self::assertFalse($this->manager->unitOfWork()->isManaged($user));
        self::assertNull($this->manager->repository(User::class)->find(1));
    }

    public function testDeleteOfAConcurrentlyDeletedRowAbortsTheFlush(): void
    {
        $user = $this->makeUser('Ada', 'ada@test.com');
        $this->manager->repository(User::class)->save($user);

        $this->seedRow('DELETE FROM users WHERE id = 1');

        $this->manager->remove($user);

        $this->expectException(OrmException::class);
        $this->expectExceptionMessage('affected no row');

        $this->manager->flush();
    }
}
