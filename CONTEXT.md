# CONTEXT — platforms-asset-manager

Domänen-Sprache und Kernkonzepte des Asset-Managers. Kurz halten, bei Bedarf erweitern.

## Zweck

Verwaltet IT-Assets (Hardware, Lizenzen, Geräte) **und** deren Kosten je **Kostenstelle** und
**Gesellschaft** — als digitales Abbild der bisherigen manuellen Excel `Kostenaufteilung_IT.xlsx`.

## Datenquellen (vier, doppelzählungsfrei)

Jede **Kostenart** (`asset_cost_types.aggregation_source`) zieht ihren Pivot-Wert aus **genau einer** Quelle:

| Quelle | aggregation_source | Beispiele | Herkunft |
|---|---|---|---|
| Kostenposition | `cost_line` | Mobilfunk, Leasing, Internet, Drucker, ChatGPT, Canva, necta, HGK, BPEvent | manuell / Excel-Import |
| Hardware-AfA | `hardware_afa` | gekaufte Hardware (lineare Abschreibung) | `asset_items.monthlyCost()` |
| MS-Lizenz | `ms_license` | Microsoft 365 SKUs | Graph-Sync (`asset_license_skus` × `asset_user_licenses`) |
| Gerät | `asset_device` | Intune-Geräte (Laptops/Notebooks) mit Leasing- oder AfA-Kosten | `asset_devices` (Kosten je Gerät-Override → `asset_device_models`-Default) |

→ MS-Lizenzen, gekaufte Hardware und Intune-Geräte werden **nie** zusätzlich als `cost_line` doppelt erfasst.
Wird eine Kostenart von `cost_line` auf `asset_device` umgestellt, fallen ihre manuellen Importzeilen
automatisch aus dem `cost_line`-Block (Aggregation gated über `aggregation_source`).

## Glossar

- **Gesellschaft** (`AssetCompany`) — Firmen-/Bereichsgruppe (BROICH - GF GL, VERWALTUNG, RHEIN RUHR, BONN, SPORTS, KITA, BHG.DIGITAL, EFP). Gruppiert Kostenstellen.
- **Kostenstelle** (`AssetCostCenter`) — Buchungsstelle, Code wie `2599` (auch `EFP`). Hauptachse der Kostenaufteilung. Mitarbeiter, Kostenpositionen und Assets hängen daran.
- **Kostenart** (`AssetCostType`) — Spalten der Excel-Pivot (MS Lizenz, Mobilfunk, Lap+Dock, …). Trägt Default-Kreditor, Buchungssystem, Frequenz, `aggregation_source`.
- **Kreditor** (`AssetVendor`) — Lieferant/Rechnungssteller (Vodafone, CSI Leasing, OpenAI, GRENKE, …).
- **Kostenposition** (`AssetCostLine`) — eine wiederkehrende Opex-Zeile: Betrag + Frequenz → normalisiertes `monthly_amount` (EUR). Verknüpft optional Mitarbeiter (`assignee`), Asset (`asset_item`), Kostenstelle, Kreditor; trägt GL-Konten (BPEvent) und Verteilfaktor (HGK).
- **Funktionskonto** — `AssetEmployee.account_type = 'function'` (CONTROLLING, HELPDESK, WEBSHOP …). Trägt Kosten ohne echte Person; synthetische UPN `<slug>@funktion.import.local`.
- **Frequenz** — `monthly|quarterly|yearly|once`; Normalisierung auf Monat in `AssetCostLine::computeMonthlyAmount()` (saving-Hook).
- **Buchungssystem** — `HGK` oder `Moss` (welche Buchhaltung die Kostenart kontiert).
- **Lifecycle-Status** (`AssetDevice.lifecycle_status`) — manuell gepflegter Geräte-Lebenszyklus: `in_use` · `spare` · `repair` · `defect` (kaputt) · `retired` · `lost`. Wird über die Geräte-Detailseite oder die Bulk-Aktion gesetzt (Intune liefert ihn nicht). Die **Geräte-Status-Sicht** (`asset-manager.devices.status`) zeigt Stückzahlen je Status + filterbare Liste, **tenant-rein** über den [[Tenant-Selektor]]; ein gleichnamiges MCP-Tool (`asset-manager.device-status.GET`) liefert die Status-Verteilung LLM-lesbar (Tenant-/Status-Filter).
- **Schreibrechte** (`asset-manager.manage`) — **Jeder** mutierende Zugriff (Inventar **und** Finanz-/Stammdaten) erfordert **Owner/Admin** des aktiven Teams; Member dürfen **lesen, nicht schreiben**. Eine zentrale Gate-Ability (`Gate::define('asset-manager.manage')` → `Support\TeamRole::isOwnerOrAdmin`) ist die **einzige Wahrheitsquelle**, geteilt von UI (`Gate::authorize` / `canManage()`) und MCP-Tools (`Gate::forUser($context->user)->allows(...)` → `ACCESS_DENIED`). Die Grenze ist **kanal-unabhängig** — UI und MCP setzen dieselbe Regel durch (siehe [ADR 0004](docs/adr/0004-schreibrechte-owner-admin.md)). _Abgrenzung_: `team_id` ist die Sicherheitsgrenze, der aktive Tenant nur ein Arbeitsfilter (ADR 0003).

