<?php

declare(strict_types=1);

namespace G\Orm\Driver\Pdo;

use G\Orm\Contract\Driver\ResultInterface;
use G\Orm\Contract\Driver\StatementInterface;
use G\Orm\Exception\QueryException;
use PDO;
use PDOException;

/**
 * PDO-backed prepared statement wrapping a native \PDOStatement.
 *
 * Values may be bound individually with bind() (PDO type inferred from the
 * PHP value) or supplied in bulk at execute() time. Positional bindings use
 * 1-based integer placeholders; named bindings use string placeholders.
 *
 * Bind OR Execute rule: choose one binding style per statement. Either chain
 * bind() and then call execute() with no arguments, or pass the full set of
 * parameters to execute(); never mix the two. Mixing is rejected to avoid
 * partially-bound statements with undefined behaviour.
 */
final class PdoStatement implements StatementInterface
{
    private bool $hasBoundValues = false;

    public function __construct(
        private readonly \PDOStatement $statement,
    ) {
    }

    public function bind(string|int $param, mixed $value): self
    {
        try {
            $this->statement->bindValue($param, $value, $this->pdoType($value));
        } catch (PDOException $exception) {
            throw new QueryException(
                'Unable to bind parameter: ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        $this->hasBoundValues = true;

        return $this;
    }

    public function execute(array $params = []): ResultInterface
    {
        if ($params !== [] && $this->hasBoundValues) {
            throw QueryException::mixedBindingStrategies();
        }

        try {
            if ($params === []) {
                $this->statement->execute();
            } else {
                $this->statement->execute($params);
            }
        } catch (PDOException $exception) {
            throw new QueryException(
                'Unable to execute statement: ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        return new PdoResult($this->statement);
    }

    private function pdoType(mixed $value): int
    {
        return match (true) {
            is_int($value) => PDO::PARAM_INT,
            is_bool($value) => PDO::PARAM_BOOL,
            $value === null => PDO::PARAM_NULL,
            default => PDO::PARAM_STR,
        };
    }
}
