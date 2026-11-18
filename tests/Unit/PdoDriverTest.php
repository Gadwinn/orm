<?php

declare(strict_types=1);

namespace G\Orm\Tests\Unit;

use G\Orm\Contract\Driver\ConnectionInterface;
use G\Orm\Driver\Pdo\PdoDriver;
use G\Orm\Exception\ConnectionException;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The PDO driver's connection bootstrap: DSN resolution (explicit DSN, SQLite
 * shorthand, and discrete parts), scalar option coercion, attribute merging,
 * and the failure paths that raise a typed ConnectionException.
 */
#[CoversClass(PdoDriver::class)]
final class PdoDriverTest extends TestCase
{
    public function testConnectsToInMemorySqliteByDriverShorthand(): void
    {
        $connection = (new PdoDriver())->connect(['driver' => 'sqlite']);

        self::assertInstanceOf(ConnectionInterface::class, $connection);

        // The connection is live: a full round trip succeeds.
        $connection->prepare('CREATE TABLE probe (id INTEGER PRIMARY KEY, v TEXT)')->execute();
        $connection->prepare("INSERT INTO probe (id, v) VALUES (1, 'ok')")->execute();
        $rows = $connection->prepare('SELECT v FROM probe')->execute()->fetchAll();

        self::assertSame([['v' => 'ok']], $rows);
    }

    public function testConnectsViaExplicitDsnPassthrough(): void
    {
        $connection = (new PdoDriver())->connect(['dsn' => 'sqlite::memory:']);

        self::assertInstanceOf(ConnectionInterface::class, $connection);
    }

    public function testSqliteHonoursAnExplicitPathOption(): void
    {
        // The explicit ":memory:" path travels through the sqlite branch of
        // resolveDsn rather than the default.
        $connection = (new PdoDriver())->connect(['driver' => 'sqlite', 'path' => ':memory:']);

        self::assertInstanceOf(ConnectionInterface::class, $connection);
    }

    public function testCustomPdoOptionsAreMergedAndNonIntKeysIgnored(): void
    {
        $connection = (new PdoDriver())->connect([
            'driver' => 'sqlite',
            'options' => [
                PDO::ATTR_TIMEOUT => 5,
                'ignored-string-key' => 'dropped',
            ],
        ]);

        self::assertInstanceOf(ConnectionInterface::class, $connection);
    }

    public function testMysqlCountsMatchedRowsUnlessTheCallerSaysOtherwise(): void
    {
        if (!extension_loaded('pdo_mysql')) {
            self::markTestSkipped('pdo_mysql is not loaded.');
        }

        $attribute = defined('Pdo\Mysql::ATTR_FOUND_ROWS')
            ? constant('Pdo\Mysql::ATTR_FOUND_ROWS')
            : constant('PDO::MYSQL_ATTR_FOUND_ROWS');
        self::assertIsInt($attribute);
        $options = new \ReflectionMethod(PdoDriver::class, 'pdoOptions');

        $mysql = $options->invoke(new PdoDriver(), [], 'mysql:host=db;dbname=app');
        self::assertIsArray($mysql);
        self::assertTrue($mysql[$attribute]);

        $overridden = $options->invoke(new PdoDriver(), ['options' => [$attribute => false]], 'mysql:host=db');
        self::assertIsArray($overridden);
        self::assertFalse($overridden[$attribute]);

        $sqlite = $options->invoke(new PdoDriver(), [], 'sqlite::memory:');
        self::assertIsArray($sqlite);
        self::assertArrayNotHasKey($attribute, $sqlite);
    }

    public function testBuildingADsnFromPartsWithAnUnknownDriverFails(): void
    {
        // Exercises the discrete-parts DSN assembly and the connect() catch
        // block: the driver name is rejected by PDO before any socket is opened.
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Unable to open the database connection');

        (new PdoDriver())->connect(['driver' => 'no_such_driver', 'dbname' => 'x', 'host' => 'localhost']);
    }

    public function testMissingDsnAndPartsIsRejected(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Cannot build a DSN');

        (new PdoDriver())->connect(['driver' => 'pgsql']);
    }

    public function testNonScalarOptionIsRejected(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Connection options must be scalar values');

        (new PdoDriver())->connect(['dsn' => ['not', 'scalar']]);
    }
}
