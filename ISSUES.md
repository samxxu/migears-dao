# migears-dao — Known Issues

> Summary of this module's issues. The items themselves are in [`issues/`](issues/README.md), one file
> per item: a front-matter header and a thread. This file is generated from them and can be rewritten at
> any time; edit an item, never this file.
>
> From the miGears Full-Module Code Review Report (6th round, 2026-10-01).

| | |
|---|---|
| Status | **Best state** |
| Size | src 339 lines (net) · 68 tests · 2 src files |

Legend — **P0** functional or security · **P1** documentation that fails when copied · **P2** robustness · **P3** metadata and docs

## At a glance

| | |
|---|---|
| Unsettled | P0 0 · P1 0 · P2 0 · P3 0 · other 0 |
| Settled | 11 of 11 |
| Waiting on the owner | _nothing_ |
| Waiting on the coordinator | _nothing_ |
| Waiting on the reviewer | _nothing_ |
| Deferred, owing nobody | _nothing_ |

| id | level | status | title |
|---|---|---|---|
| [`P1-1`](issues/P1-1.md) | P1 | **verified** | The README says `CachedDao` wraps every read method with a cache layer. … |
| [`P2-1`](issues/P2-1.md) | P2 | **verified** | `cacheRemove()` unconditionally reads the row back with a `SELECT` even … |
| [`P2-2`](issues/P2-2.md) | P2 | **verified** | Both class docblock examples omit the constructor and the … |
| [`P2-3`](issues/P2-3.md) | P2 | **verified** | `$cache` has no initialisation guard (asymmetric with … |
| [`P2-4`](issues/P2-4.md) | P2 | **verified** | `initCachedDao()` validates only `$table` and `$idColumn`, skipping the … |
| [`P2-5`](issues/P2-5.md) | P2 | **verified** | `domainId()` read the primary key with `get_object_vars()`, evaluated … |
| [`P2-6`](issues/P2-6.md) | P2 | **verified** | Cache-failure handling was asymmetric. Writes wrapped `cacheRemove()` … |
| [`P3-1`](issues/P3-1.md) | P3 | **verified** | The README 'Requires' line lists only PHP and `migears/sql` while … |
| [`P3-2`](issues/P3-2.md) | P3 | **verified** | Cache keys are not normalised per instance: `getById('01')` writes key … |
| [`P3-3`](issues/P3-3.md) | P3 | **verified** | The `### Using CachedDao` example presented `class UserDao { use … |
| [`G2`](issues/G2.md) | - | **verified** | Strict flags: `phpunit.xml.dist` currently sets none of the five. The … |

## Unclosed

_Nothing unclosed — every item in this module is `verified` or `closed`._

## Verdict

The three items filed from the 2026-09-28 audit are real fixes, each pinned by a test that turns red when the guard is reverted; the working tree is now committed and the suite is green.

## Fixed since the last round

P2-5, P2-6 and P3-3 all verified by mutation: domainId() reads the primary key through reflection instead of get_object_vars(), reads and writes handle cache failures symmetrically, and the README’s CachedDao example carries its constructor again.

## Test gaps

deleteCacheForIsOverridden()’s static cache across subclasses has no direct test; the hook’s "row no longer exists" path is covered, but the path where the hook itself throws is not separately asserted.

## Verification protocol

- `./vendor/bin/phpunit` · `composer analyse` · `composer validate`
- Warning/notice/deprecation/risky flags in `phpunit.xml.dist`: all four on
- A PHP warning counts as a test failure only where those flags are on; otherwise run `./vendor/bin/phpunit --fail-on-warning` explicitly.


---

# migears-dao — 已知问题

> 本模块问题的概览。条目本体在 [`issues/`](issues/README.md)，一条目一文件：前置字段加讨论串。
> 本文件由条目生成，随时可以整段重写；请改条目，不要改本文件。
>
> 出自 miGears 全模块代码评审报告（6th round，2026-10-01）。

| | |
|---|---|
| 状态 | **状态最好** |
| 体量 | src 339 行（净）· 68 个用例 · 2 个源文件 |

级别说明 — **P0** 功能性或安全级 · **P1** 文档照抄即错 · **P2** 健壮性 · **P3** 元数据与文档

## 状态一览

| | |
|---|---|
| 未了结 | P0 0 · P1 0 · P2 0 · P3 0 · 其他 0 |
| 已了结 | 11 / 11 |
| 等模块主 | _无_ |
| 等协调人 | _无_ |
| 等评审方 | _无_ |
| 已暂缓，不欠谁 | _无_ |

| id | 级别 | 状态 | 标题 |
|---|---|---|---|
| [`P1-1`](issues/P1-1.md) | P1 | **verified** | README 称 CachedDao 为每个读方法都套了缓存层。实测：getById/getByIds … |
| [`P2-1`](issues/P2-1.md) | P2 | **verified** | cacheRemove() 无条件回读一次 SELECT，即使 deleteCacheFor() 是默认空实现，每次写入都多一次往返；三处 … |
| [`P2-2`](issues/P2-2.md) | P2 | **verified** | 两个类的 docblock 示例都缺构造器与 initDao()/initCachedDao() 调用，照抄即抛错；README 仍写 new … |
| [`P2-3`](issues/P2-3.md) | P2 | **verified** | $cache 缺初始化守卫（与 SingleTableDao::sql() 不对称）：未初始化即用会得到 Typed property … |
| [`P2-4`](issues/P2-4.md) | P2 | **verified** | initCachedDao() 只校验 $table 与 $idColumn，漏掉 docblock 声明为必需的 … |
| [`P2-5`](issues/P2-5.md) | P2 | **verified** | `domainId()` 用 `get_object_vars()` 读主键，而该函数在 DAO 作用域内取值，因此只看得见 public … |
| [`P2-6`](issues/P2-6.md) | P2 | **verified** | 缓存失败处理不对称。写路径把 `cacheRemove()` 包在 `try/catch (\Throwable)` 里并记 … |
| [`P3-1`](issues/P3-1.md) | P3 | **verified** | README 的 Requires 只列 PHP 与 migears/sql，而 composer 实际 require … |
| [`P3-2`](issues/P3-2.md) | P3 | **verified** | 缓存键未按实例归一：getById("01") 写 users_01，getById(1) 写 users_1，同一行会被缓存两份——正是 … |
| [`P3-3`](issues/P3-3.md) | P3 | **verified** | `### 使用 CachedDao` 示例以 `class UserDao { use CachedDao; … }` … |
| [`G2`](issues/G2.md) | - | **verified** | 严格开关：`phpunit.xml.dist` … |

## 未关闭

_无未关闭条目——本模块每条都已是 `verified` 或 `closed`。_

## 结论

2026-09-28 审计立案的三条都是真修复，每条都有「还原守卫即转红」的用例钉住；工作树现已提交，套件全绿。

## 本轮已修复确认

P2-5, P2-6 and P3-3 all verified by mutation: domainId() reads the primary key through reflection instead of get_object_vars(), reads and writes handle cache failures symmetrically, and the README’s CachedDao example carries its constructor again.

## 测试盲区

deleteCacheForIsOverridden() 的跨子类静态缓存无直接用例；钩子的「行已不存在」路径有覆盖，但钩子自身抛错的路径未单独断言。

## 验证方式

- `./vendor/bin/phpunit` · `composer analyse` · `composer validate`
- `phpunit.xml.dist` 中的 warning/notice/deprecation/risky 开关：四个全开
- 只有在上述开关打开时 PHP 警告才会导致套件失败；否则请显式加 `--fail-on-warning`。
