<?php

declare(strict_types=1);

namespace G\Orm\Tests\Fixture;

use G\Orm\Mapping\Column;
use G\Orm\Mapping\Id;
use G\Orm\Mapping\Repository;
use G\Orm\Mapping\Table;

/**
 * Entity fixture declaring a custom repository and a column that exercises the
 * full set of #[Column] options (custom name, length, nullable, unique).
 */
#[Repository(CustomUserRepository::class)]
#[Table('tags')]
final class Tag
{
    #[Id]
    #[Column]
    public int $id;

    #[Column(name: 'label', nullable: true, unique: true, length: 50)]
    public ?string $name;
}
