# Architecture Review — platforms-asset-manager

Wiederverwendbarer Struktur- / Safe-Refactor-Audit-Prompt für dieses Modul. Einer frischen
Analyse-Session (Claude/Subagent) als Instruktions-Set übergeben — sie erzeugt einen
`ARCHITECTURE REVIEW REPORT`.

Komplementär zu [`logic-audit.md`](logic-audit.md): **Logic-Audit = Korrektheit / Failure-Modes**,
**Architecture-Review = Struktur / Grenzen / Clutter / sichere Refactorings**. Wer Bugs sucht,
nimmt den Logic-Audit; wer Struktur und Boundaries beurteilt, nimmt diesen Prompt.

- **Stand:** 2026-06-13 — die genannten Layer (`src/Livewire`, `Services`, `Models`, `Jobs`,
  `Console/Commands`, `Http/Controllers/Api`, `Policies`, `Tools`, `Support`) und die konkreten
  Datei-Anker wurden gegen `src/` verifiziert. Beim Pflegen: bei Code-Umbau die Anker nachziehen.
- **Sprache:** Audit-Framing Englisch, Domänen-Begriffe Deutsch (wie in Code / `CONTEXT.md`).
- **Geltung:** nur `platforms-asset-manager` (Goldene Regeln) — `platform-core`, das UI-Modul und
  Fremdmodule nur lesen, **nie** umstrukturieren. Ein Strukturvorschlag, der Core/UI berührt, ist
  eine „raise with Martin"-Beobachtung, kein ausführbarer Schritt.

---

# platforms-asset-manager — Architecture Review & Safe Refactor Audit

