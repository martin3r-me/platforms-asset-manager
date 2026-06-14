# Platform Asset Manager

IT-Asset- und Kostenverwaltung für die Platform. Verwaltet Hardware, Lizenzen und Geräte
**und** deren Kosten je **Kostenstelle** und **Gesellschaft** — als digitales Abbild der
bisher manuellen Excel `Kostenaufteilung_IT.xlsx`.

- **Paket:** `martin3r/platform-asset-manager`
- **Namespace:** `Platform\AssetManager\`
- **Tabellen-Prefix:** `asset_`

> Produktives Fachmodul, **kein Template**. Es wurde ursprünglich aus `module-template`
> gebootstrappt — die generische „neues Modul anlegen"-Anleitung lebt im Template-Repo, nicht hier.

## Was es tut

Vier **doppelzählungsfreie** Datenquellen speisen die Kostenaufteilung — jede **Kostenart**
zieht ihren Pivot-Wert aus **genau einer** Quelle (Details: [`CONTEXT.md`](CONTEXT.md)):

| Quelle | `aggregation_source` | Herkunft |
|---|---|---|
| Kostenposition | `cost_line` | manuell / Excel-Import |
| Hardware-AfA | `hardware_afa` | gekaufte Hardware (lineare Abschreibung) |
| MS-Lizenz | `ms_license` | Graph-Sync (M365-SKUs × User-Lizenzen) |
| Gerät | `asset_device` | Intune-Geräte mit Leasing-/AfA-Kosten |

Die Kostenaufteilung (Pivot Kostenstelle × Kostenart) reproduziert die Excel-Sheets über
`CostAggregationService::costCenterByType($teamId, 'monthly'|'quarterly')` (UI: `Costs/Allocation`).

## Dateneingabe

1. **Einmaliger Excel-Bootstrap:** `php artisan asset-manager:import-costs --team=ID --file=… [--dry-run]` (idempotent via `import_hash`).
2. **Manuelle Pflege:** Livewire-CRUD (Kostenpositionen, Kostenstellen, Kreditoren, Kostenarten).
3. **Graph-Sync:** `asset-manager:sync-intune` + `asset-manager:sync-licenses` (MS-Lizenzen + Intune-Geräte/Hardware).

## Konventionen (dieses Modul)

- **Auto-increment IDs** — *kein* UuidV7.
- **Kein** `LogsActivity`-Trait.
- `team_id`-Scoping überall; Tabellen-Prefix `asset_`.
- **Content-Bereich:** custom Tailwind statt `x-ui-*` (nur die Page-Shell-Komponenten bleiben) — siehe [`DESIGN.md`](DESIGN.md).

> Plattformweite Modul-Architektur, Boot-Sequenz und Goldene Regeln stehen in der übergeordneten
> `C:\Coding\Platforms\CLAUDE.md` und gelten unverändert.

## Doku-Landkarte

| Datei | Inhalt |
|---|---|
| [`CONTEXT.md`](CONTEXT.md) | Domänen-Sprache, Glossar, Datenquellen, Pivot |
| [`DESIGN.md`](DESIGN.md) | UI-Design-System (Linear/Raycast-Personality) |
| [`docs/adr/0001-cost-lines-modell.md`](docs/adr/0001-cost-lines-modell.md) | Kostenaufteilung über generische `asset_cost_lines` |
| [`docs/adr/0002-fx-snapshot-policy.md`](docs/adr/0002-fx-snapshot-policy.md) | Währungsumrechnung via Snapshot-`fx_rate` |
| [`docs/agents/`](docs/agents/) | Agent-Skills: Issue-Tracker, Triage-Labels, Domain-Docs, Logic-Audit, Architecture-Review |
| [`CLAUDE.md`](CLAUDE.md) | Repo-spezifische Skill-Konfiguration |

## Struktur

```
src/
├── AssetManagerServiceProvider.php   # Boot: Modul-Registrierung, Routes, Views, Livewire, Tools
├── Console/Commands/   # Artisan: import-costs, sync-intune, sync-licenses, backfill-employees
├── Http/               # Controller/Middleware (Connector-OAuth-Callback etc.)
├── Jobs/               # Sync-Jobs (SyncIntuneDevicesJob, SyncLicensesJob, …)
├── Livewire/           # UI-Komponenten (Dashboard, Costs/*, Devices/*, Licenses/*, MasterData/*, …)
├── Models/             # Eloquent (AssetCostLine, AssetCostCenter, AssetCompany, AssetDevice, …)
├── Policies/           # Authorization (AssetItemPolicy, AssetDevicePolicy)
├── Services/           # Business-Logik (CostAggregationService, CostExcelImportService, …)
├── Support/            # CostBootstrap (Stammdaten + code→Gesellschaft-Mapping)
└── Tools/              # MCP/LLM-Tools (Naming: asset-manager.resource.VERB)
```

## Entwicklung

- Code = dieses Repo · ADRs = `docs/adr/` · Domänen-Glossar = `CONTEXT.md` · Tasks = Dev-Modul (Package `platforms-asset-manager`).
- **Vor dem Commit:** `php -l <datei>`; Modul-Blade lokal gegen die Demo-App kompilieren (HTTP-500-Falle).
- **Static Guardrails:** `php tests/guardrails.php` (kein Framework nötig) prüft Tool-Registrierungs-Vollständigkeit, Layer-Abhängigkeitsrichtung und Blade-Alias-Mangling. Exit 0 = grün.
- **Git-/Deploy-Workflow** (pull → commit → push → auf der Umgebung `composer update` + `update.sh`): siehe übergeordnete `CLAUDE.md`.
