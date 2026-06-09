<?php

declare(strict_types=1);

namespace G\Orm\Tests\Fixture;

use PDO;

/**
 * Test double that reports itself as the MySQL driver while running on a real
 * in-memory SQLite database.
 *
 * The only behaviour overridden is the driver-name attribute, which is what
 * {@see \G\Orm\Driver\Pdo\PdoConnection} reads to choose its identifier-quoting
 * character. This lets the backtick (MySQL) branch be covered deterministically
 * with no MySQL server in the loop.
 */
final class MysqlNamedPdo extends PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:');
    }

    public function getAttribute(int $attribute): mixed
    {
        if ($attribute === PDO::ATTR_DRIVER_NAME) {
            return 'mysql';
        }

        return parent::getAttribute($attribute);
    }
}
