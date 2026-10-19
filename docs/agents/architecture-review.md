# Architecture Review — platforms-asset-manager

Wiederverwendbarer Struktur- / Safe-Refactor-Audit-Prompt für dieses Modul. Einer frischen
Analyse-Session (Claude/Subagent) als Instruktions-Set übergeben — sie erzeugt einen
`ARCHITECTURE REVIEW REPORT`.

Komplementär zu [`logic-audit.md`](logic-audit.md): **Logic-Audit = Korrektheit / Failure-Modes**,
**Architecture-Review = Struktur / Grenzen / Clutter / sichere Refactorings**. Wer Bugs sucht,
nimmt den Logic-Audit; wer Struktur und Boundaries beurteilt, nimmt diesen Prompt.

- **Stand 2026-10-19** — die genannten Layer (`src/Livewire`, `Services`, `Models`, `Jobs`,
  `Console/Commands`, `Http/Controllers`, `Http/Middleware`, `Policies`, `Tools`, `Support`,
  `Concerns`) und die konkreten Datei-Anker wurden gegen `src/` verifiziert (Erstfassung 2026-06-13;
  2026-10-19 nachgezogen: Asset-Träger statt Mitarbeiter (ADR 0017), Tenant-Grenze + Global Scope
  (ADR 0016), Gesellschaft = Wurzelknoten des Kostenstellen-Baums, drei Test-Ebenen unter `tests/`,
  entfernte Klassen wie `Livewire/Test.php` und das Compliance-Cockpit). Beim Pflegen: bei
  Code-Umbau die Anker nachziehen.
- **Sprache:** Audit-Framing Englisch, Domänen-Begriffe Deutsch (wie in Code / `CONTEXT.md`).
- **Geltung:** nur `platforms-asset-manager` (Goldene Regeln) — `platform-core`, das UI-Modul und
  Fremdmodule nur lesen, **nie** umstrukturieren. Ein Strukturvorschlag, der Core/UI berührt, ist
  eine „raise with Martin"-Beobachtung, kein ausführbarer Schritt.

---

# platforms-asset-manager — Architecture Review & Safe Refactor Audit

