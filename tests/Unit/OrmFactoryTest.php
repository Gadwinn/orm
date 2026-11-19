<?php

declare(strict_types=1);

namespace G\Orm\Tests\Unit;

use G\Orm\Contract\DbManagerInterface;
use G\Orm\Exception\ConnectionException;
use G\Orm\Factory\OrmFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The composition root: normalisation of the three accepted configuration
 * shapes (PDO DSN, option array, URI) into PDO driver options, lazy per-name
 * caching of connections and managers, multi-database isolation, and the
 * security rule that a parse failure never echoes a URI that may carry
 * credentials.
 */
#[CoversClass(OrmFactory::class)]
final class OrmFactoryTest extends TestCase
{
    public function testNormalisesAFullUriIntoDriverOptions(): void
    {
        $options = OrmFactory::normalise(
            'mysql://db_user:db_password@127.0.0.1:3306/db_name?serverVersion=8.0.37&charset=utf8mb4'
        );

        self::assertSame(
            [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => 3306,
                'user' => 'db_user',
                'password' => 'db_password',
                'dbname' => 'db_name',
                'charset' => 'utf8mb4',
            ],
            $options
        );
    }

    public function testStripsTheBracketsOfAnIpv6Host(): void
    {
        self::assertSame('::1', OrmFactory::normalise('pgsql://app@[::1]:5432/shop')['host']);
    }

    public function testUnknownUriQueryParametersAreDropped(): void
    {
        $options = OrmFactory::normalise('mysql://app@db.local/shop?serverVersion=8.0.37');

        self::assertArrayNotHasKey('serverVersion', $options);
    }

    public function testSchemeAliasesResolveToTheirPdoDriver(): void
    {
        self::assertSame('mysql', OrmFactory::normalise('mariadb://app@db.local/shop')['driver']);
        self::assertSame('pgsql', OrmFactory::normalise('postgres://app@db.local/shop')['driver']);
        self::assertSame('pgsql', OrmFactory::normalise('postgresql://app@db.local/shop')['driver']);
        self::assertSame('sqlsrv', OrmFactory::normalise('mssql://app@db.local/shop')['driver']);
    }

    public function testPercentEncodedCredentialsAreDecoded(): void
    {
        $options = OrmFactory::normalise('mysql://app:p%40ss%2Fword@db.local/shop');

        self::assertSame('p@ss/word', $options['password']);
    }

    public function testSqliteUriCarriesAFilePath(): void
    {
        self::assertSame(
            ['driver' => 'sqlite', 'path' => '/var/data/app.db'],
            OrmFactory::normalise('sqlite:///var/data/app.db')
        );

        // The leading slash of the URL path is an artefact on Windows drives.
        self::assertSame(
            ['driver' => 'sqlite', 'path' => 'C:/data/app.db'],
            OrmFactory::normalise('sqlite:///C:/data/app.db')
        );
    }

    public function testSqliteUriForTheInMemoryDatabase(): void
    {
        self::assertSame(['driver' => 'sqlite'], OrmFactory::normalise('sqlite://'));
        self::assertSame(['driver' => 'sqlite'], OrmFactory::normalise('sqlite:///:memory:'));
        self::assertSame(['driver' => 'sqlite'], OrmFactory::normalise('sqlite://:memory:'));
    }

    public function testDsnStringPassesThroughUntouched(): void
    {
        self::assertSame(
            ['dsn' => 'pgsql:host=127.0.0.1;dbname=shop'],
            OrmFactory::normalise('pgsql:host=127.0.0.1;dbname=shop')
        );
    }

    public function testOptionArrayPassesThroughUntouched(): void
    {
        $config = ['driver' => 'mysql', 'host' => '127.0.0.1', 'dbname' => 'shop'];

        self::assertSame($config, OrmFactory::normalise($config));
    }

    public function testUnparsableUriFailsWithoutEchoingItsCredentials(): void
    {
        try {
            OrmFactory::normalise('mysql://app:secret@');
            self::fail('An unparsable URI should raise a ConnectionException.');
        } catch (ConnectionException $exception) {
            self::assertStringContainsString('cannot be parsed', $exception->getMessage());
            // The security rule: credentials never leak into the message.
            self::assertStringNotContainsString('secret', $exception->getMessage());
        }
    }

    public function testManagersAreCachedPerNameAndIsolatedAcrossNames(): void
    {
        $factory = new OrmFactory([
            'main' => 'sqlite::memory:',
            'analytics' => ['driver' => 'sqlite'],
        ]);

        $main = $factory->manager('main');

        // Same name, same instance; different names, fully isolated managers
        // (own connection, own Unit Of Work, own identity map).
        self::assertSame($main, $factory->manager('main'));
        self::assertNotSame($main, $factory->manager('analytics'));
        self::assertNotSame($main->unitOfWork(), $factory->manager('analytics')->unitOfWork());
    }

    public function testConnectionIsCachedAndSharedWithTheManager(): void
    {
        $factory = new OrmFactory(['main' => 'sqlite::memory:']);

        self::assertSame($factory->connection('main'), $factory->connection('main'));

        // The manager writes through the very same session: a table created
        // on the factory's connection is visible to the manager's raw query.
        $factory->connection('main')->prepare('CREATE TABLE t (id INTEGER PRIMARY KEY)')->execute();
        self::assertSame([], $factory->manager('main')->query('SELECT * FROM t'));
    }

    public function testUnknownConnectionNameIsRejectedWithTheConfiguredNames(): void
    {
        $factory = new OrmFactory(['main' => 'sqlite::memory:', 'analytics' => 'sqlite::memory:']);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Unknown connection "reporting"; configured connections: main, analytics.');

        $factory->manager('reporting');
    }

    public function testConnectIsAWorkingSingleDatabaseShortcut(): void
    {
        $orm = OrmFactory::connect('sqlite::memory:');

        self::assertInstanceOf(DbManagerInterface::class, $orm);
        self::assertSame([['one' => 1]], $orm->query('SELECT 1 AS one'));
        self::assertSame([['one' => 1]], OrmFactory::connect('sqlite:///:memory:')->query('SELECT 1 AS one'));
    }
}
