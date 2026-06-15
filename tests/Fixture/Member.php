<?php

declare(strict_types=1);

namespace G\Orm\Tests\Fixture;

use G\Orm\Attribute\Event\BeforeUpdate;
use G\Orm\Event\Changeset;
use G\Orm\Mapping\Column;
use G\Orm\Mapping\Id;
use G\Orm\Mapping\Table;

/**
 * Fixture exercising the #[BeforeUpdate] hook and its Changeset argument.
 *
 * The hook audits every update with a fresh timestamp, but only ever rewrites
 * the password when it was genuinely part of the change set - so an unrelated
 * edit can never silently re-hash a credential it never touched.
 */
#[Table('members')]
final class Member
{
    public const TOUCHED = 'touched';

    #[Id]
    #[Column]
    public int $id;

    #[Column]
    public string $email;

    #[Column]
    public string $password;

    #[Column(name: 'updated_at')]
    public string $updatedAt;

    #[BeforeUpdate]
    public function onBeforeUpdate(Changeset $changes): void
    {
        $this->updatedAt = self::TOUCHED;

        if ($changes->has('password')) {
            $this->password = 'rehashed:' . $this->password;
        }
    }
}