You are a senior software architect reviewing the **`platforms-asset-manager` repository** — a
**production, multi-tenant Laravel platform module** (`Platform\AssetManager\`) that plugs into a
larger platform (Core + UI + sibling `platform-*` modules) and whose output — the
**Kostenaufteilung** (Kostenstelle × Kostenart pivot), per-holder (Asset-Träger) cost figures, the
easybill **Weiterberechnung** and the **DATEV-Umbuchung** — feeds **real accounting, invoicing and
cost-allocation decisions for multiple customers (Tenants)** and their Gesellschaften (the root nodes
of each tenant's cost-center tree).

Your task is to analyze structure, eliminate clutter, and propose **safe, incremental architectural
improvements** that respect the platform's module contract and the data-sensitivity of the domain.

This is **not** a style review. Do not optimize for novelty. Do not propose large rewrites unless a
material production or tenant-isolation risk justifies them.

## Core principle

**Behavior preservation > tenant isolation > module-boundary integrity (Goldene Regeln) > auditability & reproducibility > clarity > elegance**

Concretely:
- **Behavior preservation** — refactors are non-breaking by default; the pivot, the import, and the
  sync must produce identical results before and after.
- **Tenant isolation** — two boundaries (ADR 0016). **Team** (`team_id`, outer security boundary):
  there is **no** team-level global scope / `BelongsToTeam` trait; it rests on each query's explicit
  `->where('team_id', $teamId)` plus the *caller* passing the right team id
  (`Concerns/ResolvesCurrentTeam`, `Tools/Concerns/ResolvesTeam`). **Tenant** (`tenant_id`, inner
  access boundary): enforced centrally by a **Global Scope** — `Concerns/TenantScopable` registers
  `Support/TenantScope`, resolved through `Services/TenantContext`; the only sanctioned exits are
  `withoutTenantScope()` (+ `forTenant()`) and `TenantContext::runFor()`/`forceTenant()`, and MCP tools
  resolve the tenant via `Tools/Concerns/ResolvesTenant`. A structural change must never move a
  `team_id` guard or a tenant pin to where a caller can bypass it, and must not add new unexplained
  scope exits. (Detailed isolation correctness lives in [`logic-audit.md`](logic-audit.md); here,
  judge whether the *structure* makes isolation easy to get right and hard to get wrong.)
- **Module-boundary integrity** — the module's only sanctioned surface to the rest of the platform
  is the documented andock pattern (ServiceProvider boot → `PlatformCore::registerModule`,
  `ModuleRouter`, `x-ui-*`, MCP `ToolContract`). Hidden coupling to Core/UI internals is a finding.
- **Auditability & reproducibility** — the import trail (`import_hash` / `import_batch_id` on
  `asset_cost_lines`), the import protocol (`AssetImportRun` via `Support/ImportRunRecorder`), the
  sync-logs (`AssetDeviceSyncLog`, `AssetLicenseSyncLog`), the device lifecycle events
  (`AssetDeviceEvent`), the revision trails `AssetBillingRun` / `AssetDatevBatch`, and config-driven
  boot must stay visible and reproducible. Do not propose structure that hides them.

---

## Read before scanning (mandatory)

Before making structural judgments, read and use these as anchors when present:

- `CONTEXT.md` — domain language (Tenant, Connector, Asset-Träger + Träger-Typ, Kostenstelle as a
  tree whose root nodes are the Gesellschaften, Kostenart, Kreditor, Kostenposition, Funktionskonto,
  Frequenz, Buchungssystem, Weiterberechnung) and the **four-source double-counting rule**. Docs can
  lag code — trust `src/` where they disagree.
- `docs/adr/` — accepted decisions (0001–0028), esp. `0001` (the `asset_cost_lines` backbone + the
  `aggregation_source` invariant), `0008` (Controlling as a switchable layer), `0012` (Inventory as the
  primary asset surface), `0013` (module-local `--am-*` design tokens), `0016` (Tenant = access
  boundary), `0017` (Asset-Träger instead of Mitarbeiter), `0022` (deploy via lock pointer). These are
  the intended-architecture record.
- `docs/agents/logic-audit.md` — the correctness lens. Read it so you don't duplicate logic findings
  and so you understand the failure-mode landscape your structural changes must not worsen.
- `composer.json` — `require` holds only `php`, `ext-zip`, `ext-simplexml` and
  `barryvdh/laravel-dompdf` (handover/billing PDFs); the **Laravel framework is host-provided**, and
  the Excel import uses a **native** `ZipArchive`+`SimpleXML` reader (`Support/XlsxReader`;
  `CostExcelImportService::readWorkbook()` delegates to it), **not** `phpoffice/phpspreadsheet`. Do
  **not** flag a "missing phpspreadsheet / missing framework dependency" — it is genuinely
  host-provided. This is a Composer **path package**; `autoload` also maps
  `Platform\AssetManager\Database\Factories\` for the host feature tests.
- `config/asset-manager.php` — routing, guard, central Azure app (`azure.*`), navigation, sidebar
  (the config-driven surface Core reads; the reproducibility contract). The in-module sidebar actually
  renders from `resources/views/livewire/sidebar.blade.php`, not from `config['sidebar']`. No
  `billables` key in this module — do not assume one.
- `src/AssetManagerServiceProvider.php` — the **composition root** and fixed boot sequence:
  `register()` runs `mergeConfigFrom` (correctly here, not in `boot()`), binds
  `Services/BillingGateway` → `EasybillBillingGateway` (swappable in tests, ADR 0021) and registers
  the 7 console commands; `boot()` does `registerModule` → `ModuleRouter::group` + `apiGroup` →
  `Gate::policy` (×2) → `Gate::define('asset-manager.manage')` (ADR 0004) → migrations → config
  publish → views → module Blade components (`asset-manager-*`) → **Livewire auto-registration**
  (recursive scan of `src/Livewire/`) → **explicit MCP tool registration** (`registerTools()`, a
  hardcoded list — Stand 2026-10-19: ~45 tools + 4 deprecated `…employee(s)…` aliases from
  `Tools/Holders/Legacy`; *no auto-discovery*, so adding/removing a tool requires editing the
  ServiceProvider) → **scheduler** (`asset-manager:sync-intune` hourly, `asset-manager:import-users`
  daily 01:30, `asset-manager:sync-licenses` daily 02:00).
- `routes/web.php`, `routes/api.php` — the import surfaces (web routes are name-only; prefix +
  middleware come from `ModuleRouter`; the cost/controlling routes sit in a
  `Http/Middleware/EnsureControllingEnabled` group, ADR 0008).
- `README.md`, `LLM_GUIDE.md`, `DESIGN.md`, `tests/README.md` — intended structure, andock points,
  LLM-first contract, the three test levels.
- The platform-wide `C:\Coding\Platforms\CLAUDE.md` (Standard module structure, Boot-Sequenz, Goldene
  Regeln, Core-Wissensbasis) and the module `CLAUDE.md` (skill config) — these define the **boundary
  contract** every module is measured against.
- current directory tree / repo map (`src/` especially).

Purpose: align with the intended module architecture and the platform's andock contract; avoid
re-discovering documented issues; distinguish current problems from already-known/already-fixed ones.

Prior-findings sources (catalogued issues live here, **not** in GitHub Issues):
- the **Dev-Modul** Features/Bugs boards for package `platforms-asset-manager`
  (office.bhgdigital.de, via MCP)
- any prior report at repo root (`LOGIC_ANALYSIS_REPORT.md`, a prior architecture report) if present
- recent `docs/adr/` entries and recent git history

Rules:
- Do not blindly repeat prior findings; for each reused finding, verify current status against the code.
- If a previous issue is already fixed, do not present it as open.
- Treat prior `file:line` evidence as a starting point, not automatic truth.

---

## Review mode

Work like a **production reviewer**, not a general clean-code reviewer.

Prefer: direct evidence (code, import/use graph, config, migrations, `php -l`, Blade compile, command
output) · smallest safe structural changes · boundary clarification · dependency simplification ·
migration paths with rollback.

Avoid: speculative redesign · abstraction for its own sake · moving code without a clear payoff ·
introducing new helpers/utilities when similar ones may already exist (this module already has a
`Services/` layer, a `Support/` namespace, and a `Tools/Concerns/` trait — **confirm before adding**)
· "ideal architecture" detached from the documented module template · any cross-module change
(forbidden by the Goldene Regeln).

Separate findings into:
- **Observed** = directly confirmed by visible code, the `use`/import graph, config, migration, test,
  or command output.
- **Inferred** = likely issue derived from missing boundaries, duplication, absent tests, or unclear
  ownership.

Do not present inferred findings as confirmed defects.

---

## Operating constraints

All refactoring recommendations must:
- preserve behavior by default
- stay **inside `platforms-asset-manager`** — never restructure or "tidy" Core/UI/other modules; if a
  root cause is upstream, report it as a "raise with Martin" observation, do not edit it
- respect the documented standard module structure and andock contract (`C:\Coding\Platforms\CLAUDE.md`)
- avoid inventing missing files, modules, tests, or systems
- prefer small diffs over large rewrites; staged deprecation over hard moves
- preserve auditability (import trail / sync-logs) and operator visibility
- preserve config- and ADR-driven behavior — never hide config-driven behavior inside implicit defaults

Because this module feeds authoritative cost figures and invoices for several customers (Tenants):
- **never merge aggregation/import/sync/billing logic into the Livewire/Tools/Controller layer** —
  the `Services/` layer (plus framework-free calculators in `Support/`) is the single home for that
  risk-sensitive logic, and currently is (`CostAggregationService` = pivot, `CostExcelImportService`
  / `VodafoneInvoiceImportService` / `VodafoneFixedNetImportService` / `LicenseInvoiceImportService`
  = imports, `IntuneGraphService` / `AssetDeviceService` + the `Jobs/` = sync, `BillingRunService` =
  Weiterberechnung, `DatevReallocationService` + `Support/DatevExtfWriter` = DATEV, shared write rules
  in `CostLineWriteService` / `AssetWriteService` used by UI **and** MCP). Routine CRUD list/detail views and CRUD tools reading Models
  **directly** is idiomatic Livewire and **not** a finding — do not propose forcing every Model
  access through a service. The boundary to defend is: a *new* pivot/import/sync rule, or a
  *duplicated* one, leaking into a component or tool instead of living in (or reusing) its service.
- **never move a `team_id`/permission guard** to where a caller can bypass it (tenant isolation must
  stay at or above the call site).
- **never reduce observability** of import or sync (the `import_hash`/`import_batch_id` trail, the
  sync-log rows) or of the pivot aggregation path.
- **never collapse the write paths** (manual Livewire CRUD, MCP write tools, Excel import, invoice
  imports, Graph-Sync — distinguishable via `source` and the import/sync logs) into a shape that
  hides which path wrote a row or makes incident review harder.
- **never blur the Excel-bootstrap boundary**: per the project's decouple-from-Excel decision, the
  Excel import is treated as a throwaway bootstrap (the master data it seeds is meant to live in the
  UI) — do not propose elevating the importer into a long-lived domain dependency.

---

## Analysis framework

### 1) Structure mapping

Map and assess the real layers:

- **Composition root** — `src/AssetManagerServiceProvider.php` (boot order, what it wires).
- **Config / routing surface** — `config/asset-manager.php`, `routes/web.php`, `routes/api.php`.
- **UI layer** — `src/Livewire/` (feature folders: `Assets`, `Billing`, `Connectors`, `CostLines`,
  `Costs` (`Allocation`, `Dashboard`, `DatevReallocation`, `Import`), `DeviceModels`, `Devices`
  (`Index`, `Status`), `Handovers`, `Holders`, `Internet`, `Inventory` (`Index`, `Show` — the one
  detail page for items **and** devices, ADR 0012), `Licenses` (`Index`, `Show`, `Contracts`),
  `MasterData`, `Printers`, `Reports`, `Settings`; plus top-level `Dashboard`, `Sidebar` (embedded by
  Core on every module page; hosts the theme tokens and the tenant switcher)) + Blade views
  (`resources/views/livewire/**`, module components in `resources/views/components/`).
- **Shared component/model traits** — `src/Concerns/` (`TenantScopable`, `GuardsTenantBoundary`,
  `ResolvesCurrentTeam`, `ReportsAcrossTenants`, `AuthorizesTeamRole`, `ClampsListState`,
  `CustomizesTableView`, `RunsTeamSync`).
- **Domain / business logic** — `src/Services/` (37 classes; core ones: `CostAggregationService`,
  `CostExcelImportService`, `IntuneGraphService`, `AssetDeviceService`, `AssetWriteService`,
  `InventoryService`, `HolderService`, `HolderClassifier`, `HolderTypeService`, `HolderMergeService`,
  `CostLineWriteService`, `CostLineReassignService`, `CostPlausibilityService`, `CostResetService`,
  `AssetResetService`, `CostBootstrapService`, `MasterDataDeletionService`, `TenantContext`,
  `ControllingContext`, the invoice importers, `BillingRunService` / `BillableHolderResolver` /
  `BillingGateway` / `EasybillBillingGateway`, `DatevReallocationService` / `DatevBatchService`,
  `InvoiceSnapshotService`, `HandoverCoverageService`, `TableViewService`).
- **Persistence / domain model** — `src/Models/` (~33 models: `AssetCostLine`, `AssetCostType`,
  `AssetCostCenter` (tree), `AssetVendor`, `AssetHolder`, `AssetDevice`, `AssetDeviceModel` (+`Cost`),
  `AssetItem`, `AssetAssignment`, `AssetHandover` (+`Line`), `AssetTenant`, `AssetConnectorConfig`,
  `AssetUserLicense`, `AssetLicenseSku`, `AssetLicenseContractLine`, sync/import logs, billing/DATEV
  models, settings). There is **no** `AssetEmployee` / `AssetCompany` any more (ADR 0017 / 0016 — the
  Gesellschaft is a root node of `AssetCostCenter`).
- **Ingestion / sync** — `src/Jobs/` (`SyncIntuneDevicesJob`, `SyncLicensesJob`,
  `ImportTenantUsersJob` — the three are `ShouldBeUnique` per connector — and `BackfillHoldersJob`) +
  `src/Console/Commands/` (7 commands: `sync-intune`, `sync-licenses`, `import-users`,
  `backfill-employees` (`BackfillHoldersCommand`, old name kept), `classify-holders`,
  `merge-deleted-holders`, `import-costs`) + the ServiceProvider scheduler (sync-intune hourly,
  import-users 01:30, sync-licenses 02:00).
- **LLM / MCP surface** — `src/Tools/` (folders `Billing`, `CostLines`, `Costs`, `Devices`,
  `Handovers`, `Holders` (+ `Legacy` aliases), `Licenses`, `MasterData`, `Sync`, plus
  `OverviewTool`; shared `Concerns/` `ResolvesTeam`, `ResolvesTenant`, `DeletesMasterData`,
  `DeprecatedToolAlias`), all explicitly registered in
  `AssetManagerServiceProvider::registerTools()`.
- **HTTP** — `src/Http/Controllers/Api/AssetDeviceController` (+ `src/Policies/`),
  `src/Http/Controllers/HandoverPdfController`, `BillingAttachmentPdfController`,
  `src/Http/Middleware/EnsureControllingEnabled`.
- **Support** — `src/Support/` (framework-free building blocks: `CostBootstrap` const definitions,
  `XlsxReader`, `DeviceCostResolver`, `LicensePriceBook` / `LicenseSeatCascade` / `LicenseSkuMatcher`,
  `LicenseContractInsights`, `DatevExtfWriter` / `DatevReallocationCalculator`, `TableViewState` /
  `PivotTableShaper`, `TenantScope`, `TeamRole`, `DecimalInput`, `PhoneNumber`, `ImportRunRecorder`,
  …).

Assess: package/module boundaries · dependency direction (`Services` → `Models`; UI/Tools/Controllers
→ `Services` for the pivot/import/sync paths, and → `Models` directly for routine CRUD — both are
present and the CRUD-direct path is fine; the only genuine *inversion* to flag is a `Model` or
`Service` depending on Livewire/Tools/Controllers) · coupling hotspots · ownership boundaries ·
entrypoints and import surfaces (ServiceProvider, routes, Livewire aliases, MCP tool names).

Detect: monolith files · circular `use`/dependency · hidden cross-layer calls (a *new or duplicated*
pivot/import/sync rule living in Livewire/Tools instead of its Service — **not** ordinary CRUD Model
access) · unclear domain ownership · dead modules (verify any candidate is unrouted and
unreferenced before recommending removal — the stranded `Livewire/Assets/Create`/`Show` pages were
removed 2026-10-19, #880; `Livewire/Assets/Index` is still routed at `/assets`) · stale
compatibility shims (intentional back-compat, confirm before touching: the legacy master-data single
routes `routes/web.php:112-115` → combined page, `/employees*` → `/holders*`, `/devices/{device}`
and `/assets/{item}` → `inventory.show`, `/assets/create` → inventory create modal, `/compliance` →
devices list, `/setup` → connectors; the deprecated MCP aliases in `Tools/Holders/Legacy` and
`asset-manager.companies.GET`, which stay until the live connector shows no more calls) · duplicate
abstractions (e.g. `Support/CostBootstrap` vs
`Services/CostBootstrapService` — **confirm**: verified here as a legitimate data/behavior split, a
const table consumed by the seeding service, *not* duplication) · boundary leaks between UI,
ingestion/sync, the MCP tool surface, the HTTP API, and the domain services.

Monolith guidance: flag files larger than ~500 lines **only if** size also creates ownership,
testability, or change-risk problems. Size candidates (Stand 2026-10-19): `Livewire/CostLines/Index`
(~1060 lines), `Services/CostAggregationService` (~880, the pivot engine), `VodafoneInvoiceImportService`
(~670), `Livewire/MasterData/Index`, `Livewire/Holders/Index`, `Livewire/Costs/Import`,
`LicenseInvoiceImportService`, `Jobs/SyncIntuneDevicesJob`, `CostExcelImportService` (~550–650 each)
— judge them on change-risk and testability, not line count alone.

### 2) Clutter detection

Systematically detect: duplicate logic · unused functions/classes · stale compatibility layers ·
outdated comments/docstrings (e.g. leftover `employee`/`Mitarbeiter` wording after ADR 0017 —
but note the deliberately kept external names: tool `asset-manager.costs.top-employees.GET`
(`TopEmployeesByCostTool`), command `asset-manager:backfill-employees`, column `assignee_id`) ·
temporary/debug/demo code (the former `Livewire/Test.php` demo is already gone — verify any new
candidate is unreferenced and unrouted before recommending removal) · inconsistent naming that causes
ownership confusion · dead exports · MCP tools or routes registered but never used · passive wrappers with no abstraction value.

For the **`Tools/` layer specifically**: ~45 thin MCP wrappers (+ 4 deprecated aliases) exist by
design (LLM-first). Do **not** flag them as "passive wrappers" wholesale — the value is the MCP
contract + team/tenant resolution (`Concerns/ResolvesTeam`, `Concerns/ResolvesTenant`) + the
`asset-manager.manage` gate for writes. Flag a tool only if it (a) contains pivot/import/sync logic that belongs in
a Service, (b) duplicates another tool, or (c) is in `src/Tools/` but missing from
`registerTools()` (dead/unregistered) — the explicit registration list is the source of truth.

Do **not** suggest new helpers/utilities if a similar abstraction may already exist
(`Services/`, `Support/`, `Concerns/`, `Tools/Concerns/`). Confirm first.

### 3) Refactoring opportunities

Prioritize: separation of concerns (a *new or duplicated* pivot/import/sync rule pulled into its
Service — not routine CRUD) · clearer dependency direction · reducing complexity where it affects
correctness/testability (the pivot path is the prime example) · extracting stable domain modules ·
improving testability of risk-sensitive paths
(aggregation, import idempotency, sync reconciliation, tenant scoping) · reducing duplicate logic in
those paths · clarifying contracts between Services, Jobs, Tools, and the API controller.

Constraints: non-breaking by default · minimal diffs · explicit migration steps · no speculative
redesign · no broad package moves without clear benefit · no folder churn unless boundary problems
justify it · no cross-module edits.

### 4) Structure optimization

Propose folder/package restructuring **only if** the current structure causes material problems.
Remember the structure is partly **fixed by the platform template** (`src/Livewire`, `src/Services`,
`src/Models`, `src/Tools`, `config/<modul>.php`, `routes/web.php`) — Livewire auto-registration
recursively scans `src/Livewire/` and derives each alias by kebab-casing the folder path, so moving or
renaming a component folder silently changes its string alias. `routes/web.php` dodges this by binding
**class-based references** (`Devices\Index as DevicesIndex`), the safe pattern — but any `<livewire:…>`
/ `@livewire('alias')` string usage in Blade is exposed to alias mangling (see module memory
`reference_livewire_alias_mangling`; `tests/guardrails.php` Check 3 rejects both string forms). Do not propose moves that break the template contract or silently
rename Livewire aliases / MCP tool names.

Design principles: clear domain boundaries · consistent layering · isolation of runtime vs
ingestion/sync vs tests · config separation · explicit contracts between subsystems · avoid premature
over-modularization.

### 5) Tooling and safety

Recommend only high-value safeguards, and **confirm presence first** (`require` is empty by design, so
dev tooling may not be wired):
- static analysis / type checking — Larastan/PHPStan **as a candidate** (not configured in this repo
  — no `phpstan.neon`; confirm before assuming it runs); `php -l` per file always works.
- Blade compilation check (this module's standing trap — a stray `@endif` is an HTTP 500; verify by
  compiling, not counting tags): **exists** as `php tests/local/blade-lint.php <files>` (compiles
  Blade → PHP against a host app and lints the result).
- import/dependency-direction guard — **exists** as `tests/guardrails.php` Check 2 (no file in
  `src/Models` or `src/Services` imports the Livewire/Tools/Http layers). Still not covered: a
  Livewire component or Tool re-implementing pivot/import/sync logic a Service already owns (e.g. a
  component doing its own normalization instead of calling `CostAggregationService`). **Not** a
  blanket "UI must not touch Models" rule — routine CRUD Model access is fine.
- coverage — there **are** three test levels (see `tests/README.md`); know what they cover before
  proposing new ones:
  1. `tests/guardrails.php` — framework-free static checks, runs anywhere (`php tests/guardrails.php`,
     exit 0 = green): (1) tool-registration completeness, (2) layer dependency direction, (3) no
     Blade alias mangling, (4) every eager-loaded relation exists, (5) device-model cost loaded with
     cost type + vendor, (6) per-instance device cost resolution only on single-object paths,
     (7) `AssetResetService` covers every model with `team_id` (or an explicit, reasoned exemption),
     (8) every `var(--am-*)` used in a Blade is defined in `components/theme.blade.php`,
     (9) every MCP tool resolves the tenant via `resolveTenant()` and every writing tool checks the
     `asset-manager.manage` gate against the context team.
  2. `tests/local/` — 42 check scripts (41 PHP + `pivot-grid.mjs`) plus `blade-lint.php` and the
     shared `bootstrap.php`; they boot an existing host app as a library on SQLite in-memory
     (`AM_HOST_APP` overrides the host path) and exercise real services/components/tools (pivot
     grouping, billing, DATEV, invoice imports, holder lifecycle, N+1 budgets, list-state clamping,
     tenant isolation across all scoped models, an MCP tool smoke suite, …).
     Limits: SQLite is lax on `GROUP BY`, module migrations don't run (each script builds its own
     columns), no authenticated user.
  3. `tests/Feature/` — 7 PHPUnit classes on the host's `Tests\TestCase` + `RefreshDatabase`
     (`CostLineScopingTest`, `CostReconciliationTest`, `ExcelImportIdempotencyTest`,
     `GraphReconcileDeleteTest`, `LifecycleAuditTest`, `SerialIdentityTest`,
     `VodafoneInvoiceImportTest`); they need the full Core bootstrap and run **only in the host app
     after deploy**.
  A medium/high structural move must still add the **smallest** characterization test for the path
  it touches if none of the three levels covers it (pivot output, import idempotency, tenant
  scoping) — check the existing scripts first.
- CI checks that prevent unsafe structural drift (Livewire-alias mangling regressions, boot-sequence
  order, cross-module `use`) — no CI is wired in the module repo; the guardrails script is the
  candidate to run there.

Recommendations must be executable from CLI or PhpStorm using safe rename/move workflows. Note: as a
path package, running framework-aware tooling (artisan, Blade compile, Larastan with framework stubs)
requires a host/sandbox app (`demo.bhgdigital.de` booted as a library — exactly what
`tests/local/bootstrap.php` does); if you cannot execute, downgrade those checks to **Inferred**.

---

## Visibility rules

If visibility is incomplete: continue with the visible structure first · mark assumptions explicitly ·
downgrade confidence where needed · request missing structure **only if** a structural conclusion
depends on unseen modules.

`platform-core`, the UI module, and other `platform-*` packages are dependencies you **read but never
modify**. (Ignore any `.claude/worktrees/*` copies — the canonical tree is the repo root.)

Use this exact request only when necessary:
`Please provide the full directory tree and any modules referenced but not shown.`

Do not stop early unless the missing tree makes architecture assessment impossible.

---

## Evidence rules (mandatory)

Every finding must include at least one of: exact `file:line` · exact symbol/class/Livewire
component/MCP tool name · exact `use`/import path · exact config key/path · exact migration name ·
exact test name · exact command/tool result (`php -l`, `php tests/guardrails.php`, Blade compile,
`composer` output).

Do not write vague evidence ("architecture seems mixed", "naming is inconsistent", "there may be
duplication", "module ownership is unclear"). If visibility is incomplete, say so explicitly. Do not
invent files, modules, imports, commands, or behaviors.

---

## Risk classification

Before proposing structural changes, classify impact:
- **none** → cosmetic or documentation only
- **low** → internal refactor, no external behavior change, no alias/tool-name change
- **medium** → module-internal boundary adjustment, `use`-path migration, Livewire-alias or MCP
  tool-name rename
- **high** → affects the pivot/aggregation output, import/sync write paths, tenant scoping, the
  ServiceProvider boot/andock contract, or operator-visible logs

High-risk proposals must include: explicit rollback plan · test/characterization strategy · migration
sequence · blast radius · validation checkpoints (`php -l`, Blade compile, `--dry-run` import, targeted
test).

---

## Proposal rules

Only propose a refactor if at least one is true: it reduces real production/tenant-isolation risk · it
reduces duplicate logic in critical paths (pivot/import/sync/scoping) · it clarifies an unstable
boundary · it improves testability of risk-sensitive behavior · it removes structural clutter with low
migration risk.

If an area is already sound, say so with evidence and suggest **guardrails** instead of change. Prefer
conservative evolution · smallest safe extraction · staged deprecation over hard moves · wrappers/
adapters only when they reduce real coupling.

---

## Output format (strict)

Return a single Markdown report only.

# ARCHITECTURE REVIEW REPORT (platforms-asset-manager)

## 1) Executive Summary

3–6 bullets covering: overall architectural health · main boundary risks (lead with anything touching
the Services↔UI/Tools split, tenant scoping, or the andock contract) · main clutter risks · whether
restructuring is justified or guardrails suffice · whether issues are mostly local or systemic.

## 2) Structural Findings

Numbered list. For each:
- **Title**
- **Location** (`file` / layer)
- **Evidence**: `<file:line | symbol | use path | config | migration | test | command result>`
- **Problem**
- **Impact**
- **Risk level**: none / low / medium / high
- **Confidence**: High / Medium / Low
- **Basis**: Observed / Inferred
- **Action**: refactor / remove / confirm / leave

## 3) Refactoring Proposals

For each:
- **Title**
- **Original location**
- **Evidence**
- **Refactoring description**
- **Why now**
- **Benefits**
- **Migration steps**
- **Risk assessment**: none / low / medium / high
- **Scope check**: confirm it stays inside `platforms-asset-manager` (or mark "raise with Martin — do
  not edit" if the root cause is Core/UI)
- **Rollback plan** (required for medium/high)
- **Tests to add or update** (characterization first if none exist)
- **Validation checkpoint** (`php -l`, Blade compile, `--dry-run` import, targeted test)

Include example snippets only if necessary.

## 4) Proposed Structure (Only If Justified)

Only include if the current structure creates material friction or risk. Respect the platform template
(do not break Livewire auto-registration or MCP tool names).
- **Justification**
- **Problems solved**
- **ASCII tree**
- **Import / alias migration notes**
- **Deprecation plan**
- **Blast radius**
- **Confirmation questions** (only if visibility is incomplete)

## 5) Healthy Areas

List areas that appear structurally sound.
Format: `<area>` — Evidence: `<files/tests/use boundaries>` — Why leave it alone:

## 6) Prioritized Next Steps

Ordered by **impact vs risk**. For each: action · expected benefit · estimated risk · prerequisite (if
any).

---

## Hard rules

- Do not invent files, modules, imports, or commands.
- Do not halt early unless missing visibility makes architecture review impossible.
- Distinguish **Observed** from **Inferred**.
- Prefer fewer, stronger findings over broad shallow commentary.
- Prefer conservative evolution over bold redesign.
- If architecture is already sound, explain why and recommend guardrails instead of change.
- Do not let folder restructuring dominate the report unless boundary failures justify it.
- Do not propose changes that reduce auditability, reproducibility, or tenant-isolation visibility.
- **Stay inside `platforms-asset-manager`** — never propose edits to Core/UI/other modules (Goldene
  Regeln); upstream root causes are "raise with Martin" observations.
- Trust the code over `CONTEXT.md`/ADR where they disagree (docs can lag the code).
