# gadwinn/orm

A lightweight, **SQL-first**, framework-agnostic ORM for PHP 8.3+.

No lazy loading, no proxies, no magic property access. Entities are plain PHP objects (POPOs) mapped with native attributes; persistence flows through a Unit of Work with an Identity Map and snapshot-based change tracking. What you map is what you get, and what you query is what loads.

## Tech Stack

| Concern | Choice |
|---|---|
| Language | PHP **>= 8.3** (typed properties, enums, attributes) |
| Persistence | PDO (`ext-pdo`) — MySQL/MariaDB, PostgreSQL, SQLite |
| Events | PSR-14 (`psr/event-dispatcher`) — optional external dispatch |
| Mapping | Native PHP attributes only (no XML/YAML/annotations) |
| Quality bar | PHPStan level max · PSR-12 · zero magic numbers |

Architecture: Unit of Work · Identity Map · Repository · Hydrator · attribute-driven MetadataReader. Construction is concrete and explicit; every collaborator sits behind an interface for substitution.

## Installation

```bash
composer require gadwinn/orm
```

`ext-pdo` is a hard requirement. Install the concrete PDO driver for your database (`ext-pdo_mysql`, `ext-pdo_sqlite`, …) — these are advertised via `suggest`.

## Usage

### 1. Bootstrap a manager

`PdoDriver::connect()` accepts either a ready-made `dsn` or discrete parts. `DbManager::create()` wires the default engine-neutral stack around the open connection.

```php
use G\Orm\Core\DbManager;
use G\Orm\Driver\Pdo\PdoDriver;

// MySQL / MariaDB (driver defaults to "mysql" when omitted)
$connection = (new PdoDriver())->connect([
    'driver'   => 'mysql',
    'host'     => '127.0.0.1',
    'dbname'   => 'shop',
    'charset'  => 'utf8mb4',
    'user'     => 'app',
    'password' => 'secret',
]);

// SQLite (file path, or omit "path" for :memory:)
$connection = (new PdoDriver())->connect([
    'driver' => 'sqlite',
    'path'   => __DIR__ . '/var/data.sqlite',
]);

// Or pass a complete DSN — it takes precedence over the parts above
$connection = (new PdoDriver())->connect([
    'dsn'  => 'pgsql:host=127.0.0.1;dbname=shop',
    'user' => 'app',
]);

$orm = DbManager::create($connection);
```

PDO is configured for real prepared statements (`ATTR_EMULATE_PREPARES => false`), exception error mode, and associative fetch. Pass integer-keyed PDO attributes under the `options` key to override.

### 2. Map an entity

Attributes only. `name`/`type` are inferred from the property when omitted; native enums (incl. backed enums) map automatically.

```php
use G\Orm\Mapping\Table;
use G\Orm\Mapping\Id;
use G\Orm\Mapping\Column;
use G\Orm\Mapping\BelongsTo;

#[Table('users')]
final class User
{
    #[Id(generated: true)]          // DB-assigned (auto-increment / identity / sequence)
    #[Column]
    public int $id;

    #[Column]
    public string $name;

    #[Column(name: 'email', unique: true, length: 255)]
    public string $email;

    #[Column(nullable: true)]
    public ?string $bio = null;

    // Relations are descriptive metadata only — they never auto-load.
    #[BelongsTo(targetEntity: Address::class, foreignKey: 'address_id')]
    public ?Address $address = null;
}
```

Relation attributes: `#[BelongsTo(targetEntity, foreignKey)]` (owning side), `#[HasOne(targetEntity, mappedBy)]` and `#[HasMany(targetEntity, mappedBy)]` (inverse side). Composite keys: apply `#[Id]` to each key property (a composite key with a `generated` member is rejected at metadata build time).

### 3. Persist, update, delete (Unit of Work)

Mutations are staged and applied on `flush()`. Updates are derived from a snapshot diff — only changed columns are written. A generated identifier is back-filled onto the entity after insert.

```php
$user = new User();
$user->name  = 'Ada';
$user->email = 'ada@example.com';

$orm->persist($user);
$orm->flush();              // INSERT — $user->id is now populated

$user->name = 'Ada Lovelace';
$orm->flush();              // UPDATE — snapshot diff, name column only

$orm->remove($user);
$orm->flush();              // DELETE
```

### 4. Repositories

`repository()` returns an entity-typed repository (cached per class). Primary-key lookups consult the Identity Map before touching the database.

