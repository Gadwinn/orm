<?php

declare(strict_types=1);

namespace G\Orm\Driver\Pdo;

use G\Orm\Contract\Driver\ResultInterface;
use PDO;
use PDOStatement;

/**
 * PDO-backed result wrapping the executed \PDOStatement.
 *
 * Rows are always fetched as associative arrays.
 */
final class PdoResult implements ResultInterface
{
    public function __construct(
        private readonly PDOStatement $statement,
    ) {
    }

    public function isResultSet(): bool
    {
        return $this->statement->columnCount() > 0;
    }

    public function fetchAll(): array
    {
        $rows = $this->statement->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $result[] = $this->normaliseRow($row);
            }
        }

        return $result;
    }

    public function fetchAssociative(): ?array
    {
        $row = $this->statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->normaliseRow($row) : null;
    }

    /**
     * Guarantee string-keyed rows regardless of how the driver typed them.
     *
     * @param array<array-key, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function normaliseRow(array $row): array
    {
        $normalised = [];
        foreach ($row as $column => $value) {
            $normalised[(string) $column] = $value;
        }

        return $normalised;
    }

    public function rowCount(): int
    {
        return $this->statement->rowCount();
    }
}
