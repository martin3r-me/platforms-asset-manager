# Logic Audit — platforms-asset-manager

Wiederverwendbarer Korrektheits- / Failure-Mode-Audit-Prompt für dieses Modul. Einer frischen
Analyse-Session (Claude/Subagent) als Instruktions-Set übergeben — sie erzeugt einen
`LOGIC ANALYSIS REPORT`.

- **Stand 2026-10-19** — jede faktische Behauptung unten wurde gegen `src/` verifiziert (`file:line`).
  Erstfassung 2026-06-13; 2026-10-19 nachgezogen auf Tenant-Grenze (ADR 0016), Asset-Träger (ADR 0017),
  `ShouldBeUnique`-Sync-Jobs, `import_hash`-Unique-Index, `validOn()`-Gating, Kostenstellen-Baum.
  Beim Pflegen: Code ändert sich, der Prompt muss nachgezogen werden (vor allem die konkreten Zeilen-Anker).
- **Sprache:** Audit-Framing Englisch, Domänen-Begriffe Deutsch (wie in Code / `CONTEXT.md`).
- **Geltung:** nur `platforms-asset-manager` (Goldene Regeln) — Core/UI/Fremdmodule nur lesen, nie ändern.

---

# platforms-asset-manager — Logic Analysis & Correctness / Failure-Mode Audit

