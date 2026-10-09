# migears/dao

![Version](https://img.shields.io/badge/version-2.3.0-blue)

Minimalist DAO layer — connects SQL and Domain with zero abstraction.

> **Background**: miGears is the open-source successor of **TinyGears**, a
> self-developed PHP framework. It was renamed and open-sourced recently because
> the name *TinyGears* is already taken in the open-source community.

## Philosophy

- **Generic single-table CRUD, delivered as a trait** — `SingleTableDao` *is* generic CRUD, but a trait rather than a base class, so it imposes no inheritance lock-in
- **Business and multi-table SQL is written directly** — no query-builder DSL and no auto-generated SQL; anything beyond the generic single-table operations is plain SQL inside the DAO method
- **Direct hydration via `Domain::fromArray()`** — no intermediate hydrator or mapper
- **`toArray()` for persistence** — Domain → array → SQL
- **One DAO per table** — simple and predictable

### DAO design principle

The API is shaped around one convention:

- **Writes take a Domain** — `save(object $domain): object` is the canonical
  write entry: a Domain carrying a primary key is updated, otherwise it is
  inserted and the persisted Domain is returned.
- **The targeted write is `patch(Domain, columns)`** — `save()` writes the whole
  row, while `patch()` sends only the named columns, so an unnamed column is
  never clobbered (a concurrent change to it survives) and a wide row's payload
  stays small.
- **Reads/queries take scalars** — `getById(int|string $id)`, `getByIds` and
  `getAll` are located by scalar values, never by a Domain.
- **Entity queries return a Domain** — a DAO's entity reads (`getById` /
  `getByIdOrFail` / `getByIds` / `getAll`) return hydrated `Domain` objects.
  Pagination is a separate, opt-in strategy: `OffsetPagination::paginate()`
  and `CursorPagination::paginateAfter()` wrap a page of Domains in the
  matching `migears/utils` DTO. Aggregates (`count`) and projections issued
  through `sql()` are the deliberate exception: they are not
  entities, so they return scalars / raw rows.
- **The Manager is the boundary** — only the Manager layer holds both a Domain
  and a DAO and wires them together; the layers above it (resources /
  controllers) see Domains, never DAOs. The DAO is where Domain and row meet.

The package is therefore Domain-oriented rather than row-oriented. It stops
short of a full Repository: there is no identity map and no collection
semantics — a DAO reads and writes one table, and cross-table work is the
application's job.

## Boundaries

**In scope**

- Single-table CRUD, delivered as a trait rather than a base class: entity reads (`getById` / `getByIdOrFail` / `getByIds` / `getAll`) returning hydrated Domains, plus `count` / `insert` / `update` / `patch` / `delete`, with a configurable `$idColumn` for a non-`id` primary key.
- Two opt-in pagination traits layered on the CRUD trait: `OffsetPagination` (`paginate()` → `MiGears\Utils\Paginator`, one `COUNT(*)` per page for an exact total) and `CursorPagination` (`paginateAfter()` → `MiGears\Utils\FlowPaginator`, one extra row per page instead of a count).
- Abstract `table()` / `idColumn()` / `domainClass()` hooks plus `hydrate()` and `save(Domain)`, which turn rows into Domain objects and persist Domains back; `CachedDao` builds on the same contract.
- All data-access logic for one table, including complex joins and multi-table queries issued through the underlying `SqlBuilder` (`sql()`); those projections come back as raw rows.
- The cacheable variant `CachedDao`: caching the primary-key reads (`getById` / `getByIds`), invalidating the primary-key cache automatically on writes, and exposing the `deleteCacheFor()` hook for extra keys. (`getAll`, `count` and `getByIdOrFail` are inherited unchanged; pagination is not part of the cache layer — compose a pagination trait separately.)
- Handing raw PDO rows straight to `Domain::fromArray()`, adding no casting of its own.

**Not in scope (by design)**

- Business logic, orchestration and the Service layer above the DAO — owned by `migears/manager`.
- Building/executing SQL and talking to PDO — delegated to `migears/sql` (its `SqlBuilder`, `SqlException`, `RecordNotFoundException`).
- Defining Domain objects and normalizing/casting column values — owned by `migears/domain`; the DAO deliberately performs no `intval()` or column-type mapping.
- Cache backend implementations and storage (array / Redis) — owned by `migears/cache`; the DAO only depends on `CacheInterface`.
- Pagination data structures (`Paginator` / `FlowPaginator`) — owned by `migears/utils`; the pagination traits only wrap a page's Domains in one.
- Repository / identity-map semantics (managed entities, cross-table collection queries) — this is a single-table, Domain-oriented gateway, not a Repository; cross-table work belongs to the application.

