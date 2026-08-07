# Kundenkostenstellen bleiben im Asset-Manager (nicht in `platform-organization`)

Status: akzeptiert (beschlossen 2026-08-07, Max/Martin)

Kontext: Der Asset-Manager führt einen eigenen Kostenstellen-Baum (`asset_cost_centers`, künftig mit
`parent_id`/`depth` — siehe [ADR 0016](0016-tenant-als-zugriffsgrenze.md)). Parallel bietet
`platform-organization` mit `OrganizationCostCenter` und dem `CostCenterLinkableInterface` eine
plattformweite Kostenstellen-Dimension an. Die Frage war, ob unsere Kostenstellen dorthin gehören und
der Asset-Manager sie nur noch referenziert.

Der entscheidende Unterschied kam aus dem **Betriebsmodell**: Der Asset-Manager läuft bei
**BHG.DIGITAL**, nicht beim Kunden. Wir brauchen alle Mandanten (Broich, Culinaria, Nodera, …) auf
einen Blick in **einer** Installation. `platform-organization` bildet dagegen die **eigene**
Organisation der jeweiligen Plattform-Installation ab — bei uns also BHG.DIGITAL selbst, nicht die
Gesellschaftsstruktur unserer Kunden.

Entscheidung: **Die Kostenstellen der Kunden bleiben fachliches Eigentum des Asset-Managers.**

- `asset_cost_centers` bleibt die kanonische Tabelle, künftig hierarchisch und **tenant-gebunden**.
- Keine Migration der Kundenkostenstellen nach `platform-organization`, kein FK dorthin, kein
  `CostCenterLinkableInterface` an unseren Modellen.
- Die Kostenstelle eines Kunden ist damit ein **Kundenstammdatum**, nicht eine Dimension unserer
  eigenen Orga.

## Bewusste Abgrenzungen / Trade-offs

- **Semantik geht vor Wiederverwendung.** Eine `OrganizationCostCenter`-Zeile „Broich Bonn" in
  *unserer* Organisation wäre sachlich falsch: Bonn ist keine Einheit von BHG.DIGITAL. Die formale
  Wiederverwendung würde eine inhaltliche Verwechslung erzeugen, die jede Auswertung über die eigene
  Orga (VSM-Baum, Fokusräume, Cashflow) verfälscht.
- **Zwei Kostenstellen-Begriffe koexistieren bewusst.** „Kostenstelle" im Asset-Manager = Buchungs-
  stelle *des Kunden*; „Kostenstelle" in `platform-organization` = Einheit *unserer* Orga. Das
  Glossar in `CONTEXT.md` benennt den Unterschied, damit er nicht stillschweigend verschwimmt.
- **Keine Fremdmodul-Kopplung** — konsistent mit [ADR 0003](0003-multi-tenant-tenant-modell.md), das
  schon den Tenant-Anker bewusst als intrinsischen FK statt über Dimension-Links gelöst hat. Das
  Modul referenziert weiterhin **kein** Domänenmodul außer Core.
- **Preis dieser Entscheidung:** Wollte man später die *eigenen* BHG-IT-Kosten im Asset-Manager gegen
  die eigene Orga auswerten, braucht es eine zusätzliche, lose Referenz (nullable
  `org_cost_center_id`, Match über `code`, defensiv per `class_exists`). Das ist bewusst **nicht** Teil
  dieser Entscheidung — erst bauen, wenn der Bedarf real ist.
- **Anschlussfähig statt abgeschottet:** Falls doch einmal verlinkt werden soll, bleibt der Weg
  additiv offen (nullable Referenz), weil unsere Tabelle die kanonische Quelle behält. Eine Migration
  *hinein* wäre dagegen nur schwer rückholbar.

## Considered Options

- *Kundenkostenstellen nach `platform-organization` migrieren, Asset-Manager referenziert nur* —
  verworfen: vermischt Kundenstruktur mit der eigenen Orga, koppelt an ein Fremdmodul und macht jede
  tenant-reine Kostensicht zum Cross-Modul-Join.
- *Kostenstellen doppelt halten (hier und dort, Sync)* — verworfen: zwei Wahrheiten für dasselbe
  Stammdatum driften garantiert auseinander.
- *Kostenmodell in ein eigenes Modul auslagern* (in ADR 0003 als Perspektive erwogen) — vorerst
  verworfen: Kosten und Inventar teilen Kostenstelle, Träger und Gerät als gemeinsame Achsen; eine
  Modulgrenze dazwischen erzeugte mehr Schnittstelle als Nutzen. Die Abschaltbarkeit ist über
  [ADR 0008](0008-controlling-abschaltbare-schicht.md) ohnehin gelöst.

Verweise: [ADR 0003](0003-multi-tenant-tenant-modell.md),
[ADR 0008](0008-controlling-abschaltbare-schicht.md),
[ADR 0016](0016-tenant-als-zugriffsgrenze.md).
