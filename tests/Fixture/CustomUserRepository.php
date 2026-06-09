<?php

declare(strict_types=1);

namespace G\Orm\Tests\Fixture;

use G\Orm\Repository\Repository;

/**
 * Custom repository fixture. Extends the generic repository so it can be
 * auto-wired by the DbManager and recognised by the metadata reader through
 * the #[Repository] attribute on {@see Tag}.
 *
 * @extends Repository<Tag>
 */
final class CustomUserRepository extends Repository
{
}
