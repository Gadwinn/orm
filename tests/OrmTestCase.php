<?php

declare(strict_types=1);

namespace G\Orm\Tests;

use G\Orm\Compiler\SqlCompiler;
use G\Orm\Contract\Driver\ConnectionInterface;
use G\Orm\Core\DbManager;
use G\Orm\Driver\Pdo\PdoConnection;
use G\Orm\Hydrator\Hydrator;
use G\Orm\Metadata\MetadataReader;
use G\Orm\Registry\OrmRegistry;
use G\Orm\Tests\Fixture\RecordingConnection;
use G\Orm\Tests\Fixture\User;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Shared base for the ORM test suite.
 *
 * Each test runs against a private, throw-away in-memory SQLite database, so
 * cases are fully isolated and order-independent. The default engine-neutral
 * components (metadata reader, hydrator, compiler) are instantiated for direct
 * use, and a {@see DbManager} is booted around the connection.
 *
 * tearDown() unbinds the global registry so static manager state never leaks
 * from one test into the next.
 */
abstract class OrmTestCase extends TestCase
{
    protected PDO $pdo;

    protected ConnectionInterface $connection;

    protected MetadataReader $metadata;

    protected Hydrator $hydrator;

    protected SqlCompiler $compiler;

    protected DbManager $manager;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->connection = new PdoConnection($this->pdo);
        $this->metadata = new MetadataReader();
        $this->hydrator = new Hydrator($this->metadata);
        $this->compiler = new SqlCompiler();
        $this->manager = DbManager::create($this->connection);

        $this->migrate();
    }

    protected function tearDown(): void
    {
        // Critical: drop any manager bound to the process so static registry
        // state cannot bleed across tests.
        OrmRegistry::clear();
    }

    /**
     * Create the fixture schema. SQLite maps INTEGER PRIMARY KEY to the
     * implicit rowid, so single-key inserts produce a usable lastInsertId().
     */
    protected function migrate(): void
    {
        $this->pdo->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL UNIQUE,
                address_id INTEGER
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE addresses (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                city TEXT NOT NULL
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE user_roles (
                user_id INTEGER NOT NULL,
                role_id INTEGER NOT NULL,
                granted_at TEXT NOT NULL,
                PRIMARY KEY (user_id, role_id)
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE members (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL,
                password TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE accounts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL UNIQUE,
                level INTEGER NOT NULL
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE registry_aware (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE appointments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                scheduled_at TEXT NOT NULL
            )'
        );
    }

    /**
     * Build a fresh, unsaved User. Its auto-increment id is left unassigned so
     * the Unit Of Work exercises its generated-identifier path on insert.
     */
    protected function makeUser(string $name, string $email): User
    {
        $user = new User();
        $user->name = $name;
        $user->email = $email;

        return $user;
    }

    /**
     * Boot a manager whose Unit Of Work writes through a recording connection
     * sharing this test's in-memory database, so emitted SQL and transaction
     * control can be asserted.
     *
     * @return array{0: DbManager, 1: RecordingConnection}
     */
    protected function recordingManager(): array
    {
        $recording = new RecordingConnection($this->connection);

        return [DbManager::create($recording), $recording];
    }

    protected function seedRow(string $sql): void
    {
        $this->pdo->exec($sql);
    }

    protected function countRows(string $table): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM ' . $table);
        if ($statement === false) {
            self::fail('Unable to count rows in ' . $table . '.');
        }

        return (int) $statement->fetchColumn();
    }
}
