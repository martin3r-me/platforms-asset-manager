# ADR 0002 — Währungsumrechnung: Snapshot-fx_rate statt Live-Kurs

- **Status:** akzeptiert
- **Datum:** 2026-06-14
- **Bezug:** löst den offenen Punkt aus [ADR 0001](0001-cost-lines-modell.md) („FX-Kurs ist Stichtagswert; spätere automatische Kursquelle möglich") · Logic-Audit Tasks 12 & 25

## Kontext

Ein Teil der wiederkehrenden Kosten wird **nicht in EUR** abgerechnet (bei BROICH u. a. einige
USD-Posten, z. B. ChatGPT). Die Auswertung (Pivot, Dashboard-Total, Mitarbeiter-/Kostenstellen-Sicht)
rechnet aber durchgängig in EUR. Es braucht eine definierte, auditierbare Umrechnungslogik. Bisher wurde
`fx_rate` zwar gespeichert, aber `monthly_amount` fiel bei fehlendem Kurs still auf 1:1 zurück (USD wurde
faktisch wie EUR gewertet).

## Entscheidung

1. **Snapshot-Kurs.** Jede Nicht-EUR-Kostenposition trägt `currency` **und** einen bei der Erfassung
   eingetragenen `fx_rate` (Kurs der Fremdwährung → EUR). Der Kurs wird **eingefroren** und dauerhaft so
   verwendet — kein Live-Bezug zur Laufzeit. `monthly_amount = amount × fx_rate × frequency_factor`
   (`AssetCostLine::computeMonthlyAmount()`).
2. **Pflicht-Kurs für Nicht-EUR.** `CreateCostLineTool` / `UpdateCostLineTool` lehnen eine Position mit
   `currency != EUR` und fehlendem/`≤ 0`-`fx_rate` ab (`VALIDATION_ERROR`). Verhindert die stille
   1:1-Bewertung.
3. **Erfassung über manuelle Cost-Lines.** USD-Posten werden im UI / über die MCP-Tools als Cost-Lines mit
   `currency='USD'` + `fx_rate` gepflegt. Der **Excel-Importer bleibt EUR-only** (er wird nicht
   weiterentwickelt, siehe ADR 0001 / Entkopplung von Excel) — `currency` ist trotzdem Teil des
   `import_hash`, damit gleiche Position in unterschiedlicher Währung nicht kollidiert.
4. **Team-konfigurierbar, keine Hardcodes.** Es gibt keine fest verdrahtete Währung oder Kurs im Schema/in
   der Logik (Multi-Tenant-Leitplanke). Welche Posten in welcher Währung laufen, ist reine Dateneingabe.

## Konsequenzen

- **+** Deterministisch & auditierbar: eine Auswertung liefert für denselben Datenstand immer dieselbe
  Summe, unabhängig vom Abrufzeitpunkt.
- **+** Keine externe Abhängigkeit / kein Netzwerkpfad in der Aggregation.
- **−** Kursänderungen schlagen nicht automatisch durch — der Kurs muss bei Bedarf manuell aktualisiert
  werden (bewusst: eine Kostenaufteilung ist eine Stichtagsrechnung, kein Devisen-Ticker).
- **Migrationspfad zu Live-FX** bleibt offen: eine zentrale Kursquelle könnte `fx_rate` beim Speichern
  vorbefüllen, ohne die Snapshot-Semantik der gespeicherten Zeile zu ändern.

## Alternativen verworfen

- *Live-FX zur Laufzeit* — macht Summen zeitabhängig/nicht reproduzierbar, braucht eine Kursquelle und
  einen HTTP-Pfad in der Aggregation; nicht modul-lokal.
- *Implizite 1:1-Wertung ohne Kurs* — der bisherige stille Bug (USD = EUR); durch Task 12 abgestellt.
