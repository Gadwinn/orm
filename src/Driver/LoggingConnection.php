<?php

declare(strict_types=1);

namespace G\Orm\Driver;

use G\Orm\Compiler\Platform\PlatformInterface;
use G\Orm\Contract\Driver\ConnectionInterface;
use G\Orm\Contract\Driver\StatementInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Connection decorator that logs every executed query to a PSR-3 logger.
 *
 * Each statement execution is logged at the debug level with the SQL as the
 * message and this context:
 *
 *   - 'bindings'  => array of every value bound with bind() or execute()
 *   - 'duration'  => execution time in milliseconds (float)
 *   - 'exception' => the Throwable, only when the execution failed
 *
 * Transactions are logged as BEGIN, COMMIT and ROLLBACK with their duration
 * (and empty bindings). A failing operation is logged, then its exception is
 * rethrown untouched. Every other method is delegated without logging.
 */
final class LoggingConnection implements ConnectionInterface
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getPlatform(): PlatformInterface
    {
        return $this->connection->getPlatform();
    }

    public function beginTransaction(): void
    {
        $this->control('BEGIN', fn () => $this->connection->beginTransaction());
    }

    public function commit(): void
    {
        $this->control('COMMIT', fn () => $this->connection->commit());
    }

    public function rollBack(): void
    {
        $this->control('ROLLBACK', fn () => $this->connection->rollBack());
    }

    public function inTransaction(): bool
    {
        return $this->connection->inTransaction();
    }

    public function lastInsertId(?string $name = null): string|int
    {
        return $this->connection->lastInsertId($name);
    }

    public function quote(string $value): string
    {
        return $this->connection->quote($value);
    }

    public function quoteIdentifier(string $identifier): string
    {
        return $this->connection->quoteIdentifier($identifier);
    }

    public function prepare(string $sql): StatementInterface
    {
        return new LoggingStatement($this->connection->prepare($sql), $sql, $this->logger);
    }

    /**
     * Run a transaction-control operation and log it.
     *
     * @param callable(): void $operation
     */
    private function control(string $sql, callable $operation): void
    {
        $start = hrtime(true);

        try {
            $operation();
        } catch (Throwable $exception) {
            $this->logger->debug($sql, [
                'bindings' => [],
                'duration' => LoggingStatement::elapsed($start),
                'exception' => $exception,
            ]);

            throw $exception;
        }

        $this->logger->debug($sql, ['bindings' => [], 'duration' => LoggingStatement::elapsed($start)]);
    }
}
