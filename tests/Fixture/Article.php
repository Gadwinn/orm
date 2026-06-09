<?php

declare(strict_types=1);

namespace G\Orm\Tests\Fixture;

use G\Orm\Mapping\Column;
use G\Orm\Mapping\Id;
use G\Orm\Mapping\Table;

/**
 * Entity fixture exercising the hydrator's edge cases: a strict-typed scalar,
 * a nullable column, and a native backed-enum column.
 */
#[Table('articles')]
final class Article
{
    #[Id]
    #[Column]
    public int $id;

    #[Column]
    public string $title;

    #[Column(nullable: true)]
    public ?string $subtitle;

    #[Column(name: 'status')]
    public Status $status;
}
