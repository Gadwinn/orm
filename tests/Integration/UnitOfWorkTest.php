<?php

declare(strict_types=1);

namespace G\Orm\Tests\Integration;

use G\Orm\Contract\DbManagerInterface;
use G\Orm\Contract\UnitOfWorkInterface;
use G\Orm\Core\DbManager;
use G\Orm\Core\UnitOfWork;
use G\Orm\Exception\QueryException;
use G\Orm\Tests\Fixture\User;
use G\Orm\Tests\Fixture\UserRole;
use G\Orm\Tests\OrmTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionProperty;

/**
 * End-to-end persistence behaviour of the Unit Of Work against a real (in
 * memory) SQLite database: identifier write-back, snapshot-driven dirty
 * diffing, and automatic state purging on a rolled-back transaction.
 */
#[CoversClass(UnitOfWork::class)]
#[CoversClass(DbManager::class)]
final class UnitOfWorkTest extends OrmTestCase
{
    public function testInsertFetchesAndAssignsGeneratedIdentifier(): void
    {
        $repository = $this->manager->repository(User::class);
        $user = $this->makeUser('Ada', 'ada@example.test');

        $repository->save($user);

        // lastInsertId fetched, cast to int, written back to the entity.
        self::assertSame(1, $user->id);
        self::assertTrue($this->manager->unitOfWork()->isManaged($user));

        $rows = $this->manager->query('SELECT name, email FROM users WHERE id = ?', [1]);
        self::assertSame([['name' => 'Ada', 'email' => 'ada@example.test']], $rows);
    }

    public function testBaselineSnapshotMakesNoOpFlushSilent(): void
    {
        [$manager, $recording] = $this->recordingManager();
        $user = $this->makeUser('Ada', 'ada@example.test');
        $manager->repository(User::class)->save($user);

        $recording->reset();
        $manager->flush();

        // The insert captured a baseline snapshot equal to the current state,
        // so diffing yields nothing and no SQL is emitted.
        self::assertSame([], $recording->statements());
    }

    public function testUpdateTargetsOnlyTheModifiedColumn(): void
    {
        [$manager, $recording] = $this->recordingManager();
        $user = $this->makeUser('Ada', 'ada@example.test');
        $manager->repository(User::class)->save($user);

        $recording->reset();
        $user->name = 'Grace';
        $manager->flush();

        self::assertSame(['UPDATE users SET name = ? WHERE id = ?'], $recording->statements());
        self::assertStringNotContainsString('email', $recording->statements()[0]);
    }

    public function testRollbackPurgesStaleInMemoryState(): void
    {
        $repository = $this->manager->repository(User::class);
        $ada = $this->makeUser('Ada', 'dup@example.test');
        $repository->save($ada);

        $conflict = $this->makeUser('Grace', 'dup@example.test');

        try {
            $this->manager->transaction(static function (DbManagerInterface $manager) use ($conflict): void {
                $manager->persist($conflict);
                $manager->flush();
            });
            self::fail('The duplicate email should have aborted the transaction.');
        } catch (QueryException) {
            // Expected: the unique constraint violation rolls the transaction
            // back and triggers the automatic state purge.
        }

        $unitOfWork = $this->manager->unitOfWork();
        self::assertFalse($unitOfWork->isManaged($ada));
        self::assertFalse($unitOfWork->isManaged($conflict));

        // The identity map was purged, so a re-fetch hydrates a fresh, clean
        // instance rather than returning the now-detached original.
        $refetched = $repository->find(1);
        self::assertInstanceOf(User::class, $refetched);
        self::assertNotSame($ada, $refetched);
        self::assertSame('Ada', $refetched->name);
    }

    public function testInsertSnapshotMatchesDatabaseRow(): void
    {
        $user = $this->makeUser('John', 'john@test.com');
        $this->manager->repository(User::class)->save($user);

        // After the insert the baseline snapshot must be the full, flat row -
        // not empty, not partially tracked - so subsequent diffs are accurate.
        self::assertSame(
            ['id' => 1, 'name' => 'John', 'email' => 'john@test.com'],
            $this->snapshotOf($this->manager->unitOfWork(), $user)
        );
    }

