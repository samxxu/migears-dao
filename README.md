# migears/dao

![Version](https://img.shields.io/badge/version-2.0.0-blue)

Minimalist DAO layer — connects SQL and Domain with zero abstraction.

> **Background**: miGears is the open-source successor of **TinyGears**, a
> self-developed PHP framework. It was renamed and open-sourced recently because
> the name *TinyGears* is already taken in the open-source community.

## Philosophy

- **No generic CRUD base class** — `SingleTableDao` is a trait, not a base class
- **Direct SQL in each method** — no magic, no auto-generated queries
- **Direct `new Domain(...$row)`** — no intermediate hydrator or mapper
- **`toArray()` for persistence** — Domain → array → SQL
- **One DAO per table** — simple and predictable

## Installation

```bash
composer require migears/dao
```

Requires: PHP 8.1+, `migears/sql`.

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
$dao = new UserDao($pdo);

// Read
$row = $dao->getById(1);                    // array|null
$row = $dao->getByIdOrFail(1);              // array (throws if not found)
$rows = $dao->getByIds([1, 2, 3]);          // array keyed by ID
$all = $dao->getAll();                       // all rows (id DESC)
$count = $dao->count();                      // int
$page = $dao->paginate(1, 20);               // ['records' => [...], 'total' => 100]

// Write
$id = $dao->insert(['user_name' => 'Alice', 'email' => 'a@b.com']);  // last insert id
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
`CachedDao::hydrate()` calls `{$domainClass}::fromArray($row)` with no
hydration magic. The Domain layer performs **zero coercion**: PHP 8.x strict typed
named arguments bind each value to the declared constructor type, so a missing
key, an extra key, or a type mismatch throws a native `\Error` / `\TypeError`
(see migears/domain "Type Contract").

This makes the **DAO the enforcement point for native types**. Between the SQL
result and the Domain constructor, the DAO must guarantee every value already
carries its native PHP type:

- `int` columns arrive as a real PHP `int`, not the string `'42'`
- `bool` columns as `true` / `false`, not `'1'` / `'0'`
- nullable columns as `null` when empty

Either configure PDO to return native types (e.g. `PDO::ATTR_EMULATE_PREPARES
=> false` with a driver that infers column types), or cast explicitly inside the
DAO method before handing the row to `fromArray()`.

The SQL layer only executes queries and returns raw arrays — normalizing result
types is owned here, in the DAO. (The same holds for `SingleTableDao`, where
the row travels up to `fromArray()` in the caller.)

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

![Version](https://img.shields.io/badge/version-2.0.0-blue)

极简 DAO 层 — 连接 SQL 和 Domain，零抽象。

## 设计哲学

- **不封装通用 CRUD 基类** — `SingleTableDao` 是 trait，不是基类
- **每个方法直接写 SQL** — 没有魔法，没有自动生成查询
- **直接 `new Domain(...$row)`** — 不经过任何中间 hydrator 或 mapper
- **`toArray()` 用于持久化** — Domain → 数组 → SQL
- **一个 DAO 对应一张表** — 简单可预测

## 安装

```bash
composer require migears/dao
```

要求：PHP 8.1+，`migears/sql`。

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
$dao = new UserDao($pdo);

// 读
$row = $dao->getById(1);                    // array|null
$row = $dao->getByIdOrFail(1);              // array（找不到抛异常）
$rows = $dao->getByIds([1, 2, 3]);          // 按主键索引的数组
$all = $dao->getAll();                       // 全部记录（按 id 降序）
$count = $dao->count();                      // int
$page = $dao->paginate(1, 20);               // ['records' => [...], 'total' => 100]

// 写
$id = $dao->insert(['user_name' => 'Alice', 'email' => 'a@b.com']);  // 自增 ID
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
`CachedDao::hydrate()` 即调用 `{$domainClass}::fromArray($row)`，不做任何
hydration 魔法。Domain 层**零类型转换**：PHP 8.x 强类型命名参数把每个值绑定到
构造声明的类型，缺键、多键或类型不匹配都会抛原生 `\Error` / `\TypeError`
（参见 migears/domain「类型契约」）。

因此 **DAO 是原生类型的履约点**。在 SQL 结果与 Domain 构造函数之间，DAO 必须
保证每个值已是原生 PHP 类型：

- `int` 列是真正的 PHP `int`，而非字符串 `'42'`
- `bool` 列是 `true` / `false`，而非 `'1'` / `'0'`
- 可空列为空时是 `null`

要么配置 PDO 返回原生类型（如 `PDO::ATTR_EMULATE_PREPARES => false` 且驱动能
推断列类型），要么在 DAO 方法内、把行交给 `fromArray()` 之前显式 cast。

SQL 层只负责执行查询并返回原始数组 — 结果类型归一化统一收口在 DAO 层。
（`SingleTableDao` 同样如此，只是该行会向上传递到调用方再进 `fromArray()`。）

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
