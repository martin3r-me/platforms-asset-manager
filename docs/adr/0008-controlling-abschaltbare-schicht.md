# Controlling als abschaltbare, team-weite Schicht

Status: akzeptiert (beschlossen 2026-06-22, Umsetzung steht aus)

Kontext: Das Kostenmodell ist stark BROICH-spezifisch (ADR 0001) und bewusst **team-weit, aus dem Multi-Tenant-Scope ausgeklammert** (ADR 0003). Der universelle Kern des Moduls ist der **IT-Asset-/Geräte-Lifecycle** (Zielnutzer: IT-Administration). Ein Team ohne Controlling-Bedarf soll die Kosten-/Buchhaltungs-Flächen nicht sehen, ohne dass das Inventar darunter leidet.

Entscheidung: Wir führen einen **per-Team-Schalter** `Controlling` ein. Er gated als Einheit: Kostenaufteilung, Kostenpositionen, Kosten-Auswertungen, den „Geräte nach Modell"-Report, die Stammdaten (Gesellschaft/Kostenstelle/Kostenart/Kreditor) und den Excel-Import. **Default: AUS** für neue Teams; eine **einmalige Migration** setzt das Flag auf **AN** für Teams mit bereits vorhandenen Controlling-Daten (Bestandsschutz BROICH).

Der Schalter ist ein **Präsentations-/Zugriffs-Gate, kein Daten-Gate**:
- Daten (Geräte-Kosten-Overrides, Modell-Defaults, Cost-Lines) bleiben unangetastet; `CostAggregationService` wird bei „aus" schlicht nicht aufgerufen. Wieder-Einschalten stellt alles her.
- Die **Dashboard-Kosten-Kacheln** folgen demselben Flag.
- **Seeding bleibt manuell** über die (gegatete) Stammdaten-Seite (`seedForTeam()` / „Seed-Defaults"-Button). Es gibt heute **kein** automatisches Kostenart-Seeding bei Team-Anlage — bei deaktiviertem Controlling ist der Button schlicht unerreichbar, also entsteht kein Cruft.
- **MCP-Kosten-Tools verweigern hörbar** bei „aus" (`CONTROLLING_DISABLED`, analog `ACCESS_DENIED`/ADR 0004), statt unsichtbar zu sein.
- **`Geräte-Modelle` bleibt IT-Kern** (immer sichtbar) — nur die **Kosten-Spalten** werden bei deaktiviertem Controlling ausgeblendet.

## Bewusste Abgrenzungen / Trade-offs

- **Pro Team, nicht pro Tenant** — konsistent mit ADR 0003 (Kosten sind team-weit, ignorieren den aktiven Tenant). Ein per-Tenant-Schalter wäre inkohärent, da die Kostendaten gar nicht tenant-getrennt sind.
- Die gerenderte Sidebar ist hartcodiert im Blade (`config('asset-manager.sidebar')` ist ungenutzte Alt-Doku) → das Gating erfolgt im Blade **und** an den Routen **und** an den MCP-Tools, nicht über die Config.
