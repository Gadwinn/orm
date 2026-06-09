<?php

declare(strict_types=1);

namespace G\Orm\Contract\Driver;

/**
 * Entry point of a database driver.
 *
 * A driver is a thin factory that knows how to open a connection to one
 * database engine (MySQL, MariaDB, PostgreSQL, SQLite, ...). The ORM core
 * depends only on this abstraction, never on a concrete driver, so engines
 * remain interchangeable.
 */
interface DriverInterface
{
    /**
     * Open a connection using the given driver-specific options.
     *
     * Common keys include host, port, dbname, user, password, charset; the
     * exact set is defined by each concrete driver.
     *
     * @param array<string, mixed> $options
     *
     * @throws \G\Orm\Exception\ConnectionException When the connection cannot be opened.
     */
    public function connect(array $options): ConnectionInterface;
}
