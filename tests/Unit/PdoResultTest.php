<?php

declare(strict_types=1);

namespace G\Orm\Tests\Unit;

use G\Orm\Driver\Pdo\PdoConnection;
use G\Orm\Driver\Pdo\PdoResult;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The PDO result wrapper's single-row fetch and affected-row count, with the
 * string-keyed normalisation guarantee.
 */
#[CoversClass(PdoResult::class)]
#[CoversClass(PdoConnection::class)]
final class PdoResultTest extends TestCase
{
    private function connection(): PdoConnection
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $pdo->exec("INSERT INTO t (id, name) VALUES (1, 'first'), (2, 'second')");

        return new PdoConnection($pdo);
    }

    public function testFetchAssociativeReturnsTheNextRowAsAStringKeyedArray(): void
    {
        $row = $this->connection()
            ->prepare('SELECT id, name FROM t ORDER BY id')
            ->execute()
            ->fetchAssociative();

        self::assertSame(['id' => 1, 'name' => 'first'], $row);
    }

    public function testFetchAssociativeReturnsNullWhenNoRowsRemain(): void
    {
        $row = $this->connection()
            ->prepare('SELECT id, name FROM t WHERE id = ?')
            ->execute([999])
            ->fetchAssociative();

        self::assertNull($row);
    }

    public function testRowCountReportsAffectedRowsForWrites(): void
    {
        $affected = $this->connection()
            ->prepare('UPDATE t SET name = ? WHERE id IN (1, 2)')
            ->execute(['renamed'])
            ->rowCount();

        self::assertSame(2, $affected);
    }
}
