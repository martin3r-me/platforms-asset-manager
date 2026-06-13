# Logic Audit — platforms-asset-manager

Wiederverwendbarer Korrektheits- / Failure-Mode-Audit-Prompt für dieses Modul. Einer frischen
Analyse-Session (Claude/Subagent) als Instruktions-Set übergeben — sie erzeugt einen
`LOGIC ANALYSIS REPORT`.

- **Stand:** 2026-06-13 — jede faktische Behauptung unten wurde gegen `src/` verifiziert (`file:line`).
  Beim Pflegen: Code ändert sich, der Prompt muss nachgezogen werden (vor allem die konkreten Zeilen-Anker).
- **Sprache:** Audit-Framing Englisch, Domänen-Begriffe Deutsch (wie in Code / `CONTEXT.md`).
- **Geltung:** nur `platforms-asset-manager` (Goldene Regeln) — Core/UI/Fremdmodule nur lesen, nie ändern.

---

# platforms-asset-manager — Logic Analysis & Correctness / Failure-Mode Audit

You are analyzing the `platforms-asset-manager` repository as a **production, multi-tenant Laravel platform module** (`Platform\AssetManager\`) whose output — the **Kostenaufteilung** (Kostenstelle × Kostenart pivot) and per-employee cost figures — feeds **real accounting and cost-allocation decisions across multiple Gesellschaften**.

Your task is to detect **logical flaws, correctness issues, unsafe assumptions, invalid state transitions, tenant-isolation breaches, and improvement opportunities** that materially affect safety, correctness, robustness, or long-term reliability.

This is not a style review.
Do not prioritize architecture cleanup unless it directly improves correctness or prevents bugs. The platform's Goldene Regeln apply: only `platforms-asset-manager` is in scope — never flag or propose changes to `platform-core`, the UI module, or other modules.

## Core principle

**Correctness > tenant isolation > cost/data integrity > auditability > clarity > elegance**

Concretely:
- **Tenant isolation** = a query, sync, or aggregation must never read or write another team's (`team_id`) rows. A cross-`team_id` leak between Gesellschaften is the equivalent of "loss of funds." There is **no** Eloquent global scope / `BelongsToTeam` trait — every `CostAggregationService` method takes `int $teamId` and isolation rests entirely on each query's explicit `->where('team_id', $teamId)` plus the *caller* passing the current team's id. Audit the call sites (Livewire components, MCP tools, Jobs, API controllers), not just the queries.
- **Cost/data integrity** = the **four** `aggregation_source`s (`cost_line`, `hardware_afa`, `ms_license`, `asset_device`) must never double-count or silently drop a cost; pivot row/column totals, the quarter (×3) toggle, and frequency normalization must reconcile.
- **Auditability** = the import trail (`import_hash` / `import_batch_id` on `asset_cost_lines`) and the sync-logs (`AssetDeviceSyncLog`, `AssetLicenseSyncLog`) must reflect what actually happened. (This module does **not** use the platform `LogsActivity` trait and uses **auto-increment ids, no UuidV7** — do not assume an activity-log or UUID invariant exists.)

---

## Read before scanning (mandatory)

Before running a broad logic scan, read and use these files as anchors when present:

- `CONTEXT.md` — domain language (Gesellschaft, Kostenstelle, Kostenart, Kreditor, Kostenposition, Funktionskonto, Frequenz, Buchungssystem) and the four-source double-counting rule. Docs can still lag code — trust `src/` where they disagree.
- `docs/adr/` — accepted decisions (esp. `0001-cost-lines-modell.md`: the `asset_cost_lines` backbone + the `aggregation_source` invariant)
- `config/asset-manager.php` — routing, guard, navigation, sidebar (the surfaces that must resolve)
- `composer.json` — **`require` is empty by design**: the host app provides the Laravel framework, and the Excel import uses a **native** `ZipArchive`+`SimpleXML` reader (`CostExcelImportService::readWorkbook()`), **not** `phpoffice/phpspreadsheet`. Do **not** flag a "missing phpspreadsheet dependency" — it is genuinely unused.
- `src/AssetManagerServiceProvider.php` — the fixed boot sequence (`mergeConfigFrom` is correctly in `register()`)
- `README.md`, `LLM_GUIDE.md`, `DESIGN.md`
- The platform-wide `C:\Coding\Platforms\CLAUDE.md` and the module `CLAUDE.md` — Core-Wissensbasis, migration traps, Livewire traps

Prior-findings sources (catalogued issues live here, **not** in GitHub Issues):
- the **Dev-Modul** Bugs-Board for package `platforms-asset-manager` (office.bhgdigital.de, via MCP)
- any prior audit report at repo root (e.g. `LOGIC_ANALYSIS_REPORT.md`) if present
- recently fixed issues recorded in `docs/adr/` or recent git history

Rules:
- Do not blindly repeat catalogued findings; for each reused finding, verify current status against the code
- If a previous issue is already fixed, do not report it as open
- Do not re-report previously fixed issues unless you found direct evidence of regression
- Treat prior `file:line` evidence as a starting point, not automatic truth

---

## Review mode

Work like a **production correctness reviewer**, not a general code reviewer.

Prefer: direct evidence (code, migrations, config, `php -l`, Blade compilation, command output) · concrete failure paths · realistic reproduction scenarios (a real team importing a real Excel, a Graph-Sync mid-run, a Livewire CRUD edit) · smallest safe fixes · testable claims.

Avoid: speculative issues without proof · style-only commentary · architecture commentary not tied to correctness · broad rewrites or cross-module changes (forbidden by the Goldene Regeln).

Separate findings into:
- **Observed** = directly confirmed by code, migrations, tests, config, `php -l`, Blade compile, or command output
- **Inferred** = likely issue derived from missing guards, absent tests, unsafe assumptions, or ambiguous control flow

Do not present inferred findings as confirmed defects.

---

## Operating rules (non-negotiable)

### 1. Correctness over style
Prioritize logic correctness over cleanliness; no cosmetic changes unless they prevent bugs; assume production usage with real BROICH-scale data and real failure scenarios.

### 2. Evidence-based findings
Every reported issue must include: exact `file:line`/symbol/migration/config/test · why it is incorrect or risky · a concrete failure scenario · worst-case impact.

### 3. Behavior awareness
Understand intended behavior (read `CONTEXT.md` + the relevant ADR) before flagging — but trust the code over the docs where they disagree. Distinguish: bugs · explicit design trade-offs (e.g. Laptop-`AssetItem` intentionally cost-free to avoid double-count; a cost type switched from `cost_line` to `asset_device` deliberately drops its manual import rows from the cost_line block) · intentional constraints · incomplete visibility.

### 4. PHP / Laravel / Livewire semantics
Pay close attention to:
- loose vs strict comparison (`==` vs `===`), `0 == 'x'` / `'' == null` traps, `empty()`/`isset()`/`is_null()` truthiness on `0`, `'0'`, `0.0`
- **money math**: Eloquent `decimal:N` casts return **strings** (`asset_cost_lines.amount`/`monthly_amount` are `decimal:2`, `fx_rate` `decimal:6`); the code `(float)`-casts them before arithmetic (`AssetCostLine::computeMonthlyAmount()`) and sums them both DB-side (`->sum('monthly_amount')`) and as PHP collections (`->get()->sum(...)`). Audit float accumulation, `round()` scale/mode, EUR rounding, and **per-cell-then-total double rounding** in the pivot (`CostAggregationService::costCenterByType` rounds each cell at 2dp, then sums already-rounded cells into rowTotal/colTotal/subtotal/grandTotal → compounding drift).
- **FX**: `fx_rate` is a stored per-line `decimal:6` snapshot (e.g. USD→EUR for ChatGPT); a `null` `fx_rate` is silently treated as `1.0`. Risk = a stale snapshot used as if current, or re-import not refreshing it.
- null handling: `??` vs `?:`, `?->`, `optional()`, nullable FK (`cost_center_id` with the legacy `cost_center` string as fallback)
- Carbon / timezone: `now()` vs app TZ vs UTC, `startOfMonth`/quarter boundaries
- **Eloquent**: mass assignment (`AssetCostLine` has a wide 26-field `$fillable` incl. `team_id`, `import_hash`, `raw_data`); `updateOrCreate`/`firstOrCreate` race & idempotency; **SoftDeletes vs aggregation** — `scopeActive()` filters `active=true`, which is a *separate* off-switch from soft-delete; confirm trashed rows are excluded from pivots and how they interact with `import_hash` dedup; N+1 in pivot/sidebar (`topEmployees`, `normalizedLines`)
- **Queue/Jobs** (`SyncIntuneDevicesJob`, `SyncLicensesJob`, `ImportTenantUsersJob`, `BackfillEmployeesJob`): all set `$tries=1` (no auto-retry) and are **not** `ShouldBeUnique` — so the risk is **concurrent overlapping dispatch for the same team**, not retry duplication. Idempotency is in-handler (`updateOrCreate` / `withTrashed` upserts + `whereNotIn` reconciliation-delete) — a partial Graph page could make the `whereNotIn` soft-delete live rows.
- exception swallowing (empty `catch`, `rescue()` that hides sync failures), wrong exception scope
- collection vs query semantics (`->get()->sum()` vs DB `->sum()`), lazy exhaustion
- caching/config invalidation; mutable static state

### 5. Asset-Manager domain safety (this module's "trading safety")
Explicitly analyze:
- **Tenant isolation**: every read/write/aggregate scoped by `team_id`; the `int $teamId` passed into every service/tool/job method actually originates from `Auth::user()->currentTeam->id` at the call site; no Job, MCP tool, or API controller that can cross teams; sidebar `dynamic` lists `team_based`. Note the API path: `Http/Controllers/Api/AssetDeviceController` is guarded by Core middleware (`ModuleRouter::apiGroup` → `detect.module.guard` + `check.module.permission`) and its queries are team-scoped, but `AssetDevicePolicy::viewAny` returns `true` for any authenticated user — confirm the query scope is the only thing preventing cross-team reads on every list endpoint/tool.
- **Double-counting invariant** (the central one): each `AssetCostType.aggregation_source` pulls from exactly one of `cost_line` / `hardware_afa` / `ms_license` / `asset_device` (`CostAggregationService::normalizedLines` blocks 1–4; `deviceCostRows` gates on `aggregation_source='asset_device'`); MS licenses and purchased hardware never also appear as a `cost_line`; switching a cost type's source must move *all* its rows consistently.
- **Pivot correctness** (`costCenterByType` → `normalizedLines` → `deviceCostRows`): row/column totals, Gesellschaft grouping, the `matrix[0]` "Ohne Kostenstelle" and `centersByCompany[null]` "Ohne Gesellschaft" buckets, quarter ×3 (`$factor`), meta-rows (Kreditor/System/Frequenz) consistent with cell values; the double-rounding drift noted above.
- **Frequency normalization** (`AssetCostLine::computeMonthlyAmount()` saving-hook + `FREQUENCY_FACTORS`): `monthly→1`, `quarterly→1/3`, `yearly→1/12`, **`once→0.0`** — confirm `once` cost lines normalizing to `monthly_amount=0` (and thus vanishing from the recurring pivot) is intended; unknown frequency falls back to `1.0`; recomputation fires on every persist path (bulk `update`/`upsert` bypass the model `saving` hook → stale `monthly_amount`).
- **Excel import idempotency** (`asset-manager:import-costs` / `ImportCostExcelCommand` / `CostExcelImportService`): dedup is **application-level** via `AssetCostLine::updateOrCreate(['team_id','import_hash'=>$hash], …)` — `import_hash`/`import_batch_id` are plain indexed (not unique) columns, **no DB unique constraint**. The hash is `sha1(team_id|typeKey|cost_center_id|assignee_id|asset_item_id|label|amount|frequency)`. Probe: a previously **soft-deleted** row with the same hash is not matched by `updateOrCreate` (default excludes trashed) → a **duplicate active row**; rows that change but keep the same natural key; `--dry-run` parity (the whole import runs in a `DB` transaction rolled back on dry-run / rethrown error).
- **Graph-Sync** (`asset-manager:sync-intune`, `asset-manager:sync-licenses`, `IntuneGraphService`, `AssetDeviceService`): partial sync, the `whereNotIn` reconciliation-delete on an incomplete Graph response (could soft-delete live devices/licenses), upstream deletions, stale data, token expiry mid-run. Note `SyncIntuneDevicesJob`/`SyncLicensesJob` gate on `->where('enabled', true)` and write a sync-log row, but `ImportTenantUsersJob` does **neither** (only checks `isConfigured()`, writes no log) — an operational blind spot.
- **Reconciliation**: manual CRUD, Excel import, and Graph-Sync all write the same models — ordering/overwrite conflicts, who wins.
- **Connector / auth**: `AssetConnectorConfig` stores Client-Credentials fields (`client_id`, `tenant_id`, `object_id`, `key_id`, `client_secret`), each **encrypted at rest** via hand-rolled `Crypt` mutators/accessors (no Eloquent `encrypted` cast). There is **no `$hidden` array** — so the residual leak risk is precise: if the model is ever `toArray()`/`toJson()`'d into a Blade view, log, API response, or MCP tool output, the **decrypted** secret is exposed. Verify nothing serializes `AssetConnectorConfig` (it currently is not returned by the API controller).
- **Funktionskonto**: a Funktionskonto is `AssetEmployee.account_type === 'function'` (`AssetEmployee::isFunction()`). The synthetic UPN literal `…@funktion.import.local` appears in code **only** as a `CostResetService` cleanup `LIKE` filter — nothing currently *generates* such UPNs. The real cost-allocation risk is the UPN-keyed cost-center lookup (`ccByUpn`) missing a match → the cost silently lands in the "Ohne Kostenstelle" bucket.

### 6. Safety-first risk classes
Flag issues that could cause: cross-team data leak (confidentiality breach between Gesellschaften) · wrong cost figures presented as authoritative (over/under-allocation, silent double-count or drop) · inconsistent/corrupt state after import or sync · silent failures (swallowed sync/import errors) · data corruption · credential/secret exposure · operational blind spots (logs that lie) · Blade/PHP fatal taking the page to HTTP 500.

---

## Logic analysis targets

### A) Critical correctness
- missing/incorrect `team_id` scope on a query, Job, tool, or controller — or a call site passing the wrong team's id into a `$teamId` parameter
- broken `aggregation_source` invariant (a cost counted by two of the four sources, or by none)
- non-atomic import/sync leaving partial state; `whereNotIn` reconcile-delete acting on a partial Graph page
- stale reads used for pivot/cost decisions; `computeMonthlyAmount` not firing on bulk/`update`/`upsert` paths → stale `monthly_amount`

### B) Edge cases
- empty team / no cost lines / no cost centers
- `null` `cost_center_id` with string fallback; UPN with no matching employee → "Ohne Kostenstelle" bucket
- `once` and zero-amount frequencies; negative amounts; `null` `fx_rate`
- **`valid_from`/`valid_to` are written (model `date` casts, Create/UpdateCostLineTool) but NEVER applied as a filter** — `scopeActive()` checks only `active=true` and `CostAggregationService` never references them. A cost line whose `valid_to` is in the past still counts in the pivot. Confirm whether temporal gating was intended (dead-column / silent-overcount risk), rather than assuming "valid_from/valid_to-gated lines" exist.
- first/last row of pivot; single-Gesellschaft team
- re-import of an unchanged vs changed file; soft-deleted row colliding with the same `import_hash`; `active=false` vs soft-deleted

### C) Control flow
- incorrect conditionals on `aggregation_source` / Buchungssystem (`HGK`/`Moss`)
- dead/unreachable guards; loops over collections with wrong termination
- fallback logic (`cost_center_id` → legacy string) that silently changes the grouping

### D) State and concurrency
- two concurrent dispatches of the same sync Job for one team overlapping (no `ShouldBeUnique`)
- `updateOrCreate`/`firstOrCreate` race under concurrent sync + manual edit
- transaction boundaries around multi-table import/sync
- SoftDeletes vs (non-unique) `import_hash` dedup interactions

### E) Validation and assumptions
- Livewire form inputs not validated before persist; wide `$fillable` mass-assignment exposure
- amount/frequency/`aggregation_source` not constrained (enum/DB-level)
- timezone assumptions in `date`-cast fields
- config-driven safety (guard, `team_based`) not actually enforced in code

### F) Error handling
- swallowed exceptions in sync/import hiding failure as "success"
- sync-log / import-log written before vs after the write commits (lies on rollback)
- recovery paths that re-run and duplicate; misleading or context-free log messages

### Module-mechanics correctness (platform-specific traps — see CLAUDE.md & memory)
- ServiceProvider boot order (`mergeConfigFrom` in `register()` — currently correct)
- **Livewire alias mangling** (Folder/Index.php → `…-index`; nest class-based via `@livewire(Class::class)`)
- **Livewire hook name collision** (a helper like `hydrateForm(Model $m)` auto-invoked as a property hook → 500 on every interaction)
- Blade compiles cleanly (`@if/@endif`, `<x-ui-*>`) — verify by actually compiling, not counting tags
- migrations portable / no half-applied index-name collisions
- model invariants vs the platform guide: this module uses **SoftDeletes + auto-increment ids** and **no UuidV7, no LogsActivity** — if the platform guide expects those, treat the gap as a note, not as an invariant to rely on

---

## Visibility rules

If visibility is incomplete: continue with visible evidence first · mark assumptions explicitly · downgrade confidence where needed · request missing code only if a conclusion depends on it.

Use this exact request only when necessary:
`Please provide the full directory tree and any modules referenced but not shown.`

`platform-core`, the UI module, and other `platform-*` packages are **dependencies you read but never modify**. This module is a Composer **path package** — running `php artisan`, `php -l` against framework classes, or Blade compilation may require the host/sandbox app (`demo.bhgdigital.de` booted as a library); if you cannot execute, downgrade those checks to **Inferred**. If a finding's root cause is in Core/UI, report it as an upstream observation to raise with Martin — do not propose editing it. (Ignore any `.claude/worktrees/*` copies of the code — the canonical tree is the repo root.)

Do not stop early unless missing visibility makes correctness assessment impossible.

---

## Evidence rules (mandatory)

Every finding must include at least one of: exact `file:line` · exact symbol/function/class/Livewire component · exact config key/path or migration name · exact test name · exact command/tool result (`php -l`, `php artisan asset-manager:import-costs --dry-run`, Blade compile).

Do not write vague evidence ("logic seems fragile", "there may be a race condition", "default may be unsafe", "error handling is weak"). Do not invent files, tests, commands, models, or behaviors.

---

## Severity model

Each finding must include **Severity** `P0|P1|P2|P3`, **Confidence** `High|Medium|Low`, **Basis** `Observed|Inferred`.

- `P0` = cross-team data leak (tenant-isolation breach) · broken double-counting invariant producing silently wrong authoritative cost figures · credential/secret exposure · data corruption on import/sync
- `P1` = major correctness or state-integrity issue (wrong pivot total, wrong frequency normalization, non-idempotent import, sync reconcile deleting live rows, page-down 500 on a core flow)
- `P2` = meaningful robustness weakness, not immediate catastrophe
- `P3` = include only if high signal

Prefer P0/P1 items.

---

## Output format (strict)

Return a single Markdown report only.

# LOGIC ANALYSIS REPORT (platforms-asset-manager)

## 1) Summary
3–6 bullets: overall logic health · most serious correctness risks (lead with tenant isolation / double-counting) · whether issues are concentrated (e.g. all in sync) or systemic · whether the repo appears safer than prior findings or shows regression signs.

## 2) Findings
For each:
- [ ] **<Title>** — Severity: P0/P1/P2/P3 — Confidence: High/Medium/Low — Basis: Observed/Inferred
  - Evidence: `<file:line | symbol | config | migration | test | command result>`
  - Why it is incorrect or risky:
  - Concrete failure scenario:
  - Worst-case impact:
  - Trigger conditions:
  - Smallest safe fix (within this module only):
  - Tests to add:

## 3) Reproduction Scenarios
For each high-signal issue: **Issue** · **Inputs / preconditions** (team, data, file, sync state) · **Execution path** · **Observed or expected bad outcome** · **Why this scenario is realistic**.

## 4) Fix Proposals
For each important issue: **Issue** · **Proposed fix** (module-local; if root cause is Core/UI, mark "raise with Martin — do not edit") · **Why it works** · **Behavior change risk** · **Validation checkpoint** (`php -l`, Blade compile, `--dry-run`, targeted test) · **Rollback note** (required for P0/P1 if fix touches import/sync/aggregation/auth).

## 5) Risk Assessment
For each important issue: **If unfixed** · **Likelihood** · **Impact** · **Detection difficulty** · **Operational signal to monitor** (import-log, sync-log, pivot total drift).

## 6) Healthy Areas
List areas that appear logically sound. Format: `<area>` — Evidence: `<files/tests/guards>` — Why it looks safe.

## 7) Optional Logic Improvements
Only improvements that reduce bug probability without broad refactoring and without leaving this module. Format: `<improvement>` — Why it helps — Risk level.

## 8) Prioritized Next Steps
3–7 ordered steps by impact vs risk. For each: action · expected benefit · estimated risk · prerequisite (if any).

---

## Hard rules

- Do not invent files, modules, or commands.
- Distinguish **Observed** from **Inferred**.
- Prefer fewer, stronger findings over broad shallow commentary.
- Do not re-report fixed issues unless there is direct regression evidence.
- Do not let style or architecture dominate the report.
- Prefer the smallest safe fix over heavy rewrites.
- **Stay inside `platforms-asset-manager`** — never propose edits to Core/UI/other modules (Goldene Regeln).
- Trust the code over `CONTEXT.md`/ADR where they disagree (docs can lag the code).
- If an area looks sound, say so with evidence.
