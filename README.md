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
- **Reads/queries take scalars** — `findById(int|string $id)` (and the raw
  `getById` / `getByIds` / `getAll` / `paginate`) are located by scalar values,
  never by a Domain.
- **Entity queries return a Domain** — `findById()` and `CachedDao::getById()`
  return `Domain|null`. Aggregates, counts, id lists and projections are the
  deliberate exception: they are not entities, so they return scalars.
- **The Manager is the boundary** — only the Manager layer holds both a Domain
  and a DAO and wires them together; the layers above it (resources /
  controllers) see Domains, never DAOs. The DAO is where Domain and row meet.

The package is therefore Domain-oriented rather than row-oriented. It stops
short of a full Repository: there is no identity map and no collection
semantics — a DAO reads and writes one table, and cross-table work is the
application's job.

## Boundaries

**In scope**

- Single-table CRUD, delivered as a trait rather than a base class: `getById` / `getByIdOrFail` / `getByIds` / `getAll` / `count` / `paginate` / `insert` / `update` / `delete`, with a configurable `$idColumn` for a non-`id` primary key.
- An optional `$domainClass` plus `hydrate()`, `findById()` and `save(Domain)`, which turn rows into Domain objects and persist Domains back; `CachedDao` builds on the same contract.
- All data-access logic for one table, including complex joins and multi-table queries issued through the underlying `SqlBuilder` (`getSqlBuilder()`).
- The cacheable variant `CachedDao`: caching primary-key reads (`getById` / `getByIds`), returning hydrated Domain objects, invalidating the primary-key cache automatically on writes, and exposing the `deleteCacheFor()` hook for extra keys.
- Handing raw PDO rows straight to `Domain::fromArray()`, adding no casting of its own.

**Not in scope (by design)**

- Business logic, orchestration and the Service layer above the DAO — owned by `migears/manager`.
- Building/executing SQL and talking to PDO — delegated to `migears/sql` (its `SqlBuilder`, `SqlException`, `RecordNotFoundException`).
- Defining Domain objects and normalizing/casting column values — owned by `migears/domain`; the DAO deliberately performs no `intval()` or column-type mapping.
- Cache backend implementations and storage (array / Redis) — owned by `migears/cache`; the DAO only depends on `CacheInterface`.
- Repository / identity-map semantics (managed entities, cross-table collection queries) — this is a single-table, Domain-oriented gateway, not a Repository; cross-table work belongs to the application.

## Installation

```bash
composer require migears/dao
```

Requires: PHP 8.1+, ext-pdo, `psr/log`, `migears/sql`, `migears/domain`, `migears/cache`.

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

    protected string $table = 'users';
    protected string $idColumn = 'id';

    public function __construct(PDO $pdo, LoggerInterface $logger)
    {
        $this->initDao($pdo, $logger);
    }
}

// Cacheable DAO — pdo + logger + cache
class CachedUserDao
{
    use CachedDao;

    protected string $table = 'users';
    protected string $idColumn = 'id';
    protected string $domainClass = UserDomain::class;

    public function __construct(PDO $pdo, LoggerInterface $logger, CacheInterface $cache)
    {
        $this->initCachedDao($pdo, $logger, $cache);
    }
}
```

`$logger` is **never** defaulted inside a DAO — it is always injected by the caller.
This keeps every DAO's construction explicit, testable, and uniform across the codebase.

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

    protected string $table = 'users';
    protected string $idColumn = 'id';

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
$row = $dao->getById(1);                    // array|null
$row = $dao->getByIdOrFail(1);              // array (throws if not found)
$rows = $dao->getByIds([1, 2, 3]);          // array keyed by ID
$all = $dao->getAll();                       // all rows (id DESC)
$count = $dao->count();                      // int
$page = $dao->paginate(1, 20);               // ['records' => [...], 'total' => 100]

// Write
$id = $dao->insert(['user_name' => 'Alice', 'email' => 'a@b.com']);  // last insert id (string)
$n = $dao->update(['id' => 1, 'user_name' => 'Bob']);                  // affected rows
$n = $dao->delete(1);                                                  // affected rows
```

### With Domain Objects

```php
// Array → Domain
$row = $dao->getByIdOrFail(1);
$user = UserDomain::fromArray($row);
echo $user->user_name;  // "Alice"

// Domain → Array → SQL
$dao->update($user->toArray());
```

### Using CachedDao

`CachedDao` caches primary-key lookups (`getById` / `getByIds`) and returns
**hydrated Domain objects** instead of raw arrays. `getAll()`, `count()` and
`paginate()` always query the database — only the primary-key reads are cached.

