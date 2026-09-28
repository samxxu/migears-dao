# migears-dao — Known Issues / 已知问题

> Summary of this module's issues. The items themselves are in [`issues/`](issues/README.md), one file
> per item: a front-matter header and a thread. This file is generated from them and can be rewritten at
> any time; edit an item, never this file.
>
> 本模块问题的概览。条目本体在 [`issues/`](issues/README.md)，一条目一文件：前置字段加讨论串。
> 本文件由条目生成，随时可以整段重写；请改条目，不要改本文件。
>
> From the miGears Full-Module Code Review Report (4th round, 2026-09-27).

| | |
|---|---|
| Status / 状态 | **P0 cleared / P0 已清零** |
| Size / 体量 | src 466 lines (279 net) · 51 tests · 2 src files |

Legend / 图例 — **P0** functional or security · **P1** documentation that fails when copied · **P2** robustness · **P3** metadata and docs
级别说明 — **P0** 功能性或安全级 · **P1** 文档照抄即错 · **P2** 健壮性 · **P3** 元数据与文档

## At a glance / 状态一览

| | |
|---|---|
| Items / 条目 | P0 0 · P1 1 · P2 4 · P3 2 · other 1 |
| Answered / 已回复 | 1 of 8 |
| Waiting / 等待回复 | `P1-1`, `P2-1`, `P2-2`, `P2-3`, `P2-4`, `P3-1`, `P3-2` |

| id | level | status | title |
|---|---|---|---|
| [`P1-1`](issues/P1-1.md) | P1 | **open** | The README says `CachedDao` wraps every read method with a cache layer. … |
| [`P2-1`](issues/P2-1.md) | P2 | **open** | `cacheRemove()` unconditionally reads the row back with a `SELECT` even … |
| [`P2-2`](issues/P2-2.md) | P2 | **open** | Both class docblock examples omit the constructor and the … |
| [`P2-3`](issues/P2-3.md) | P2 | **open** | `$cache` has no initialisation guard (asymmetric with … |
| [`P2-4`](issues/P2-4.md) | P2 | **open** | `initCachedDao()` validates only `$table` and `$idColumn`, skipping the … |
| [`P3-1`](issues/P3-1.md) | P3 | **open** | The README 'Requires' line lists only PHP and `migears/sql` while … |
| [`P3-2`](issues/P3-2.md) | P3 | **open** | Cache keys are not normalised per instance: `getById('01')` writes key … |
| [`G2`](issues/G2.md) | - | **fixed** | Strict flags: `phpunit.xml.dist` currently sets none of the five. The … |

## Verdict / 结论

Almost nothing moved in this module. The README claim that `CachedDao` wraps "every read method" is the opposite of the implementation, both class docblock examples cannot be copied, and the two construction-time guards are still missing.

这个模块几乎没有动。README 称 CachedDao 包装「所有读方法」与实现相反，两个类的 docblock 示例照抄即失败，两个构造期守卫仍然缺失。

## Fixed since the last round / 本轮已修复确认

上一轮 P2-4（getByIds 非规范数字键导致重复行）已修复，改用 Domain 主键作缓存命中键并有回归测试。 

## Test gaps / 测试盲区

Nothing asserts whether `getAll()`/`count()`/`paginate()` use the cache (which is why the README error survived); no `$domainClass` or `$cache` guard test; the cache-key fragmentation below has no test.

没有任何用例断言 getAll()/count()/paginate() 是否走缓存（这正是 README 结论长期错误的原因）；无 $domainClass 与 $cache 守卫用例；缓存键碎片化无测试。

## Verification protocol / 验证方式

- `./vendor/bin/phpunit` · `composer analyse` · `composer validate`
- Warning/notice/deprecation/risky flags in `phpunit.xml.dist`: all four on
- A PHP warning counts as a test failure only where those flags are on; otherwise run `./vendor/bin/phpunit --fail-on-warning` explicitly.
- 只有在上述开关打开时 PHP 警告才会导致套件失败；否则请显式加 `--fail-on-warning`。
