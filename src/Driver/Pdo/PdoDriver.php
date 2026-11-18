<?php

declare(strict_types=1);

namespace G\Orm\Driver\Pdo;

use G\Orm\Contract\Driver\ConnectionInterface;
use G\Orm\Contract\Driver\DriverInterface;
use G\Orm\Exception\ConnectionException;
use PDO;
use PDOException;

/**
 * PDO-backed driver for MySQL/MariaDB, PostgreSQL, SQL Server and SQLite.
 *
 * Accepts either a ready-made DSN or discrete connection options from which a
 * DSN is built. PDO is configured for real prepared statements and exception
 * error mode by default; callers may override PDO attributes.
 */
final class PdoDriver implements DriverInterface
{
    /**
     * Recognised PDO driver names. MySQL is the default when no driver is
     * given; SQLite takes a file path (or in-memory) DSN shape; SQL Server
     * has its own DSN grammar (Server=/Database=) handled separately.
     */
    private const DRIVER_MYSQL = 'mysql';
    private const DRIVER_PGSQL = 'pgsql';
    private const DRIVER_SQLSRV = 'sqlsrv';
    private const DRIVER_SQLITE = 'sqlite';

    private const SQLITE_IN_MEMORY = ':memory:';

    /** @var list<string> Name of the MySQL "found rows" attribute, newest first. */
    private const FOUND_ROWS_CONSTANTS = ['Pdo\Mysql::ATTR_FOUND_ROWS', 'PDO::MYSQL_ATTR_FOUND_ROWS'];

    /**
     * Discrete DSN parts recognised per driver. Drivers not listed here fall
     * back to the MySQL-shaped set below (kept permissive for unknown/custom
     * PDO drivers, which PDO itself will reject at connection time).
     *
     * @var array<string, list<string>>
     */
    private const DSN_PARTS = [
        self::DRIVER_MYSQL => ['host', 'port', 'dbname', 'charset', 'unix_socket'],
        self::DRIVER_PGSQL => ['host', 'port', 'dbname'],
    ];

    /**
     * Open a connection.
     *
     * Recognised option keys:
     *   - dsn:        a complete PDO DSN (takes precedence over the parts below)
     *   - driver:     pdo driver name (mysql, pgsql, sqlsrv, sqlite); default "mysql"
     *   - host, port, dbname, charset, unix_socket: DSN parts, applicable per
     *                 driver (mysql accepts all five; pgsql only host, port,
     *                 dbname; sqlsrv accepts host, port, dbname and charset
     *                 but maps them to its own Server/Database/CharacterSet
     *                 DSN keywords)
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
            $pdo = new PDO($dsn, $user, $password, $this->pdoOptions($options, $dsn));
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

        if ($driver === self::DRIVER_SQLSRV) {
            return $this->resolveSqlSrvDsn($options);
        }

        $parts = [];
        foreach (self::DSN_PARTS[$driver] ?? self::DSN_PARTS[self::DRIVER_MYSQL] as $key) {
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
     * Build a SQL Server DSN. pdo_sqlsrv uses its own grammar: the host and
     * port are combined into a single "Server" part (comma-separated, not two
     * DSN keys), the database is "Database" rather than "dbname", and the
     * encoding key is "CharacterSet" rather than "charset". There is no
     * unix_socket equivalent.
     *
     * @param array<string, mixed> $options
     */
    private function resolveSqlSrvDsn(array $options): string
    {
        $parts = [];

        if (isset($options['host'])) {
            $server = $this->stringOption($options['host']);
            if (isset($options['port'])) {
                $server .= ',' . $this->stringOption($options['port']);
            }

            $parts[] = 'Server=' . $server;
        }

        if (isset($options['dbname'])) {
            $parts[] = 'Database=' . $this->stringOption($options['dbname']);
        }

        if (isset($options['charset'])) {
            $parts[] = 'CharacterSet=' . $this->stringOption($options['charset']);
        }

        if ($parts === []) {
            throw new ConnectionException(
                'Cannot build a DSN: provide "dsn" or at least one connection part (host, dbname, ...).'
            );
        }

        return self::DRIVER_SQLSRV . ':' . implode(';', $parts);
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
    private function pdoOptions(array $options, string $dsn): array
    {
        $merged = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        // MySQL reports the rows *changed* by an UPDATE, so writing values the
        // row already holds would count 0 and look like a vanished row to the
        // unit of work. FOUND_ROWS makes it report the rows *matched*, as the
        // other engines do. (Pdo\Mysql exists from PHP 8.4; the PDO::MYSQL_*
        // constant is deprecated from 8.5.)
        // Set before the caller's options, which may still override it.
        $foundRows = $this->mysqlFoundRowsAttribute();
        if ($foundRows !== null && str_starts_with($dsn, self::DRIVER_MYSQL . ':')) {
            $merged[$foundRows] = true;
        }

        if (isset($options['options']) && is_array($options['options'])) {
            foreach ($options['options'] as $key => $value) {
                if (is_int($key)) {
                    $merged[$key] = $value;
                }
            }
        }

        return $merged;
    }

    private function mysqlFoundRowsAttribute(): ?int
    {
        foreach (self::FOUND_ROWS_CONSTANTS as $name) {
            if (defined($name)) {
                return constant($name);
            }
        }

        return null;
    }
}
