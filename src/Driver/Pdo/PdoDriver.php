<?php

declare(strict_types=1);

namespace G\Orm\Driver\Pdo;

use G\Orm\Contract\Driver\ConnectionInterface;
use G\Orm\Contract\Driver\DriverInterface;
use G\Orm\Exception\ConnectionException;
use PDO;
use PDOException;

/**
 * PDO-backed driver for MySQL/MariaDB, PostgreSQL and SQLite.
 *
 * Accepts either a ready-made DSN or discrete connection options from which a
 * DSN is built. PDO is configured for real prepared statements and exception
 * error mode by default; callers may override PDO attributes.
 */
final class PdoDriver implements DriverInterface
{
    /**
     * Recognised PDO driver names. MySQL is the default when no driver is
     * given; SQLite takes a file path (or in-memory) DSN shape.
     */
    private const DRIVER_MYSQL = 'mysql';
    private const DRIVER_SQLITE = 'sqlite';

    private const SQLITE_IN_MEMORY = ':memory:';

    /**
     * Open a connection.
     *
     * Recognised option keys:
     *   - dsn:        a complete PDO DSN (takes precedence over the parts below)
     *   - driver:     pdo driver name (mysql, pgsql, sqlite); default "mysql"
     *   - host, port, dbname, charset, unix_socket: DSN parts
     *   - path:       database file for sqlite
     *   - user, password
     *   - options:    array<int, mixed> of extra PDO attributes
     *
     * @param array<string, mixed> $options
     *
     * @throws ConnectionException
     */
    public function connect(array $options): ConnectionInterface
    {
        $dsn = $this->resolveDsn($options);
        $user = isset($options['user']) ? $this->stringOption($options['user']) : null;
        $password = isset($options['password']) ? $this->stringOption($options['password']) : null;

        try {
            $pdo = new PDO($dsn, $user, $password, $this->pdoOptions($options));
        } catch (PDOException $exception) {
            throw new ConnectionException(
                'Unable to open the database connection: ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        return new PdoConnection($pdo);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function resolveDsn(array $options): string
    {
        if (isset($options['dsn'])) {
            return $this->stringOption($options['dsn']);
        }

        $driver = isset($options['driver']) ? $this->stringOption($options['driver']) : self::DRIVER_MYSQL;

        if ($driver === self::DRIVER_SQLITE) {
            $path = isset($options['path']) ? $this->stringOption($options['path']) : self::SQLITE_IN_MEMORY;

            return self::DRIVER_SQLITE . ':' . $path;
        }

        $parts = [];
        foreach (['host', 'port', 'dbname', 'charset', 'unix_socket'] as $key) {
            if (isset($options[$key])) {
                $parts[] = $key . '=' . $this->stringOption($options[$key]);
            }
        }

        if ($parts === []) {
            throw new ConnectionException(
                'Cannot build a DSN: provide "dsn" or at least one connection part (host, dbname, ...).'
            );
        }

        return $driver . ':' . implode(';', $parts);
    }

    /**
     * Coerce a scalar connection option to string, rejecting non-scalars.
     *
     * @throws ConnectionException
     */
    private function stringOption(mixed $value): string
    {
        if (!is_scalar($value)) {
            throw new ConnectionException('Connection options must be scalar values.');
        }

        return (string) $value;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<int, mixed>
     */
    private function pdoOptions(array $options): array
    {
        $merged = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        if (isset($options['options']) && is_array($options['options'])) {
            foreach ($options['options'] as $key => $value) {
                if (is_int($key)) {
                    $merged[$key] = $value;
                }
            }
        }

        return $merged;
    }
}
