# gadwinn/orm

A lightweight, **SQL-first**, framework-agnostic ORM for PHP 8.3+.

No lazy loading, no proxies, no magic property access. Entities are plain PHP objects (POPOs) mapped with native attributes; persistence flows through a Unit of Work with an Identity Map and snapshot-based change tracking. What you map is what you get, and what you query is what loads.

## Tech Stack

| Concern | Choice |
|---|---|
| Language | PHP **>= 8.3** (typed properties, enums, attributes) |
| Persistence | PDO (`ext-pdo`) - MySQL/MariaDB, PostgreSQL, SQLite, SQL Server |
| Events | PSR-14 (`psr/event-dispatcher`) - optional external dispatch |
| Mapping | Native PHP attributes only (no XML/YAML/annotations) |
| Quality bar | PHPStan level max · PSR-12 · zero magic numbers |

Architecture: Unit of Work · Identity Map · Repository · Hydrator (+ value converters) · attribute-driven MetadataReader · SQL Platform (dialect) layer · `OrmFactory` composition root (multi-database, named connections). Construction is concrete and explicit; every collaborator sits behind an interface for substitution.

## Installation

```bash
composer require gadwinn/orm
```

`ext-pdo` is a hard requirement. Install the concrete PDO driver for your database (`ext-pdo_mysql`, `ext-pdo_sqlite`, …) - these are advertised via `suggest`.

## Bootstrap

`OrmFactory` is the composition root. It accepts three configuration shapes - pick whichever fits your environment - and wires a fully configured manager (connection, SQL dialect, Unit of Work) per database.

```php
use G\Orm\Factory\OrmFactory;

// 1. URI (scheme aliases mariadb/postgres/postgresql/mssql resolve automatically)
$orm = OrmFactory::connect('mysql://db_user:db_password@127.0.0.1:3306/db_name?charset=utf8mb4');

// 2. Option array (the exact PdoDriver::connect() contract)
$orm = OrmFactory::connect([
    'driver'   => 'mysql',
    'host'     => '127.0.0.1',
    'dbname'   => 'shop',
    'charset'  => 'utf8mb4',
    'user'     => 'app',
    'password' => 'secret',
]);

// 3. PDO DSN string
$orm = OrmFactory::connect('pgsql:host=127.0.0.1;dbname=shop');

// SQLite: file URI, or the bare DSN for in-memory
$orm = OrmFactory::connect('sqlite:///var/data/app.db');   // sqlite:///C:/data/app.db on Windows
$orm = OrmFactory::connect('sqlite::memory:');
```

Configuration notes:

- **URI** - percent-encoded credentials are decoded (`p%40ss` → `p@ss`); recognised query parameters (`charset`, `unix_socket`) are forwarded, unknown ones (e.g. `serverVersion`) are silently ignored for compatibility with configs written for other stacks.
- **DSN** - a bare PDO DSN cannot carry credentials; when a user/password is needed, use the URI form or the array form (`['dsn' => ..., 'user' => ..., 'password' => ...]`).
- **Security** - when a URI cannot be parsed, the exception deliberately never echoes the configuration string, so credentials can't leak into logs. Keep connection strings in environment variables, not in code.

### Multiple databases

The factory holds **named** configurations and lazily builds one manager per name, cached for its lifetime. Each named manager owns its private connection, Unit of Work and Identity Map - two databases never share tracking state, and transactions are per connection.

```php
$factory = new OrmFactory([
    'default'   => 'mysql://app:secret@127.0.0.1:3306/shop?charset=utf8mb4',
    'analytics' => 'postgres://reporting:secret@10.0.0.7:5432/warehouse',
    'cache'     => 'sqlite:///var/data/cache.db',
]);

$shop      = $factory->manager();              // "default"
$warehouse = $factory->manager('analytics');
$raw       = $factory->connection('cache');    // the shared ConnectionInterface, if you need it

$shop->persist($order);
$shop->flush();                                // touches the shop database only
```

An unknown name raises a `ConnectionException` listing the configured names. With `EntityTrait`/`OrmRegistry`, bind the manager that owns the entity's database (`OrmRegistry::set($factory->manager('analytics'))`) - the registry holds one manager per execution context.

### Low-level wiring

The factory is sugar, not a cage: `PdoDriver::connect()` (which consumes the same option array) and `DbManager::create()` remain the explicit composition path, e.g. to inject a custom hydrator or share a hand-built connection. `OrmFactory::normalise($config)` exposes the option array a URI or DSN resolves to, without opening a connection.

