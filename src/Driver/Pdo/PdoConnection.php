<?php

declare(strict_types=1);

namespace G\Orm\Driver\Pdo;

use G\Orm\Compiler\Platform\MySqlPlatform;
use G\Orm\Compiler\Platform\PlatformInterface;
use G\Orm\Compiler\Platform\PostgreSqlPlatform;
use G\Orm\Compiler\Platform\SqlitePlatform;
use G\Orm\Compiler\Platform\SqlServerPlatform;
use G\Orm\Contract\Driver\ConnectionInterface;
use G\Orm\Contract\Driver\StatementInterface;
use G\Orm\Exception\DriverException;
use G\Orm\Exception\QueryException;
use G\Orm\Exception\TransactionException;
use PDO;
use PDOException;

/**
 * PDO-backed connection wrapping a native \PDO instance.
 *
 * Transaction control is explicit and never implicit. Every dialect concern —
 * identifier quoting included — is owned by the {@see PlatformInterface}
 * resolved from the PDO driver name at construction time; the connection
 * itself carries no engine-specific behaviour.
 */
final class PdoConnection implements ConnectionInterface
{
    /**
     * PDO driver names with a dedicated platform; any other driver falls back
     * to the standard-conforming SQLite platform.
     */
    private const DRIVER_MYSQL = 'mysql';
    private const DRIVER_PGSQL = 'pgsql';
    private const DRIVER_SQLSRV = 'sqlsrv';

    private readonly PlatformInterface $platform;

    public function __construct(
        private readonly PDO $pdo,
    ) {
        $name = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->platform = self::resolvePlatform(is_string($name) ? $name : '');
    }

    public function getPlatform(): PlatformInterface
    {
        return $this->platform;
    }

    private static function resolvePlatform(string $driverName): PlatformInterface
    {
        return match ($driverName) {
            self::DRIVER_MYSQL => new MySqlPlatform(),
            self::DRIVER_PGSQL => new PostgreSqlPlatform(),
            self::DRIVER_SQLSRV => new SqlServerPlatform(),
            default => new SqlitePlatform(),
        };
    }

    public function beginTransaction(): void
    {
        try {
            $this->pdo->beginTransaction();
        } catch (PDOException $exception) {
            throw new TransactionException(
                'Unable to begin transaction: ' . $exception->getMessage(),
                0,
                $exception
            );
        }
    }

    public function commit(): void
    {
        try {
            $this->pdo->commit();
        } catch (PDOException $exception) {
            throw new TransactionException(
                'Unable to commit transaction: ' . $exception->getMessage(),
                0,
                $exception
            );
        }
    }

    public function rollBack(): void
    {
        try {
            $this->pdo->rollBack();
        } catch (PDOException $exception) {
            throw new TransactionException(
                'Unable to roll back transaction: ' . $exception->getMessage(),
                0,
                $exception
            );
        }
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    public function lastInsertId(?string $name = null): string
    {
        try {
            $id = $this->pdo->lastInsertId($name);
        } catch (PDOException $exception) {
            throw new DriverException(
                'Unable to read the last insert id: ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        if ($id === false) {
            throw new DriverException('The driver did not return a last insert id.');
        }

        return $id;
    }

    public function quote(string $value): string
    {
        $quoted = $this->pdo->quote($value);

        if ($quoted === false) {
            throw new DriverException('The driver does not support value quoting.');
        }

        return $quoted;
    }

    public function quoteIdentifier(string $identifier): string
    {
        return $this->platform->quoteIdentifier($identifier);
    }

    public function prepare(string $sql): StatementInterface
    {
        try {
            $statement = $this->pdo->prepare($sql);
        } catch (PDOException $exception) {
            throw new QueryException(
                'Unable to prepare statement: ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        if ($statement === false) {
            throw new QueryException('Unable to prepare statement.');
        }

        return new PdoStatement($statement);
    }
}
