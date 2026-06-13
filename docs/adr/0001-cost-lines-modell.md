# ADR 0001 — Kostenaufteilung über generische Kostenpositionen (`asset_cost_lines`)

- **Status:** akzeptiert
- **Datum:** 2026-06-13
- **Kontext-Doku:** siehe `CONTEXT.md`

## Kontext

Die IT pflegte die Kostenaufteilung manuell in `Kostenaufteilung_IT.xlsx` (12 Sheets). Kern ist eine
Matrix **Kostenstelle × Kostenart** (monatlich + quartalsweise), gespeist aus vielen Detail-Listen
(pro Mitarbeiter, Drucker, Internet, Abos, Lizenzen). Der Asset-Manager kannte bisher nur Intune-Hardware
(Capex/AfA) und MS-Lizenzen. Die breite wiederkehrende **Opex** (Mobilfunk, Leasing, Internet, Drucker,
ChatGPT, Canva, necta, HGK, BPEvent) sowie die Begriffe Kreditor, GL-Konto, Gesellschaft und Kostenstelle
als Entität fehlten.

## Entscheidung

1. **Generische Kostenposition `asset_cost_lines`** als Rückgrat. Sheets 3,4,6,7,8,9,10,11,12 sind
   strukturell identisch (Betrag, Frequenz, Kostenstelle, Kreditor, Kostenart, optional Person/Asset).
   Eine Zeile = eine wiederkehrende Kostenposition mit normalisiertem `monthly_amount`.
2. **Stammdaten als eigene Tabellen**: `asset_companies` (Gesellschaft), `asset_cost_centers` (Kostenstelle,
   `employee.cost_center`-String per Backfill → FK migriert, String bleibt Fallback), `asset_vendors` (Kreditor),
   `asset_cost_types` (Kostenart-Katalog).
3. **Doppelzählung verhindern** über `asset_cost_types.aggregation_source` ∈ {`cost_line`, `hardware_afa`,
   `ms_license`, `asset_device`}. Jede Kostenart-Spalte zieht aus genau einer Quelle. MS-Lizenzen bleiben in den
   SKU-Tabellen, Hardware-AfA in `asset_items.monthlyCost()`, Intune-Geräte in `asset_devices` (Kosten je
   Gerät-Override → `asset_device_models`-Default). Stellt man eine Kostenart von `cost_line` auf `asset_device`
   um, fallen ihre manuellen Importzeilen automatisch aus dem `cost_line`-Block.
4. **Excel-Import** als einmaliger Bootstrap (`asset-manager:import-costs`), idempotent über `import_hash`.
   Importiert **nur** Opex (`cost_line`-Kostenarten); MS-Lizenzen und gekaufte Hardware kommen aus
   Graph-Sync bzw. Inventar.
5. **Pivot** `CostAggregationService::costCenterByType()` reproduziert Sheet1/2 (monatlich + Quartal-Toggle).

## Konsequenzen

- **+** Sheet1/2 exakt reproduzierbar; eine Quelle der Wahrheit je Kostenart.
- **+** Manuelle Pflege, Excel-Import und Graph-Sync schreiben in dasselbe Modell.
- **+** Drucker/Internet/Laptops als `AssetItem` (Inventar + Historie) mit verknüpften `cost_line`(s).
- **+** Intune-Geräte können eigene Monatskosten tragen (`aggregation_source='asset_device'`): Leasing-Rate
  oder Kauf+AfA je Gerät-Override bzw. `asset_device_models`-Default — gated, damit nichts doppelt zählt.
- **+** Excel-Import nutzt einen eigenen schlanken Reader (`ZipArchive` + `SimpleXML`,
  `CostExcelImportService::readWorkbook()`) — **keine** Dependency `phpoffice/phpspreadsheet`. Liest den
  gecachten Zellwert (`<v>`, also auch Formelergebnisse); unterstützt nur `.xlsx` (kein altes `.xls`).
- **−** Laptop-Leasing kommt aus der Übersicht-Spalte (`lap_dock`), nicht aus dem Laptop-Sheet — Laptop-`AssetItem`
  bleibt kostenfrei (kein AfA), um Doppelzählung zu vermeiden.
- **Offen:** FX-Kurs (ChatGPT USD→EUR) ist Stichtagswert; spätere automatische Kursquelle möglich.

## Alternativen verworfen

- *Pro Kostenart eine Tabelle* — zu viele fast identische Schemata, kein gemeinsamer Pivot.
- *cost_center als String belassen* — keine saubere Gesellschaft-Gruppierung, fehleranfällige Aggregation.
- *MS-Lizenzen zusätzlich als cost_line importieren* — Doppelzählung gegen den Graph-Sync.
