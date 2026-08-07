# Tenant als Zugriffsgrenze + tenant-gebundenes Kostenmodell

Status: akzeptiert (beschlossen 2026-08-07, Max/Martin) — **löst die entsprechenden Passagen von
[ADR 0003](0003-multi-tenant-tenant-modell.md) ab**

Kontext: [ADR 0003](0003-multi-tenant-tenant-modell.md) hat den Tenant als **Arbeitsfilter** eingeführt
und das Kostenmodell **ausdrücklich ausgeklammert** (team-weit, kein `tenant_id`). Beide Annahmen
tragen nicht mehr:

1. **Der Filter ist zu schwach.** Der aktive Tenant filtert heute nur 10 der 27 Livewire-Komponenten;
   `scopeForTenant()` muss an jeder Query manuell gerufen werden (~200 Aufrufe). Ein vergessener Aufruf
   liefert **still** team-weite Daten — Broich-Zeilen erscheinen in der Culinaria-Ansicht. Show-Seiten
   prüfen nur das Team, ein Fremd-Tenant-Datensatz bleibt per URL sichtbar.
2. **Das team-weite Kostenmodell trägt Mehrmandanz nicht.** Kostenarten, Kreditoren, Kostenstellen und
   Kostenpositionen sind gemeinsam für alle Tenants. Sobald ein zweiter Kunde eigene Kostenstellen und
   Kreditoren braucht, mischen sich die Stammdaten unauflösbar. Die ADR-0003-Begründung („die
   Kostenaufteilung ist stark BROICH-spezifisch") widerspricht zudem der
   Multi-Tenant-Leitplanke: keine Kundenspezifika im Schema.
3. **Der externe Kanal macht es scharf.** Die MCP-Tools antworten team-weit; ein geplanter
   Read-only-Endpunkt für Kunden liefert bei vergessenem Filter Fremdkundendaten **nach außen** statt
   nur in die falsche interne Ansicht.

Entscheidung: **Der Tenant ist eine Zugriffsgrenze, und das Kostenmodell ist tenant-gebunden.**

- **Grenz-Semantik**: Ein Datensatz eines fremden Tenants ist **nicht sichtbar** — Show-Seiten
  antworten mit **404** (nicht 403: die Existenz wird nicht preisgegeben). Zusätzlich zum bestehenden
  Team-Check, nicht statt ihm; `team_id` bleibt die äußere Sicherheitsgrenze.
- **Zentrale Durchsetzung statt opt-in**: Ein **Global Scope** im Trait `Concerns\TenantScopable`,
  gespeist aus `Services\TenantContext` über ein Request-Binding. Ein vergessener Filter kann keine
  Fremddaten mehr liefern, weil es keinen Filter mehr zu vergessen gibt. Ausstieg nur **explizit** über
  `withoutTenantScope()`, und nur auf den Pfaden ohne Auth-Kontext (Sync-Jobs, Console-Commands,
  Reconcile).
- **Nichts wird zwischen Tenants geteilt**: `asset_cost_centers`, `asset_cost_types`, `asset_vendors`,
  `asset_cost_lines`, `asset_assignments`, `asset_handover_lines` und `asset_team_settings` erhalten
  `tenant_id`. `tenant_id` ist **DB-seitig NOT NULL** — kein Datensatz ohne Tenant, erzwungen von der
  Datenbank und nicht nur von der Applikation.
- **Ein Kostenstellen-Baum**: `asset_companies` (Gesellschaft) war eine feste zweite Ebene über den
  Kostenstellen. Sie wird zur **Wurzelebene von `asset_cost_centers`** migriert (`parent_id`, `depth`),
  die Tabelle entfällt. Beliebige Tiefe je Tenant — „Broich Verwaltung" ist ein **Kind** von
  „Broich Catering", nicht ihr Geschwister.
- **Geräte-Modelle geteilt, ihre Kosten nicht**: `asset_device_models` bleibt ein **team-weiter**
  Hardware-Katalog (Hersteller/Modell — ein MacBook Air M2 ist bei jedem Kunden dasselbe Gerät). Die
  vier Kostenfelder ziehen in eine tenant-scoped `asset_device_model_costs` (Tenant × Modell), weil
  Leasingrate und Kreditor pro Kunde verhandelt sind.
- **Funktionskonten ohne Sonderregel**: Sie bekommen `tenant_id` wie alles andere. Begründung: das
  Modul wird in Kundeninstallationen mit **genau einem** Tenant wiederverwendet — dort landen sie
  ohnehin in diesem einen. Eine Sonderregel wäre Komplexität ohne Gegenwert.
- **„Alle Tenants" als expliziter Umschalter-Wert**, zulässig **nur** auf Dashboard und Auswertungen,
  dort mit ausgewiesenem Tenant je Zeile. Auf Listen- und Detailseiten fällt der Wert auf den zuletzt
  gewählten Tenant zurück.
- **Globaler Umschalter** in der Modul-Sidebar (die der Core auf jeder Modulseite einbettet) statt als
  `@include` in 10 Actionbars. Sichtbar ab **≥2 Tenants** — bewusst nicht „≥2 Connectoren": ein
  Manuell-Kunde ohne Microsoft-Anbindung ist ein vollwertiger Tenant und muss anwählbar bleiben.

## Was von ADR 0003 abgelöst wird

| ADR 0003 sagte | Gilt jetzt |
|---|---|
| „Aktiver Tenant = Arbeitsfilter, **keine** Zugriffsgrenze" | Zugriffsgrenze; Show-Seiten liefern 404 bei fremdem Tenant |
| „Detail-/Show-Seiten behalten **ausschließlich** den Team-Check" | Team-Check **und** Tenant-Check |
| „Ein Datensatz eines anderen Tenants desselben Teams bleibt per URL sichtbar" | nicht mehr sichtbar |
| „UPN-basierte Queries bleiben **ohne** Tenant-Filter korrekt" | explizit tenant-gescoped (`findOrCreateByUpn`, Lizenz-Zuordnung, `anonymize`) |
| „Das Kostenmodell bleibt vollständig aus dem Multi-Tenant-Scope ausgeklammert … team-weit und unangetastet" | tenant-gebunden (`tenant_id` NOT NULL auf allen Kostentabellen) |
| „Kostenmodell ggf. künftig in ein eigenes Modul auslagern" | verworfen, siehe [ADR 0015](0015-kundenkostenstellen-bleiben-im-asset-manager.md) |
| Tenant-Modell, Connector-Trennung, Consent-Modell, On-Delete-Verhalten | **unverändert gültig** |

## Bewusste Abgrenzungen / Trade-offs

- **404 statt 403.** Ein 403 bestätigt, dass der Datensatz existiert. Bei getrennten Kundenkontexten ist
  schon diese Information zu viel.
- **Global Scope ist ein scharfes Werkzeug.** Er greift auch dort, wo *kein* User im Request steckt —
  in Queue-Jobs und Console-Commands. Deshalb sind alle drei Sync-Jobs (`SyncIntuneDevicesJob`,
  `SyncLicensesJob`, `ImportTenantUsersJob`), die Console-Commands und die Reconcile-Läufe **explizit**
  mit `withoutTenantScope()` ausgenommen. Wird das vergessen, syncen sie ins Leere statt falsch — der
  Fehlermodus ist bewusst „nichts passiert" und nicht „falsche Daten".
- **Der Umschalter wird einfacher, nicht komplizierter.** Er schreibt nur noch in `TenantContext` und
  löst ein Neuladen aus; das Filtern übernimmt der Global Scope. Unterm Strich weniger Mechanik als die
  heutige Kombination aus Trait, Property und 10 Includes.
- **`asset_categories` bleibt global** (kein `team_id`, `key` global unique) — ein reiner Typ-Katalog
  ohne Kundenbezug. Nicht jede Tabelle bekommt einen Tenant.
- **Migrationsrisiko ist real.** Die Schema-Welle zieht `tenant_id` auf NOT NULL und hängt Uniques um;
  auf MySQL scheitert `dropUnique` an FK-gestützten Indizes (Fehler 1553), obwohl SQLite grün ist.
  Gegenmaßnahmen: Plain-Index als FK-Stütze, idempotente Guards, `migrate --force`, Bündelung in **eine**
  Welle, damit `asset_cost_centers` nicht dreimal angefasst wird.
- **Preis: Kosten-Auswertungen über alle Kunden brauchen jetzt einen expliziten Modus.** Vorher war
  team-weit der Default. Das ist gewollt — der Default soll die engere, sichere Sicht sein.

## Considered Options

- *Beim Arbeitsfilter bleiben, nur die 17 fehlenden Komponenten nachziehen* — verworfen: löst das
  Strukturproblem nicht. Die 18. Komponente vergisst den Filter wieder, und der externe Endpunkt macht
  daraus einen Datenschutzvorfall statt eines Anzeigefehlers.
- *Kostenmodell team-weit lassen, nur Inventar tenant-scopen (Status quo ADR 0003)* — verworfen:
  spätestens beim zweiten Kunden mit eigenen Kostenstellen und Kreditoren unhaltbar.
- *Tenant als eigenes Plattform-Team abbilden (Team = Tenant)* — verworfen: ein Team betreut legitim
  mehrere Tenants und braucht die Gesamtsicht; die Vereinigung wäre ein Rückschritt zum
  Ein-Kunde-pro-Installation-Modell, das wir gerade nicht wollen ([ADR 0015](0015-kundenkostenstellen-bleiben-im-asset-manager.md)).
- *Gesellschaft als eigene Tabelle behalten und nur tenant-scopen* — verworfen: zwei feste Ebenen
  reichen nicht (Broich braucht drei), und „Gesellschaft" ist strukturell nichts anderes als eine
  Kostenstelle ohne Eltern.

Verweise: [ADR 0003](0003-multi-tenant-tenant-modell.md) (in Teilen abgelöst),
[ADR 0001](0001-cost-lines-modell.md), [ADR 0004](0004-schreibrechte-owner-admin.md),
[ADR 0008](0008-controlling-abschaltbare-schicht.md),
[ADR 0015](0015-kundenkostenstellen-bleiben-im-asset-manager.md),
[ADR 0017](0017-asset-traeger-statt-mitarbeiter.md).
