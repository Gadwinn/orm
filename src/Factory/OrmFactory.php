<?php

declare(strict_types=1);

namespace G\Orm\Factory;

use G\Orm\Contract\DbManagerInterface;
use G\Orm\Contract\Driver\ConnectionInterface;
use G\Orm\Core\DbManager;
use G\Orm\Driver\Pdo\PdoDriver;
use G\Orm\Exception\ConnectionException;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Composition root for one or several databases.
 *
 * The factory holds named connection configurations and lazily builds one
 * fully wired {@see DbManager} per name, cached for the factory's lifetime.
 * Each named manager owns its private Unit Of Work and Identity Map, so two
 * databases in the same project never share tracking state; transactions are
 * per connection.
 *
 * Three configuration shapes are accepted, normalised to the option array the
 * PDO driver consumes:
 *
 *   - PDO DSN string:  'pgsql:host=127.0.0.1;dbname=shop'
 *     (a bare DSN cannot carry credentials — use the array or URI form when
 *     a user/password is needed)
 *   - option array:    ['driver' => 'mysql', 'host' => ..., 'user' => ...]
 *     (the exact contract of {@see PdoDriver::connect()})
 *   - URI string:      'mysql://db_user:db_password@127.0.0.1:3306/db_name'
 *     (scheme aliases like mariadb/postgres/mssql resolve to their PDO
 *     driver; percent-encoded credentials are decoded; recognised query
 *     parameters are kept, unknown ones — e.g. serverVersion — are ignored)
 *
 * Security: a URI may embed credentials, so when one cannot be parsed the
 * exception deliberately never echoes the configuration string.
 */
final class OrmFactory
{
    /**
     * Name under which a single-database configuration is registered when no
     * explicit name is used.
     */
    public const DEFAULT_CONNECTION = 'default';

    /**
     * URI scheme => PDO driver name. Unlisted schemes pass through verbatim
     * so future PDO drivers keep working without a factory change.
     */
    private const DRIVER_ALIASES = [
        'mariadb' => 'mysql',
        'postgres' => 'pgsql',
        'postgresql' => 'pgsql',
        'mssql' => 'sqlsrv',
    ];

    private const DRIVER_SQLITE = 'sqlite';

    /**
     * SQLite URIs ("sqlite:///var/app.db") have an empty authority, which
     * parse_url() rejects outright; they are recognised by prefix instead.
     */
    private const SQLITE_URI_PREFIX = 'sqlite://';

    /**
     * URI query parameters forwarded to the connection options; anything
     * else (serverVersion, ...) is configuration noise for other stacks and
     * is deliberately dropped.
     */
    private const URI_QUERY_OPTIONS = ['charset', 'unix_socket'];

    /**
     * A URL path that is really a Windows drive path ("/C:/data/app.db"):
     * the leading slash is an artefact of the URI form and must be removed.
     */
    private const WINDOWS_DRIVE_PATH = '#^/[A-Za-z]:#';

    /**
     * @var array<string, ConnectionInterface>
     */
    private array $connections = [];

    /**
     * @var array<string, DbManagerInterface>
     */
    private array $managers = [];

    /**
     * @param array<string, string|array<string, mixed>> $configurations Connection
     *        configuration per name (DSN string, URI string or option array).
     * @param EventDispatcherInterface|null              $dispatcher     Optional
     *        PSR-14 dispatcher shared by every manager this factory builds.
     */
    public function __construct(
        private readonly array $configurations,
        private readonly ?EventDispatcherInterface $dispatcher = null,
    ) {
    }

    /**
     * One-shot shortcut for the single-database case: normalise, connect and
     * wire a manager in one call.
     *
     * @param string|array<string, mixed> $config
     *
     * @throws ConnectionException
     */
    public static function connect(
        string|array $config,
        ?EventDispatcherInterface $dispatcher = null
    ): DbManagerInterface {
        return (new self([self::DEFAULT_CONNECTION => $config], $dispatcher))->manager();
    }

