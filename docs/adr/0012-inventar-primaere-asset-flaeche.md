# Inventar als primäre Asset-Fläche — Vereinigung am Read-/Detail-/Service-Layer

Status: akzeptiert (beschlossen 2026-06-29)

Kontext: „Assets" zerfiel in viele getrennte Voll-Seiten-Views — manuelle Asset-Liste/-Detail/-Anlage
(`asset_items`) plus die Intune-Geräte als komplett eigene Welt (`asset_devices`), dazu eine read-only
„Inventar"-Liste (Phase 1, [[project_inventory_unification]]). Gewünscht war das Gegenteil: **eine**
vereinte Hauptliste über alle Hardware, **eine** reichhaltige Detailseite, und Aktionen als **Modals**
statt eigener Seiten (weniger Views, mehr Popups). Gleichzeitig gelten: Core/UI-Modul sind tabu
(Goldene Regeln), und die beiden Tabellen haben **fundamental verschiedene** Felder, Status-Maschinen
und Schreibpfade (Item: `assignTo`/4-Status/löschbar; Gerät: Lifecycle-Audit/Kosten-Override/nie
löschbar/Intune-Felder read-only).

Entscheidung: **„Inventar" ist die primäre, schreibfähige Asset-Fläche.** Vereinigt wird **nur am
Read-/Detail-/Service-Layer**, nicht in der DB:

- **Liste:** `Inventory/Index` über `Services\InventoryService` → `Support\InventoryRow`-DTOs (read-only
  In-Memory-Merge beider Tabellen). Sidebar-Einstieg „Inventar" zeigt hierauf.
- **Detail:** **eine** Komponente `Inventory/Show` unter `asset-manager.inventory.show` mit
  `{type}=manual|intune` + `{id}`. `mount()` lädt per Typ-Dispatch **entweder** `AssetItem` **oder**
  `AssetDevice` (team-gescopet). Anzeige über `Support\AssetSubject` (typ-übergreifende Header-/
  Quick-Stat-Felder). Tabs (Übersicht/Notizen/Kosten + Platzhalter) via Alpine-`@entangle`.
- **Schreiben:** ein gemeinsamer `Services\AssetWriteService` (UI-frei) kapselt alle mehrschrittigen
  Schreibvorgänge (Item: create/update/assign/depreciation/notes; Gerät: cost/lifecycle+Audit/notes),
  geteilt von Anlage-Modal (Liste) und Edit-Modals (Detail). Rechte prüft der Caller (Gate/Policy).
- **Modals statt Seiten:** Anlegen/Bearbeiten/Zuordnen/Abschreibung/Notizen/Löschen laufen als
  `<x-ui-modal>`-Popups; die Detailseite ist read-only mit Edit-Triggern.
- Zeilen-Links (`InventoryRow::detailRoute`) zeigen auf `inventory.show`; **`assets.show` UND `devices.show`
  redirecten dorthin** (Phase 6: Geräte-Diagnose — Events/Sync-Timelines, Rohdaten, Geräteausgabe-Anlage —
  ist als device-only „Verlauf"-Tab + Karten in `inventory.show` portiert). `devices.index` (Intune-Liste)
  bleibt als gefilterter Einstieg.

## Bewusste Abgrenzungen / Trade-offs

- **Typ-Dispatch statt Schein-Abstraktion** — kein gemeinsames Schreib-Interface über Item+Gerät; die
  Schreibpfade sind zu verschieden. `match($type)` in `Inventory/Show` ist ehrlicher. `AssetSubject`/
  `InventoryRow` kapseln **nur** die read-Anzeige.
- **Keine DB-Brücke** — `asset_items` und `asset_devices` bleiben getrennt (kein physischer Merge,
  konsistent mit [ADR 0003] und [[project_inventory_unification]]). IDs kollidieren nicht, weil die
  Route per `{type}` diskriminiert.
- **Status bleibt zweigeteilt** — Item-`status` (4) vs. Geräte-`lifecycle_status` (6, [ADR 0007]) sind
  zwei Maschinen; „Status" ist nur ein **Anzeige-Dach** (eine Badge), Bearbeiten ist typ-spezifisch.
- **Lizenzen draußen** — per-User-SKU passt nicht in eine Hardware-Zeile; eigene Sicht bleibt.
- **Geräte-Schreiblogik = nur noch `AssetWriteService`** — `Devices/Show` (Komponente + Blade) ist in
  Phase 6 **entfernt**; die früher temporäre Doppelung (inline vs. Service) ist damit aufgelöst.
  `LifecycleAuditTest` testet jetzt `Inventory/Show::saveDeviceEdit` (umgezogen).
- **Terminologie** — „Inventar" = Dach, „Asset" = nur manuelles Item, „Zuordnung" (nicht
  Zuweisung/Besitzer), „Geräteausgabe" ≠ „Übergabe" (siehe `CONTEXT.md`).

Verweise: [ADR 0003](0003-multi-tenant-tenant-modell.md), [ADR 0006](0006-geraete-identitaet-seriennummer.md),
[ADR 0007](0007-lifecycle-pinnt-gegen-reconcile.md), [ADR 0009](0009-provider-abstraktion-zwei-rollen.md),
[ADR 0011](0011-statusfarben-wcag-aa-abweichung.md).
