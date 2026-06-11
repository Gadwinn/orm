<?php

declare(strict_types=1);

namespace G\Orm\Tests\Integration;

use G\Orm\Core\DbManager;
use G\Orm\Driver\Pdo\PdoResult;
use G\Orm\Tests\OrmTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Raw SQL through DbManager::query(): the decision between a fetched result
 * set and an affected-row count is made by the driver (did the statement
 * produce columns?), never by sniffing the SQL's leading keyword. Leading
 * comments, parenthesised SELECTs and data-modifying CTEs are therefore all
 * classified by what they actually are.
 */
#[CoversClass(DbManager::class)]
#[CoversClass(PdoResult::class)]
final class RawQueryTest extends OrmTestCase
{
    public function testPlainSelectReturnsRows(): void
    {
        $this->seedRow("INSERT INTO users (id, name, email) VALUES (1, 'Ada', 'ada@test.com')");

        $rows = $this->manager->query('SELECT name FROM users WHERE id = ?', [1]);

        self::assertSame([['name' => 'Ada']], $rows);
    }

    public function testSelectBehindALeadingCommentStillReturnsRows(): void
    {
        $this->seedRow("INSERT INTO users (id, name, email) VALUES (1, 'Ada', 'ada@test.com')");

        $rows = $this->manager->query('/* planner hint */ SELECT name FROM users');

        self::assertSame([['name' => 'Ada']], $rows);
    }

    public function testWriteStatementReturnsAffectedRowCount(): void
    {
        $this->seedRow("INSERT INTO users (id, name, email) VALUES (1, 'Ada', 'ada@test.com')");
        $this->seedRow("INSERT INTO users (id, name, email) VALUES (2, 'Grace', 'grace@test.com')");

        $affected = $this->manager->query('DELETE FROM users WHERE id IN (?, ?)', [1, 2]);

        self::assertSame(2, $affected);
    }

    public function testDataModifyingCteIsClassifiedAsAWrite(): void
    {
        $this->seedRow("INSERT INTO users (id, name, email) VALUES (1, 'Ada', 'ada@test.com')");

        // Starts with WITH, but is a DELETE: the old keyword heuristic called
        // this a read and tried to fetch rows from a write statement.
        $affected = $this->manager->query(
            'WITH doomed(id) AS (SELECT 1) DELETE FROM users WHERE id IN (SELECT id FROM doomed)'
        );

        self::assertSame(1, $affected);
        self::assertSame(0, $this->countRows('users'));
    }

    public function testRowReturningCteIsClassifiedAsARead(): void
    {
        $rows = $this->manager->query('WITH x(one) AS (SELECT 1) SELECT one FROM x');

        self::assertSame([['one' => 1]], $rows);
    }
}
