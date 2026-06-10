<?php

declare(strict_types=1);

namespace G\Orm\Tests\Fixture;

use G\Orm\Attribute\Event\BeforeInsert;
use G\Orm\Mapping\Column;
use G\Orm\Mapping\Id;
use G\Orm\Mapping\Table;
use G\Orm\Registry\OrmRegistry;

/**
 * Fixture whose #[BeforeInsert] hook re-enters the Unit Of Work by calling
 * flush() again through the active manager. It exists solely to prove the
 * re-entrancy guard converts that into an explicit OrmException instead of a
 * double write or unbounded recursion.
 */
#[Table('registry_aware')]
final class ReentrantEntity
{
    #[Id(generated: true)]
    #[Column]
    public int $id;

    #[Column]
    public string $name;

    #[BeforeInsert]
    public function reenter(): void
    {
        OrmRegistry::get()->flush();
    }
}
