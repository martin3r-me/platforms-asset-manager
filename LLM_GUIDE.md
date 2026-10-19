# LLM-Orientierung — Asset Manager

Kurzkontext für LLMs, die **an diesem Modul** arbeiten. (Dies ist **kein** „neues Modul anlegen"-
Leitfaden mehr — die generische Template-Anleitung lebt im Template-Repo `module-template/LLM_GUIDE.md`.)

## Erst lesen

| Datei | wofür |
|---|---|
| [`README.md`](README.md) | Was das Modul tut, Tenants & Anbindung, Struktur, Deploy, Doku-Landkarte inkl. ADR-Liste |
| [`CONTEXT.md`](CONTEXT.md) | Domänen-Sprache, Glossar, die vier Datenquellen, Pivot, Tenant/Connector, Asset-Träger |
| [`DESIGN.md`](DESIGN.md) | UI-Design-System (modul-lokale Komponenten + `--am-*`-Tokens im Content-Bereich) |
| [`docs/adr/`](docs/adr/) | Akzeptierte Entscheidungen 0001–0028 — u. a. Cost-Lines-Modell (0001), Tenant als Zugriffsgrenze (0016), Asset-Träger (0017), Deploy per Lock-Zeiger (0022), Belegdatum (0025), Festnetz/RingCentral (0026), DATEV-Umbuchung (0027), Geräteausgabe/Signatur (0028) |
| [`tests/README.md`](tests/README.md) | Drei Test-Ebenen: `tests/guardrails.php`, `tests/local/`, `tests/Feature/` |
| [`docs/agents/`](docs/agents/) | Skills: Issue-Tracker, Triage, Domain-Docs, Logic-Audit, Architecture-Review |
| übergeordnete `C:\Coding\Platforms\CLAUDE.md` | Modul-Architektur, Boot-Sequenz, **Goldene Regeln** |

## Konventionen dieses Moduls — Abweichungen vom generischen Template merken

Das Modul wurde aus `module-template` gebootstrappt, weicht aber **bewusst** in mehreren Punkten ab.
Generische Template-Ratschläge **nicht blind** auf dieses Modul anwenden:

- **Auto-increment IDs**, *kein* `UuidV7` (siehe `CONTEXT.md`).
- **Kein** `LogsActivity`-Trait auf den Models. Nachvollziehbarkeit über eigene Spuren
  (`AssetImportRun`, Sync-Logs, `AssetDeviceEvent`, `AssetBillingRun`, `AssetDatevBatch`).
- **Content-Bereich:** modul-lokale `<x-asset-manager-*>`-Komponenten + `--am-*`-Tokens, **keine**
  `x-ui-*`-Komponenten im Content (nur die Page-Shell: `x-ui-page`, `…-navbar`, `…-actionbar` (über den
  Adapter `<x-asset-manager-page-actionbar>`), `…-container`, `…-sidebar`, `x-ui-sidebar-list`, sowie
  `x-ui-modal`). Begründung: `DESIGN.md`, ADR 0013.
- **Zwei Grenzen** (ADR 0016):
  - **Team** — `team_id`-Filter explizit auf jeder Query: `Model::where('team_id', $user->currentTeam->id)`
    (in Livewire über `Concerns\ResolvesCurrentTeam::teamId()`).
  - **Tenant** — tenant-gebundene Models tragen `Concerns\TenantScopable` (Global Scope auf
    `tenant_id`, aufgelöst über `Services\TenantContext`). **Nicht** selbst nachfiltern, aber auch nie
    still aussteigen: `withoutTenantScope()` nur auf Pfaden ohne Auth-Kontext (Jobs, Commands,
    Reconcile) und dann mit `->forTenant($id)`. Neuanlagen landen in `TenantContext::resolveForWrite()`.
- **Asset-Träger** (`AssetHolder`, `holder_type`) statt „Mitarbeiter" — `AssetEmployee`/`EmployeeService`
  gibt es nicht mehr (ADR 0017). **Gesellschaft** ist keine Entität, sondern ein oberster Knoten im
  Kostenstellen-Baum (`AssetCostCenter`, ADR 0016).
- **Schreibrechte:** jede Mutation hinter dem Gate `asset-manager.manage` (Owner/Admin des Teams) — in
  UI **und** MCP (ADR 0004).
- Tabellen-Prefix `asset_`.

## LLM-First-Kontrakt (MCP/LLM-Tools)

- Tools liegen in `src/Tools/`, Naming `asset-manager.resource.VERB` (REST-Verben).
- **Fachregeln leben in `src/Services/`** (bzw. framework-frei in `src/Support/`). Schreibende Tools
  rufen dieselben Services wie die UI (z. B. `CostLineWriteService`, `AssetWriteService`), damit UI und
  MCP dieselben Regeln durchsetzen; reine Lese-Tools dürfen Models direkt abfragen. Eine **neue**
  Pivot-/Import-/Sync-/Abrechnungsregel gehört nie ins Tool.
- Tenant-Auflösung in Tools über `Tools\Concerns\ResolvesTenant` (optionaler Parameter `tenant_id`,
  Default = gespeicherte Auswahl; `"all"` nur bei Auswertungs-Tools, dann mit Tenant je Zeile).
- Tool-Registrierung ist **explizit** in `AssetManagerServiceProvider::registerTools()` (kein Auto-Discovery):
  ein neues/entferntes Tool erfordert eine Editierung des ServiceProviders. `php tests/guardrails.php`
  prüft die Vollständigkeit (Check 1) sowie `resolveTenant()` in jedem Tool und das Gate
  `asset-manager.manage` in jedem schreibenden Tool (Check 9); `php tests/local/mcp-tools-smoke.php`
  ruft die Tools echt auf.
- Tool-Namen sind über den Live-Connector nach außen sichtbar: umbenannte Tools bekommen einen
  **Alias** mit `deprecated`/`use_instead` (siehe `Tools/Holders/Legacy`, `asset-manager.companies.GET`),
  statt hart zu verschwinden.

## Boundary / Goldene Regeln (verbindlich)

- Nur **im eigenen Modulordner** arbeiten. Core- und UI-Modul sind **tabu**.
- Andockpunkte (PlatformCore, ModuleRouter, `x-ui-*`-Shell, Core-Enums wie `StandardRole`) werden
  **genutzt, nicht verändert**. Modulübergreifende Änderungen vorher abstimmen.
- Vor jedem Commit: `php -l`, `php tests/guardrails.php`, Modul-Blade echt kompilieren
  (`php tests/local/blade-lint.php <dateien>`, HTTP-500-Falle).
- Deploy: `python tools/deploy-lock.py` (Lock-Zeiger, ADR 0022) — **nicht** `composer update`/`update.sh`.
