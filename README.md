# Platform Asset Manager

IT-Asset- und Kostenverwaltung für die Platform. Verwaltet Hardware, Lizenzen und Geräte **und**
deren Kosten je **Kostenstelle** — getrennt nach **Tenant** (Kundenkontext) — als digitales Abbild
der bisher manuellen Excel `Kostenaufteilung_IT.xlsx`. Darauf aufbauend: Geräteausgaben mit
Übergabeprotokoll, die Weiterberechnung an Kunden (easybill) und die DATEV-Umbuchung der IT-Kosten.

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
| Kostenposition | `cost_line` | manuell / MCP / Excel-Bootstrap / Rechnungsimporte (Vodafone Mobilfunk + Festnetz/RingCentral) |
| Hardware-AfA | `hardware_afa` | gekaufte Hardware (lineare Abschreibung) |
| MS-Lizenz | `ms_license` | Graph-Sync (User-Lizenzen) × Preis der Vertragszeile aus der Lizenz-Rechnung (ADR 0019) |
| Gerät | `asset_device` | Intune-Geräte mit Leasing-/AfA-Kosten (Geräte-Override → Modell-Default) |

Die Kostenaufteilung (Pivot Kostenstelle × Kostenart) reproduziert die Excel-Sheets über
`CostAggregationService::costCenterByType($teamId, 'monthly'|'quarterly')` (UI: `Costs/Allocation`).
Die Kostenstellen bilden einen **Baum je Tenant**; die obersten Knoten sind die **Gesellschaften**
(eine eigene Gesellschafts-Entität gibt es nicht mehr, ADR 0016).

Weitere Flächen: vereinte **Inventar**-Sicht für manuelle Assets und Intune-Geräte (ADR 0012),
**Asset-Träger** (wer ein Gerät/eine Lizenz trägt, ADR 0017), **Geräteausgaben** mit
Übergabeprotokoll (PDF, ADR 0028), **Weiterberechnung** an Kunden über easybill (ADR 0021/0024/0025),
**DATEV-Umbuchung** als EXTF-Stapel (ADR 0027). Kosten-/Controlling-Flächen sind je Tenant
abschaltbar (ADR 0008).

## Tenants & Anbindung

- **Tenant** (`asset_tenants`) = ein vom Team verwalteter Kundenkontext und die **Zugriffsgrenze**
  des Moduls (ADR 0016): jeder fachliche Datensatz gehört zu genau einem Tenant (`tenant_id`,
  NOT NULL). Durchgesetzt zentral über einen **Global Scope** (`Concerns\TenantScopable`); Ausstieg
  nur explizit über `withoutTenantScope()`. Der aktive Tenant wird pro User gespeichert und in der
  Modul-Sidebar umgeschaltet.
- **Connector** = optionale Microsoft-Anbindung eines Tenants (Intune-Geräte, M365-Lizenzen,
  Verzeichnis). Auth über **eine** zentrale Multi-Tenant-Azure-App (`config('asset-manager.azure.*')`,
  `.env`: `ASSET_MANAGER_AZURE_*`). Der Admin-Consent ist **manuell**: Consent-Link an den
  Kunden-Admin schicken, danach in der Connector-Ansicht **„Anbindung prüfen"** (Token-Test) —
  **kein** Callback. Anleitung: [`docs/connector-microsoft-onboarding.md`](docs/connector-microsoft-onboarding.md).
- Tenants **ohne** Connector sind reine Manuell-Kunden.

## Dateneingabe

1. **Einmaliger Excel-Bootstrap:** `php artisan asset-manager:import-costs --team=ID --file=… [--tenant=ID] [--dry-run]` (idempotent via `import_hash`).
2. **Manuelle Pflege:** Livewire-CRUD (Kostenpositionen, Kostenstellen-Baum, Kreditoren, Kostenarten, Inventar, Träger) bzw. die MCP-Tools.
3. **Rechnungsimporte** (UI `Costs/Import`): Vodafone-Rechnungsanalyse (Mobilfunk, ADR 0018), Vodafone-Festnetz-CSV + RingCentral-Benutzerliste (ADR 0026), Lizenz-Rechnung des Wiederverkäufers (ADR 0019).
4. **Graph-Sync** je Connector (Zeitplan im ServiceProvider): `asset-manager:sync-intune` (stündlich), `asset-manager:import-users` (01:30, Verzeichnis-Abgleich + Stilllegen ausgeschiedener Träger, ADR 0023), `asset-manager:sync-licenses` (02:00).