```php
$user  = $cachedDao->getById(1);          // ?UserDomain
$user  = $cachedDao->findById(1);         // same, canonical read entry
$user  = $cachedDao->getByIdOrFail(1);    // UserDomain (throws if not found)
$users = $cachedDao->getByIds([1, 2]);    // [id => UserDomain]
$user  = $cachedDao->save($userDomain);   // Domain in, persisted Domain out
$all   = $cachedDao->getAll();            // UserDomain[]
$page  = $cachedDao->paginate(1, 20);     // ['records' => UserDomain[], 'total' => int]
```

Write operations (`insert` / `update` / `delete`) automatically invalidate
the primary-key cache entry. To clear additional cache keys (e.g. lookups
by secondary indexes like `byEmail`), override the `deleteCacheFor()` hook:

```php
class UserDao
{
    use CachedDao;

    protected string $table = 'users';
    protected string $idColumn = 'id';
    protected string $domainClass = UserDomain::class;

    public function __construct(PDO $pdo, LoggerInterface $logger, CacheInterface $cache)
    {
        $this->initCachedDao($pdo, $logger, $cache);
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

    protected string $table = 'posts';
    protected string $idColumn = 'post_id';

    public function __construct(PDO $pdo, LoggerInterface $logger)
    {
        $this->initDao($pdo, $logger);
    }
}
```

## Complex Queries

For queries beyond simple CRUD, use the underlying `SqlBuilder`:

```php
$rows = $dao->getSqlBuilder()
    ->select(['u.user_name', 'p.title'])
    ->from('users u')
    ->where('u.id = p.user_id', [])
    ->execute();
```

Complex joins and multi-table queries also belong in the DAO — all database access logic is encapsulated here. The Service layer should never touch the database directly.

## Type Contract (with Domain)

`SingleTableDao` / `CachedDao` hand raw rows straight to `Domain::fromArray()` —
`hydrate()` (defined in `SingleTableDao`, reused by `CachedDao`) calls
`{$domainClass}::fromArray($row)` with no hydration magic. The Domain layer performs **zero coercion**: PHP 8.x strict typed
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
- **读/查询收标量** —— `findById(int|string $id)`（以及底层的 `getById` / `getByIds` / `getAll` / `paginate`）只用标量定位，绝不以 Domain 为入参。
- **实体查询返回 Domain** —— `findById()` 与 `CachedDao::getById()` 返回 `Domain|null`。聚合、计数、ID 列表与投影是刻意的例外：它们不是实体，返回标量。
- **Manager 是边界** —— 只有 Manager 层同时持有 Domain 与 DAO 并把两者接起来；其上的层（resource / controller）只见 Domain、不见 DAO。Domain 与「行」相遇之处是 DAO 自己。

因此本包是「面向 Domain」而非「面向行」的。它并未做成完整 Repository：没有 identity map，也没有集合语义——DAO 只读写一张表，跨表编排归应用。

## 边界

**范围内**

- 以 trait 而非基类提供单表 CRUD：`getById` / `getByIdOrFail` / `getByIds` / `getAll` / `count` / `paginate` / `insert` / `update` / `delete`，并可用 `$idColumn` 配置非 `id` 主键。
- 可选的 `$domainClass` 与 `hydrate()`、`findById()`、`save(Domain)`：把行转成 Domain、把 Domain 落库；`CachedDao` 在同一契约之上构建。
- 封装单表的全部数据访问逻辑，包括通过底层 `SqlBuilder`（`getSqlBuilder()`）编写的复杂关联查询与多表查询。
- cacheable 变体 `CachedDao`：为按主键读取（`getById` / `getByIds`）加缓存、返回 hydrate 后的 Domain 对象、写操作自动失效主键缓存，并提供 `deleteCacheFor()` 钩子清理额外键。
- 把 PDO 返回的原始行直接交给 `Domain::fromArray()`，自身不做任何 cast。

**范围外（刻意不做）**

- 业务逻辑、流程编排，以及 DAO 之上的 Service 层 —— 由 `migears/manager` 负责。
- 拼接/执行 SQL 以及与 PDO 打交道 —— 交给 `migears/sql`（`SqlBuilder`、`SqlException`、`RecordNotFoundException`）。
- 定义 Domain 对象、对列值做类型归一化/cast —— 由 `migears/domain` 负责；DAO 刻意不做 `intval()`，也不维护列类型映射。
- 缓存后端的实现与存储（数组 / Redis）—— 由 `migears/cache` 负责；DAO 仅依赖 `CacheInterface`。
- Repository / identity map 语义（受管实体、跨表集合式查询）—— 本包是单表、面向 Domain 的 gateway，不是 Repository；跨表工作归应用。

