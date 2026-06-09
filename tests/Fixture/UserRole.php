<?php

declare(strict_types=1);

namespace G\Orm\Tests\Fixture;

use G\Orm\Mapping\Column;
use G\Orm\Mapping\Id;
use G\Orm\Mapping\Table;

/**
 * Composite-key entity fixture (user_id + role_id).
 *
 * Its identifier folds to "user_id=..&role_id=.." after alphabetical sorting,
 * which is what proves the identity map is insertion-order independent.
 */
#[Table('user_roles')]
final class UserRole
{
    #[Id]
    #[Column(name: 'user_id')]
    public int $userId;

    #[Id]
    #[Column(name: 'role_id')]
    public int $roleId;

    #[Column(name: 'granted_at')]
    public string $grantedAt;
}
