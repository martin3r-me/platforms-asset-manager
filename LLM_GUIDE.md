# LLM-Orientierung — Asset Manager

Kurzkontext für LLMs, die **an diesem Modul** arbeiten. (Dies ist **kein** „neues Modul anlegen"-
Leitfaden mehr — die generische Template-Anleitung lebt im Template-Repo `module-template/LLM_GUIDE.md`.)

## Erst lesen

| Datei | wofür |
|---|---|
| [`README.md`](README.md) | Was das Modul tut, Struktur, Doku-Landkarte |
| [`CONTEXT.md`](CONTEXT.md) | Domänen-Sprache, Glossar, die vier Datenquellen, Pivot |
| [`DESIGN.md`](DESIGN.md) | UI-Design-System (custom Tailwind im Content-Bereich) |
| [`docs/adr/`](docs/adr/) | Akzeptierte Entscheidungen (Cost-Lines-Modell, FX-Snapshot) |
| [`docs/agents/`](docs/agents/) | Skills: Issue-Tracker, Triage, Domain-Docs, Logic-Audit, Architecture-Review |
| übergeordnete `C:\Coding\Platforms\CLAUDE.md` | Modul-Architektur, Boot-Sequenz, **Goldene Regeln** |

## Konventionen dieses Moduls — Abweichungen vom generischen Template merken

Das Modul wurde aus `module-template` gebootstrappt, weicht aber **bewusst** in mehreren Punkten ab.
Generische Template-Ratschläge **nicht blind** auf dieses Modul anwenden:

- **Auto-increment IDs**, *kein* `UuidV7` (siehe `CONTEXT.md`).
- **Kein** `LogsActivity`-Trait auf den Models.
- **Content-Bereich:** custom Tailwind, **keine** `x-ui-*`-Komponenten im Content (nur die Page-Shell:
  `x-ui-page`, `…-navbar`, `…-actionbar`, `…-container`, `…-sidebar`). Begründung: `DESIGN.md`.
- `team_id`-Scoping auf jeder Query: `Model::where('team_id', $user->currentTeam->id)`.
- Tabellen-Prefix `asset_`.

## LLM-First-Kontrakt (MCP/LLM-Tools)

- Tools liegen in `src/Tools/`, Naming `asset-manager.resource.VERB` (REST-Verben).
- **Tools rufen Services, nie direkt Models.** Business-Logik bleibt in `src/Services/` (stateless).
- Tool-Registrierung ist **explizit** in `AssetManagerServiceProvider::registerTools()` (kein Auto-Discovery):
  ein neues/entferntes Tool erfordert eine Editierung des ServiceProviders. (Static-Guardrail prüft die
  Vollständigkeit — siehe `docs/agents/architecture-review.md`.)

## Boundary / Goldene Regeln (verbindlich)

- Nur **im eigenen Modulordner** arbeiten. Core- und UI-Modul sind **tabu**.
- Andockpunkte (PlatformCore, ModuleRouter, `x-ui-*`-Shell, Core-Enums wie `StandardRole`) werden
  **genutzt, nicht verändert**. Modulübergreifende Änderungen vorher abstimmen.
- Vor jedem Commit: `php -l`, Modul-Blade gegen die Demo-App kompilieren (HTTP-500-Falle).
