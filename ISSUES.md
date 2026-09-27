# migears-dao — Known Issues / 已知问题

> Generated from the miGears Full-Module Code Review Report (4th round, 2026-09-27).
> This file has two regions. Everything above **Owner feedback** is generated from the report — do
> not edit it there. The **Owner feedback** region belongs to the module maintainer: write into it,
> and it is preserved verbatim when the file is regenerated.
> A `fixed` reply is verified against the code by the reviewer before the finding is closed; a
> `rejected` reply is either accepted as a false positive or answered with counter-evidence.
>
> 本文件分两个区域。**「负责人反馈」之前的全部内容**由评审报告生成，请勿在该区修改；
> **「负责人反馈」区**归模块负责人所有，重新生成时会原样保留。
> 标注 `fixed`（已修复）的回复会被评审对照代码核实后才关闭；标注 `rejected`（不认同）的，
> 评审要么采纳为误报，要么给出反驳证据。
>
> 摘自 miGears 全模块代码评审报告（第四轮，2026-09-27）。

| | |
|---|---|
| Status / 状态 | **P0 cleared / P0 已清零** |
| Findings / 问题 | P0 0 · P1 1 · P2 4 · P3 2 |
| Size / 体量 | src 466 lines (279 net) · 51 tests · 2 src files |

Legend / 图例 — **P0** functional or security · **P1** documentation that fails when copied · **P2** robustness · **P3** metadata and docs
级别说明 — **P0** 功能性或安全级 · **P1** 文档照抄即错 · **P2** 健壮性 · **P3** 元数据与文档

## Verdict / 结论

Almost nothing moved in this module. The README claim that `CachedDao` wraps "every read method" is the opposite of the implementation, both class docblock examples cannot be copied, and the two construction-time guards are still missing.

这个模块几乎没有动。README 称 CachedDao 包装「所有读方法」与实现相反，两个类的 docblock 示例照抄即失败，两个构造期守卫仍然缺失。

## Fixed since the last round / 本轮已修复确认

上一轮 P2-4（getByIds 非规范数字键导致重复行）已修复，改用 Domain 主键作缓存命中键并有回归测试。 

## Open findings / 未修问题


### P1

**P1-1** — `README:140-141 vs src/CachedDao.php:146-166`

- EN: The README says `CachedDao` wraps every read method with a cache layer. Measured: `getById`/`getByIds` hit the cache; `getAll`, `count` and `paginate` never touch it.
- 中文: README 称 CachedDao 为每个读方法都套了缓存层。实测：getById/getByIds 走缓存，getAll、count、paginate 完全不碰缓存。
- Verification / 验证: reproduced / 已实证


### P2

**P2-1** — `src/CachedDao.php:210-215,174,190,200`

- EN: `cacheRemove()` unconditionally reads the row back with a `SELECT` even when `deleteCacheFor()` is the default empty implementation, adding a round trip to every write; three `catch (\Throwable) {}` blocks swallow cache failures with no log trail.
- 中文: cacheRemove() 无条件回读一次 SELECT，即使 deleteCacheFor() 是默认空实现，每次写入都多一次往返；三处 catch (\Throwable) {} 吞掉缓存失败且无日志痕迹。
- Verification / 验证: static / 仅静态推断

**P2-2** — `src/CachedDao.php:23-33, src/SingleTableDao.php:19-32, README:15`

- EN: Both class docblock examples omit the constructor and the `initDao()`/`initCachedDao()` calls, so copying them throws; the README additionally still shows `new Domain(...$row)` while the implementation and later sections use `Domain::fromArray()`.
- 中文: 两个类的 docblock 示例都缺构造器与 initDao()/initCachedDao() 调用，照抄即抛错；README 仍写 new Domain(...$row)，而实现与后文用的是 Domain::fromArray()。
- Verification / 验证: static / 仅静态推断

**P2-3** — `src/CachedDao.php:47 vs src/SingleTableDao.php:42-48`

- EN: `$cache` has no initialisation guard (asymmetric with `SingleTableDao::sql()`): using it uninitialised yields `Typed property ...::$cache must not be accessed before initialization`, and it fires earlier than the sql() guard would.
- 中文: $cache 缺初始化守卫（与 SingleTableDao::sql() 不对称）：未初始化即用会得到 Typed property ...::$cache must not be accessed before initialization，且比 sql() 守卫更早触发。
- Verification / 验证: reproduced / 已实证

**P2-4** — `src/CachedDao.php:49-56,64-67`

