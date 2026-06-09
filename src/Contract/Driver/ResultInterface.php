<?php

declare(strict_types=1);

namespace G\Orm\Contract\Driver;

/**
 * Wraps the outcome of an executed statement.
 *
 * For SELECT-like statements it exposes the result set; for write statements
 * it exposes the affected-row count. The last generated identifier is session
 * state and lives on ConnectionInterface, not here.
 */
interface ResultInterface
{
    /**
     * Return every remaining row as a list of associative arrays.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchAll(): array;

    /**
     * Return the next row as an associative array, or null when the result
     * set is exhausted.
     *
     * @return array<string, mixed>|null
     */
    public function fetchAssociative(): ?array;

    /**
     * The number of rows affected by the last write statement.
     */
    public function rowCount(): int;
}