## Installation

```bash
composer require migears/dao
```

Requires: PHP 8.1+, ext-pdo, `psr/log`, `migears/sql`, `migears/domain`, `migears/cache`, `migears/utils`.

## Constructor Contract

Every DAO constructor is explicit and follows a fixed argument order:

| Argument | Type | Required | Meaning |
|----------|------|----------|---------|
| `$pdo` | `PDO` | always | database connection |
| `$logger` | `Psr\Log\LoggerInterface` | always | logger — inject it explicitly, never rely on a silent default |
| `$cache` | `MiGears\Cache\CacheInterface` | only when cacheable | cache layer, only for DAOs using `CachedDao` |

A DAO constructor takes **at least two arguments** (`pdo`, `logger`). If the DAO is
cacheable (uses `CachedDao`), add the third `cache` argument:

```php
// Plain DAO — pdo + logger
class PlainUserDao
{
    use SingleTableDao;

    protected function table(): string { return 'users'; }

    protected function idColumn(): string { return 'id'; }

    protected function domainClass(): string { return UserDomain::class; }

    public function __construct(PDO $pdo, LoggerInterface $logger)
    {
        $this->initDao($pdo, $logger);
    }
}

// Cacheable DAO — pdo + logger + cache
class CachedUserDao
{
    use CachedDao;

    protected function table(): string { return 'users'; }

    protected function idColumn(): string { return 'id'; }

    protected function domainClass(): string { return UserDomain::class; }

    public function __construct(PDO $pdo, LoggerInterface $logger, CacheInterface $cache)
    {
        $this->initDao($pdo, $logger);
        $this->initCachedDao($cache, $logger);
    }
}
```

`$logger` is **never** defaulted inside a DAO — it is always injected by the caller.
This keeps every DAO's construction explicit, testable, and uniform across the codebase.

`table()`, `idColumn()` and `domainClass()` are declared abstract by the trait,
so a DAO that forgets any of them fails to compile rather than misbehaving at
run time; there is no separate "define the properties" step.

## Quick Start

### Define a DAO

```php
use MiGears\Dao\SingleTableDao;
use MiGears\Domain\DataAccess;

class UserDomain
{
    use DataAccess;

    public function __construct(
        public readonly int $id,
        public readonly string $user_name,
        public readonly string $email,
    ) {}
}

class UserDao
{
    use SingleTableDao;

    protected function table(): string { return 'users'; }

    protected function idColumn(): string { return 'id'; }

    protected function domainClass(): string { return UserDomain::class; }

    public function __construct(PDO $pdo, LoggerInterface $logger)
    {
        $this->initDao($pdo, $logger);
    }
}
```

### CRUD Operations

```php
$dao = new UserDao($pdo, $logger);

// Read
$user  = $dao->getById(1);                    // UserDomain|null
$user  = $dao->getByIdOrFail(1);              // UserDomain (throws if not found)
$users = $dao->getByIds([1, 2, 3]);           // [id => UserDomain]
$all   = $dao->getAll();                       // UserDomain[] (id DESC)
$count = $dao->count();                        // int

// Write
$id = $dao->insert(['user_name' => 'Alice', 'email' => 'a@b.com']);  // last insert id (string)
$n = $dao->update(['id' => 1, 'user_name' => 'Bob']);                  // affected rows
$n = $dao->delete(1);                                                  // affected rows
```

### Pagination

Pagination is opt-in: compose `OffsetPagination` or `CursorPagination` on top
of `SingleTableDao`, whichever matches the query strategy.

**Offset** — page numbers with an exact total (`MiGears\Utils\Paginator`):

```php
class UserDao
{
    use SingleTableDao;
    use OffsetPagination;

    protected function table(): string { return 'users'; }
    protected function idColumn(): string { return 'id'; }
    protected function domainClass(): string { return UserDomain::class; }
}

$page = $dao->paginate(2, 20);   // runs one COUNT(*) for the total

$page->items();       // UserDomain[] on the current page
$page->total();       // int — rows across all pages
$page->totalPages();  // int
$page->hasNext();     // bool
```

