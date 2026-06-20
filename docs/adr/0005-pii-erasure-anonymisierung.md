# ADR 0005 — PII-Erasure: gezielte Einzel-Anonymisierung (Auftragsverarbeiter)

- **Status:** akzeptiert
- **Datum:** 2026-06-20
- **Bezug:** Code-Review M7 (M13) · Entscheidung E2 · DSGVO Art. 17 · ergänzt [ADR 0003](0003-multi-tenant-tenant-modell.md)

## Kontext

Der Asset Manager speichert personenbezogene Daten (PII) zu Mitarbeitern der **Kunden-Tenants**:
`AssetEmployee` (`display_name`, `email`, `user_principal_name`, `raw_data`) sowie — über die UPN verknüpft —
`AssetDevice` (`user_display_name`, `user_principal_name`, `raw_data`) und `AssetUserLicense`
(`display_name`, `user_principal_name`, `raw_data`). Bisher gab es nur einen **Tenant-weiten Purge**
(Tenant löschen → Inventar kaskadiert), aber **keine gezielte Löschung/Anonymisierung einer einzelnen
Person**. Für ein DSGVO-Art.-17-Ersuchen einer einzelnen Person fehlte damit die Strecke.

Rollen: **BHG = Auftragsverarbeiter**, der **Tenant = Verantwortlicher**. BHG stellt das Werkzeug bereit;
die Auslösung ist eine bewusste Verwaltungsaktion des Kunden-Teams.

## Entscheidung

1. **Gezielte Einzel-Anonymisierung als Action** je Mitarbeiter (`EmployeeService::anonymize()`, ausgelöst
   über die Mitarbeiter-Detailseite). Sie **pseudonymisiert** Anzeigename, E-Mail und UPN und leert
   `raw_data` — und maskiert begleitend dieselbe PII auf den über die alte UPN verknüpften **Geräten** und
   **Lizenz-Zuweisungen** (die UPN wird dabei auf denselben stabilen Pseudonym gesetzt, damit die
   Verknüpfung erhalten bleibt). **Keine** Datensatz-Löschung — der Tenant-Purge bleibt die
   Komplett-Löschung.
2. **Owner/Admin-gated, team-scoped.** Die Action nutzt die zentrale Gate-Ability `asset-manager.manage`
   (ADR 0004) und prüft zusätzlich die Team-Zugehörigkeit des Mitarbeiters.
3. **KEINE Auto-Anonymisierung.** Es gibt keinen Hintergrund-Job / keine Aufbewahrungsfrist-Automatik —
   die Anonymisierung ist ausschließlich eine explizite Aktion (der Verantwortliche entscheidet).
4. **Re-Sync-Semantik.** Existiert die Person noch im M365 des Tenants, legt der nächste Sync sie unter
   ihrer echten UPN neu an (eigener Datensatz). Die Anonymisierung ist daher für **ausgeschiedene** Personen
   gedacht; für noch aktive Personen ist die Quelle (M365) zuerst zu bereinigen.

## Konsequenzen

- **+** DSGVO-Art.-17-Strecke für Einzelpersonen vorhanden, ohne den ganzen Tenant zu löschen.
- **+** Verknüpfungen (Geräte/Lizenzen ↔ Person) bleiben über den gemeinsamen Pseudonym konsistent;
  Auswertungen brechen nicht.
- **−** Bei noch aktiven M365-Personen ist die Anonymisierung nicht „endgültig" (Re-Sync legt neu an) —
  bewusst, weil die Quelle der Verantwortliche ist.
- **Logs:** Graph-Fehler werden nur noch mit `error.code`/`error.message` geloggt (kein voller Body),
  Import-Fehler mit generischer Nutzer-Meldung + Server-Log — damit keine PII in die Logs/UI leckt (N8/N9).

## Alternativen verworfen

- *Nur Tenant-Purge (Status quo)* — zu grob für ein Einzel-Ersuchen; löscht den ganzen Kundenkontext.
- *Harte Einzel-Löschung des Datensatzes* — bricht Verknüpfungen/Auswertungen und würde vom Re-Sync ohnehin
  teilweise neu erzeugt; Pseudonymisierung erhält Konsistenz bei gleichem Erasure-Effekt.
- *Automatische Anonymisierung nach Inaktivitätsfrist* — verworfen: die Aufbewahrungsentscheidung liegt beim
  Verantwortlichen (Tenant), nicht beim Auftragsverarbeiter (BHG).