```php
$users = $orm->repository(User::class);

$user = $users->find(1);                              // by primary key (int|string|array)
$all  = $users->findAll();
$set  = $users->findBy(['email' => 'ada@example.com']);   // null → IS NULL, array → IN
$one  = $users->findOneBy(['name' => 'Ada']);

$users->save($user);     // persist + flush
$users->remove($user);   // remove + flush
```

### 5. Query builder

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
    ->where('deleted_at')->isNull()
    ->get();
```

Clause operators: `eq · neq · gt · gte · lt · lte · like · in · notIn · between · isNull · isNotNull`.

**Entity-style joins** resolve the table and the `ON` condition from relation metadata; **manual joins** require an explicit condition:

```php
// Entity join — ON clause derived from the BelongsTo relation
$rows = $orm->query()
    ->from(User::class, 'u')
    ->leftJoin(Address::class, 'a')
    ->where('u.id', '=', 1)
    ->get();

// Manual join — explicit ON condition is mandatory
$rows = $orm->query()
    ->from('orders')
    ->join('customers', 'orders.customer_id = customers.id')
    ->get();
```

Writes through the builder:

```php
$orm->query()->insert('users')->values(['name' => 'Grace', 'email' => 'g@x.io'])->execute();
$orm->query()->update('users')->set('name', 'Grace H.')->where('id', '=', 7)->execute();
$orm->query()->from('users')->delete()->where('id', '=', 7)->execute();
```

Inspect compiled output without executing: `->toSql()`, `->getBindings()`, or `->debug()` (returns `['sql' => ..., 'bindings' => ...]`).

> **Eager hydration:** a builder constructed with the full stack (metadata reader + hydrator + identity map) exposes `getEntities()`, which ventilates each flat row into hydrated root entities and injects every *selected* joined entity into its relation property. Relations whose columns are absent from the `SELECT` are left untouched — never lazily fetched.

### 6. Raw SQL

`query()` with a SQL string executes immediately: row-returning statements return an array, write statements return the affected-row count.

```php
$rows  = $orm->query('SELECT * FROM users WHERE email = ?', ['ada@example.com']); // array
$count = $orm->query('DELETE FROM users WHERE id = ?', [7]);                       // int
```

### 7. Transactions

Closure form commits on return and rolls back on any throwable. A rollback also purges in-memory state (Unit of Work + Identity Map) so the next operation re-fetches clean rows.

```php
use G\Orm\Contract\DbManagerInterface;

$orm->transaction(function (DbManagerInterface $orm) use ($a, $b): void {
    $orm->persist($a);
    $orm->persist($b);
    $orm->flush();
});
```

Manual control is also available: `beginTransaction()`, `commit()`, `rollBack()`.

### 8. Lifecycle hooks & PSR-14 events

In-entity attribute hooks run on every flush, regardless of any dispatcher:

```php
use G\Orm\Attribute\Event\BeforeInsert;

#[Table('users')]
final class User
{
    #[Column(name: 'created_at')]
    public string $createdAt;

    #[BeforeInsert]
    public function stampCreatedAt(): void
    {
        $this->createdAt = date('Y-m-d H:i:s');
    }
}
```

Available hook attributes: `BeforeInsert · AfterInsert · BeforeUpdate · AfterUpdate · BeforeDelete · AfterDelete`.

For external listeners, supply any PSR-14 dispatcher; matching event objects (`BeforeInsertEvent`, `AfterUpdateEvent`, …) are dispatched per entity, each exposing `getEntity()`:

```php
$orm = DbManager::create($connection, $psr14Dispatcher);
```

### 9. Active-record trait (optional)

`EntityTrait` adds convenience helpers while keeping entities pure POPOs — there is no base class and no injected state. It resolves the active manager through `OrmRegistry`, which you bind once per execution context.

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

For concurrent runtimes (Swoole/RoadRunner/FrankenPHP), install a context-aware resolver once at worker start via `OrmRegistry::useResolver(...)`; entity code stays unchanged. Relation accessors stay explicit — write them by hand over the protected `orm()` helper rather than hiding queries behind property access.

### 10. Custom repositories

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
    /** @return list<array<string,mixed>> */
    public function activeAdmins(): array
    {
        return $this->query()
            ->where('status', '=', 'active')
            ->andWhere('role', '=', 'admin')
            ->get();
    }
}

$admins = $orm->repository(User::class)->activeAdmins();
```
