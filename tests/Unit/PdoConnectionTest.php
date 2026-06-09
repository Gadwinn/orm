<?php

declare(strict_types=1);

namespace G\Orm\Tests\Unit;

use G\Orm\Contract\Driver\StatementInterface;
use G\Orm\Driver\Pdo\PdoConnection;
use G\Orm\Exception\QueryException;
use G\Orm\Exception\TransactionException;
use G\Orm\Tests\Fixture\MysqlNamedPdo;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The PDO connection wrapper: engine-aware identifier quoting, explicit
 * transaction control, value quoting, last-insert-id retrieval and statement
 * preparation — including the failure paths that translate PDOExceptions into
 * the ORM's typed exception hierarchy.
 */
#[CoversClass(PdoConnection::class)]
final class PdoConnectionTest extends TestCase
{
    private function standardConnection(): PdoConnection
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');

        return new PdoConnection($pdo);
    }

    public function testQuotesIdentifiersWithStandardDoubleQuotesByDefault(): void
    {
        $connection = $this->standardConnection();

        self::assertSame('"users"', $connection->quoteIdentifier('users'));
        // Qualified names are split on the dot and each part quoted.
        self::assertSame('"t"."col"', $connection->quoteIdentifier('t.col'));
        // An embedded delimiter is doubled, never left to break the quoting.
        self::assertSame('"we""ird"', $connection->quoteIdentifier('we"ird'));
    }

    public function testQuotesIdentifiersWithBackticksOnMysql(): void
    {
        $connection = new PdoConnection(new MysqlNamedPdo());

        self::assertSame('`users`', $connection->quoteIdentifier('users'));
        self::assertSame('`t`.`col`', $connection->quoteIdentifier('t.col'));
        self::assertSame('`we``ird`', $connection->quoteIdentifier('we`ird'));
    }

    public function testQuotesScalarValues(): void
    {
        $connection = $this->standardConnection();

        self::assertSame("'O''Brien'", $connection->quote("O'Brien"));
    }

    public function testCommitTransactionLifecycle(): void
    {
        $connection = $this->standardConnection();

        self::assertFalse($connection->inTransaction());
        $connection->beginTransaction();
        self::assertTrue($connection->inTransaction());

        $connection->prepare("INSERT INTO widgets (name) VALUES ('a')")->execute();
        $connection->commit();

        self::assertFalse($connection->inTransaction());
        self::assertSame('1', $connection->lastInsertId());
    }

    public function testRollbackTransactionLifecycle(): void
    {
        $connection = $this->standardConnection();

        $connection->beginTransaction();
        $connection->prepare("INSERT INTO widgets (name) VALUES ('a')")->execute();
        $connection->rollBack();

        self::assertFalse($connection->inTransaction());
        $rows = $connection->prepare('SELECT COUNT(*) AS c FROM widgets')->execute()->fetchAll();
        self::assertSame([['c' => 0]], $rows);
    }

    public function testPrepareReturnsAStatement(): void
    {
        $statement = $this->standardConnection()->prepare('SELECT 1 AS one');

        self::assertInstanceOf(StatementInterface::class, $statement);
    }

    public function testBeginningASecondTransactionThrowsTransactionException(): void
    {
        $connection = $this->standardConnection();
        $connection->beginTransaction();

        $this->expectException(TransactionException::class);
        $this->expectExceptionMessage('Unable to begin transaction');

        $connection->beginTransaction();
    }

    public function testCommitWithoutActiveTransactionThrowsTransactionException(): void
    {
        $this->expectException(TransactionException::class);
        $this->expectExceptionMessage('Unable to commit transaction');

        $this->standardConnection()->commit();
    }

    public function testRollbackWithoutActiveTransactionThrowsTransactionException(): void
    {
        $this->expectException(TransactionException::class);
        $this->expectExceptionMessage('Unable to roll back transaction');

        $this->standardConnection()->rollBack();
    }

    public function testPreparingInvalidSqlThrowsQueryException(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Unable to prepare statement');

        $this->standardConnection()->prepare('SELECT FROM WHERE NOT valid');
    }
}