```php
use G\Orm\Core\DbManager;
use G\Orm\Driver\Pdo\PdoDriver;

$connection = (new PdoDriver())->connect(OrmFactory::normalise('mysql://app:secret@127.0.0.1/shop'));
$orm = DbManager::create($connection);
```

PDO is configured for real prepared statements (`ATTR_EMULATE_PREPARES => false`), exception error mode, and associative fetch. Pass integer-keyed PDO attributes under the `options` key of the array form to override. Every binding - bulk or individual - travels with its inferred PDO type (`int`, `bool`, `null`, `string`), never as a stringly-typed blob. The SQL dialect ([see Platforms](#sql-dialects-platform-layer)) is resolved from the PDO driver name at connection time.

## Map an entity

Attributes only. `name`/`type` are inferred from the property when omitted; backed enums and `DateTimeImmutable` map automatically ([see Value converters](#value-converters-rich-types)).

```php
use DateTimeImmutable;
use G\Orm\Mapping\Table;
use G\Orm\Mapping\Id;
use G\Orm\Mapping\Column;
use G\Orm\Mapping\BelongsTo;

#[Table('users')]
final class User
{
    #[Id(generated: true)]          // DB-assigned (auto-increment / identity)
    #[Column]
    public int $id;

    #[Column]
    public string $name;

    #[Column(name: 'email', unique: true, length: 255)]
    public string $email;

    #[Column(nullable: true)]
    public ?string $bio = null;

    #[Column(name: 'created_at')]
    public DateTimeImmutable $createdAt;   // stored as "Y-m-d H:i:s"

    // Relations are descriptive metadata only - they never auto-load.
    #[BelongsTo(targetEntity: Address::class, foreignKey: 'address_id')]
    public ?Address $address = null;
}
```

Relation attributes: `#[BelongsTo(targetEntity, foreignKey)]` (owning side), `#[HasOne(targetEntity, mappedBy)]` and `#[HasMany(targetEntity, mappedBy)]` (inverse side). Composite keys: apply `#[Id]` to each key property. Rejected at metadata build time: a composite key with a `generated` member, and a relation pointing at a composite-key target.

---

# The four ways to use it

The manager exposes four usage levels - pick your altitude per call site, mix freely. They all share the same connection, transaction, and (for the upper two) the same Unit of Work.

| Level | Surface | Returns |
|---|---|---|
| 1. Raw SQL | `$orm->query($sql, $bindings)` | rows or affected count |
| 2. Query builder | `$orm->query()` | rows, count, or tracked entities |
| 3. Repositories | `$orm->repository(User::class)` | tracked entities |
| 4. Unit of Work / Active record | `persist` / `flush` / `EntityTrait` | - |

## 1. Raw SQL

`query()` with a SQL string executes immediately. The driver - not a keyword heuristic - decides what comes back: statements that produce a result set return rows, anything else returns the affected-row count. Leading comments, parenthesised SELECTs and data-modifying CTEs are all classified correctly.

```php
$rows  = $orm->query('SELECT * FROM users WHERE email = ?', ['ada@example.com']); // list<array>
$count = $orm->query('DELETE FROM users WHERE id = ?', [7]);                      // int
$rows  = $orm->query('WITH vip AS (SELECT ...) SELECT * FROM vip');               // list<array>
```

## 2. Query builder

`$orm->query()` (no argument) returns a fluent, mapping-aware builder.

```php
$rows = $orm->query()
    ->select('id', 'name')
    ->from('users')
    ->where('age', '>=', 18)
    ->andWhere('status', '=', 'active')
    ->orderBy('name', 'ASC')
    ->limit(20)
    ->offset(40)
    ->get();                 // list<array<string,mixed>>
```

Inline operators (`where('col', 'OP', $value)`) or the expressive clause (column-only `where()` returns a clause object):

```php
$rows = $orm->query()
    ->from('users')
    ->where('status')->eq('active')
    ->andWhere('role')->in(['admin', 'editor'])
    ->orWhere('email')->like('%@example.com')
    ->where('login')->regexp('^[a-z0-9_]+$')
    ->where('deleted_at')->isNull()
    ->get();
```

Clause operators: `eq · neq · gt · gte · lt · lte · like · notLike · regexp · notRegexp · in · notIn · between · isNull · isNotNull`.

### Validated by default, raw by intent

The standard surfaces are **hard-validated**: operators against a closed whitelist, sort directions against `ASC|DESC`, and every column reaching a non-parameterisable clause (WHERE/HAVING column, ORDER BY, GROUP BY) against a strict identifier pattern (`col` or `alias.col`, nothing else). A user-supplied sort field or operator can therefore be passed straight through - an injection payload raises a `QueryException`, it never reaches the SQL.

```php
$orm->query()->from('users')->orderBy($_GET['sort'] ?? 'id', $_GET['dir'] ?? 'ASC'); // safe
```

When you need native SQL power (functions, JSON paths, aggregates), reach for the explicit **raw escape hatches**. They emit the expression verbatim - and PHPStan's `literal-string` constraint rejects any expression assembled from runtime input at the call site, so values must travel through `?` bindings:

```php
$orm->query()
    ->from('users')
    ->whereRaw('LOWER(email) = ?', ['ada@example.com'])
    ->whereRaw("JSON_EXTRACT(meta, '$.tier') = ?", ['gold'])
    ->groupByRaw('YEAR(created_at)')
    ->havingRaw('COUNT(*) > ?', [5])
    ->orderByRaw('LOWER(name) DESC')
    ->get();
```

> Identifiers are emitted unquoted (SQL-first transparency). A column named after a reserved word (`order`, `group`) should be mapped to a non-reserved name via `#[Column(name:)]` or reached through a raw surface.

### Joins

**Entity-style joins** resolve the table and the `ON` condition from relation metadata; **manual joins** require an explicit condition:

```php
// Entity join - ON clause derived from the BelongsTo relation
$rows = $orm->query()
    ->from(User::class, 'u')
    ->leftJoin(Address::class, 'a')
    ->where('u.id', '=', 1)
    ->get();

// Manual join - explicit ON condition is mandatory
$rows = $orm->query()
    ->from('orders')
    ->join('customers', 'orders.customer_id = customers.id')
    ->get();
```

### Eager hydration: `getEntities()`

The SELECT-driven eager terminal. No `with()`, no proxies: which relations are hydrated is decided strictly by the columns present in the result set. Every hydrated entity - root and joined alike - is **registered with the Unit of Work**, so it is change-tracked exactly like a repository-loaded one.

```php
$users = $orm->query()
    ->from(User::class, 'u')
    ->leftJoin(Address::class, 'a')
    ->getEntities();             // list<User>, $user->address populated

$users[0]->name = 'Ada Lovelace';
$orm->flush();                   // UPDATE users SET name = ? WHERE id = ?
```

An empty/`*` select is auto-expanded to alias-prefixed entity columns; an explicit root-only select (`u.*`) returns the roots alone and leaves relations untouched - never lazily fetched.

### Streaming large result sets: `cursor()`

`get()` materialises the whole result set; for exports and batch jobs that don't fit comfortably in memory, `cursor()` is the streaming counterpart - same compiled query, but rows are *yielded* one at a time instead of collected into an array. It keeps the builder's compilation (WHERE, JOIN, bindings) instead of dropping to hand-written SQL on the raw driver.

```php
foreach ($orm->query()->from('orders')->where('archived', '=', 0)->cursor() as $row) {
    fputcsv($out, $row);     // one row in memory at a time
}
```

> **Memory note:** fetching row by row only lowers the footprint when the driver isn't buffering the whole set client-side. SQLite streams natively; **MySQL buffers by default** - enable real streaming by passing the PDO attribute through the connection `options`, after which no other query may run on that connection until the cursor is drained:
> ```php
> $orm = OrmFactory::connect([
>     'driver' => 'mysql', 'host' => '127.0.0.1', 'dbname' => 'shop', 'user' => 'app',
>     'options' => [\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false],
> ]);
> ```
> `cursor()` yields plain rows (read-only). It is deliberately not an entity terminal: streaming hydration would either retain every entity in the Identity Map - defeating the purpose - or yield untracked entities that silently skip change detection. Need typed objects in a stream? Hydrate from the yielded rows yourself, or page through `getEntities()` with `limit()`/`offset()`.

### Writes through the builder

```php
$orm->query()->insert('users')->values(['name' => 'Grace', 'email' => 'g@x.io'])->execute();
$orm->query()->update('users')->set('name', 'Grace H.')->where('id', '=', 7)->execute();
$orm->query()->from('users')->delete()->where('id', '=', 7)->execute();
```

Inspect compiled output without executing: `->toSql()`, `->getBindings()`, or `->debug()`.

## 3. Repositories

`repository()` returns an entity-typed repository (cached per class). Primary-key lookups consult the Identity Map before touching the database, so a row is represented by a single instance for the lifetime of the Unit of Work.

```php
$users = $orm->repository(User::class);

$user = $users->find(1);                                  // by primary key
$role = $roles->find(['user_id' => 5, 'role_id' => 12]);  // composite key
$all  = $users->findAll();
$set  = $users->findBy(['email' => 'ada@example.com']);   // null → IS NULL, array → IN
$one  = $users->findOneBy(['name' => 'Ada']);

$users->save($user);     // persist + flush
$users->remove($user);   // remove + flush

$users->query()          // entity-scoped builder (getEntities() ready)
    ->where('status', '=', 'active')
    ->getEntities();
```

### Custom repositories

Bind a repository to an entity with `#[Repository]`; extend the base `Repository` so it auto-wires through the manager.

```php
use G\Orm\Mapping\Table;
use G\Orm\Mapping\Repository;          // mapping attribute
use G\Orm\Repository\Repository as BaseRepository;

#[Table('users')]
#[Repository(UserRepository::class)]
final class User { /* ... */ }

/** @extends BaseRepository<User> */
final class UserRepository extends BaseRepository
{
    /** @return list<User> */
    public function activeAdmins(): array
    {
        return $this->query()
            ->where('status', '=', 'active')
            ->andWhere('role', '=', 'admin')
            ->getEntities();
    }
}

$admins = $orm->repository(User::class)->activeAdmins();
```

## 4. Unit of Work

Mutations are staged and applied on `flush()`. Updates are derived from a snapshot diff - only changed columns are written. A generated identifier is back-filled onto the entity after insert.

```php
$user = new User();
$user->name  = 'Ada';
$user->email = 'ada@example.com';

$orm->persist($user);
$orm->flush();              // INSERT - $user->id is now populated

$user->name = 'Ada Lovelace';
$orm->flush();              // UPDATE - snapshot diff, name column only

$orm->remove($user);
$orm->flush();              // DELETE
```

### Write-integrity guards

The flush pipeline fails loudly instead of drifting silently:

- **Re-entrant flush** - a lifecycle hook or listener calling `flush()` from inside a running flush raises `OrmException` instead of double-writing or recursing. Staging *intentions* from a hook (`persist()` without flush) is supported: they survive the running flush and are written by the next one.
- **Incomplete primary key** - an UPDATE/DELETE whose entity has no fully-initialised key is rejected before any hook fires (a `pk = NULL` predicate would silently match nothing).
- **Lost update detection** - an UPDATE/DELETE that affects zero rows (the row vanished under a concurrent transaction) aborts the flush, rolls back, and purges the now-stale in-memory state.

### Active-record trait (optional)

`EntityTrait` adds convenience helpers while keeping entities pure POPOs - no base class, no injected state. It resolves the active manager through `OrmRegistry`, bound once per execution context.

```php
use G\Orm\Registry\OrmRegistry;
use G\Orm\Traits\EntityTrait;

OrmRegistry::set($orm);     // bootstrap: bind for this request/worker

#[Table('users')]
final class User
{
    use EntityTrait;
    // ... id / column / relation mapping ...
}

$user = new User();
$user->name = 'Ada';
$user->save();                                   // persist + flush via the active manager

$found = User::find(1);
$list  = User::query()->where('name', 'LIKE', 'A%')->get();
$repo  = User::repository();

OrmRegistry::clear();       // reset at the request / coroutine boundary
```

For concurrent runtimes (Swoole/RoadRunner/FrankenPHP), install a context-aware resolver once at worker start via `OrmRegistry::useResolver(...)`; entity code stays unchanged. Relation accessors stay explicit - write them by hand over the protected `orm()` helper rather than hiding queries behind property access.

---

## Transactions

Closure form commits on return and rolls back on any throwable. A rollback also purges in-memory state (Unit of Work + Identity Map) so the next operation re-fetches clean rows. Calls **nest**: only the outermost `transaction()` opens and resolves the physical transaction; inner calls participate, and an inner failure rolls back the whole unit.

```php
use G\Orm\Contract\DbManagerInterface;

$orm->transaction(function (DbManagerInterface $orm) use ($a, $b): void {
    $orm->persist($a);
    $orm->flush();

    $orm->transaction(function (DbManagerInterface $orm) use ($b): void {
        $orm->persist($b);
        $orm->flush();          // same physical transaction
    });
});
```

Manual control is also available: `beginTransaction()`, `commit()`, `rollBack()`. `flush()` participates silently in any transaction you own and only manages its own when none is active.

## Lifecycle hooks & PSR-14 events

In-entity attribute hooks run on every flush, regardless of any dispatcher:

```php
use DateTimeImmutable;
use G\Orm\Attribute\Event\BeforeInsert;
use G\Orm\Attribute\Event\BeforeUpdate;
use G\Orm\Event\Changeset;

#[Table('users')]
final class User
{
    #[Column(name: 'created_at')]
    public DateTimeImmutable $createdAt;

    #[BeforeInsert]
    public function stampCreatedAt(): void
    {
        $this->createdAt = new DateTimeImmutable();
    }

    #[BeforeUpdate]
    public function audit(Changeset $changes): void
    {
        // react to what the caller actually changed; your own mutations
        // here are re-diffed and joined to the same UPDATE
    }
}
```

Available hook attributes: `BeforeInsert · AfterInsert · BeforeUpdate · AfterUpdate · BeforeDelete · AfterDelete`.

For external listeners, supply any PSR-14 dispatcher; matching event objects (`BeforeInsertEvent`, `AfterUpdateEvent`, …) are dispatched per entity, each exposing `getEntity()`:

```php
$orm = DbManager::create($connection, $psr14Dispatcher);
```

## SQL dialects (Platform layer)

Dialect-sensitive SQL is owned by a `PlatformInterface`, resolved once from `PDO::ATTR_DRIVER_NAME` at connection time and injected into the compiler by `DbManager::create()`. The same builder chain produces each engine's exact syntax:

| Engine | Identifier quoting | `REGEXP` / `NOT REGEXP` | `limit(10)->offset(20)` |
|---|---|---|---|
| MySQL / MariaDB | `` `col` `` | `col REGEXP ?` / `col NOT REGEXP ?` | `LIMIT 10 OFFSET 20` |
| PostgreSQL | `"col"` | `col ~* ?` / `col !~* ?` | `LIMIT 10 OFFSET 20` |
| SQLite *(default/fallback)* | `"col"` | `col REGEXP ?` (requires a registered `regexp()` function) | `LIMIT 10 OFFSET 20` |
| SQL Server | `[col]` | **rejected at compile time** | `OFFSET 20 ROWS FETCH NEXT 10 ROWS ONLY` |

PostgreSQL's `~*` is selected deliberately over `~`: MySQL and SQLite match case-insensitively by default, so the insensitive variant is the only unified cross-platform behaviour (case-sensitive `~` stays reachable via `whereRaw()`). SQL Server throws a `QueryException` instead of emitting `REGEXP_LIKE()`, which only exists on SQL Server 2025+/Azure - fail fast at the call site rather than at runtime on a 2019/2022 server.

An `offset()` without a `limit()` works everywhere (MySQL and SQLite receive the "no limit" value they require). On SQL Server, a limited query without `orderBy()` gets a neutral `ORDER BY (SELECT NULL)`, since `OFFSET ... FETCH` only exists after an `ORDER BY`; add a real `orderBy()` for a stable pagination. Negative limits and offsets throw a `QueryException`.

## Value converters (rich types)

Rich property types cross the database boundary through `ValueConverterInterface` (`toDatabase()` / `fromDatabase()`), resolved by the property's declared or inferred type. Conversion happens at extraction as well as hydration, so **snapshots, change diffs and bindings all operate on the scalar form**: an equal-but-distinct object instance is never a phantom change, and no reference type is shared between an entity and its snapshot.

`DateTimeImmutable` ships built-in (stored as `Y-m-d H:i:s`; a malformed database string raises a typed `OrmException`). Register your own when wiring manually:

```php
use G\Orm\Hydrator\Hydrator;

$hydrator = new Hydrator($metadataReader, [
    Money::class => new MoneyConverter(),   // keyed by property type
]);
```

## Quality gates

```bash
vendor/bin/phpunit          # full suite, in-memory SQLite
vendor/bin/phpstan analyse  # level max
vendor/bin/phpcs            # PSR-12
vendor/bin/phpmnd src       # zero magic numbers
```
