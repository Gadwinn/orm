<?php

declare(strict_types=1);

namespace G\Orm\Tests\Unit;

use G\Orm\Contract\Driver\ResultInterface;
use G\Orm\Driver\Pdo\PdoConnection;
use G\Orm\Driver\Pdo\PdoResult;
use G\Orm\Driver\Pdo\PdoStatement;
use G\Orm\Exception\QueryException;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The PDO prepared-statement wrapper: per-value binding with PHP-to-PDO type
 * inference, bulk execute() parameters, the mutual exclusion between the two
 * binding strategies, and execution-failure translation.
 */
#[CoversClass(PdoConnection::class)]
#[CoversClass(PdoStatement::class)]
#[CoversClass(PdoResult::class)]
final class PdoStatementTest extends TestCase
{
    private function connection(): PdoConnection
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE t (i INTEGER, b INTEGER, n TEXT, s TEXT NOT NULL)');

        return new PdoConnection($pdo);
    }

    public function testIndividualBindingInfersPdoTypesAcrossPhpValues(): void
    {
        $connection = $this->connection();

        // One placeholder per inferred type: int, bool, null and string.
        $connection->prepare('INSERT INTO t (i, b, n, s) VALUES (?, ?, ?, ?)')
            ->bind(1, 42)
            ->bind(2, true)
            ->bind(3, null)
            ->bind(4, 'hello')
            ->execute();

        $rows = $connection->prepare('SELECT i, b, n, s FROM t')->execute()->fetchAll();

        self::assertSame([['i' => 42, 'b' => 1, 'n' => null, 's' => 'hello']], $rows);
    }

    public function testBulkExecuteParametersBindPositionally(): void
    {
        $connection = $this->connection();

        $result = $connection->prepare('INSERT INTO t (i, s) VALUES (?, ?)')
            ->execute([7, 'bulk']);

        self::assertInstanceOf(ResultInterface::class, $result);
        self::assertSame(1, $result->rowCount());

        $rows = $connection->prepare('SELECT i, s FROM t')->execute()->fetchAll();
        self::assertSame([['i' => 7, 's' => 'bulk']], $rows);
    }

    public function testMixingBindAndExecuteParametersIsRejected(): void
    {
        $statement = $this->connection()
            ->prepare('INSERT INTO t (i, s) VALUES (?, ?)')
            ->bind(1, 1);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Do not mix bind() and execute($params)');

        $statement->execute([1, 'oops']);
    }

    public function testFailedExecutionIsTranslatedToQueryException(): void
    {
        // The NOT NULL column "s" is left null, so the driver rejects the row
        // at execution time; the PDOException must surface as a QueryException.
        $statement = $this->connection()->prepare('INSERT INTO t (i, s) VALUES (?, ?)');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Unable to execute statement');

        $statement->execute([1, null]);
    }
}
