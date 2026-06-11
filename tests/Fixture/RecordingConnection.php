<?php

declare(strict_types=1);

namespace G\Orm\Tests\Fixture;

use G\Orm\Compiler\Platform\PlatformInterface;
use G\Orm\Contract\Driver\ConnectionInterface;
use G\Orm\Contract\Driver\StatementInterface;

/**
 * Transparent {@see ConnectionInterface} decorator that records every SQL
 * string handed to prepare().
 *
 * It lets a test assert precisely which statements the Unit Of Work emitted —
 * for instance that a dirty-diff UPDATE targets only the modified column, or
 * that a no-op flush emits nothing at all. Transaction control is delegated
 * untouched and is deliberately not recorded, so the statement log holds SQL
 * only.
 */
final class RecordingConnection implements ConnectionInterface
{
    /**
     * @var list<string>
     */
    private array $statements = [];

    /**
     * Transaction-control ops in execution order: BEGIN, COMMIT, ROLLBACK.
     *
     * @var list<string>
     */
    private array $control = [];

    public function __construct(
        private readonly ConnectionInterface $inner,
    ) {
    }

    /**
     * The SQL statements prepared since construction or the last reset(), in
     * execution order.
     *
     * @return list<string>
     */
    public function statements(): array
    {
        return $this->statements;
    }

    /**
     * The transaction-control ops issued since construction or the last
     * reset(), in execution order. Kept separate from statements() so an SQL
     * assertion never has to account for BEGIN/COMMIT noise.
     *
     * @return list<string>
     */
    public function controlLog(): array
    {
        return $this->control;
    }

    public function reset(): void
    {
        $this->statements = [];
        $this->control = [];
    }

    public function getPlatform(): PlatformInterface
    {
        return $this->inner->getPlatform();
    }

    public function prepare(string $sql): StatementInterface
    {
        $this->statements[] = $sql;

        return $this->inner->prepare($sql);
    }

    public function beginTransaction(): void
    {
        $this->control[] = 'BEGIN';
        $this->inner->beginTransaction();
    }

    public function commit(): void
    {
        $this->control[] = 'COMMIT';
        $this->inner->commit();
    }

    public function rollBack(): void
    {
        $this->control[] = 'ROLLBACK';
        $this->inner->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->inner->inTransaction();
    }

    public function lastInsertId(?string $name = null): string|int
    {
        return $this->inner->lastInsertId($name);
    }

    public function quote(string $value): string
    {
        return $this->inner->quote($value);
    }

    public function quoteIdentifier(string $identifier): string
    {
        return $this->inner->quoteIdentifier($identifier);
    }
}