### Anbindung & Tenants

- **Tenant** — Ein vom **Team** verwalteter Kundenkontext (`asset_tenants`), auf den sich das gesamte Inventar bezieht: jedes Inventar-Objekt (Gerät, Asset, Lizenz, Mitarbeiter) gehört zu **genau einem** Tenant (`tenant_id`, Pflicht, keine Mehrfach-Zugehörigkeit). **Kann**, muss aber nicht, eine Microsoft-Anbindung haben — ein Tenant **ohne** Connector ist ein reiner Manuell-Kunde (kein Intune). Ein Team hat viele Tenants, nicht teamübergreifend. _Avoid_: Mandant, Kunde, Organisation (= `platform-organization`-Begriff). _Hinweis_: bei uns **nicht** zwingend ein M365-Verzeichnis — die Azure-Tenant-GUID lebt am Connector.
- **Connector** — Die **optionale** Microsoft-365-/Intune-Anbindung eines Tenants (**0..1** je Tenant): trägt die Azure-Tenant-GUID (`azure_tenant_id`), Consent- und Sync-Status. Synchronisierte Geräte/Lizenzen kommen *durch* den Connector herein und erben den Tenant. _Avoid_: Integration, Verbindung.
  - **Zentrale App + Fallback**: Auth läuft über **eine** zentrale Multi-Tenant-Azure-App (`config('asset-manager.azure.*')`, geteilt). Hinterlegt ein Connector eigene `client_id`/`client_secret`, haben diese **Vorrang** (Legacy/Override) — sonst die zentrale App. Token-Cache ist **pro Connector**.
  - **Consent ist manuell**: Der Admin-Consent-Link (`/{azure_tenant_id}/v2.0/adminconsent`) wird angezeigt/verschickt; aktiviert wird über **„Anbindung prüfen"** (Token-Test) — **kein** öffentlicher Callback. `consent_confirmed_at` markiert die Aktivierung.
  - **Status** (abgeleitet, `connectionStatus()`): `incomplete` (kein Verzeichnis) · `pending` (Consent ausstehend) · `active` · `disconnected` (getrennt = `enabled=false`, **Daten bleiben**). Sync läuft **pro Connector** (`uniqueId=connector_id`), reconcile-delete strikt pro Tenant.
- **UPN-Eindeutigkeit** — Ein **Mitarbeiter** gehört zu **genau einem Tenant** (tenant-gebundenes Inventar). Eine **UPN ist global eindeutig** (= E-Mail in genau einem M365-Verzeichnis) → kein Kreuz-Tenant-UPN; das **team-weite Kostenmodell bleibt davon unberührt** (UPN→Kostenstelle kollisionsfrei). **Funktionskonten** (CONTROLLING …, synthetische UPN) sind team-weite Kosten-Konstrukte ohne reale Person → liegen im Default-Tenant, kein echtes Inventar.
- **Aktiver Tenant** — Der pro User gewählte, **dauerhaft gespeicherte** Tenant-Kontext (`asset_tenant_selections`, je User × Team). Filtert **ausschließlich die Inventar-Sichten** (Geräte, Lizenzen, Mitarbeiter, Inventar, Compliance, Assets, Drucker, Internet). **Dashboard** (tenant-übergreifend) und **Kosten-/Stammdaten** (team-weit) ignorieren ihn. Verwaltet von `Services\TenantContext` (gespeichert → Default → erster → `null`). _Hinweis_: ein **Arbeitsfilter, keine Zugriffsgrenze** — Listen sind tenant-rein, Detail-Seiten behalten nur den **Team**-Check (kein Tenant-403); ein Datensatz eines anderen Tenants desselben Teams bleibt per URL sichtbar (siehe ADR 0003).
- **Tenant-Selektor** — Das UI-Element (Dropdown in der Actionbar) zum Wechseln des aktiven Tenants. Nur sichtbar bei **≥2 Tenants**; bei genau einem Tenant filtern die Sichten still auf diesen. Livewire-Trait `Concerns\ScopesToTenant` + Model-Scope `Concerns\TenantScopable::forTenant()`.

## Kostenaufteilung (Pivot)

`CostAggregationService::costCenterByType($teamId, 'monthly'|'quarterly')` reproduziert Excel Sheet1/2:
Zeilen = Kostenstellen (gruppiert nach Gesellschaft), Spalten = Kostenarten, Summenzeile/-spalte, Metazeilen (Kreditor/System/Frequenz). Quartal = ×3. UI: `Costs/Allocation`.

## Dateneingabe

1. **Einmaliger Excel-Bootstrap**: `php artisan asset-manager:import-costs --team=ID --file=… [--dry-run]` (idempotent via `import_hash`).
2. **Manuelle Pflege**: Livewire-CRUD (Kostenpositionen, Kostenstellen, Kreditoren, Kostenarten).
3. **Graph-Sync**: liefert weiterhin MS-Lizenzen + Intune-Geräte/Hardware.

## Konventionen

Auto-increment IDs (kein UuidV7), `team_id`-Scoping, Tabellen-Prefix `asset_`. Stammdaten + code→Gesellschaft-Mapping in `src/Support/CostBootstrap.php`. Siehe Plattform-`CLAUDE.md` für Modul-Architektur und Goldene Regeln.
