# Weiterberechnung: die Abrechnungsbasis ist eine Regel, kein Häkchen am Träger

Status: akzeptiert (beschlossen 2026-09-02, Max)

Kontext: Für betreute Kunden — zuerst Broich — soll monatlich eine Rechnung entstehen: eine
Servicepauschale je betreutem User. Der Preis ist bereits als Commerce-Artikel gepflegt
(`IT-5001`, „Mitarbeiter Servicepauschale", 80,00 € netto, Erlöskonto 440100), der Empfänger liegt in
easybill (BHG.BROICHCATERING GMBH, `2636491004`), und das Integrations-Modul bringt einen fertigen
easybill-Connector mit. Offen waren drei Fragen: **wer zählt**, **wo der Preis herkommt** und **wie
weit die Automatik geht**.

Der ursprüngliche Gedanke war, abzurechnende Träger im Asset Manager zu **markieren** — ein
`billable`-Häkchen oder ein Profil-Feld je Träger. Die Bestandsaufnahme sprach dagegen:

- Auf der Live-Umgebung stehen **290 Träger, davon 290 als `person` und 290 als `is_active`** —
  beide Felder trennen nichts. Darunter sind Administrationskonten, ein `__vmware_user__`, ein
  Datensatz ohne UPN und **17 eigene Mitarbeiter** unter `bhgdigital.de`.
- **Kein einziger Träger** hat dort eine Kostenstelle (weder als Text noch als Fremdschlüssel);
  `department` enthält Funktionen („PROJEKTLEITUNG", „LOGISTIK"), nicht die Gesellschaftsstruktur,
  und ist zu 37 % leer.
- **152 Träger haben mindestens eine Microsoft-Lizenz.** Das ist die einzige Achse, die heute
  belastbar zwischen „betreut" und „steht nur im Verzeichnis" trennt.

Ein Häkchen an 152 Trägern wäre eine Kopie von Wissen, das die Lizenzzuweisung schon trägt — und es
müsste bei jedem Ein- und Austritt von Hand nachgezogen werden. Vergisst das jemand, ist der Fehler
nicht sichtbar, sondern steht als falscher Betrag auf einer Kundenrechnung.

Entscheidung: **Die Abrechnungsbasis wird bei jedem Lauf frisch aus einer Regel abgeleitet.**

- `asset_billing_profiles` hält die Regel: easybill-Kunde, Commerce-SKU, Zählregel
  (`licensed` | `all_holders`), ausgeschlossene Domains, Steuersatz.
- `asset_billing_runs` hält das Ergebnis: Menge, Stückpreis, Summe, Beleg-ID — und einen **Snapshot**
  der gezählten UPNs samt Ausschlussgründen.
- Am Asset-Träger wird **kein Feld** ergänzt.
- Der Lauf erzeugt einen **Entwurf**. Festschreiben und Versenden bleiben in easybill.

## Bewusste Abgrenzungen / Trade-offs

- **Der Snapshot ist der Kern, nicht Beiwerk.** Menge und Preis stehen im Lauf fest, statt bei Bedarf
  neu berechnet zu werden. Eine Rechnung ist ein Vorgang mit Datum; sie darf sich nicht rückwirkend
  ändern, nur weil jemand eine Lizenz umgehängt hat. Ohne die eingefrorene Trägerliste wäre die Frage
  „warum standen da 152?" drei Monate später nicht mehr beantwortbar.

- **Nicht gezählte Träger werden mitgeschrieben, nicht weggeworfen.** Bewusste Entscheidung gegen
  einen Blocker: der Lauf hält nicht an, wenn Träger herausfallen, aber ihre Zahl und ihr Grund
  stehen in der Vorschau und im Lauf. Der Preis dieser Entscheidung ist, dass eine Rechnung zu
  niedrig ausfallen kann, ohne dass jemand gezwungen wird hinzusehen — die Zahl in der Oberfläche
  ist das Gegenmittel.

- **Kein `composer require` auf `platform-integrations` oder `platform-commerce`.** Beide werden über
  eine Naht angesprochen (`BillingGateway`, `BillingUnitPriceResolver`), die zur Laufzeit per
  `class_exists` prüft. Dieselbe Richtung wie [ADR 0009](0009-provider-abstraktion-zwei-rollen.md):
  der Kern des Moduls ist IT-Lifecycle, nicht Fakturierung — es darf nicht aufhören zu laufen, weil
  ein kaufmännisches Fremdmodul fehlt. Der Bezug auf den Artikel läuft über die **SKU als
  Zeichenkette**, nicht über einen Fremdschlüssel (Muster aus
  [ADR 0015](0015-kundenkostenstellen-bleiben-im-asset-manager.md)).

- **Preis aus Commerce, mit Rückfallwert am Profil.** Den Preis am Profil zu führen wäre eine zweite
  Wahrheit neben dem gepflegten Artikel. Ganz ohne eigenen Wert stünde der Lauf aber still, sobald
  Commerce fehlt oder die SKU verschwindet — und zwar an dem Tag im Monat, an dem er gebraucht wird.
  Der Rückfallwert ist der Kompromiss; welche Quelle gegriffen hat, steht im Lauf.

- **Beträge in Cent, durchgehend.** easybill erwartet Integer-Cent (`single_price_net`). Die einzige
  Umrechnung liegt an der Eingabe des Rückfallpreises und beim Lesen des Artikelpreises — nicht an
  der Schnittstelle, wo ein Faktor-100-Fehler erst auf der Rechnung des Kunden auffiele.

- **Nur anlegen, nie festschreiben.** Der Connector kann `completeDocument`, `sendDocument` und
  `cancelDocument`; keiner dieser Wege ist angebunden. easybill legt jeden Beleg ohnehin als Entwurf
  an (`is_draft` ist dort read-only), sodass ein Fehler auf unserer Seite höchstens einen Entwurf
  erzeugt, den man löscht — nie eine gültige Rechnung. Der Preis: der letzte Schritt bleibt Handarbeit.

- **Kein Datenbank-UNIQUE gegen die zweite Rechnung.** Fehlgeschlagene Läufe müssen sichtbar bleiben
  und dürfen den zweiten Versuch nicht blockieren; ein UNIQUE über (Profil, Periode) täte beides
  falsch. Stattdessen prüft der `BillingRunService` auf einen bereits gesendeten Beleg derselben
  Periode und beanstandet ihn in der Vorschau.

- **Kostenstellen bleiben außen vor.** Die fachlich präziseste Zuordnung wäre über den
  Kostenstellen-Baum (die sechs BROICH-Bereichswurzeln zeigen auf denselben easybill-Kunden). Sie
  scheitert heute an der Datenlage — live ist der Baum unbesetzt. Die Zählregel ist als Aufzählung
  angelegt, sodass ein `cost_center`-Verfahren später ohne Schemaänderung dazukommen kann.
