<?php

declare(strict_types=1);

namespace G\Orm\Driver;

use G\Orm\Contract\Driver\ResultInterface;
use G\Orm\Contract\Driver\StatementInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Statement decorator created by {@see LoggingConnection}: logs each
 * execution with its SQL, bindings and duration.
 */
final class LoggingStatement implements StatementInterface
{
    private const NANOSECONDS_PER_MILLISECOND = 1_000_000;

    /**
     * Values bound one by one with bind(), kept for the log.
     *
     * @var array<string|int, mixed>
     */
    private array $bindings = [];

    public function __construct(
        private readonly StatementInterface $statement,
        private readonly string $sql,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function bind(string|int $param, mixed $value): self
    {
        $this->statement->bind($param, $value);
        $this->bindings[$param] = $value;

        return $this;
    }

    public function execute(array $params = []): ResultInterface
    {
        $bindings = $this->bindings + $params;
        $start = hrtime(true);

        try {
            $result = $this->statement->execute($params);
        } catch (Throwable $exception) {
            $this->logger->debug($this->sql, [
                'bindings' => $bindings,
                'duration' => self::elapsed($start),
                'exception' => $exception,
            ]);

            throw $exception;
        }

        $this->logger->debug($this->sql, ['bindings' => $bindings, 'duration' => self::elapsed($start)]);

        return $result;
    }

    /**
     * Milliseconds elapsed since an hrtime(true) reading.
     *
     * @internal
     */
    public static function elapsed(int|float $start): float
    {
        return (hrtime(true) - $start) / self::NANOSECONDS_PER_MILLISECOND;
    }
}
