<?php

declare(strict_types=1);

namespace G\Orm\Tests\Integration;

use G\Orm\Core\DbManager;
use G\Orm\Core\UnitOfWork;
use G\Orm\Event\AfterDeleteEvent;
use G\Orm\Event\AfterInsertEvent;
use G\Orm\Event\BeforeDeleteEvent;
use G\Orm\Event\BeforeInsertEvent;
use G\Orm\Event\Changeset;
use G\Orm\Tests\Fixture\Member;
use G\Orm\Tests\Fixture\RecordingDispatcher;
use G\Orm\Tests\OrmTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The dual-layer lifecycle: internal attribute hooks (always on) and external
 * PSR-14 events (opt-in), plus the Changeset-driven update protection.
 */
#[CoversClass(UnitOfWork::class)]
#[CoversClass(Changeset::class)]
final class LifecycleEventTest extends OrmTestCase
{
    public function testBeforeUpdateHookProtectsUntouchedColumnsViaChangeset(): void
    {
        $this->seedRow(
            "INSERT INTO members (id, email, password, updated_at) "
            . "VALUES (1, 'old@test.com', 'secret-hash', '2020-01-01')"
        );

        $member = $this->manager->repository(Member::class)->find(1);
        self::assertInstanceOf(Member::class, $member);

        // Change only the email; the password is never assigned here.
        $member->email = 'new@test.com';
        $this->manager->flush();

        $rows = $this->manager->query('SELECT email, password, updated_at FROM members WHERE id = ?', [1]);

        // updated_at was rewritten by the hook and folded into the UPDATE via
        // the post-hook re-diff; password stayed put because the change set the
        // hook inspected reported has('password') === false.
        self::assertSame(
            [['email' => 'new@test.com', 'password' => 'secret-hash', 'updated_at' => Member::TOUCHED]],
            $rows
        );
    }

    public function testInsertDispatchesBeforeAndAfterInsertEventsToDispatcher(): void
    {
        $dispatcher = new RecordingDispatcher();
        $manager = DbManager::create($this->connection, $dispatcher);

        $manager->persist($this->makeMember('eve@test.com'));
        $manager->flush();

        $events = $dispatcher->events();
        self::assertCount(2, $events);
        self::assertInstanceOf(BeforeInsertEvent::class, $events[0]);
        self::assertInstanceOf(AfterInsertEvent::class, $events[1]);
    }

    public function testDeleteDispatchesBeforeAndAfterDeleteEventsToDispatcher(): void
    {
        $dispatcher = new RecordingDispatcher();
        $manager = DbManager::create($this->connection, $dispatcher);

        $member = $this->makeMember('toremove@test.com');
        $manager->persist($member);
        $manager->flush();

        $manager->remove($member);
        $manager->flush();

        // The After/Delete paths follow the same synchronised hook-then-dispatch
        // pattern as inserts.
        self::assertTrue($dispatcher->has(BeforeDeleteEvent::class));
        self::assertTrue($dispatcher->has(AfterDeleteEvent::class));
        self::assertSame(0, $this->countRows('members'));
    }

    private function makeMember(string $email): Member
    {
        $member = new Member();
        $member->email = $email;
        $member->password = 'hash';
        $member->updatedAt = '2020-01-01';

        return $member;
    }
}