## Konventionen (dieses Modul)

- **Auto-increment IDs** — *kein* UuidV7.
- **Kein** `LogsActivity`-Trait.
- `team_id`-Filter explizit auf jeder Query, Tenant-Grenze über den Global Scope; Tabellen-Prefix `asset_`.
- Schreibrechte = Owner/Admin des Teams über das Gate `asset-manager.manage` — in UI **und** MCP (ADR 0004).
- **Content-Bereich:** modul-lokale Komponenten (`<x-asset-manager-*>`) + `--am-*`-Tokens statt `x-ui-*` (nur Page-Shell und Modals bleiben) — siehe [`DESIGN.md`](DESIGN.md).

> Plattformweite Modul-Architektur, Boot-Sequenz und Goldene Regeln stehen in der übergeordneten
> `C:\Coding\Platforms\CLAUDE.md` und gelten unverändert.

## Doku-Landkarte

| Datei | Inhalt |
|---|---|
| [`CONTEXT.md`](CONTEXT.md) | Domänen-Sprache, Glossar, Datenquellen, Pivot, Tenant/Connector |
| [`DESIGN.md`](DESIGN.md) | UI-Design-System (flach, Navy-Primary, `--am-*`-Tokens) |
| [`LLM_GUIDE.md`](LLM_GUIDE.md) | Kurzkontext für LLMs, die an diesem Modul arbeiten |
| [`docs/adr/`](docs/adr/) | Entscheidungen 0001–0029 (Übersicht unten) |
| [`docs/connector-microsoft-onboarding.md`](docs/connector-microsoft-onboarding.md) | Microsoft-Connector anbinden (zentrale App, manueller Consent) |
| [`docs/agents/`](docs/agents/) | Agent-Skills: Issue-Tracker, Triage-Labels, Domain-Docs, Logic-Audit, Architecture-Review |
| [`tests/README.md`](tests/README.md) | Die drei Test-Ebenen (Guardrails, `tests/local`, Host-Feature-Tests) |
| [`CLAUDE.md`](CLAUDE.md) | Repo-spezifische Skill-Konfiguration |

**ADRs** (`docs/adr/`):

| Nr. | Thema |
|---|---|
| 0001 | Kostenaufteilung über generische `asset_cost_lines` |
| 0002 | Währungsumrechnung via Snapshot-`fx_rate` |
| 0003 | Multi-Tenant: Tenant als eigenes Modell (Arbeitsfilter-Semantik abgelöst durch 0016) |
| 0004 | Schreibrechte = Owner/Admin, kanal-übergreifend (UI + MCP) |
| 0005 | PII-Erasure: gezielte Einzel-Anonymisierung |
| 0006 | Geräte-Identität = Seriennummer |
| 0007 | Terminaler Lifecycle pinnt ein Gerät gegen den Reconcile-Delete |
| 0008 | Controlling als abschaltbare Schicht (seit 0016 je Tenant) |
| 0009 | Provider-Abstraktion: zwei Rollen (MDM vs. Eigentum) |
| 0010 | Apple Business Manager als Eigentums-Quelle (Umsetzung steht aus) |
| 0011 | Statusfarben weichen für WCAG AA von den UI-Tokens ab |
| 0012 | Inventar als primäre Asset-Fläche |
| 0013 | Modul-lokale Design-Token-Schicht (`--am-*`) |
| 0014 | Mobilfunk ohne eigene Vertrags-Entität |
| 0015 | Kundenkostenstellen bleiben im Asset-Manager |
| 0016 | Tenant als Zugriffsgrenze + tenant-gebundenes Kostenmodell |
| 0017 | Asset-Träger statt Mitarbeiter |
| 0018 | Vodafone-Rechnungsanalyse als einzige Mobilfunk-Quelle |
| 0019 | Lizenzpreise an der Vertragszeile, nicht an der SKU |
| 0020 | Tabellen-Ansicht als Nutzer-Präferenz |
| 0021 | Weiterberechnung als Zählregel |
| 0022 | Deploy per Lock-Zeiger statt `composer update` |
| 0023 | Ausgeschiedene Träger stilllegen, nicht löschen |
| 0024 | Abrechnungs-Ausnahme mit Grund |
| 0025 | Belegdatum = Monatsletzter des Abrechnungsmonats |
| 0026 | Festnetz und RingCentral aus der Vodafone-Rechnung |
| 0027 | DATEV-Umbuchung aus den Monatsständen der Rechnungen |
| 0028 | Geräteausgabe: Signatur optional, eine offene je Gerät, Abdeckung als Kennzahl |
| 0029 | Preis-Historie der Rechnungsimporte: Betrag je Kostenposition und Rechnung, Regelpreis, Historie nachtragen |

