# migears/dao

![Version](https://img.shields.io/badge/version-2.0.0-blue)

Minimalist DAO layer — connects SQL and Domain with zero abstraction.

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

    public function __construct(PDO $pdo)
    {
        $this->initDao($pdo);
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

    public function __construct(PDO $pdo)
    {
        $this->initDao($pdo);
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

Complex joins and multi-table queries belong in the Service layer, not the DAO.

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
- **DAO layer** knows both, but only does simple conversion — no business logic

## Why a Trait?

1. **No inheritance lock-in** — DAO classes can extend whatever they need
2. **Explicit constructor** — user controls PDO injection and logger setup
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

    public function __construct(PDO $pdo)
    {
        $this->initDao($pdo);
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

    public function __construct(PDO $pdo)
    {
        $this->initDao($pdo);
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

复杂的关联查询和多表查询应放在 Service 层，不在 DAO 中处理。

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
- **DAO 层**同时知道两者，但只做简单转换 — 不含业务逻辑

## 为什么用 Trait？

1. **不受继承锁定** — DAO 类可以继承任何需要的父类
2. **构造函数显式** — 用户控制 PDO 注入和日志配置
3. **可读性好** — 所有方法都在类里可见，没有隐藏的基类方法

## 许可证

MIT