    public function testInsertRefreshesIdentityMapKey(): void
    {
        $repository = $this->manager->repository(User::class);

        // The auto-increment identifier is unassigned before the flush.
        $user = $this->makeUser('John', 'john@test.com');
        $repository->save($user);

        // find() under the generated id must resolve the very same instance,
        // proving it was re-indexed in the identity map under its new key
        // rather than re-hydrated into a duplicate.
        $found = $repository->find($user->id);

        self::assertSame($user, $found);
    }

    public function testPartialHydrationDoesNotOverwriteUnknownColumns(): void
    {
        [$manager, $recording] = $this->recordingManager();
        $this->seedRow("INSERT INTO users (id, name, email) VALUES (1, 'John', 'foo@test.com')");

        // Hydrate only id + email; the strict-typed $name stays uninitialised.
        $partial = $this->hydrator->hydrate(User::class, ['id' => 1, 'email' => 'foo@test.com'], ['email']);
        $manager->unitOfWork()->register($partial, 1, ['email']);

        $recording->reset();
        $partial->email = 'bar@test.com';
        $manager->flush();

        self::assertSame(['UPDATE users SET email = ? WHERE id = ?'], $recording->statements());

        // The uninitialised column was never read nor written: the row keeps
        // its original name.
        $rows = $this->manager->query('SELECT name FROM users WHERE id = ?', [1]);
        self::assertSame([['name' => 'John']], $rows);
    }

    public function testCompositeKeyUpdateTargetsNonKeyColumnWithFullKeyWhere(): void
    {
        [$manager, $recording] = $this->recordingManager();
        $this->seedRow("INSERT INTO user_roles (user_id, role_id, granted_at) VALUES (5, 12, '2020-01-01')");

        $role = $this->hydrator->hydrate(
            UserRole::class,
            ['user_id' => 5, 'role_id' => 12, 'granted_at' => '2020-01-01']
        );
        $manager->unitOfWork()->register($role, ['user_id' => 5, 'role_id' => 12]);

        $recording->reset();
        $role->grantedAt = '2021-06-01';
        $manager->flush();

        self::assertSame(
            ['UPDATE user_roles SET granted_at = ? WHERE user_id = ? AND role_id = ?'],
            $recording->statements()
        );
    }

    public function testCompositeKeyDeleteTargetsFullKey(): void
    {
        [$manager, $recording] = $this->recordingManager();
        $this->seedRow("INSERT INTO user_roles (user_id, role_id, granted_at) VALUES (5, 12, '2020-01-01')");

        $role = $this->hydrator->hydrate(
            UserRole::class,
            ['user_id' => 5, 'role_id' => 12, 'granted_at' => '2020-01-01']
        );
        $manager->unitOfWork()->register($role, ['user_id' => 5, 'role_id' => 12]);

        $recording->reset();
        $manager->remove($role);
        $manager->flush();

        self::assertSame(
            ['DELETE FROM user_roles WHERE user_id = ? AND role_id = ?'],
            $recording->statements()
        );
        self::assertSame(0, $this->countRows('user_roles'));
    }

    /**
     * Read the Unit Of Work's private baseline snapshot for an entity, so a
     * test can assert it captured the exact persisted row.
     *
     * @return array<array-key, mixed>|null
     */
    private function snapshotOf(UnitOfWorkInterface $unitOfWork, object $entity): ?array
    {
        $snapshots = (new ReflectionProperty(UnitOfWork::class, 'snapshots'))->getValue($unitOfWork);
        if (!is_array($snapshots)) {
            self::fail('The Unit Of Work snapshot store is not an array.');
        }

        $snapshot = $snapshots[spl_object_id($entity)] ?? null;

        return is_array($snapshot) ? $snapshot : null;
    }
}