## Struktur

```
src/
├── AssetManagerServiceProvider.php   # Boot: Modul-Registrierung, Routes, Gates, Views, Komponenten, Livewire, Tools, Zeitplan
├── Concerns/           # Traits: TenantScopable (Global Scope), GuardsTenantBoundary, ClampsListState, …
├── Console/Commands/   # Artisan: import-costs, sync-intune, sync-licenses, import-users, backfill-employees, classify-holders, merge-deleted-holders
├── Http/               # Api/AssetDeviceController, PDF-Controller (Übergabe, Abrechnung), Middleware EnsureControllingEnabled
├── Jobs/               # SyncIntuneDevicesJob, SyncLicensesJob, ImportTenantUsersJob, BackfillHoldersJob
├── Livewire/           # UI (Dashboard, Inventory/*, Devices/*, Holders/*, Licenses/*, Costs/*, CostLines, Billing, MasterData, Connectors, Settings, …)
├── Models/             # Eloquent (AssetCostLine, AssetCostCenter, AssetHolder, AssetDevice, AssetTenant, …)
├── Policies/           # Authorization (AssetItemPolicy, AssetDevicePolicy)
├── Services/           # Business-Logik (CostAggregationService, Importer, BillingRunService, DatevReallocationService, TenantContext, …)
├── Support/            # framework-freie Bausteine (XlsxReader, DeviceCostResolver, LicensePriceBook, DatevExtfWriter, CostBootstrap, …)
└── Tools/              # MCP/LLM-Tools (Naming: asset-manager.resource.VERB)
```

## Entwicklung

- Code = dieses Repo · ADRs = `docs/adr/` · Domänen-Glossar = `CONTEXT.md` · Tasks = Dev-Modul (Package `platforms-asset-manager`).
- **Vor dem Commit:** `php -l <datei>`; Modul-Blade echt kompilieren: `php tests/local/blade-lint.php <dateien>` (HTTP-500-Falle).
- **Static Guardrails:** `php tests/guardrails.php` (kein Framework nötig) prüft u. a. Tool-Registrierungs-Vollständigkeit, Layer-Abhängigkeitsrichtung, Blade-Alias-Mangling, Eager-Loading, Team-Reset-Vollständigkeit, Design-Tokens sowie Tenant-Auflösung und Rollenprüfung der MCP-Tools. Exit 0 = grün. Die übrigen Test-Ebenen: [`tests/README.md`](tests/README.md).
- **Git-Workflow:** pull → commit → push (siehe übergeordnete `CLAUDE.md`).
- **Deploy:** nach dem Push `python tools/deploy-lock.py` (erst `--dry-run`). Das Skript dreht in der
  `composer.lock` der Host-App nur den Commit-Zeiger dieses Pakets weiter, prüft vorher auf
  Metadaten-Drift der `composer.json` und pusht; der Server zieht per `composer install`. Ein
  `composer update` bzw. `update.sh` in der Host-App ist derzeit blockiert und **nicht** der
  Deploy-Weg (ADR 0022). Neue Migrationen laufen beim Deploy der Host-App mit.