**Cursor** — keyset / "load more" without a total (`MiGears\Utils\FlowPaginator`):

```php
class UserDao
{
    use SingleTableDao;
    use CursorPagination;

    protected function table(): string { return 'users'; }
    protected function idColumn(): string { return 'id'; }
    protected function domainClass(): string { return UserDomain::class; }
}

$page = $dao->paginateAfter(null, 20);                 // first page
$page->items();                                        // UserDomain[]
$page->hasMore();                                      // bool
$page = $dao->paginateAfter($page->nextCursor(), 20);  // next page
```

`CursorPagination` reads one extra row to decide `hasMore` instead of running a
`COUNT(*)`, and orders by the primary key descending; the cursor is that key, so
the Domain must expose it publicly.

### With Domain Objects

```php
// Entity reads already return a Domain — no manual fromArray() step
$user = $dao->getByIdOrFail(1);
echo $user->user_name;  // "Alice"

// save() takes a Domain and returns the persisted Domain (insert or update)
$user = $dao->save($userDomain);

// patch() writes only the named columns of a Domain that already holds the new
// values; every other column — including one changed concurrently — is untouched
$dao->patch($userDomain, ['user_name']);
```

Pick the write that matches the intent — `save()` first, and reach for the
lower-level methods only when they earn it:

- **`save(Domain)` — the default write.** You hold a complete Domain and mean to
  persist the whole row (insert when it has no key, full update otherwise). Start
  here.
- **`patch(Domain, columns)` — for a targeted write.** It sends only the named
  columns, so reach for it when only a few columns change, when another column may
  have changed since you read the Domain (patch cannot clobber it), or when the
  row is wide (large `TEXT`/`BLOB`) and re-sending every column is wasteful. It
  locates the row by the Domain's primary key, never inserts, and naming no column
  is an error.
- **`update(array)` — low-level; do not call it in ordinary code.** It takes a
  raw column map instead of a Domain, and is the primitive `save()` and `patch()`
  are built on.
- **`insert(array)` — low-level; avoid it, with one exception.** `save()` already
  inserts a Domain that carries no key, so `insert()` is needed only when the
  caller must assign the primary key itself — a UUID or a natural key. `save()`
  cannot cover that case: it reads *any* non-empty key as an existing row and
  would update nothing instead of inserting. That caller-assigned key is the
  exception which justifies `insert()`:

  ```php
  // save() sees a non-empty key and updates nothing; insert() is the one path
  // that persists a caller-assigned key.
  $dao->insert(['uuid' => $uuid, 'user_name' => 'Alice']);
  ```

### Patching a few columns

`patch()` reads the values straight off the Domain it is handed — it never
rebuilds or copies it. Pass a Domain that already carries the values you want
written, and name the columns to persist:

```php
// The Domain already holds the new values (built by whatever flow produced it).
$affected = $dao->patch($user, ['user_name', 'email']);

// UPDATE `users` SET `user_name` = :set_user_name, `email` = :set_email WHERE `id` = …
// Only the named columns reach the SET clause: age, status and created_at keep
// whatever the row holds — even if another writer changed them after your read.

$affected;  // 1 — rows changed (0 when the row no longer exists)
```

This is what separates `patch()` from `save()`: a Domain built from a request
often carries values for columns that are not yours to write, and `patch()` writes
only the columns you name. Naming no column throws, and a name that is not a
column of the Domain throws too.

### Using CachedDao

Both traits return **hydrated Domain objects**; `CachedDao` additionally caches
the primary-key lookups (`getById` / `getByIds`). `getAll()` and `count()` always
query the database — only the primary-key reads are cached. Pagination is not
part of the cache layer: compose `OffsetPagination` / `CursorPagination` on the
cacheable DAO if you need it. `CachedDao` depends only on `CacheInterface` and
the `SingleTableDao` contract — it references no `migears/sql` type, so all SQL
and its exceptions stay in `SingleTableDao`. A cache hit is returned only when
it really is a `Domain` of the DAO's class; a stale or foreign value is logged
as a warning, deleted, and re-read from the database.

```php
$user  = $cachedDao->getById(1);          // ?UserDomain (cached)
$user  = $cachedDao->getByIdOrFail(1);    // UserDomain (throws if not found)
$users = $cachedDao->getByIds([1, 2]);    // [id => UserDomain] (cached)
$user  = $cachedDao->save($userDomain);   // Domain in, persisted Domain out
$all   = $cachedDao->getAll();            // UserDomain[]
```

