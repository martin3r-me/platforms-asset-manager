# Mobilfunk ohne eigene Vertrags-Entität — Kosten als cost_line, Metadaten am Mitarbeiter

Status: akzeptiert (beschlossen 2026-07-01)

Kontext: Gewünscht waren (1) die **Kosten der Vodafone-Mobilfunkverträge** je Mitarbeiter und (2) die
**Rufnummern** sowie **Abteilung/Position** aus Microsoft Entra (Graph). Der erste Entwurf sah eine eigene
Entität `AssetContract` (Rufnummer/Tarif/Preis/Laufzeit) als dritten Inventar-Typ mit neuer
`aggregation_source='contract'` vor. Bei der Prüfung gegen die Domäne fiel auf: **Mobilfunk-Kosten sind
bereits gelöst.** `CostBootstrap::NEUTRAL_COST_TYPES` seedet für *jedes* Team die Kostenart `mobilfunk`
(`aggregation_source='cost_line'`, `per_employee=true`); das BROICH-Set trägt sie mit Kreditor **Vodafone**,
und „Vodafone" ist als [[Kreditor]] geseedet. `CONTEXT.md` führt „Mobilfunk" explizit als `cost_line`.
Geklärte Anforderung: **eine** Rufnummer je Person, **keine** Pool-/Daten-SIMs, Vertragsmetadaten nur
„nice to have".

Entscheidung: **Kein eigenes Mobilfunk-/Vertrags-Modell.**

- **Kosten** = bestehende [[Kostenposition]] der Kostenart `mobilfunk` (`cost_line`, `per_employee`,
  `assignee`=Mitarbeiter, `vendor`=Vodafone, Laufzeit über `valid_from`/`valid_to`). `CostAggregationService`
  bleibt **unverändert** — die Kosten fließen bereits in Dashboard, Pivot, Kosten-pro-Mitarbeiter und
  `byVendor`.
- **Rufnummer, SIM-Nummer, Vertragsnummer, Datenvolumen** = Felder am `AssetEmployee` (1:1). Rufnummern
  (`mobile_phone`/`business_phone`) kommen aus Entra (`mobilePhone`/`businessPhones`); `phone_overridden`
  schützt eine manuell korrigierte Nummer vor dem nächsten Sync.
- **Abteilung/Position** kommen aus Entra und sind **Entra-führend** (überschreibend). Benötigt nur
  `User.Read.All` (bereits erteilt); `$select` in `IntuneGraphService::getUsersWithLicenses()` ist erweitert.
- Die Anreicherung (department/jobTitle/Rufnummern) kapselt der gemeinsame Helfer
  `EmployeeService::applyGraphProfile()` und wird von BEIDEN Wegen genutzt: dem manuellen User-Import
  (`ImportTenantUsersJob`) UND dem **regulären Lizenz-Sync** (`SyncLicensesJob`, läuft bei jedem „Sync",
  iteriert `/users` ohnehin) — so werden die Felder bei jedem Sync automatisch aktualisiert, nicht nur beim
  seltenen manuellen Import. `SyncLicensesJob` legt dabei keine zusätzlichen Karteileichen an (reichert die
  ohnehin durchlaufenen Mitarbeiter an).
- Die Mitarbeiter-Detailseite (`Employees/Show`) zeigt einen **„Mobilfunk"-Block** (Nummer/SIM/Vertragsnr/
  Datenvolumen) und — nur bei aktivem Controlling — den Monatspreis **lesend** aus der Mobilfunk-Kostenposition.

## Bewusste Abgrenzungen / Trade-offs

- **Kein `aggregation_source='contract'`** — Mobilfunk existiert schon als `cost_line`. Eine zweite Quelle
  für dieselbe Kostenart würde die **Doppelzählungs-Invariante** verletzen ([ADR 0001]) und im BROICH-Team
  (Excel-Import enthält Mobilfunk-Zeilen) real doppelt zählen.
- **Felder am Mitarbeiter statt eigener Tabelle** — bei 1:1 (eine Nummer je Person) und ohne Pool-SIMs
  trägt eine eigene Entität nur Overhead (Model, CRUD, UI, Aggregation) für ein „nice to have". YAGNI.
- **Preis lebt in der Kostenposition, nicht am Mitarbeiter** — keine Doppelpflege; der Block liest die
  Summe der aktiven `mobilfunk`-Kostenpositionen (`cost_type.key='mobilfunk'`), gated über das Controlling
  ([ADR 0008]).
- **Keine separate Kündigungsfrist/Vertragsende** — die Laufzeit steckt in `AssetCostLine::valid_from/to`;
  ein zweiter Ort würde auseinanderdriften.
- **Entra-Precedence defensiv** — nicht-leere Graph-Werte überschreiben Abteilung/Position; **leere** Werte
  lassen den Bestand stehen, damit ein lückenhaft gepflegtes Entra keine guten Daten löscht.
- **Grenze der Entscheidung** — kommen später **mehrere Rufnummern je Person** oder **Pool-/M2M-SIMs**
  (Router, Alarmanlagen) hinzu, tragen die 1:1-Felder das nicht mehr; dann ist auf eine eigene Entität
  umzustellen und diese ADR abzulösen.

Verweise: [ADR 0001](0001-cost-lines-modell.md), [ADR 0008](0008-controlling-abschaltbare-schicht.md).
