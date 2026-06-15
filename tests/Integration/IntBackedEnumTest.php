<?php

declare(strict_types=1);

namespace G\Orm\Tests\Integration;

use G\Orm\Core\DbManager;
use G\Orm\Hydrator\Hydrator;
use G\Orm\Tests\Fixture\Account;
use G\Orm\Tests\Fixture\Level;
use G\Orm\Tests\OrmTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Full persist/fetch round trip for an integer-backed enum column.
 *
 * The string-backed path is covered by the hydrator unit tests; this exercises
 * the integer branch end to end against a real database, where SQLite returns
 * the column as a native int that must be rebuilt into the enum instance.
 */
#[CoversClass(Hydrator::class)]
final class IntBackedEnumTest extends OrmTestCase
{
    public function testPersistsEnumAsItsIntegerBackingValue(): void
    {
        $account = new Account();
        $account->email = 'admin@test.com';
        $account->level = Level::Admin;

        $this->manager->persist($account);
        $this->manager->flush();

        // The enum was flattened to its integer backing on the way into the
        // database, not stored as the case name.
        $rows = $this->manager->query('SELECT level FROM accounts WHERE id = ?', [$account->id]);
        self::assertSame([['level' => 1]], $rows);
    }

    public function testFetchesIntegerColumnBackIntoEnumInstance(): void
    {
        $account = new Account();
        $account->email = 'guest@test.com';
        $account->level = Level::Guest;

        $this->manager->persist($account);
        $this->manager->flush();
        $id = $account->id;

        // A fresh manager starts with an empty identity map, so find() truly
        // hydrates the row from the database rather than returning the managed
        // instance - the integer column is converted up to the enum case.
        $reloaded = DbManager::create($this->connection)->repository(Account::class)->find($id);

        self::assertInstanceOf(Account::class, $reloaded);
        self::assertNotSame($account, $reloaded);
        self::assertSame(Level::Guest, $reloaded->level);
        self::assertSame('guest@test.com', $reloaded->email);
    }
}