Write operations (`insert` / `update` / `delete`) automatically invalidate
the primary-key cache entry. To clear additional cache keys (e.g. lookups
by secondary indexes like `byEmail`), override the `deleteCacheFor()` hook:

```php
class UserDao
{
    use CachedDao;

    protected function table(): string { return 'users'; }

    protected function idColumn(): string { return 'id'; }

    protected function domainClass(): string { return UserDomain::class; }

    public function __construct(PDO $pdo, LoggerInterface $logger, CacheInterface $cache)
    {
        $this->initDao($pdo, $logger);
        $this->initCachedDao($cache, $logger);
    }

    protected function deleteCacheFor(?object $user): void
    {
        if ($user !== null) {
            $this->cache->delete('user_by_email_' . $user->email);
        }
    }
}
```

`deleteCacheFor()` receives the fresh Domain object (an uncached read
taken after the write) so the hook can inspect current field values.
It receives `null` when the record no longer exists (e.g. after a delete).

`insert()` preserves a caller-supplied primary key — pass `idColumn` in
the data array for UUID or application-generated keys. When omitted,
the auto-increment ID from the database is used.

## Custom Primary Key

```php
class PostDao
{
    use SingleTableDao;

    protected function table(): string { return 'posts'; }

    protected function idColumn(): string { return 'post_id'; }

    protected function domainClass(): string { return PostDomain::class; }

    public function __construct(PDO $pdo, LoggerInterface $logger)
    {
        $this->initDao($pdo, $logger);
    }
}
```

## Complex Queries

For queries beyond simple CRUD, use the underlying `SqlBuilder`:

```php
$rows = $dao->sql()
    ->select(['u.user_name', 'p.title'])
    ->from('users u')
    ->where('u.id = p.user_id', [])
    ->execute();
```

Complex joins and multi-table queries also belong in the DAO — all database access logic is encapsulated here. The Service layer should never touch the database directly.

## Type Contract (with Domain)

`SingleTableDao` / `CachedDao` hand raw rows straight to `Domain::fromArray()` —
`hydrate()` (defined in `SingleTableDao`, reused by `CachedDao`) calls
`fromArray($row)` on the class returned by `domainClass()`, with no hydration magic. The Domain layer performs **zero coercion**: PHP 8.x strict typed
named arguments bind each value to the declared constructor type, so a missing
key, an extra key, or a type mismatch throws a native `\Error` / `\TypeError`
(see migears/domain "Type Contract").

The DAO adds **no casting of its own** — no column-type map, no `intval()`.
Native types come from PDO itself: since PHP 8.1 it returns real `int` / `float`
for numeric columns, under both emulated and native prepares. So an `int` column
arrives as a genuine PHP `int` and a nullable column as `null`, with no
normalizing step in this layer. The SQL layer likewise only executes queries and
returns raw arrays.

See migears/domain "Type Contract" for the caveats (`DECIMAL` stays `string`,
`TINYINT(1)` is `int`, never enable `PDO::ATTR_STRINGIFY_FETCHES`) and why the
strict binding is deliberate rather than something to paper over with casts.

## Architecture

DAO is the glue layer of miGears' three-layer data architecture:

```
Service Layer (business logic)
    ↓ calls
DAO Layer (receives/returns Domain objects) → this package
    ↓ internally calls
SQL Layer (SQL + params → arrays) → migears/sql
    ↓
PDO / MySQL
```

- **SQL layer** knows nothing about Domain
- **Domain layer** knows nothing about SQL or DAO
- **DAO layer** knows both SQL and Domain — encapsulates ALL data access logic including complex joins, no business logic

## Why a Trait?

1. **No inheritance lock-in** — DAO classes can extend whatever they need
2. **Explicit constructor** — user controls PDO, logger (and cache for `CachedDao`) injection; no hidden defaults
3. **Readable** — all methods are visible in the class, no hidden base class methods

## License

MIT

---

# migears/dao