You are a senior software architect reviewing the **`platforms-asset-manager` repository** — a
**production, multi-tenant Laravel platform module** (`Platform\AssetManager\`) that plugs into a
larger platform (Core + UI + sibling `platform-*` modules) and whose output — the
**Kostenaufteilung** (Kostenstelle × Kostenart pivot) and per-employee cost figures — feeds **real
accounting and cost-allocation decisions across multiple Gesellschaften**.

Your task is to analyze structure, eliminate clutter, and propose **safe, incremental architectural
improvements** that respect the platform's module contract and the data-sensitivity of the domain.

This is **not** a style review. Do not optimize for novelty. Do not propose large rewrites unless a
material production or tenant-isolation risk justifies them.

## Core principle

**Behavior preservation > tenant isolation > module-boundary integrity (Goldene Regeln) > auditability & reproducibility > clarity > elegance**

Concretely:
- **Behavior preservation** — refactors are non-breaking by default; the pivot, the import, and the
  sync must produce identical results before and after.
- **Tenant isolation** — there is **no** Eloquent global scope / `BelongsToTeam` trait; isolation
  rests on each query's explicit `->where('team_id', $teamId)` plus the *caller* passing the right
  team id (`Tools/Concerns/ResolvesTeam`, Livewire `Auth::user()->currentTeam`). A structural change
  must never move a `team_id` guard to where a caller can bypass it. (Detailed isolation correctness
  lives in [`logic-audit.md`](logic-audit.md); here, judge whether the *structure* makes isolation
  easy to get right and hard to get wrong.)
- **Module-boundary integrity** — the module's only sanctioned surface to the rest of the platform
  is the documented andock pattern (ServiceProvider boot → `PlatformCore::registerModule`,
  `ModuleRouter`, `x-ui-*`, MCP `ToolContract`). Hidden coupling to Core/UI internals is a finding.
- **Auditability & reproducibility** — the import trail (`import_hash` / `import_batch_id` on
  `asset_cost_lines`), the sync-logs (`AssetDeviceSyncLog`, `AssetLicenseSyncLog`), and
  config-driven boot must stay visible and reproducible. Do not propose structure that hides them.

---

## Read before scanning (mandatory)

Before making structural judgments, read and use these as anchors when present:

- `CONTEXT.md` — domain language (Gesellschaft, Kostenstelle, Kostenart, Kreditor, Kostenposition,
  Funktionskonto, Frequenz, Buchungssystem) and the **four-source double-counting rule**. Docs can
  lag code — trust `src/` where they disagree.
- `docs/adr/` — accepted decisions, esp. `0001-cost-lines-modell.md` (the `asset_cost_lines`
  backbone + the `aggregation_source` invariant). These are the intended-architecture record.
- `docs/agents/logic-audit.md` — the correctness lens. Read it so you don't duplicate logic findings
  and so you understand the failure-mode landscape your structural changes must not worsen.
- `composer.json` — **`require` is empty by design**: the host app provides the Laravel framework,
  and the Excel import uses a **native** `ZipArchive`+`SimpleXML` reader
  (`CostExcelImportService::readWorkbook()`), **not** `phpoffice/phpspreadsheet`. Do **not** flag a
  "missing phpspreadsheet / missing framework dependency" — it is genuinely host-provided. This is a
  Composer **path package**.
- `config/asset-manager.php` — routing, guard, navigation, sidebar (the config-driven surface Core
  reads; the reproducibility contract). No `billables` key in this module — do not assume one.
- `src/AssetManagerServiceProvider.php` — the **composition root** and fixed boot sequence:
  `register()` runs `mergeConfigFrom` (correctly here, not in `boot()`) and registers the 4 console
  commands; `boot()` does `registerModule` → `ModuleRouter::group` + `apiGroup` → `Gate::policy`
  (×2) → migrations → config publish → views → **Livewire auto-registration** (recursive scan of
  `src/Livewire/`) → **explicit MCP tool registration** (`registerTools()`, a hardcoded list of all
  28 tools — *no auto-discovery*, so adding/removing a tool requires editing the ServiceProvider) →
  **scheduler** (`asset-manager:sync-intune` hourly, `asset-manager:sync-licenses` daily 02:00).
- `routes/web.php`, `routes/api.php` — the import surfaces (web routes are name-only; prefix +
  middleware come from `ModuleRouter`).
- `README.md`, `LLM_GUIDE.md`, `DESIGN.md` — intended structure, andock points, LLM-first contract.
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

Because this module feeds authoritative cost figures across Gesellschaften:
- **never merge aggregation/import/sync logic into the Livewire/Tools/Controller layer** — the
  `Services/` layer is the single home for that risk-sensitive logic, and currently is
  (`CostAggregationService` = pivot, `CostExcelImportService` = import, `IntuneGraphService` /
  `AssetDeviceService` = sync). Routine CRUD list/detail views and CRUD tools reading Models
  **directly** is idiomatic Livewire and **not** a finding — do not propose forcing every Model
  access through a service. The boundary to defend is: a *new* pivot/import/sync rule, or a
  *duplicated* one, leaking into a component or tool instead of living in (or reusing) its service.
- **never move a `team_id`/permission guard** to where a caller can bypass it (tenant isolation must
  stay at or above the call site).
- **never reduce observability** of import or sync (the `import_hash`/`import_batch_id` trail, the
  sync-log rows) or of the pivot aggregation path.
- **never collapse the three write paths** (manual Livewire CRUD, Excel import, Graph-Sync) into a
  shape that hides which path wrote a row or makes incident review harder.
- **never blur the Excel-bootstrap boundary**: per the project's decouple-from-Excel decision, the
  Excel import is treated as a throwaway bootstrap (the master data it seeds is meant to live in the
  UI) — do not propose elevating the importer into a long-lived domain dependency.

---

## Analysis framework

### 1) Structure mapping

Map and assess the real layers:

- **Composition root** — `src/AssetManagerServiceProvider.php` (boot order, what it wires).
- **Config / routing surface** — `config/asset-manager.php`, `routes/web.php`, `routes/api.php`.
- **UI layer** — `src/Livewire/` (feature folders: `Assets`, `Costs`, `Devices`, `Employees`,
  `Licenses`, `MasterData`, `CostLines`, `Printers`, `Internet`, `DeviceModels`; plus top-level
  `Dashboard`, `Sidebar`, `ConnectorSetup`, `Test`) + Blade views.
- **Domain / business logic** — `src/Services/` (`CostAggregationService`, `CostExcelImportService`,
  `IntuneGraphService`, `AssetDeviceService`, `EmployeeService`, `CostResetService`,
  `CostBootstrapService`).
- **Persistence / domain model** — `src/Models/` (`AssetCostLine`, `AssetCostType`, `AssetEmployee`,
  `AssetDevice`, `AssetConnectorConfig`, sync logs, master-data models).
- **Ingestion / sync** — `src/Jobs/` (`SyncIntuneDevicesJob`, `SyncLicensesJob`,
  `ImportTenantUsersJob`, `BackfillEmployeesJob`) + `src/Console/Commands/` (4 commands) + the
  ServiceProvider scheduler (sync-intune hourly, sync-licenses daily).
- **LLM / MCP surface** — `src/Tools/` (28 tools across `CostLines`, `Costs`, `Devices`,
  `Employees`, `Licenses`, `MasterData`, `Sync`, plus `OverviewTool`; shared `Concerns/ResolvesTeam`),
  all explicitly registered in `AssetManagerServiceProvider::registerTools()`.
- **HTTP API** — `src/Http/Controllers/Api/` + `src/Policies/`.
- **Support** — `src/Support/` (`CostBootstrap` const definitions).

Assess: package/module boundaries · dependency direction (`Services` → `Models`; UI/Tools/Controllers
→ `Services` for the pivot/import/sync paths, and → `Models` directly for routine CRUD — both are
present and the CRUD-direct path is fine; the only genuine *inversion* to flag is a `Model` or
`Service` depending on Livewire/Tools/Controllers) · coupling hotspots · ownership boundaries ·
entrypoints and import surfaces (ServiceProvider, routes, Livewire aliases, MCP tool names).

Detect: monolith files · circular `use`/dependency · hidden cross-layer calls (a *new or duplicated*
pivot/import/sync rule living in Livewire/Tools instead of its Service — **not** ordinary CRUD Model
access) · unclear domain ownership · dead modules (`Livewire/Test.php` is a self-described disposable
demo — confirm it is unrouted/unreferenced) · stale compatibility shims (e.g. the legacy master-data
single routes in `routes/web.php:53-56` now redirect to the combined page — intentional back-compat,
confirm before touching) · duplicate abstractions (e.g. `Support/CostBootstrap` vs
`Services/CostBootstrapService` — **confirm**: verified here as a legitimate data/behavior split, a
const table consumed by the seeding service, *not* duplication) · boundary leaks between UI,
ingestion/sync, the MCP tool surface, the HTTP API, and the domain services.

Monolith guidance: flag files larger than ~500 lines **only if** size also creates ownership,
testability, or change-risk problems. `CostAggregationService` (the pivot engine) and
`CostExcelImportService` (the native XLSX reader) are the obvious size candidates — judge them on
change-risk and testability, not line count alone.

### 2) Clutter detection

Systematically detect: duplicate logic · unused functions/classes · stale compatibility layers ·
outdated comments/docstrings · temporary/debug/demo code (`Livewire/Test.php` and its
`asset-manager::livewire.test` view are explicit candidates — verify they are unreferenced and
unrouted before recommending removal) · inconsistent naming that causes ownership confusion · dead
exports · MCP tools or routes registered but never used · passive wrappers with no abstraction value.

For the **`Tools/` layer specifically**: 28 thin MCP wrappers exist by design (LLM-first). Do **not**
flag them as "passive wrappers" wholesale — the value is the MCP contract + team resolution
(`Concerns/ResolvesTeam`). Flag a tool only if it (a) contains pivot/import/sync logic that belongs in
a Service, (b) duplicates another tool, or (c) is in `src/Tools/` but missing from
`registerTools()` (dead/unregistered) — the explicit registration list is the source of truth.

Do **not** suggest new helpers/utilities if a similar abstraction may already exist
(`Services/`, `Support/`, `Tools/Concerns/ResolvesTeam`). Confirm first.

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
`reference_livewire_alias_mangling`). Do not propose moves that break the template contract or silently
rename Livewire aliases / MCP tool names.

Design principles: clear domain boundaries · consistent layering · isolation of runtime vs
ingestion/sync vs tests · config separation · explicit contracts between subsystems · avoid premature
over-modularization.

### 5) Tooling and safety

Recommend only high-value safeguards, and **confirm presence first** (`require` is empty by design, so
dev tooling may not be wired):
- static analysis / type checking — Larastan/PHPStan **as a candidate** (confirm it is configured
  before assuming it runs); `php -l` per file always works.
- Blade compilation check (this module's standing trap — a stray `@endif` is an HTTP 500; verify by
  compiling, not counting tags; see module memory `reference_blade_local_verify`).
- import/dependency-direction guard — a lightweight check that no Livewire component or Tool
  re-implements pivot/import/sync logic a Service already owns (e.g. a component doing its own
  normalization instead of calling `CostAggregationService`), and that no `Model`/`Service` `use`s
  Livewire/Tools. **Not** a blanket "UI must not touch Models" rule — routine CRUD Model access is fine.
- coverage — **no `tests/` directory exists** (verified 2026-06-13): there is zero automated safety
  net, so any medium/high structural move must add the **smallest** characterization tests first
  (pivot output, import idempotency, tenant scoping) — treat this as a precondition, not a nicety.
- CI checks that prevent unsafe structural drift (Livewire-alias mangling regressions, boot-sequence
  order, cross-module `use`).

Recommendations must be executable from CLI or PhpStorm using safe rename/move workflows. Note: as a
path package, running framework-aware tooling (artisan, Blade compile, Larastan with framework stubs)
may require the host/sandbox app (`demo.bhgdigital.de` booted as a library); if you cannot execute,
downgrade those checks to **Inferred**.

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
exact test name · exact command/tool result (`php -l`, Blade compile, `composer` output).

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