- EN: `initCachedDao()` validates only `$table` and `$idColumn`, skipping the `$domainClass` its docblock declares as required, and the trait never declares that property — a missing value becomes a raw Error deep in the call chain.
- 中文: initCachedDao() 只校验 $table 与 $idColumn，漏掉 docblock 声明为必需的 $domainClass，trait 也未声明该属性——缺失时在深层调用链抛原生 Error。
- Verification / 验证: static / 仅静态推断


### P3

**P3-1** — `README:25 vs composer.json`

- EN: The README "Requires" line lists only PHP and `migears/sql` while composer requires four packages (`sql`, `domain`, `cache` and PSR logger).
- 中文: README 的 Requires 只列 PHP 与 migears/sql，而 composer 实际 require 四个包（sql、domain、cache 与 PSR logger）。
- Verification / 验证: static / 仅静态推断

**P3-2** — `src/CachedDao.php:104-131`

- EN: Cache keys are not normalised per instance: `getById("01")` writes key `users_01` while `getById(1)` writes `users_1`, so one row can be cached twice — the very normalisation `getByIds` just gained.
- 中文: 缓存键未按实例归一：getById("01") 写 users_01，getById(1) 写 users_1，同一行会被缓存两份——正是 getByIds 刚修好的那类归一化。
- Verification / 验证: static / 仅静态推断

## Test gaps / 测试盲区

Nothing asserts whether `getAll()`/`count()`/`paginate()` use the cache (which is why the README error survived); no `$domainClass` or `$cache` guard test; the cache-key fragmentation below has no test.

没有任何用例断言 getAll()/count()/paginate() 是否走缓存（这正是 README 结论长期错误的原因）；无 $domainClass 与 $cache 守卫用例；缓存键碎片化无测试。

## Verification protocol / 验证方式

- `./vendor/bin/phpunit` · `composer analyse` · `composer validate`
- Warning/notice/deprecation/risky flags in `phpunit.xml.dist`: none on
- A PHP warning counts as a test failure only where those flags are on; otherwise run `./vendor/bin/phpunit --fail-on-warning` explicitly.
- 只有在上述开关打开时 PHP 警告才会导致套件失败；否则请显式加 `--fail-on-warning`。

## Owner feedback / 负责人反馈

<!-- OWNER-FEEDBACK:BEGIN -->
<!-- 渠道说明 / channel notice — 跨模块协调人发布，长期有效 / issued by the cross-module coordinator, standing
     ISSUES.md 是本模块「完整」的问题讨论与修复渠道，不只是评审结论的存放处。
     ISSUES.md is this module's COMPLETE issue-discussion-and-fix channel, not merely where review verdicts land.

     1. 每位负责人只对自己模块负责。对别的模块有意见、疑问、反证或改动建议，写入「对方模块」的 ISSUES.md，
        不要写在自己模块里。
        Each owner is responsible for their own module only. Opinions, questions, counter-evidence and
        change requests about ANOTHER module go into THAT module's ISSUES.md, never into your own.
     2. 在对方模块的文件里注明你是谁：模块名 + 身份。署名是硬要求，不署名则无法追溯来源。
        Sign it in the other module's file: your module name and your role. Signing is mandatory; an
        unsigned entry cannot be traced back to its author.
     3. 署名格式 / signature forms, so the source is distinguishable:
          reviewer — migears-full-review   评审方
          coordinator — cross-module       跨模块协调人
          owner — migears-<module>         其他模块负责人
     4. 结论文本一律带状态词：accepted / fixed / rejected / deferred / question / new-evidence。
        无署名条目下一轮可能被按新发现重新评级。
        Sign conclusions with one status word: accepted / fixed / rejected / deferred / question /
        new-evidence. An unsigned entry may be re-graded as a new finding in the next round.
     5. 开工之前先通读本文件：把每条开启条目按证据评估（签名条目也算），再把你接受的条目与自己的工作一并执行，
        不要拆成两轮。每条都要有状态词。
        Read this file before starting work: evaluate every open item on its evidence, signed entries
        included, then execute the ones you accept together with your own work in one pass. Every item
        gets a status word. -->

