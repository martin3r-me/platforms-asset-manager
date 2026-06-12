# CONTEXT — platforms-asset-manager

Domänen-Sprache und Kernkonzepte des Asset-Managers. Kurz halten, bei Bedarf erweitern.

## Zweck

Verwaltet IT-Assets (Hardware, Lizenzen, Geräte) **und** deren Kosten je **Kostenstelle** und
**Gesellschaft** — als digitales Abbild der bisherigen manuellen Excel `Kostenaufteilung_IT.xlsx`.

## Datenquellen (drei, doppelzählungsfrei)

Jede **Kostenart** (`asset_cost_types.aggregation_source`) zieht ihren Pivot-Wert aus **genau einer** Quelle:

| Quelle | aggregation_source | Beispiele | Herkunft |
|---|---|---|---|
| Kostenposition | `cost_line` | Mobilfunk, Leasing, Internet, Drucker, ChatGPT, Canva, necta, HGK, BPEvent | manuell / Excel-Import |
| Hardware-AfA | `hardware_afa` | gekaufte Hardware (lineare Abschreibung) | `asset_items.monthlyCost()` |
| MS-Lizenz | `ms_license` | Microsoft 365 SKUs | Graph-Sync (`asset_license_skus` × `asset_user_licenses`) |

→ MS-Lizenzen und gekaufte Hardware werden **nie** als `cost_line` doppelt erfasst.

## Glossar

- **Gesellschaft** (`AssetCompany`) — Firmen-/Bereichsgruppe (BROICH - GF GL, VERWALTUNG, RHEIN RUHR, BONN, SPORTS, KITA, BHG.DIGITAL, EFP). Gruppiert Kostenstellen.
- **Kostenstelle** (`AssetCostCenter`) — Buchungsstelle, Code wie `2599` (auch `EFP`). Hauptachse der Kostenaufteilung. Mitarbeiter, Kostenpositionen und Assets hängen daran.
- **Kostenart** (`AssetCostType`) — Spalten der Excel-Pivot (MS Lizenz, Mobilfunk, Lap+Dock, …). Trägt Default-Kreditor, Buchungssystem, Frequenz, `aggregation_source`.
- **Kreditor** (`AssetVendor`) — Lieferant/Rechnungssteller (Vodafone, CSI Leasing, OpenAI, GRENKE, …).
- **Kostenposition** (`AssetCostLine`) — eine wiederkehrende Opex-Zeile: Betrag + Frequenz → normalisiertes `monthly_amount` (EUR). Verknüpft optional Mitarbeiter (`assignee`), Asset (`asset_item`), Kostenstelle, Kreditor; trägt GL-Konten (BPEvent) und Verteilfaktor (HGK).
- **Funktionskonto** — `AssetEmployee.account_type = 'function'` (CONTROLLING, HELPDESK, WEBSHOP …). Trägt Kosten ohne echte Person; synthetische UPN `<slug>@funktion.import.local`.
- **Frequenz** — `monthly|quarterly|yearly|once`; Normalisierung auf Monat in `AssetCostLine::computeMonthlyAmount()` (saving-Hook).
- **Buchungssystem** — `HGK` oder `Moss` (welche Buchhaltung die Kostenart kontiert).

## Kostenaufteilung (Pivot)

`CostAggregationService::costCenterByType($teamId, 'monthly'|'quarterly')` reproduziert Excel Sheet1/2:
Zeilen = Kostenstellen (gruppiert nach Gesellschaft), Spalten = Kostenarten, Summenzeile/-spalte, Metazeilen (Kreditor/System/Frequenz). Quartal = ×3. UI: `Costs/Allocation`.

## Dateneingabe

1. **Einmaliger Excel-Bootstrap**: `php artisan asset-manager:import-costs --team=ID --file=… [--dry-run]` (idempotent via `import_hash`).
2. **Manuelle Pflege**: Livewire-CRUD (Kostenpositionen, Kostenstellen, Kreditoren, Kostenarten).
3. **Graph-Sync**: liefert weiterhin MS-Lizenzen + Intune-Geräte/Hardware.

## Konventionen

Auto-increment IDs (kein UuidV7), `team_id`-Scoping, Tabellen-Prefix `asset_`. Stammdaten + code→Gesellschaft-Mapping in `src/Support/CostBootstrap.php`. Siehe Plattform-`CLAUDE.md` für Modul-Architektur und Goldene Regeln.