## 安装

```bash
composer require migears/dao
```

要求：PHP 8.1+、ext-pdo、`psr/log`、`migears/sql`、`migears/domain`、`migears/cache`。

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

    protected string $table = 'users';
    protected string $idColumn = 'id';

    public function __construct(PDO $pdo, LoggerInterface $logger)
    {
        $this->initDao($pdo, $logger);
    }
}

// Cacheable DAO — pdo + logger + cache
class CachedUserDao
{
    use CachedDao;

    protected string $table = 'users';
    protected string $idColumn = 'id';
    protected string $domainClass = UserDomain::class;

    public function __construct(PDO $pdo, LoggerInterface $logger, CacheInterface $cache)
    {
        $this->initCachedDao($pdo, $logger, $cache);
    }
}
```

`$logger` 在 DAO 内部**绝不会被默认填充**——始终由调用方注入。
这让每个 DAO 的构造过程保持显式、可测试，并在整个代码库中保持一致。

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

    protected string $table = 'users';
    protected string $idColumn = 'id';

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
$row = $dao->getById(1);                    // array|null
$row = $dao->getByIdOrFail(1);              // array（找不到抛异常）
$rows = $dao->getByIds([1, 2, 3]);          // 按主键索引的数组
$all = $dao->getAll();                       // 全部记录（按 id 降序）
$count = $dao->count();                      // int
$page = $dao->paginate(1, 20);               // ['records' => [...], 'total' => 100]

// 写
$id = $dao->insert(['user_name' => 'Alice', 'email' => 'a@b.com']);  // 自增 ID（string）
$n = $dao->update(['id' => 1, 'user_name' => 'Bob']);                  // 影响行数
$n = $dao->delete(1);                                                  // 影响行数
```

### 配合 Domain 对象

```php
// 数组 → Domain
$row = $dao->getByIdOrFail(1);
$user = UserDomain::fromArray($row);
echo $user->user_name;  // "Alice"

// Domain → 数组 → SQL
$dao->update($user->toArray());
```

### 使用 CachedDao

`CachedDao` 为按主键的读取（`getById` / `getByIds`）加上缓存层，并返回
**已 hydrate 的 Domain 对象**而非原始数组。`getAll()`、`count()` 与
`paginate()` 始终查询数据库——只有按主键的读取走缓存。

```php
$user  = $cachedDao->getById(1);          // ?UserDomain
$user  = $cachedDao->findById(1);         // 同上，规范读入口
$user  = $cachedDao->getByIdOrFail(1);    // UserDomain（找不到抛异常）
$users = $cachedDao->getByIds([1, 2]);    // [id => UserDomain]
$user  = $cachedDao->save($userDomain);   // 收 Domain，返落库后的 Domain
$all   = $cachedDao->getAll();            // UserDomain[]
$page  = $cachedDao->paginate(1, 20);     // ['records' => UserDomain[], 'total' => int]
```

写操作（`insert` / `update` / `delete`）会自动失效主键缓存。如需清理
额外的缓存键（例如按二级索引查找的 `byEmail`），重写 `deleteCacheFor()`
钩子即可：

```php
class UserDao
{
    use CachedDao;

    protected string $table = 'users';
    protected string $idColumn = 'id';
    protected string $domainClass = UserDomain::class;

    public function __construct(PDO $pdo, LoggerInterface $logger, CacheInterface $cache)
    {
        $this->initCachedDao($pdo, $logger, $cache);
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

    protected string $table = 'posts';
    protected string $idColumn = 'post_id';

    public function __construct(PDO $pdo, LoggerInterface $logger)
    {
        $this->initDao($pdo, $logger);
    }
}
```

## 复杂查询

简单 CRUD 之外的查询，使用底层的 `SqlBuilder`：

```php
$rows = $dao->getSqlBuilder()
    ->select(['u.user_name', 'p.title'])
    ->from('users u')
    ->where('u.id = p.user_id', [])
    ->execute();
```

复杂的关联查询和多表查询同样应放在 DAO 中 — 所有数据访问逻辑都封装在 DAO 层，Service 层不应直接操作数据库。

## 类型契约（与 Domain 衔接）

`SingleTableDao` / `CachedDao` 将原始行直接交给 `Domain::fromArray()` —
`hydrate()`（定义在 `SingleTableDao`，`CachedDao` 复用）即调用
`{$domainClass}::fromArray($row)`，不做任何 hydration 魔法。Domain 层**零类型转换**：PHP 8.x 强类型命名参数把每个值绑定到
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
