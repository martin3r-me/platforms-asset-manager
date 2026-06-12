# Issue tracker: Dev-Modul (office.bhgdigital.de, via MCP)

Issues, Features, Bugs, Entscheidungen und Doku für dieses Modul leben **nicht** in GitHub Issues,
sondern im **Dev-Modul** der Plattform — erreichbar über den Platform-MCP-Server. Faustregel aus
der plattformweiten `CLAUDE.md`: **keine Modul-Infos verstreut** — alles landet im passenden
Package im Dev-Modul, damit der Stand jederzeit nachvollziehbar ist.

## Struktur

```
Package (= platforms-asset-manager)
├── Boards
│   ├── Features  → Issues für Features/Aufgaben
│   ├── Bugs      → Issues für Fehler
│   └── (optional custom)
│   └── Slots (Spalten): Backlog · To Do · In Progress · Review · Done
├── Discussions   → Entscheidungen / offene Fragen
└── Docs          → dauerhafte Doku (overview, architecture, setup, api, data_model, …)
```

**Was gehört wohin?**
- **Feature/Aufgabe** → Issue auf dem **Features-Board** (in den passenden Slot ziehen, Fortschritt dort pflegen).
- **Fehler** → Issue auf dem **Bugs-Board**.
- **Entscheidung / offene Frage** → **Discussion** im Package.
- **Dauerhafte Doku** → **Docs** im Package.

## Zugriff (MCP)

Der Platform-MCP-Server verlangt als **allerersten Call** `core__context__GET` (aktiver Team-Kontext).
Danach Tools entdecken/aktivieren:

1. `core__context__GET` — Team-Kontext sicherstellen (Pflicht, immer zuerst).
2. `core__modules__GET` — verfügbare Module auflisten.
3. `tools__GET(module="dev")` — Dev-Modul-Tools aktivieren.
4. Package `platforms-asset-manager` samt Board-/Slot-IDs über die Dev-Tools auflösen
   (analog zum Beispiel `platforms-food-alchemisten`: Package-ID 23, Features-Board 53, Bugs-Board 54 —
   die IDs für `platforms-asset-manager` zur Laufzeit über die Tools ermitteln, nicht raten).

Alternativ Tools/Skills suchen: `tool_registry.SEARCH(query="…")` bzw. `skill_registry.SEARCH(query="…")`.

## When a skill says "publish to the issue tracker"

Lege ein Issue im Dev-Modul-Package `platforms-asset-manager` an — Features-Board für Aufgaben/Features,
Bugs-Board für Fehler — über die Dev-Modul-MCP-Tools. **Nicht** `gh issue create`.

## When a skill says "fetch the relevant ticket"

Hol das Issue über die Dev-Modul-MCP-Tools aus dem Package `platforms-asset-manager`.

## When a skill says "record a decision / open question"

Lege eine **Discussion** im Package an (nicht als Issue).
