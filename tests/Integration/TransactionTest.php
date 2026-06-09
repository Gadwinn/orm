<?php

declare(strict_types=1);

namespace G\Orm\Tests\Integration;

use G\Orm\Contract\DbManagerInterface;
use G\Orm\Core\DbManager;
use G\Orm\Core\UnitOfWork;
use G\Orm\Tests\Fixture\User;
use G\Orm\Tests\OrmTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

/**
 * Explicit transaction lifecycle across multiple flushes.
 *
 * When the caller owns the transaction, each flush() must apply its writes but
 * participate silently — never issuing its own COMMIT — so the whole unit is
 * committed (or rolled back) once, by the caller.
 */
#[CoversClass(DbManager::class)]
#[CoversClass(UnitOfWork::class)]
final class TransactionTest extends OrmTestCase
{
    public function testNestedTransactionLifecycle(): void
    {
        [$manager, $recording] = $this->recordingManager();

        $manager->beginTransaction();

        $manager->persist($this->makeUser('Ada', 'ada@test.com'));
        $manager->flush();

        $manager->persist($this->makeUser('Grace', 'grace@test.com'));
        $manager->flush();

        // Both rows are written inside the open transaction, yet no COMMIT has
        // run: the two flushes participated silently in the owned transaction.
        self::assertSame(2, $this->countRows('users'));
        self::assertTrue($this->pdo->inTransaction());
        self::assertSame(['BEGIN'], $recording->controlLog());

        $manager->commit();

        // The single, global COMMIT is finally issued.
        self::assertSame(['BEGIN', 'COMMIT'], $recording->controlLog());
        self::assertFalse($this->pdo->inTransaction());
        self::assertSame(2, $this->countRows('users'));
    }

    public function testExplicitRollbackPurgesState(): void
    {
        [$manager, $recording] = $this->recordingManager();

        $manager->beginTransaction();
        $user = $this->makeUser('Ada', 'ada@test.com');
        $manager->persist($user);
        $manager->flush();

        self::assertTrue($manager->unitOfWork()->isManaged($user));
        self::assertSame(1, $this->countRows('users'));

        $manager->rollBack();

        // The global rollback ran and the now-stale in-memory state was purged
        // from both the Unit Of Work and the identity map.
        self::assertSame(['BEGIN', 'ROLLBACK'], $recording->controlLog());
        self::assertFalse($this->pdo->inTransaction());
        self::assertFalse($manager->unitOfWork()->isManaged($user));
        self::assertFalse($manager->unitOfWork()->identityMap()->contains($user));
        self::assertSame(0, $this->countRows('users'));
    }

    public function testFailedTransactionCallbackRollsBackAndPurgesState(): void
    {
        [$manager, $recording] = $this->recordingManager();

        $user = $this->makeUser('Ada', 'ada@test.com');

        // The closure writes a row, then throws: the manager must roll the
        // transaction back, purge the in-memory state, and rethrow.
        $caught = null;
        try {
            $manager->transaction(static function (DbManagerInterface $scoped) use ($user): void {
                $scoped->persist($user);
                $scoped->flush();

                throw new RuntimeException('boom');
            });
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(RuntimeException::class, $caught);
        self::assertSame('boom', $caught->getMessage());

        // The write was undone in the database and no ghost objects remain in
        // either the Unit Of Work or the identity map.
        self::assertSame(['BEGIN', 'ROLLBACK'], $recording->controlLog());
        self::assertFalse($this->pdo->inTransaction());
        self::assertSame(0, $this->countRows('users'));
        self::assertFalse($manager->unitOfWork()->isManaged($user));
        self::assertFalse($manager->unitOfWork()->identityMap()->contains($user));
        self::assertFalse($manager->unitOfWork()->identityMap()->has(User::class, $user->id));
    }
}