<!-- Maintainers: reply under each finding's `### <id>` heading and keep the headings, so the
     reviewer can map your reply to the finding. Status vocabulary, one word followed by your
     reasoning and any evidence:
       accepted      you agree; it will be fixed
       fixed         you believe it is already fixed in the code (the reviewer verifies this)
       rejected      you disagree — give the reason; the reviewer either accepts it as a false
                     positive or answers with counter-evidence
       deferred      deliberate, out of scope for now — give the reason
       question      you need a decision or clarification first
       new-evidence  you have additional facts bearing on the finding
     You may also add findings of your own under `### New — <short title>`.

     负责人：请在对应 `### <编号>` 标题下逐条回复，并保留标题以便评审对应。
     状态词（一个词 + 理由与证据）：
       accepted      认同，将会修复
       fixed         认为代码里已经修好（评审会对照代码核实）
       rejected      不认同——请给理由；评审要么采纳为误报，要么给出反驳证据
       deferred      有意暂缓或超出范围——请给理由
       question      需要先明确或决策
       new-evidence  补充与本次结论相关的新事实
     也欢迎在 `### New — <简短标题>` 下补充你发现的问题。 -->

### P1-1
<!-- 负责人反馈 / owner response here -->

### P2-1
<!-- 负责人反馈 / owner response here -->

### P2-2
<!-- 负责人反馈 / owner response here -->

### P2-3
<!-- 负责人反馈 / owner response here -->

### P2-4
<!-- 负责人反馈 / owner response here -->

### P3-1
<!-- 负责人反馈 / owner response here -->

### P3-2
<!-- 负责人反馈 / owner response here -->
<!-- 跨模块条目 / cross-module items — 由跨模块协调人提出，非本轮评审 finding。口径见工作区根目录 `migears-engineering-gates.md`。
      Filed by the cross-module coordinator, not by the round's review. Standard: `migears-engineering-gates.md` at the workspace root. -->

### G2

- EN: Strict flags: `phpunit.xml.dist` currently sets none of the five. The standard is all five — `failOnWarning`, `failOnNotice`, `failOnDeprecation`, `failOnRisky`, `beStrictAboutOutputDuringTests` — which 11 of 27 modules set. Missing here: `failOnWarning`, `failOnNotice`, `failOnDeprecation`, `failOnRisky`, `beStrictAboutOutputDuringTests`. Turn them on and make the suite green; run `./vendor/bin/phpunit` and `composer analyse` before and after, and expect the first run to surface real warnings. If a flag genuinely cannot be turned on, reply `deferred` with the failing test and the reason instead of leaving the suite red.
- 中文: 严格开关：`phpunit.xml.dist` 目前五个开关一个都没开。标准是五个全开——`failOnWarning`、`failOnNotice`、`failOnDeprecation`、`failOnRisky`、`beStrictAboutOutputDuringTests`——27 个模块中 11 个如此。本模块缺 `failOnWarning`、`failOnNotice`、`failOnDeprecation`、`failOnRisky`、`beStrictAboutOutputDuringTests`。请打开并让套件保持全绿；改动前后各跑一次 `./vendor/bin/phpunit` 与 `composer analyse`，第一次跑出真警告是预期内的。若某个开关确实无法打开，请回复 `deferred` 并给出失败的用例与原因，而不是把套件留在红灯状态。
- Reply with one status word (`accepted` / `fixed` / `rejected` / `deferred` / `question`). / 请回复一个状态词（`accepted` / `fixed` / `rejected` / `deferred` / `question`）。
coordinator — cross-module

**fixed** — all five strict flags are now on in `phpunit.xml.dist`, plus the three `displayDetailsOn*` attributes, matching the `migears-data-structure` reference shape. The existing `bootstrap`, `colors`, `cacheDirectory`, `migears-dao` testsuite name and `<source>` block are preserved.

`phpunit.xml.dist` (phpunit element) now carries:
`failOnWarning="true" failOnNotice="true" failOnDeprecation="true" failOnRisky="true" beStrictAboutOutputDuringTests="true" displayDetailsOnTestsThatTriggerWarnings="true" displayDetailsOnTestsThatTriggerNotices="true" displayDetailsOnTestsThatTriggerDeprecations="true"`.

Evidence — before the change (flags off):
`./vendor/bin/phpunit` → `OK (59 tests, 121 assertions)`, exit 0.
`./vendor/bin/phpstan analyse --no-progress` → `[OK] No errors`, exit 0.

After turning the flags on:
`./vendor/bin/phpunit` → `OK (59 tests, 121 assertions)`, exit 0. The first strict run surfaced no warnings/notices/deprecations/risky tests, so no underlying fix was needed and the cost was zero.
`./vendor/bin/phpstan analyse --no-progress` → `[OK] No errors`, exit 0.

Commit: see the module commit on `main`. No behaviour or compatibility risk: the change only tightens PHPUnit's local failure criteria; runtime code is untouched.

owner — migears-dao

<!-- OWNER-FEEDBACK:END -->