    /**
     * The fully wired manager for a named connection, built on first use and
     * cached afterwards.
     *
     * @throws ConnectionException When the name is unknown or connecting fails.
     */
    public function manager(string $name = self::DEFAULT_CONNECTION): DbManagerInterface
    {
        return $this->managers[$name] ??= DbManager::create($this->connection($name), $this->dispatcher);
    }

    /**
     * The open connection for a name, shared with the manager of the same
     * name, built on first use and cached afterwards.
     *
     * @throws ConnectionException When the name is unknown or connecting fails.
     */
    public function connection(string $name = self::DEFAULT_CONNECTION): ConnectionInterface
    {
        if (isset($this->connections[$name])) {
            return $this->connections[$name];
        }

        if (!array_key_exists($name, $this->configurations)) {
            throw new ConnectionException(sprintf(
                'Unknown connection "%s"; configured connections: %s.',
                $name,
                implode(', ', array_keys($this->configurations)),
            ));
        }

        return $this->connections[$name] = (new PdoDriver())->connect(
            self::normalise($this->configurations[$name])
        );
    }

    /**
     * Normalise any accepted configuration shape into the option array the
     * PDO driver consumes. Public so a configuration can be inspected or
     * unit-tested without opening a connection.
     *
     * @param string|array<string, mixed> $config
     *
     * @return array<string, mixed>
     *
     * @throws ConnectionException When a URI cannot be parsed.
     */
    public static function normalise(string|array $config): array
    {
        if (is_array($config)) {
            return $config;
        }

        if (str_contains($config, '://')) {
            return self::fromUri($config);
        }

        return ['dsn' => $config];
    }

    /**
     * Translate a database URI into discrete connection options.
     *
     * @return array<string, mixed>
     *
     * @throws ConnectionException
     */
    private static function fromUri(string $uri): array
    {
        if (str_starts_with(strtolower($uri), self::SQLITE_URI_PREFIX)) {
            return self::sqliteOptions(substr($uri, strlen(self::SQLITE_URI_PREFIX)));
        }

        $parts = parse_url($uri);

        if ($parts === false || !isset($parts['scheme'])) {
            // Deliberately never echo the URI: it may carry credentials.
            throw new ConnectionException(
                'The connection URI cannot be parsed; it is not echoed here because it may carry credentials.'
            );
        }

        $scheme = strtolower($parts['scheme']);
        $driver = self::DRIVER_ALIASES[$scheme] ?? $scheme;

        $options = ['driver' => $driver];

        if (isset($parts['host'])) {
            $options['host'] = $parts['host'];
        }

        if (isset($parts['port'])) {
            $options['port'] = $parts['port'];
        }

        if (isset($parts['user'])) {
            $options['user'] = rawurldecode($parts['user']);
        }

        if (isset($parts['pass'])) {
            $options['password'] = rawurldecode($parts['pass']);
        }

        $dbname = rawurldecode(ltrim($parts['path'] ?? '', '/'));
        if ($dbname !== '') {
            $options['dbname'] = $dbname;
        }

        return $options + self::queryOptions($parts['query'] ?? '');
    }

    /**
     * SQLite URIs carry a file path instead of host/dbname parts; an empty
     * path means the in-memory database.
     *
     * @return array<string, string>
     */
    private static function sqliteOptions(string $path): array
    {
        if (preg_match(self::WINDOWS_DRIVE_PATH, $path) === 1) {
            $path = ltrim($path, '/');
        }

        $options = ['driver' => self::DRIVER_SQLITE];
        if ($path !== '') {
            $options['path'] = $path;
        }

        return $options;
    }

    /**
     * Keep only the recognised query parameters of a URI.
     *
     * @return array<string, string>
     */
    private static function queryOptions(string $query): array
    {
        if ($query === '') {
            return [];
        }

        parse_str($query, $parameters);

        $options = [];
        foreach (self::URI_QUERY_OPTIONS as $key) {
            if (isset($parameters[$key]) && is_string($parameters[$key])) {
                $options[$key] = $parameters[$key];
            }
        }

        return $options;
    }
}
