<?php

declare(strict_types=1);

namespace G\Orm\Tests\Integration;

use G\Orm\Repository\Repository;
use G\Orm\Tests\Fixture\User;
use G\Orm\Tests\OrmTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Full repository API against a real in-memory database: the finders, the
 * persistence wrappers, and the mandatory identity-map cooperation that keeps
 * a row represented by a single instance.
 */
#[CoversClass(Repository::class)]
final class RepositoryTest extends OrmTestCase
{
    public function testSavePersistsAndAssignsIdentifier(): void
    {
        $repository = $this->manager->repository(User::class);
        $user = $this->makeUser('John', 'john@test.com');

        $repository->save($user);

        self::assertSame(1, $user->id);
        self::assertSame(1, $this->countRows('users'));
    }

    public function testFindResolvesAndPreservesIdentity(): void
    {
        $repository = $this->manager->repository(User::class);
        $repository->save($this->makeUser('John', 'john@test.com'));

        $first = $repository->find(1);
        $second = $repository->find(1);

        self::assertInstanceOf(User::class, $first);
        self::assertSame('John', $first->name);
        self::assertSame($first, $second);
    }

    public function testFindReturnsNullWhenAbsent(): void
    {
        self::assertNull($this->manager->repository(User::class)->find(404));
    }

    public function testFindByReturnsAllMatches(): void
    {
        $repository = $this->manager->repository(User::class);
        $repository->save($this->makeUser('John', 'john@test.com'));
        $repository->save($this->makeUser('John', 'john2@test.com'));
        $repository->save($this->makeUser('Jane', 'jane@test.com'));

        $johns = $repository->findBy(['name' => 'John']);

        self::assertCount(2, $johns);
        foreach ($johns as $john) {
            self::assertSame('John', $john->name);
        }
    }

    public function testFindOneByReturnsSingleMatchOrNull(): void
    {
        $repository = $this->manager->repository(User::class);
        $repository->save($this->makeUser('John', 'john@test.com'));

        $found = $repository->findOneBy(['email' => 'john@test.com']);

        self::assertInstanceOf(User::class, $found);
        self::assertSame('John', $found->name);
        self::assertNull($repository->findOneBy(['email' => 'ghost@test.com']));
    }

    public function testFindOneByReturnsTheManagedInstance(): void
    {
        $repository = $this->manager->repository(User::class);
        $repository->save($this->makeUser('John', 'john@test.com'));

        // Register the row in the identity map through a primary-key find.
        $managed = $repository->find(1);

        // A criteria lookup resolving the same row must return that exact
        // managed instance, not a freshly hydrated duplicate.
        $byEmail = $repository->findOneBy(['email' => 'john@test.com']);

        self::assertInstanceOf(User::class, $managed);
        self::assertSame($managed, $byEmail);
    }

    public function testRemoveDeletesAndDetaches(): void
    {
        $repository = $this->manager->repository(User::class);
        $user = $this->makeUser('John', 'john@test.com');
        $repository->save($user);

        $repository->remove($user);

        self::assertSame(0, $this->countRows('users'));
        self::assertFalse($this->manager->unitOfWork()->isManaged($user));
    }
}