![Version](https://img.shields.io/badge/version-2.3.0-blue)

极简 DAO 层 — 连接 SQL 和 Domain，零抽象。

## 设计哲学

- **通用单表 CRUD，以 trait 提供** — `SingleTableDao` 本身就是通用 CRUD，只是以 trait 而非基类交付，因此不锁定继承关系
- **业务查询与多表 SQL 直接书写** — 没有查询构造 DSL，也没有自动生成的 SQL；超出通用单表操作的部分就是 DAO 方法里的手写 SQL
- **通过 `Domain::fromArray()` 直接 hydrate** — 不经过任何中间 hydrator 或 mapper
- **`toArray()` 用于持久化** — Domain → 数组 → SQL
- **一个 DAO 对应一张表** — 简单可预测

### DAO 设计原则

API 的形状只为一条约定服务：

- **写操作收 Domain** —— 规范写入口是 `save(object $domain): object`：Domain 带主键则更新，否则插入并返回落库后的 Domain。
- **定向写是 `patch(Domain, columns)`** —— `save()` 写整行，而 `patch()` 只发送指定的列，未点名的列绝不触碰（对它的并发修改得以保留），宽表载荷也更小。
- **读/查询收标量** —— `getById(int|string $id)`、`getByIds` 与 `getAll` 只用标量定位，绝不以 Domain 为入参。
- **实体查询返回 Domain** —— DAO 的实体读（`getById` / `getByIdOrFail` / `getByIds` / `getAll`）返回 hydrate 后的 `Domain` 对象。分页是独立的、可选的策略：`OffsetPagination::paginate()` 与 `CursorPagination::paginateAfter()` 把一页 Domain 装进对应的 `migears/utils` DTO。聚合（`count`）与经 `sql()` 发出的投影是刻意的例外：它们不是实体，返回标量 / 原始行。
- **Manager 是边界** —— 只有 Manager 层同时持有 Domain 与 DAO 并把两者接起来；其上的层（resource / controller）只见 Domain、不见 DAO。Domain 与「行」相遇之处是 DAO 自己。

因此本包是「面向 Domain」而非「面向行」的。它并未做成完整 Repository：没有 identity map，也没有集合语义——DAO 只读写一张表，跨表编排归应用。

## 边界

**范围内**

- 以 trait 而非基类提供单表 CRUD：实体读（`getById` / `getByIdOrFail` / `getByIds` / `getAll`）返回 hydrate 后的 Domain，另有 `count` / `insert` / `update` / `patch` / `delete`，并可用 `$idColumn` 配置非 `id` 主键。
- 两个可选的、叠加在 CRUD trait 之上的分页 trait：`OffsetPagination`（`paginate()` → `MiGears\Utils\Paginator`，每页一次 `COUNT(*)` 换精确总数）与 `CursorPagination`（`paginateAfter()` → `MiGears\Utils\FlowPaginator`，用多取一行代替计数）。
- 抽象钩子 `table()` / `idColumn()` / `domainClass()`，以及 `hydrate()`、`save(Domain)`：把行转成 Domain、把 Domain 落库；`CachedDao` 在同一契约之上构建。
- 封装单表的全部数据访问逻辑，包括通过底层 `SqlBuilder`（`sql()`）编写的复杂关联查询与多表查询；这些投影仍以原始行返回。
- cacheable 变体 `CachedDao`：为按主键读取（`getById` / `getByIds`）加缓存、写操作自动失效主键缓存，并提供 `deleteCacheFor()` 钩子清理额外键。（`getAll`、`count` 与 `getByIdOrFail` 直接继承，不做改动；分页不属于缓存层——需要时另行组合分页 trait。）
- 把 PDO 返回的原始行直接交给 `Domain::fromArray()`，自身不做任何 cast。

**范围外（刻意不做）**

- 业务逻辑、流程编排，以及 DAO 之上的 Service 层 —— 由 `migears/manager` 负责。
- 拼接/执行 SQL 以及与 PDO 打交道 —— 交给 `migears/sql`（`SqlBuilder`、`SqlException`、`RecordNotFoundException`）。
- 定义 Domain 对象、对列值做类型归一化/cast —— 由 `migears/domain` 负责；DAO 刻意不做 `intval()`，也不维护列类型映射。
- 缓存后端的实现与存储（数组 / Redis）—— 由 `migears/cache` 负责；DAO 仅依赖 `CacheInterface`。
- 分页数据结构（`Paginator` / `FlowPaginator`）—— 由 `migears/utils` 负责；分页 trait 只负责把一页 Domain 装进去。
- Repository / identity map 语义（受管实体、跨表集合式查询）—— 本包是单表、面向 Domain 的 gateway，不是 Repository；跨表工作归应用。

## 安装

```bash
composer require migears/dao
```

要求：PHP 8.1+、ext-pdo、`psr/log`、`migears/sql`、`migears/domain`、`migears/cache`、`migears/utils`。

## 构造契约

每个 DAO 构造函数都是显式的，且遵循固定的参数顺序：

| 参数 | 类型 | 是否必须 | 含义 |
|------|------|----------|------|
| `$pdo` | `PDO` | 始终 | 数据库连接 |
| `$logger` | `Psr\Log\LoggerInterface` | 始终 | 日志器——显式注入，绝不静默使用默认实现 |
| `$cache` | `MiGears\Cache\CacheInterface` | 仅 cacheable 时 | 缓存层，仅用于使用了 `CachedDao` 的 DAO |

每个 DAO 构造函数**至少接收两个参数**（`pdo`、`logger`）。如果 DAO 是 cacheable 的
（使用了 `CachedDao`），则需额外接收第三个 `cache` 参数：

```php
// 普通 DAO — pdo + logger
class PlainUserDao
{
    use SingleTableDao;

    protected function table(): string { return 'users'; }

    protected function idColumn(): string { return 'id'; }

    protected function domainClass(): string { return UserDomain::class; }

    public function __construct(PDO $pdo, LoggerInterface $logger)
    {
        $this->initDao($pdo, $logger);
    }
}

// Cacheable DAO — pdo + logger + cache
class CachedUserDao
{
    use CachedDao;

    protected function table(): string { return 'users'; }

    protected function idColumn(): string { return 'id'; }

    protected function domainClass(): string { return UserDomain::class; }

    public function __construct(PDO $pdo, LoggerInterface $logger, CacheInterface $cache)
    {
        $this->initDao($pdo, $logger);
        $this->initCachedDao($cache, $logger);
    }
}
```

`$logger` 在 DAO 内部**绝不会被默认填充**——始终由调用方注入。
这让每个 DAO 的构造过程保持显式、可测试，并在整个代码库中保持一致。

`table()`、`idColumn()` 与 `domainClass()` 由 trait 声明为 abstract，
DAO 漏掉任何一个都无法通过编译，而不是到运行时才出错；
也不再需要单独声明属性。

## 快速开始

### 定义 DAO

```php
use MiGears\Dao\SingleTableDao;
use MiGears\Domain\DataAccess;

class UserDomain
{
    use DataAccess;

    public function __construct(
        public readonly int $id,
        public readonly string $user_name,
        public readonly string $email,
    ) {}
}

class UserDao
{
    use SingleTableDao;

    protected function table(): string { return 'users'; }

    protected function idColumn(): string { return 'id'; }

    protected function domainClass(): string { return UserDomain::class; }

    public function __construct(PDO $pdo, LoggerInterface $logger)
    {
        $this->initDao($pdo, $logger);
    }
}
```

### CRUD 操作

```php
$dao = new UserDao($pdo, $logger);

// 读
$user  = $dao->getById(1);                    // UserDomain|null
$user  = $dao->getByIdOrFail(1);              // UserDomain（找不到抛异常）
$users = $dao->getByIds([1, 2, 3]);           // [id => UserDomain]
$all   = $dao->getAll();                       // UserDomain[]（按 id 降序）
$count = $dao->count();                        // int

// 写
$id = $dao->insert(['user_name' => 'Alice', 'email' => 'a@b.com']);  // 自增 ID（string）
$n = $dao->update(['id' => 1, 'user_name' => 'Bob']);                  // 影响行数
$n = $dao->delete(1);                                                  // 影响行数
```

### 分页

分页是可选的：在 `SingleTableDao` 之上按查询策略组合 `OffsetPagination`
或 `CursorPagination` 之一。

**偏移分页** —— 页码 + 精确总数（`MiGears\Utils\Paginator`）：

```php
class UserDao
{
    use SingleTableDao;
    use OffsetPagination;

    protected function table(): string { return 'users'; }
    protected function idColumn(): string { return 'id'; }
    protected function domainClass(): string { return UserDomain::class; }
}

$page = $dao->paginate(2, 20);   // 为总数执行一次 COUNT(*)

$page->items();       // 当前页的 UserDomain[]
$page->total();       // int —— 全部页的总行数
$page->totalPages();  // int
$page->hasNext();     // bool
```

**游标分页** —— keyset / 「加载更多」，无总数（`MiGears\Utils\FlowPaginator`）：

```php
class UserDao
{
    use SingleTableDao;
    use CursorPagination;

    protected function table(): string { return 'users'; }
    protected function idColumn(): string { return 'id'; }
    protected function domainClass(): string { return UserDomain::class; }
}

$page = $dao->paginateAfter(null, 20);                 // 首页
$page->items();                                        // UserDomain[]
$page->hasMore();                                      // bool
$page = $dao->paginateAfter($page->nextCursor(), 20);  // 下一页
```

`CursorPagination` 用多取一行来判断 `hasMore`，不执行 `COUNT(*)`；按主键
降序排列，游标就是该主键，因此 Domain 必须公开暴露它。

### 配合 Domain 对象

```php
// 实体读直接返回 Domain —— 无需手动 fromArray()
$user = $dao->getByIdOrFail(1);
echo $user->user_name;  // "Alice"

// save() 收 Domain、返落库后的 Domain（插入或更新）
$user = $dao->save($userDomain);

// patch() 只写「已带新值的 Domain」中点名的列；其余列（含被并发修改的列）保持不动
$dao->patch($userDomain, ['user_name']);
```

按意图选择写方法——**首选 `save()`**，更低层的方法只在确有理由时才用：

- **`save(Domain)` —— 默认写入口。** 你持有完整的 Domain，要整体落库（无主键则插入，否则整行更新）。从这里开始。
- **`patch(Domain, columns)` —— 定向写时使用。** 它只发送点名的列，因此当只改少数几列、读之后别的列可能已被并发修改（patch 不会覆写它）、或行很宽（大 `TEXT`/`BLOB`）重发全部列不划算时，用它。它按 Domain 主键定位、绝不插入，且不点名任何列即报错。
- **`update(array)` —— 底层；一般代码不要直接调用。** 它收裸列映射而非 Domain，是 `save()` 与 `patch()` 的底层原语。
- **`insert(array)` —— 底层；同样避免使用，只有一个例外。** `save()` 已能插入无主键的 Domain，所以只有**主键必须由调用方指定**时才需要 `insert()`——UUID 或自然主键。`save()` 覆盖不了这种情况：它把**任何非空主键**都当成已存在的行，于是只会去更新一行并不存在的记录、静默不插入。这个「调用方指定主键」就是动用 `insert()` 的正当例外：

  ```php
  // save() 见到非空主键会走 update，结果什么都不插入；insert() 是唯一能持久化
  // 调用方指定主键的路径。
  $dao->insert(['uuid' => $uuid, 'user_name' => 'Alice']);
  ```

### 局部更新（patch）

`patch()` 直接从交给它的 Domain 上取值——不会重建、也不会复制它。给它一个**已带着待写入值**的 Domain，再点名要落库的列：

```php
// Domain 已带着新值（由你的流程以任意方式产出）。
$affected = $dao->patch($user, ['user_name', 'email']);

// UPDATE `users` SET `user_name` = :set_user_name, `email` = :set_email WHERE `id` = …
// 只有点名的列进入 SET：age、status、created_at 保持行内现值——即使在你读取之后
// 已被其他写入方改动。

$affected;  // 1 —— 影响行数（行已不存在时为 0）
```

这正是 `patch()` 与 `save()` 的分野：从请求构造出的 Domain 常带着一些不归你写的列的值，而 `patch()` 只写你点名的那些列。不点名任何列会抛异常，点名了不属于该 Domain 的列同样抛异常。

### 使用 CachedDao

两个 trait 都返回 **已 hydrate 的 Domain 对象**；`CachedDao` 额外为按主键的
读取（`getById` / `getByIds`）加上缓存层。`getAll()` 与 `count()` 始终查询
数据库——只有按主键的读取走缓存。分页不属于缓存层：需要时在 cacheable DAO
上另行组合 `OffsetPagination` / `CursorPagination`。`CachedDao` 只依赖
`CacheInterface` 与 `SingleTableDao` 的契约——不引用任何 `migears/sql` 类型，
SQL 及其异常都留在 `SingleTableDao`。缓存命中只在该值确实属于本 DAO 的
`Domain` 类时才返回；陈旧或异类的缓存值会被记一条 warning、从缓存中删除，
再重新查库。

```php
$user  = $cachedDao->getById(1);          // ?UserDomain（走缓存）
$user  = $cachedDao->getByIdOrFail(1);    // UserDomain（找不到抛异常）
$users = $cachedDao->getByIds([1, 2]);    // [id => UserDomain]（走缓存）
$user  = $cachedDao->save($userDomain);   // 收 Domain，返落库后的 Domain
$all   = $cachedDao->getAll();            // UserDomain[]
```

写操作（`insert` / `update` / `delete`）会自动失效主键缓存。如需清理
额外的缓存键（例如按二级索引查找的 `byEmail`），重写 `deleteCacheFor()`
钩子即可：

```php
class UserDao
{
    use CachedDao;

    protected function table(): string { return 'users'; }

    protected function idColumn(): string { return 'id'; }

    protected function domainClass(): string { return UserDomain::class; }

    public function __construct(PDO $pdo, LoggerInterface $logger, CacheInterface $cache)
    {
        $this->initDao($pdo, $logger);
        $this->initCachedDao($cache, $logger);
    }

    protected function deleteCacheFor(?object $user): void
    {
        if ($user !== null) {
            $this->cache->delete('user_by_email_' . $user->email);
        }
    }
}
```

`deleteCacheFor()` 收到的是**新鲜读出的** Domain 对象（写操作后绕过缓存
重新读取），因此钩子可以检查最新的字段值。记录已不存在时（例如 delete
之后）收到 `null`。

`insert()` 会保留调用方传入的主键值——UUID 或应用生成的主键只需在
data 数组中带上 `idColumn` 字段即可。不传时使用数据库返回的自增 ID。

## 自定义主键

```php
class PostDao
{
    use SingleTableDao;

    protected function table(): string { return 'posts'; }

    protected function idColumn(): string { return 'post_id'; }

    protected function domainClass(): string { return PostDomain::class; }

    public function __construct(PDO $pdo, LoggerInterface $logger)
    {
        $this->initDao($pdo, $logger);
    }
}
```

## 复杂查询

简单 CRUD 之外的查询，使用底层的 `SqlBuilder`：

```php
$rows = $dao->sql()
    ->select(['u.user_name', 'p.title'])
    ->from('users u')
    ->where('u.id = p.user_id', [])
    ->execute();
```

复杂的关联查询和多表查询同样应放在 DAO 中 — 所有数据访问逻辑都封装在 DAO 层，Service 层不应直接操作数据库。

## 类型契约（与 Domain 衔接）

`SingleTableDao` / `CachedDao` 将原始行直接交给 `Domain::fromArray()` —
`hydrate()`（定义在 `SingleTableDao`，`CachedDao` 复用）即对 `domainClass()` 返回的类调用 `fromArray($row)`，不做任何 hydration 魔法。Domain 层**零类型转换**：PHP 8.x 强类型命名参数把每个值绑定到
构造声明的类型，缺键、多键或类型不匹配都会抛原生 `\Error` / `\TypeError`
（参见 migears/domain「类型契约」）。

DAO 自身**不做任何 cast** — 没有列类型映射，也没有 `intval()`。原生类型来自
PDO 本身：PHP 8.1 起，数字列在结果集中即返回真正的 `int` / `float`，模拟预处理
与原生预处理皆然。因此 `int` 列到达时就是真正的 PHP `int`，可空列为空时就是
`null`，本层无需任何归一化步骤。SQL 层同样只负责执行查询并返回原始数组。

关于注意事项（`DECIMAL` 保持 `string`、`TINYINT(1)` 是 `int`、切勿开启
`PDO::ATTR_STRINGIFY_FETCHES`），以及强类型绑定为何是刻意设计而非需要用 cast
抹平的东西，参见 migears/domain「类型契约」。

## 架构

DAO 是 miGears 三层数据架构的胶水层：

```
Service 层（业务逻辑）
    ↓ 调用
DAO 层（接收/返回 Domain 对象）→ 本包
    ↓ 内部调用
SQL 层（SQL + 参数 → 数组）→ migears/sql
    ↓
PDO / MySQL
```

- **SQL 层**不知道 Domain 的存在
- **Domain 层**不知道 SQL 和 DAO 的存在
- **DAO 层**同时知道 SQL 和 Domain — 封装所有数据访问逻辑（含复杂关联查询），不含业务逻辑

## 为什么用 Trait？

1. **不受继承锁定** — DAO 类可以继承任何需要的父类
2. **构造函数显式** — 用户控制 PDO、logger（以及 `CachedDao` 的 cache）注入，没有隐藏的默认值
3. **可读性好** — 所有方法都在类里可见，没有隐藏的基类方法

## 许可证

MIT
