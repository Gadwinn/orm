<?php

declare(strict_types=1);

namespace G\Orm\Tests\Fixture;

use G\Orm\Mapping\Column;
use G\Orm\Mapping\Id;
use G\Orm\Mapping\Table;

/**
 * Entity fixture carrying an integer-backed enum column ({@see Level}), so a
 * full persist/fetch round trip proves bidirectional int-enum mapping: the
 * enum is flattened to its integer backing on write and rebuilt from the
 * integer the database returns on read.
 *
 * The id is database-generated; declaring it #[Id(generated: true)] also keeps
 * the single-column generated path covered by metadata.
 */
#[Table('accounts')]
final class Account
{
    #[Id(generated: true)]
    #[Column]
    public int $id;

    #[Column(unique: true)]
    public string $email;

    #[Column(name: 'level')]
    public Level $level;
}