You are analyzing the `platforms-asset-manager` repository as a **production, multi-tenant Laravel platform module** (`Platform\AssetManager\`) whose output — the **Kostenaufteilung** (Kostenstelle × Kostenart pivot), per-holder (Asset-Träger) cost figures, the easybill **Weiterberechnung** and the **DATEV-Umbuchung** — feeds **real accounting, invoicing and cost-allocation decisions for multiple customers (Tenants) and their Gesellschaften** (the root nodes of each tenant's cost-center tree).

Your task is to detect **logical flaws, correctness issues, unsafe assumptions, invalid state transitions, tenant-isolation breaches, and improvement opportunities** that materially affect safety, correctness, robustness, or long-term reliability.

This is not a style review.
Do not prioritize architecture cleanup unless it directly improves correctness or prevents bugs. The platform's Goldene Regeln apply: only `platforms-asset-manager` is in scope — never flag or propose changes to `platform-core`, the UI module, or other modules.

## Core principle

**Correctness > tenant isolation > cost/data integrity > auditability > clarity > elegance**

Concretely:
- **Tenant isolation** = a query, sync, or aggregation must never read or write rows across either of the **two** boundaries (ADR 0016, `CONTEXT.md` → Schreibrechte/Aktiver Tenant):
  - **Team** (`team_id`) — the outer security boundary. There is **no** team-level global scope / `BelongsToTeam` trait: team isolation still rests on each query's explicit `->where('team_id', $teamId)` plus the *caller* passing the current team's id (`Concerns\ResolvesCurrentTeam::teamId()`, `Tools\Concerns\ResolvesTeam`). Show pages assert it via `Concerns\GuardsTenantBoundary::assertTenantBoundary()` (foreign team → 403).
  - **Tenant** (`tenant_id`, NOT NULL) — the inner access boundary, enforced **centrally** by a **Global Scope**: `Concerns\TenantScopable` (`src/Concerns/TenantScopable.php:25`) registers `Support\TenantScope` on the tenant-bound models (Stand 2026-10-19: 25 of 33 in `src/Models`; `tests/local/tenant-isolation.php` checks every model carrying the trait generically, #901). **Not** scoped: `AssetTenant`, `AssetTenantSelection` (they *are* the tenant / its selection), `AssetCategory` (global catalogue), `AssetDeviceModel` (team-wide catalogue; its costs in `AssetDeviceModelCost` are scoped), `AssetTableView` (per user, no `tenant_id`) — and `AssetConnectorConfig`, `AssetTeamSetting`, `AssetDeviceSource`, which carry/derive a tenant but are filtered **explicitly**; check those call sites by hand. Foreign tenant on a show page → 404, not 403.
  - The scope is **not** watertight by itself — the risk paths are its **exits**: (a) the explicit `withoutTenantScope()` (`TenantScopable.php:29`; ~115 call sites, mostly Services/Jobs — each must re-filter with `->forTenant($id)` / `where('tenant_id', …)`); (b) `TenantScope::apply()` (`src/Support/TenantScope.php:28`) is a **no-op** whenever `TenantContext::scopeTenantId()` (`src/Services/TenantContext.php:51`) returns `null` — no auth user, no current team, or an explicit `runFor(null)` (the "Alle Tenants" report mode, `Concerns\ReportsAcrossTenants::inReportScope()`) — so Jobs, Console commands and cross-tenant reports are unscoped unless they pin the tenant; (c) the **static, mutable** override `TenantContext::forceTenant()` / `runFor()` (`:98`, `:122`) — Jobs call `clearOverride()` then `forceTenant()`, MCP tools set it per call (`Tools\Concerns\ResolvesTenant`); a missing reset in a long-running queue worker or a `forceTenant()` in a request/Livewire path silently mis-scopes. Audit those exits and the call sites (Livewire components, MCP tools, Jobs, Commands, API controller), not just the queries.
  A cross-team or cross-tenant leak between customers is the equivalent of "loss of funds."
- **Cost/data integrity** = the **four** `aggregation_source`s (`cost_line`, `hardware_afa`, `ms_license`, `asset_device` — `AssetCostType::SOURCES`) must never double-count or silently drop a cost; pivot own/rollup cells, row/column totals, the quarter (×3) toggle, and frequency normalization must reconcile. Billing quantities (Weiterberechnung) and DATEV postings derived from the same data must reconcile with it too.
- **Auditability** = the import trail (`import_hash` / `import_batch_id` on `asset_cost_lines`), the import protocol (`AssetImportRun` via `Support\ImportRunRecorder`, sources `excel`/`vodafone`/`vodafone_fixednet`/`license_invoice`/`telephony_seats`/`directory`), the sync-logs (`AssetDeviceSyncLog`, `AssetLicenseSyncLog`), the device lifecycle events (`AssetDeviceEvent`), and the revision trails `AssetBillingRun` (sent to easybill) / `AssetDatevBatch` (handed to DATEV) must reflect what actually happened. (This module does **not** use the platform `LogsActivity` trait and uses **auto-increment ids, no UuidV7** — do not assume an activity-log or UUID invariant exists.)

---

## Read before scanning (mandatory)

Before running a broad logic scan, read and use these files as anchors when present:

- `CONTEXT.md` — domain language (Tenant, Connector, Asset-Träger + Träger-Typ, Kostenstelle as a tree whose root nodes are the Gesellschaften, Kostenart, Kreditor, Kostenposition, Funktionskonto, Frequenz, Buchungssystem, Weiterberechnung) and the four-source double-counting rule. Docs can still lag code — trust `src/` where they disagree.
- `docs/adr/` — accepted decisions, esp. `0001` (the `asset_cost_lines` backbone + the `aggregation_source` invariant), `0016` (Tenant = access boundary, Global Scope), `0017` (Asset-Träger replaces Mitarbeiter), `0018`/`0026` (Vodafone invoice = only source for Mobilfunk / Festnetz+RingCentral), `0019` (license prices on the contract line), `0021`/`0024`/`0025` (Weiterberechnung counting rule, exceptions, document date), `0023` (retired holders are deactivated, never deleted), `0027` (DATEV reallocation from monthly invoice snapshots)
- `config/asset-manager.php` — routing, guard, central Azure app (`azure.*`), navigation, sidebar (the surfaces that must resolve)
- `composer.json` — `require` holds only `php`, `ext-zip`, `ext-simplexml` and `barryvdh/laravel-dompdf` (handover/billing PDFs); the Laravel framework is **host-provided**. The Excel import uses a **native** `ZipArchive`+`SimpleXML` reader (`Support\XlsxReader`; `CostExcelImportService::readWorkbook()` delegates to it), **not** `phpoffice/phpspreadsheet`. Do **not** flag a "missing phpspreadsheet / missing framework dependency" — it is genuinely unused / host-provided.
- `src/AssetManagerServiceProvider.php` — the fixed boot sequence (`mergeConfigFrom` is correctly in `register()`), the `asset-manager.manage` gate, the scheduler
- `README.md`, `LLM_GUIDE.md`, `DESIGN.md`, `tests/README.md` (what the three test levels already cover — don't propose a test that exists)
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

Prefer: direct evidence (code, migrations, config, `php -l`, Blade compilation, `php tests/guardrails.php`, a `tests/local/*.php` run, command output) · concrete failure paths · realistic reproduction scenarios (a real tenant importing a real invoice or Excel, a Graph-Sync mid-run, a Livewire CRUD edit, an MCP tool call with `tenant_id`) · smallest safe fixes · testable claims.

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
Understand intended behavior (read `CONTEXT.md` + the relevant ADR) before flagging — but trust the code over the docs where they disagree. Distinguish: bugs · explicit design trade-offs (e.g. an `AssetItem` that carries its own cost line is deliberately **not** also counted as `hardware_afa` — `CostAggregationService::itemIdsWithCostLine()`, #952; a cost type switched from `cost_line` to `asset_device` deliberately drops its manual import rows from the cost_line block — `CostAggregationService.php:482`; retired holders are deactivated, never deleted — ADR 0023) · intentional constraints · incomplete visibility.

### 4. PHP / Laravel / Livewire semantics
Pay close attention to:
- loose vs strict comparison (`==` vs `===`), `0 == 'x'` / `'' == null` traps, `empty()`/`isset()`/`is_null()` truthiness on `0`, `'0'`, `0.0`
- **money math**: Eloquent `decimal:N` casts return **strings** (`asset_cost_lines.amount`/`monthly_amount` are `decimal:2`, `fx_rate` `decimal:6`); the code `(float)`-casts them before arithmetic (`AssetCostLine::computeMonthlyAmount()`, `src/Models/AssetCostLine.php:85`) and sums them both DB-side (`->sum('monthly_amount')`) and as PHP collections (`->get()->sum(...)`). Audit float accumulation, `round()` scale/mode, EUR rounding, and **per-cell-then-total double rounding** in the pivot (`CostAggregationService::buildCostCenterPivot()` — each own cell is rounded at 2dp (`$own = round(…)`), rollups sum already-rounded descendant cells (`$sum += round(…)`), `colTotals`/`grandTotal` sum already-rounded own cells → compounding drift). Billing amounts cross into easybill as **integer cents** (ADR 0021) — a euro value at a cent interface is off by ×100.
- **FX**: `fx_rate` is a stored per-line `decimal:6` snapshot (e.g. USD→EUR for ChatGPT, ADR 0002); a `null` `fx_rate` is silently treated as `1.0` (`AssetCostLine.php:100`). Risk = a stale snapshot used as if current, or re-import not refreshing it.
- null handling: `??` vs `?:`, `?->`, `optional()`, nullable FK `cost_center_id` (`null` → "ohne Kostenstelle" bucket). `AssetHolder` still carries the legacy free-text `cost_center` string next to `cost_center_id` (read by `Livewire\Costs\Dashboard`, `Livewire\Holders\Show`; cleared by `MasterDataDeletionService` on delete, #966) — the aggregation itself uses only the FK.
- Carbon / timezone: `now()` vs app TZ vs UTC, `startOfMonth`/quarter boundaries, `validOn(now())` day boundaries, Graph timestamps converted to the app timezone (`SyncIntuneDevicesJob::graphDateTime()`, #965), the billing document date = last day of the billed month (ADR 0025)
- **Eloquent**: mass assignment (`AssetCostLine` has a wide 26-field `$fillable` incl. `team_id`, `tenant_id`, `import_hash`, `raw_data`); `updateOrCreate`/`firstOrCreate` race & idempotency; **SoftDeletes vs aggregation** — `scopeActive()` (`AssetCostLine.php:105`) filters `active=true`, which is a *separate* off-switch from soft-delete, and `scopeValidOn()` (`:116`) is a third, temporal one; confirm trashed rows are excluded from pivots and how they interact with `import_hash` dedup; N+1 in pivot/sidebar (`topHolders`, `normalizedLines`; `tests/local/query-budgets.php` (real component/tool/job/import calls at 5 vs 40 rows), `tests/local/n-plus-1.php` and guardrails Check 4–6 already pin the known ones)
- **Queue/Jobs** (`SyncIntuneDevicesJob`, `SyncLicensesJob`, `ImportTenantUsersJob`, `BackfillHoldersJob`): all set `$tries=1` (no auto-retry). The three sync jobs are **`ShouldBeUnique` per connector** (`uniqueId()` = `connectorId`; `SyncIntuneDevicesJob.php:27/57`, `SyncLicensesJob.php:24/41`, `ImportTenantUsersJob.php:21/32` since #954) with a self-releasing lock (`uniqueFor` 600 s / 600 s / 1200 s). Residual risks: a lock that expires while a slow run is still going; two *different* jobs (devices vs. directory vs. licenses) touching the same holders concurrently; `BackfillHoldersJob` is **not** unique. Idempotency is in-handler (`updateOrCreate` / `withTrashed` upserts + `whereNotIn` reconciliation-delete via `Concerns\RunsTeamSync::reconcileDelete()`) — the empty-keyset guards exist (`SyncIntuneDevicesJob.php:303ff`, `SyncLicensesJob.php:270ff`); probe whether a *partial but non-empty* Graph page could still soft-delete live rows.
- exception swallowing (empty `catch`, `rescue()` that hides sync failures), wrong exception scope
- collection vs query semantics (`->get()->sum()` vs DB `->sum()`), lazy exhaustion
- caching/config invalidation; mutable static state

### 5. Asset-Manager domain safety (this module's "trading safety")
Explicitly analyze:
- **Tenant isolation**: every read/write/aggregate scoped by `team_id` (explicit) **and** by tenant (Global Scope or explicit `forTenant()` after `withoutTenantScope()`); the `int $teamId` passed into every service/tool/job method actually originates from `Auth::user()->currentTeam->id` (UI) or the tool context user (MCP) at the call site; MCP tools resolve the tenant via `Tools\Concerns\ResolvesTenant::resolveTenant()` — `"all"` is allowed **only** on reporting tools and must then report the tenant per row (guardrails Check 9 enforces `resolveTenant()` in every tool and the `asset-manager.manage` gate in every writing tool; `tests/local/mcp-tools-smoke.php` calls the tools for real, #976); Jobs pin the connector's tenant (`TenantContext::clearOverride()` + `forceTenant()`, e.g. `SyncIntuneDevicesJob.php:106–107`). Note the API path: `Http/Controllers/Api/AssetDeviceController::index()` is guarded by Core middleware (`ModuleRouter::apiGroup` → `detect.module.guard` + `check.module.permission`), filters `team_id` explicitly and gets the tenant from the Global Scope, but `AssetDevicePolicy::viewAny` (`src/Policies/AssetDevicePolicy.php:12`) returns `true` for any authenticated user — confirm the query scopes are the only thing preventing cross-team/cross-tenant reads on every list endpoint/tool.
- **Double-counting invariant** (the central one): each `AssetCostType.aggregation_source` pulls from exactly one of `cost_line` / `hardware_afa` / `ms_license` / `asset_device` (`CostAggregationService::normalizedLines`, `:393`, blocks 1–4; `deviceCostRows`, `:490`, gates on `aggregation_source='asset_device'`); MS licenses are priced from the contract line via `Support\LicensePriceBook` (`seatRows()` + `unallocatedRows()` for overflow/pool, ADR 0019) and never also appear as a `cost_line`; items with their own cost line are skipped as AfA (`itemIdsWithCostLine()`, #952); the Vodafone imports **replace** the superseded Excel Mobilfunk/Festnetz lines (ADR 0018/0026) rather than adding to them; switching a cost type's source must move *all* its rows consistently.
- **Pivot correctness** (`costCenterByType()` → `buildCostCenterPivot()` → `normalizedLines()` → `deviceCostRows()`): the cost-center **tree** (`AssetCostCenter::treeFor($tenantId)`, root nodes = Gesellschaften, arbitrary depth) — `cells`/`rowTotal` = the node's **own** amounts, `rollupCells`/`rollupTotal` = node + descendants; `colTotals`/`grandTotal` must sum **own** amounts only (summing rollups would count every amount once per ancestor); the `matrix[0]` "ohne Kostenstelle" row (`isset($matrix[0])`) and the `$orphan` "Unbekannte Kostenstelle" row for ids not in the tree; without a tenant (console) the node list falls back to a flat team-wide list; quarter ×3 (`$factor`); meta-rows (Kreditor/System/Frequenz) consistent with cell values; the double-rounding drift noted above. Invariant pinned by `tests/Feature/CostReconciliationTest`: `totalMonthly()['total'] === costCenterByType()['grandTotal']`.
- **Frequency normalization** (`AssetCostLine::computeMonthlyAmount()` saving-hook `AssetCostLine.php:80` + `FREQUENCY_FACTORS` `:67`): `monthly→1`, `quarterly→1/3`, `yearly→1/12`, **`once→0.0`** — confirm `once` cost lines normalizing to `monthly_amount=0` (and thus vanishing from the recurring pivot) is intended; an unknown frequency is logged (`Log::warning`) and falls back to `1.0`; recomputation fires on every persist path (bulk `update`/`upsert` bypass the model `saving` hook → stale `monthly_amount`).
- **Temporal gating**: cost lines count only via `->active()->validOn(now())` (`scopeValidOn`, `AssetCostLine.php:116`; used throughout `CostAggregationService`). Since Bug #930 an importer must **not** write the invoice period as `valid_from`/`valid_to` (an invoice always lies in the past → the whole Mobilfunk silently dropped out; regression `tests/local/vodafone-invoice-validity.php`). Probe every new import/write path for the same mistake, and every aggregation that uses `active()` **without** `validOn()` (e.g. `CostReallocationService::documents()` `:41`, `InvoiceSnapshotService` `:136` — invoice-bound by design; confirm).
- **Excel import idempotency** (`asset-manager:import-costs` / `ImportCostExcelCommand` / `CostExcelImportService`): the hash is `sha1(team_id|typeKey|cost_center_id|assignee_id|asset_item_id|label|amount(4dp)|frequency|currency)` (`CostExcelImportService.php:373`). Dedup is application-level **and** DB-backed: the lookup uses `withTrashed()` (`:408`) and a soft-deleted twin is **refused**, not revived (`:413` — the deliberate UI deletion wins), and a unique index exists — first `(team_id, import_hash)` (`2026_06_13_000011_add_unique_index_to_asset_cost_lines_import_hash.php`), since tenant wave 5 `(tenant_id, import_hash)` (`2026_08_07_000005_tenant_scope_cost_unique_indexes.php`; NULL hashes of manual lines stay distinct). `pruneStaleLines()` (`:438`) hard-deletes this run's unwritten `excel_import` hashes of the **target tenant** with an empty-set guard. Probe: rows that change but keep the same natural key; the target tenant (`--tenant`, default tenant) stamped on every write path; `--dry-run` parity (the whole import runs in a `DB` transaction rolled back on dry-run / rethrown error, `:77`/`:107`/`:114`). Covered end-to-end by `tests/Feature/ExcelImportIdempotencyTest`.
- **Invoice imports** (`VodafoneInvoiceImportService` ADR 0018, `VodafoneFixedNetImportService` + `TelephonySeatImportService` ADR 0026, `LicenseInvoiceImportService` ADR 0019): matching by phone number / UPN / contract line, prune of numbers or seats missing from the new invoice (per invoice account), dry-run writes nothing, the monthly snapshot (`InvoiceSnapshotService`) written only by the real run. Wrong matching = cost on the wrong cost center, i.e. silently wrong figures.
- **Graph-Sync** (`asset-manager:sync-intune` hourly, `asset-manager:import-users` 01:30, `asset-manager:sync-licenses` 02:00; `IntuneGraphService`, `AssetDeviceService`): partial sync, the `whereNotIn` reconciliation-delete on an incomplete Graph response (could soft-delete live devices/licenses), the lifecycle pin (terminal `retired`/`lost`/`defect` devices are exempt from the reconcile-delete — `SyncIntuneDevicesJob.php:308`, ADR 0007), serial-number identity and double-enrollment dedupe (`dedupeBySerial()`, ADR 0006, #945), upstream deletions, stale data, token expiry mid-run, throttling. All three jobs gate on `->where('enabled', true)` + `isConfigured()` + `isConsentConfirmed()` in `dispatchForTeam()`. Devices/licenses write `AssetDeviceSyncLog`/`AssetLicenseSyncLog` (stuck logs are failed in `failed()`); the directory import (`ImportTenantUsersJob`) records an `AssetImportRun` with source `directory` (`ImportRunRecorder`, #954) and is the **only** path that deactivates retired holders (`accountEnabled`, ADR 0023: empty response does nothing, only `source='graph'`, only currently active holders).
- **Reconciliation**: manual CRUD (Livewire), MCP write tools (cost lines via `CostLineWriteService`, devices via `AssetWriteService`, holders via `HolderTypeService` / `BillingExclusionService` plus a direct `save()` of the plain fields in `UpdateHolderTool`), Excel import, invoice imports and Graph-Sync all write the same models — ordering/overwrite conflicts, who wins (e.g. `phone_overridden` protects manual phone numbers from the Entra sync).
- **Connector / auth**: `AssetConnectorConfig` belongs to one `AssetTenant` (`tenant_id` FK) and stores the customer directory `azure_tenant_id` plus optional own app credentials (`client_id`, `object_id`, `key_id`, `client_secret`), each **encrypted at rest** via hand-rolled `Crypt` mutators/accessors (no Eloquent `encrypted` cast); without own credentials the central app from `config('asset-manager.azure.*')` is used. A `$hidden` array now covers all five encrypted fields (`src/Models/AssetConnectorConfig.php:37`), so plain `toArray()`/`toJson()` no longer leaks them — the residual risk is an explicit accessor read (`$config->client_secret`) or `makeVisible()` ending up in a Blade view, log, API response or MCP tool output. Consent is **manual**: `consent_confirmed_at` is set only by "Anbindung prüfen" (`Livewire\Connectors\Index::checkConnection()`, token test) — there is no public callback.
- **Funktionskonto / Träger-Typ**: a Funktionskonto is `AssetHolder.holder_type === 'function'` (`AssetHolder::isFunctionAccount()`; types `person`/`function`/`admin`/`service`/`external`, ADR 0017). `account_type` survives only as a deprecated alias (fillable column + MCP alias field). `HolderClassifier::classify()` sets the type on the Entra sync — precedence: synthetic `…@funktion.import.local` UPN (`HolderClassifier::FUNCTION_UPN_SUFFIX`) → B2B guest `#EXT#` UPN → `external` (only while still `null`/`person`, #969) → manually set non-default type → per-tenant rules (`asset_team_settings.holder_type_rules`) → `person`; a mis-classification makes a holder (not) count in costs and billing; nothing in `src/` currently *generates* such UPNs (`CostResetService` only cleans them up, `:63`). The type drives the license distribution cascade and the billing counting rule. The real cost-allocation risk remains the UPN-keyed cost-center lookup (`ccByUpn`, `CostAggregationService.php:420`/`:505`) missing a match → the cost silently lands in the "ohne Kostenstelle" bucket.
- **Weiterberechnung (easybill, ADR 0021/0024/0025)**: `BillableHolderResolver::resolve()` applies the three-stage counting rule — rule (no UPN, non-person `holder_type`, excluded domain, no license) → profile extensions (count externals, name patterns) → individual exception at the holder **with reason and date** (checked first so the preview shows the decision); it reads only `is_active = true` holders, but `is_active` is set by the directory sync and is **not** a billing switch; `BillingRunService` builds integer-cent positions (`unit_price_cents × quantity`) and creates an easybill **draft** via the `BillingGateway` binding; document date = last day of the billed month. A wrong quantity writes a wrong invoice to a real customer.
- **DATEV-Umbuchung (ADR 0027)**: `DatevReallocationService` (largest-remainder split of each invoice snapshot by its cost-type × cost-center shares) → `Support\DatevReallocationCalculator` (source/target posting lines) → `Support\DatevExtfWriter` (EXTF file + checklist; a violation produces no file); `DatevBatchService::create()` locks the booked snapshots against a second reallocation, `void()` needs a reason. Probe double booking and rounding residues.

### 6. Safety-first risk classes
Flag issues that could cause: cross-team or cross-tenant data leak (confidentiality breach between customers) · wrong cost figures presented as authoritative (over/under-allocation, silent double-count or drop) · inconsistent/corrupt state after import or sync · silent failures (swallowed sync/import errors) · data corruption · credential/secret exposure · operational blind spots (logs that lie) · Blade/PHP fatal taking the page to HTTP 500.

---

## Logic analysis targets

### A) Critical correctness
- missing/incorrect `team_id` filter on a query, Job, tool, or controller — or a call site passing the wrong team's id into a `$teamId` parameter
- a `withoutTenantScope()` without a following tenant filter, an unscoped path where `TenantContext::scopeTenantId()` is `null`, or a stale/forgotten `TenantContext::forceTenant()` override
- broken `aggregation_source` invariant (a cost counted by two of the four sources, or by none)
- non-atomic import/sync leaving partial state; `whereNotIn` reconcile-delete acting on a partial Graph page
- stale reads used for pivot/cost decisions; `computeMonthlyAmount` not firing on bulk/`update`/`upsert` paths → stale `monthly_amount`
- a billing run or DATEV batch that books the same holder/snapshot twice, or a quantity that differs from the counting rule's preview

### B) Edge cases
- empty team / empty tenant / no cost lines / no cost centers / tenant without connector (manual customer)
- `null` `cost_center_id`; a `cost_center_id` not in the tenant's tree (→ "Unbekannte Kostenstelle" orphan row); UPN with no matching holder → "ohne Kostenstelle" bucket; deleted-UPN variants from the Entra recycle bin (ADR 0023, `tests/local/holder-backfill.php`)
- `once` and zero-amount frequencies; negative amounts (`allow_negative` per cost type, credits/discounts); `null` `fx_rate`
- `valid_from`/`valid_to` boundaries in `validOn()` (day granularity, `whereDate`); lines that are `active` but outside validity; a new write path that stamps an invoice period as validity (Bug #930)
- first/last row of pivot; single-tenant team; a tree with only root nodes vs. deep trees (rollup)
- re-import of an unchanged vs changed file; soft-deleted row colliding with the same `import_hash` (refused, then pruned); `active=false` vs soft-deleted vs outside `validOn`

### C) Control flow
- incorrect conditionals on `aggregation_source` / Buchungssystem (`HGK`/`Moss`)
- dead/unreachable guards; loops over collections with wrong termination
- fallback logic (device cost center override → holder via UPN; contract-line price → hand-kept SKU price; collecting cost centers for pool/overflow/telephony that may be unset) that silently changes the grouping

### D) State and concurrency
- overlapping runs despite `ShouldBeUnique` (lock expiry via `uniqueFor` during a long run; different job types on the same holders; the non-unique `BackfillHoldersJob`)
- `updateOrCreate`/`firstOrCreate` race under concurrent sync + manual edit
- transaction boundaries around multi-table import/sync
- static mutable state in long-running workers (`TenantContext::$override`, memo caches like `ControllingContext::$memo`, `TeamRole` memoisation)
- SoftDeletes vs the `(tenant_id, import_hash)` unique index (trashed rows still occupy the key)

### E) Validation and assumptions
- Livewire form inputs not validated before persist (UI and MCP must apply the **same** rules — cf. `CostLineWriteService`, `AssetWriteService::deviceCostErrors()`); wide `$fillable` mass-assignment exposure
- URL/client-supplied Livewire state (`perPage`, sort column, filters) not clamped — `Concerns\ClampsListState` (#957) is the pattern; German decimal input parsed via `Support\DecimalInput` (#958)
- amount/frequency/`aggregation_source` not constrained (enum/DB-level)
- timezone assumptions in `date`-cast fields
- config-/setting-driven safety not actually enforced on every surface: the guard, the `asset-manager.manage` gate (ADR 0004 — UI **and** MCP), and the per-tenant Controlling switch (ADR 0008: `Http\Middleware\EnsureControllingEnabled` on the cost routes, `ControllingContext::enabledFor()` in tools and dashboard)

### F) Error handling
- swallowed exceptions in sync/import hiding failure as "success"
- sync-log / `AssetImportRun` written before vs after the write commits (lies on rollback); a sync-log left in `running` after a worker kill (`failed()` → `failStuckSyncLogs()`)
- recovery paths that re-run and duplicate; misleading or context-free log messages

### Module-mechanics correctness (platform-specific traps — see CLAUDE.md & memory)
- ServiceProvider boot order (`mergeConfigFrom` in `register()` — currently correct)
- **Livewire alias mangling** (Folder/Index.php → `…-index`; nest class-based via `@livewire(Class::class)`)
- **Livewire hook name collision** (a helper like `hydrateForm(Model $m)` auto-invoked as a property hook → 500 on every interaction)
- Blade compiles cleanly (`@if/@endif`, `<x-ui-*>`, conditional `disabled` as `:disabled` prop, not `@if` inside a component tag) — verify by actually compiling (`php tests/local/blade-lint.php <files>`), not counting tags
- design tokens: every `var(--am-*)` used in a Blade must be defined in `resources/views/components/theme.blade.php` (undefined → property silently dropped; guardrails Check 8)
- migrations portable / no half-applied index-name collisions
- model invariants vs the platform guide: this module uses **SoftDeletes + auto-increment ids** and **no UuidV7, no LogsActivity** — if the platform guide expects those, treat the gap as a note, not as an invariant to rely on

---

## Visibility rules

If visibility is incomplete: continue with visible evidence first · mark assumptions explicitly · downgrade confidence where needed · request missing code only if a conclusion depends on it.

Use this exact request only when necessary:
`Please provide the full directory tree and any modules referenced but not shown.`

`platform-core`, the UI module, and other `platform-*` packages are **dependencies you read but never modify**. This module is a Composer **path package** — running `php artisan`, `php -l` against framework classes, or Blade compilation may require the host/sandbox app (`demo.bhgdigital.de` booted as a library). The module already ships that harness: `tests/local/*.php` boot an existing host app as a library on SQLite in-memory (`tests/local/bootstrap.php`, host via `AM_HOST_APP`), and `php tests/guardrails.php` runs with no framework at all. If you cannot execute, downgrade those checks to **Inferred**. If a finding's root cause is in Core/UI, report it as an upstream observation to raise with Martin — do not propose editing it. (Ignore any `.claude/worktrees/*` copies of the code — the canonical tree is the repo root.)

Do not stop early unless missing visibility makes correctness assessment impossible.

---

## Evidence rules (mandatory)

Every finding must include at least one of: exact `file:line` · exact symbol/function/class/Livewire component · exact config key/path or migration name · exact test name · exact command/tool result (`php -l`, `php tests/guardrails.php`, `php tests/local/<script>.php`, `php artisan asset-manager:import-costs --dry-run`, Blade compile).

Do not write vague evidence ("logic seems fragile", "there may be a race condition", "default may be unsafe", "error handling is weak"). Do not invent files, tests, commands, models, or behaviors.

---

## Severity model

Each finding must include **Severity** `P0|P1|P2|P3`, **Confidence** `High|Medium|Low`, **Basis** `Observed|Inferred`.

- `P0` = cross-team or cross-tenant data leak (isolation breach) · broken double-counting invariant producing silently wrong authoritative cost figures · credential/secret exposure · data corruption on import/sync
- `P1` = major correctness or state-integrity issue (wrong pivot total, wrong frequency normalization, non-idempotent import, wrong billing quantity or DATEV posting, sync reconcile deleting live rows, page-down 500 on a core flow)
- `P2` = meaningful robustness weakness, not immediate catastrophe
- `P3` = include only if high signal

Prefer P0/P1 items.

---

## Output format (strict)

Return a single Markdown report only.

# LOGIC ANALYSIS REPORT (platforms-asset-manager)

## 1) Summary
3–6 bullets: overall logic health · most serious correctness risks (lead with team/tenant isolation / double-counting) · whether issues are concentrated (e.g. all in sync) or systemic · whether the repo appears safer than prior findings or shows regression signs.

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
