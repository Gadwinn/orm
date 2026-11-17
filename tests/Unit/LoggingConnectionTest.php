<?php

declare(strict_types=1);

namespace G\Orm\Tests\Unit;

use G\Orm\Compiler\Platform\PlatformInterface;
use G\Orm\Core\DbManager;
use G\Orm\Driver\LoggingConnection;
use G\Orm\Driver\LoggingStatement;
use G\Orm\Driver\Pdo\PdoConnection;
use G\Orm\Exception\QueryException;
use G\Orm\Tests\Fixture\MemoryLogger;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

#[CoversClass(LoggingConnection::class)]
#[CoversClass(LoggingStatement::class)]
final class LoggingConnectionTest extends TestCase
{
    private MemoryLogger $logger;

    private LoggingConnection $connection;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $pdo->exec("INSERT INTO t (name) VALUES ('Ann'), ('Bob')");

        $this->logger = new MemoryLogger();
        $this->connection = new LoggingConnection(new PdoConnection($pdo), $this->logger);
    }

    public function testSelectWithParametersIsLogged(): void
    {
        $rows = $this->connection->prepare('SELECT name FROM t WHERE id = :id')->execute(['id' => 2])->fetchAll();

        self::assertSame([['name' => 'Bob']], $rows);
        self::assertCount(1, $this->logger->records);
        $record = $this->logger->records[0];
        self::assertSame(LogLevel::DEBUG, $record['level']);
        self::assertSame('SELECT name FROM t WHERE id = :id', $record['message']);
        self::assertSame(['id' => 2], $record['context']['bindings']);
        self::assertIsFloat($record['context']['duration']);
        self::assertGreaterThanOrEqual(0.0, $record['context']['duration']);
        self::assertArrayNotHasKey('exception', $record['context']);
    }

    public function testInsertWithBoundValuesIsLogged(): void
    {
        $count = $this->connection->prepare('INSERT INTO t (name) VALUES (?)')->bind(1, 'Cid')->execute()->rowCount();

        self::assertSame(1, $count);
        self::assertSame('INSERT INTO t (name) VALUES (?)', $this->logger->records[0]['message']);
        self::assertSame([1 => 'Cid'], $this->logger->records[0]['context']['bindings']);
        self::assertSame('3', (string) $this->connection->lastInsertId());
        self::assertCount(1, $this->logger->records);
    }

    public function testFailingQueryIsLoggedThenRethrown(): void
    {
        $statement = $this->connection->prepare('INSERT INTO t (name) VALUES (?)');

        try {
            $statement->execute([null]);
            self::fail('The NOT NULL constraint should have failed.');
        } catch (QueryException $exception) {
            $record = $this->logger->records[0];
            self::assertSame([null], $record['context']['bindings']);
            self::assertSame($exception, $record['context']['exception']);
            self::assertIsFloat($record['context']['duration']);
        }
    }

    public function testTransactionsAreLogged(): void
    {
        $this->connection->beginTransaction();
        self::assertTrue($this->connection->inTransaction());
        $this->connection->prepare('DELETE FROM t')->execute();
        $this->connection->rollBack();
        $this->connection->beginTransaction();
        $this->connection->commit();

        self::assertSame(
            ['BEGIN', 'DELETE FROM t', 'ROLLBACK', 'BEGIN', 'COMMIT'],
            array_column($this->logger->records, 'message'),
        );
        self::assertSame([], $this->logger->records[0]['context']['bindings']);
        self::assertIsFloat($this->logger->records[0]['context']['duration']);
        self::assertSame(2, $this->connection->prepare('SELECT COUNT(*) AS c FROM t')->execute()->fetchAll()[0]['c']);
    }

    public function testFailingTransactionControlIsLoggedThenRethrown(): void
    {
        try {
            $this->connection->commit();
            self::fail('Committing without a transaction should fail.');
        } catch (\Throwable $exception) {
            self::assertSame('COMMIT', $this->logger->records[0]['message']);
            self::assertSame($exception, $this->logger->records[0]['context']['exception']);
        }
    }

    public function testOtherMethodsAreDelegatedWithoutLogging(): void
    {
        self::assertSame("'a''b'", $this->connection->quote("a'b"));
        self::assertSame('"t"', $this->connection->quoteIdentifier('t'));
        self::assertInstanceOf(PlatformInterface::class, $this->connection->getPlatform());
        self::assertSame([], $this->logger->records);
    }

    public function testManagerQueriesGoThroughTheDecorator(): void
    {
        $orm = DbManager::create($this->connection);

        self::assertSame(2, $orm->query('UPDATE t SET name = ?', ['X']));
        self::assertSame('UPDATE t SET name = ?', $this->logger->records[0]['message']);
    }
}
