<?php

declare(strict_types=1);

namespace G\Orm\Tests\Unit;

use G\Orm\Core\IdentityMap;
use G\Orm\Tests\Fixture\User;
use G\Orm\Tests\Fixture\UserRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pure-memory tests for the deterministic key folding and reference lifecycle
 * of the identity map. No database is involved.
 */
#[CoversClass(IdentityMap::class)]
final class IdentityMapTest extends TestCase
{
    public function testStoresAndRetrievesByScalarKey(): void
    {
        $map = new IdentityMap();
        $user = $this->user(1);

        $map->add($user, 1);

        self::assertTrue($map->has(User::class, 1));
        self::assertTrue($map->contains($user));
        self::assertSame($user, $map->get(User::class, 1));
    }

    public function testGetReturnsNullForUnknownKey(): void
    {
        $map = new IdentityMap();

        self::assertFalse($map->has(User::class, 99));
        self::assertNull($map->get(User::class, 99));
    }

    public function testStoresAndRetrievesByCompositeKey(): void
    {
        $map = new IdentityMap();
        $role = $this->userRole(5, 12);

        $map->add($role, ['role_id' => 12, 'user_id' => 5]);

        self::assertSame($role, $map->get(UserRole::class, ['role_id' => 12, 'user_id' => 5]));
    }

    /**
     * Folding ksort()s the identifier, so a composite key stored in one field
     * order must resolve when queried in a different order. Without the sort
     * this is the classic order-mismatch cache miss.
     */
    public function testCompositeKeyResolutionIsInsertionOrderIndependent(): void
    {
        $map = new IdentityMap();
        $role = $this->userRole(5, 12);

        $map->add($role, ['role_id' => 12, 'user_id' => 5]);

        self::assertTrue($map->has(UserRole::class, ['user_id' => 5, 'role_id' => 12]));
        self::assertSame($role, $map->get(UserRole::class, ['user_id' => 5, 'role_id' => 12]));
    }

    public function testClearPurgesEveryReference(): void
    {
        $map = new IdentityMap();
        $user = $this->user(1);
        $role = $this->userRole(5, 12);
        $map->add($user, 1);
        $map->add($role, ['user_id' => 5, 'role_id' => 12]);

        $map->clear();

        self::assertFalse($map->has(User::class, 1));
        self::assertNull($map->get(User::class, 1));
        self::assertFalse($map->contains($user));
        self::assertFalse($map->has(UserRole::class, ['user_id' => 5, 'role_id' => 12]));
        self::assertFalse($map->contains($role));
    }

    public function testClearByClassPurgesOnlyThatClass(): void
    {
        $map = new IdentityMap();
        $user = $this->user(1);
        $role = $this->userRole(5, 12);
        $map->add($user, 1);
        $map->add($role, ['user_id' => 5, 'role_id' => 12]);

        $map->clear(User::class);

        self::assertFalse($map->has(User::class, 1));
        self::assertFalse($map->contains($user));
        self::assertTrue($map->has(UserRole::class, ['user_id' => 5, 'role_id' => 12]));
        self::assertSame($role, $map->get(UserRole::class, ['user_id' => 5, 'role_id' => 12]));
    }

    /**
     * Pre-hardening, a scalar string key "id=5" and the composite ['id' => 5]
     * folded to the identical slot "User#id=5" and clobbered one another. The
     * s:/c: discriminator now keeps them apart.
     */
    public function testScalarAndCompositeFoldsDoNotCollideForTheSameClass(): void
    {
        $map = new IdentityMap();
        $scalar = $this->user(1);
        $composite = $this->user(2);

        $map->add($scalar, 'id=5');
        $map->add($composite, ['id' => 5]);

        self::assertSame($scalar, $map->get(User::class, 'id=5'));
        self::assertSame($composite, $map->get(User::class, ['id' => 5]));
        self::assertNotSame(
            $map->get(User::class, 'id=5'),
            $map->get(User::class, ['id' => 5])
        );
    }

    /**
     * Pre-hardening, a value containing the '&'/'=' delimiters could forge a
     * neighbouring composite fold: ['a' => '1&b=2', 'z' => 'x'] and
     * ['a' => '1', 'b' => '2', 'z' => 'x'] both folded to "a=1&b=2&z=x".
     * Percent-encoding the components removes the ambiguity.
     */
    public function testDelimiterInjectionInCompositeValueDoesNotCollide(): void
    {
        $map = new IdentityMap();
        $forged = $this->user(1);
        $genuine = $this->user(2);

        $map->add($forged, ['a' => '1&b=2', 'z' => 'x']);
        $map->add($genuine, ['a' => '1', 'b' => '2', 'z' => 'x']);

        self::assertSame($forged, $map->get(User::class, ['a' => '1&b=2', 'z' => 'x']));
        self::assertSame($genuine, $map->get(User::class, ['a' => '1', 'b' => '2', 'z' => 'x']));
        self::assertNotSame(
            $map->get(User::class, ['a' => '1&b=2', 'z' => 'x']),
            $map->get(User::class, ['a' => '1', 'b' => '2', 'z' => 'x'])
        );
    }

    /**
     * Re-adding the same instance under a new identity must evict its previous
     * slot. Before the fix, the stale forward entry kept serving the old key
     * (returning an entity whose primary key no longer matched) and pinned the
     * instance in memory for the lifetime of the map.
     */
    public function testReAddingAnInstanceUnderANewIdentityEvictsTheOldSlot(): void
    {
        $map = new IdentityMap();
        $user = $this->user(1);

        $map->add($user, 1);
        $map->add($user, 2);

        self::assertFalse($map->has(User::class, 1));
        self::assertNull($map->get(User::class, 1));
        self::assertSame($user, $map->get(User::class, 2));
        self::assertTrue($map->contains($user));
    }

    public function testRemoveDropsByInstance(): void
    {
        $map = new IdentityMap();
        $user = $this->user(1);
        $map->add($user, 1);

        $map->remove($user);

        self::assertFalse($map->has(User::class, 1));
        self::assertFalse($map->contains($user));
    }

    private function user(int $id): User
    {
        $user = new User();
        $user->id = $id;
        $user->name = 'Ada';
        $user->email = 'ada@example.test';

        return $user;
    }

    private function userRole(int $userId, int $roleId): UserRole
    {
        $role = new UserRole();
        $role->userId = $userId;
        $role->roleId = $roleId;

        return $role;
    }
}
