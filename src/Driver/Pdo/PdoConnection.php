<?php

declare(strict_types=1);

namespace G\Orm\Driver\Pdo;

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
 * Transaction control is explicit and never implicit. Identifier quoting is
 * engine-aware: backticks for MySQL/MariaDB, double quotes for PostgreSQL and
 * standard SQL.
 */
final class PdoConnection implements ConnectionInterface
{
    /**
     * PDO driver name that quotes identifiers with backticks; every other
     * engine uses the SQL-standard double quote.
     */
    private const DRIVER_MYSQL = 'mysql';

    private const QUOTE_MYSQL = '`';
    private const QUOTE_STANDARD = '"';

    private readonly string $driverName;

    public function __construct(
        private readonly PDO $pdo,
    ) {
        $name = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->driverName = is_string($name) ? $name : '';
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
        $char = $this->driverName === self::DRIVER_MYSQL ? self::QUOTE_MYSQL : self::QUOTE_STANDARD;

        $parts = explode('.', $identifier);
        $quoted = array_map(
            static fn (string $part): string => $char . str_replace($char, $char . $char, $part) . $char,
            $parts
        );

        return implode('.', $quoted);
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
