# Lizenzpreise hängen an der Vertragszeile, nicht an der SKU

Status: akzeptiert (beschlossen 2026-08-26)

Kontext: Lizenzkosten kamen bisher aus **einem** handgepflegten Stückpreis je SKU
(`asset_license_skus.unit_price`), multipliziert mit den zugewiesenen Seats. Der Graph-Sync fasst das
Feld bewusst nie an. Der Lizenz-Wiederverkäufer stellt monatlich einen CSV-Export
**„Abrechnungsdetails"** bereit; drei echte Rechnungen (05–07/2026) wurden vollständig ausgewertet.

Dabei zeigte sich, dass ein Wert je SKU das Abgerechnete nicht abbilden kann. Business Premium
erscheint im Juli 2026 als **zwei** Positionen:

| Position | Menge | Listenpreis | Rabatt | Monatspreis |
|---|---:|---:|---:|---:|
| NCE Microsoft 365 Business Premium 12 (SMB300 Standalone) - P1Y | 118 | 21,63 € | 30 % | **15,14 €** |
| Microsoft 365 Business Premium | 15 | 22,87 € | — | **22,87 €** |

Zusammen 133 Seats — exakt `purchased_units` in Graph. Graph verrät jedoch **nicht**, welche Person
auf welchem Tarif sitzt; diese Information existiert nirgends.

Weitere Eigenschaften der Datei, die das Vorgehen bestimmen:

- **Rabatte sind eigene Zeilen** (Spalte „Einheit" = `%`, negativer Betrag, Anzahl 1). Der Listenpreis
  in Spalte 7 ist der Preis **vor** Rabatt. Wer ihn übernimmt, überschätzt die Lizenzkosten deutlich.
- **Mengenänderungen mitten im Monat** werden als Teilperioden mit zeitanteiligen Beträgen und Zeilen
  mit **negativer Anzahl** abgerechnet. „Exchange Online (Plan 2)" besteht im Juli aus sechs Zeilen.
- Der Anbieter **umbenennt Artikel**: zwischen Mai und Juni kam allen NCE-Positionen „- P1Y" hinzu,
  ohne dass sich Produkt oder Preis änderten.
- Positionsnamen tragen teils den abgerechneten Zeitraum, in **zwei Sprachfassungen** innerhalb
  derselben Datei („Per User Fee Zeitraum von …", „Gebühr pro Benutzer Zeitraum von …").

## Entscheidung

### 1. Die Vertragszeile ist der Träger des Preises

Neue Entität `AssetLicenseContractLine` (`asset_license_contract_lines`): eine Lizenzmenge zu einem
Preis mit einer Bindung, je Abrechnungsmonat und Rechnungsposition. **Es wird nicht gemittelt.** Ein
Durchschnitt von 16,01 € für Business Premium stünde auf keiner Rechnung und wäre gegen keinen Beleg
prüfbar.

`asset_license_skus.unit_price` bleibt als handgepflegter Preis für Lizenzen, die auf **keiner**
Rechnung stehen (Self-Service- und Gratis-SKUs wie `FLOW_FREE`, `POWER_BI_STANDARD`). Deckt die
Rechnung eine Lizenz ab, wird der Handwert geleert und der alte Wert im Importergebnis gemeldet —
zwei Preisquellen für dieselbe Lizenz wären nicht auflösbar.

### 2. Monatspreis = Listenpreis × (1 − Rabattsatz)

Beide Werte stehen unmittelbar in der Datei. Die Formel trifft über alle drei geprüften Rechnungen
**jede** Position exakt. Kein zeitanteiliges Rechnen: ein Seat kostet den Monatspreis, nicht seine
Tage (**Snapshot zum Monatsersten**).

Der zeitanteilige Weg bleibt als **Gegenprobe**: `Monatspreis × zeitanteilig gewichtete Menge` muss
den Rechnungsbetrag der Position ergeben, und die Summe aller Positionen den Wert `Gesamtnetto`.
Beides stimmt in allen drei Monaten auf den Cent. Schlägt es an, passen Listenpreis und Rabattsatz
nicht zum Betrag — dann ist eine der Angaben unbrauchbar, und das muss auffallen, statt einen falschen
Preis über alle Seats zu multiplizieren.

Der **Rabatt bleibt im Preis verrechnet**. Er wird nicht als Gutschriftsposition geführt, obwohl das
Modul mit `allow_negative` ein Konzept dafür hätte: der Rabatt ist Teil des verhandelten Preises, kein
gegengerechneter Vorgang. Listenpreis, Rabattbetrag und Prozentsatz werden je Zeile mitgespeichert,
damit die Herleitung nachvollziehbar bleibt.

### 3. Menge = Bestand zum Ende des Abrechnungszeitraums

Also zum Monatsersten des Folgemonats: die Summe der Mengen aller Rechnungszeilen einer Position,
deren Zeitraum dort endet. Bewusst dieses Ende und nicht der Beginn — Graph liefert immer den
*aktuellen* Bestand, und nur dagegen ist ein harter Mengenabgleich erfüllbar. Im Juli 2026 trifft die
Menge damit acht von elf Positionen exakt; die drei Abweichungen sind echte Befunde (2 Seats
„Business Standard" bezahlt, im Tenant nicht vorhanden; 1 Seat „Business Basic" zugewiesen ohne
Bezahlung).

### 4. Verteilungs-Kaskade (verbindliche Reihenfolge)

Verteilt werden die **Mengen**, nicht die Preise:

1. **Funktionskonten erhalten die Monatspreis-Zeilen.** Service-Accounts sind der Ort, an dem
   Flexibilität etwas wert ist — sie kommen und gehen mit Systemen, nicht mit Arbeitsverhältnissen.
2. **Übrige Monats-Seats gehen gesammelt auf die Überhang-Kostenstelle**, nicht auf willkürlich
   gewählte Personen.
3. **Überzählige Funktionskonten erhalten Bindungspreis-Zeilen.**
4. **Personen erhalten Bindungspreis-Zeilen.**
5. **Der unbesetzte Rest geht auf die Pool-Kostenstelle.**

Schritt 4 wurde beim Festlegen der Kaskade ergänzt: ohne ihn landen in der realen Datenlage des
Demo-Tenants (131 Business-Premium-Zuweisungen, **kein einziges** Funktionskonto) 100 % der
Lizenzkosten auf zwei Sammel-Kostenstellen und kein Cent bei den Fachkostenstellen — womit die
Kostenaufteilung ihren Zweck verlöre.

**Stabilität:** Weil die Zuordnung gesetzt und nicht hergeleitet ist, darf sie sich nicht ohne Anlass
ändern. Jeder Schritt bevorzugt Empfänger, die im Vormonat bereits auf derselben Vertragszeile saßen
(erkannt am `edition_key`, weil die Zeilen monatlich neu entstehen); erst danach wird nach Kennung
sortiert aufgefüllt. Ohne das würden Kosten je Kostenstelle bei jedem Import springen.

### 5. Prüfregel: Mengen hart, Beträge erklärt

`zugeordnet + Überhang + Pool = Rechnungsmenge` gilt je Vertragszeile **ohne Toleranz** — eine
Abweichung bedeutet verlorenes Geld in der Kostenaufteilung.

Die **Betragsdifferenz** zur Datei ist dagegen kein Fehler, sondern die bewusste Folge des Snapshots:
ein Seat kostet den vollen Monatspreis, auch wenn die Rechnung ihn nur für 24 Tage berechnet hat. Sie
wird beziffert ausgewiesen. Eine harte Betragsprüfung würde praktisch jeden Monat mit Zu- oder Abgang
blockieren (Juni und Juli der geprüften Rechnungen).

### 6. Bindung ist Flexibilitätsinformation, kein Kostenattribut

`binding` (P1M/P1Y) steuert die Kaskade und dient Verlängerungsentscheidungen; der Preis steht
unabhängig davon fest. Die Rechnung nennt die Bindung nicht als Feld — sie wird aus zwei Signalen
vorbelegt („- P1Y" im Positionsnamen, Rabattbezeichnung „12 M 30%") und bleibt bis zur Bestätigung als
unbestätigt markiert. Eine bestätigte Bindung überschreibt kein Import mehr. `cancellable_at` steht in
keiner Rechnung und wird ausschließlich gepflegt.

### 7. Funktionskonto: kein neuer Objekttyp

Der Träger-Typ existiert bereits als `holder_type = 'function'` mit dem Label „Funktionskonto"
([ADR 0017](0017-asset-traeger-statt-mitarbeiter.md)) und trägt schon eine Kostenstelle. Ergänzt wird
allein `function_system` — ein Funktionskonto ohne benanntes System ist nicht bewirtschaftbar.
Freitext, keine Katalogtabelle: „das System" ist je Kunde ein Fachverfahren, eine Maschine oder ein
Postfach. Fehlt eines der beiden Pflichtfelder, greift der Vorrang aus Schritt 1 nicht; das Konto wird
wie eine Person behandelt **und gemeldet**.

### 8. Zuordnung Position → Lizenz: nie raten

Ein falsch zugeordneter Preis wird über alle Seats der Lizenz multipliziert. Der Matcher verlangt für
jeden unscharfen Treffer ein **zweites, unabhängiges Signal** (die Menge) und stuft:
Begriffsgleichheit → Wortmenge + Mengenbestätigung → Wortüberschneidung + eindeutige
Mengenbestätigung. Alles andere bleibt offen und wird von Hand entschieden.

Jeder Treffer — auch ein automatischer — wird als **Alias** festgeschrieben
(`asset_license_billing_aliases`, Anker `edition_key`). Grund: die Mengenbestätigung greift nur beim
aktuellen Bestand. „Exchange Online Archiving" hat im Mai 6 Seats und heute 7, wird also im Mai nicht
mehr bestätigt. Ohne das Gedächtnis würde dieselbe Position je Monat anders behandelt und die
Verteilung wäre nicht stabil. Ein Alias mit `sku_id = null` heißt bewusst „gehört zu keiner Lizenz".

Gegen den echten Lizenzkatalog des Demo-Tenants (17 SKUs) ordnet die Automatik **11 von 11**
Juli-Positionen korrekt zu.

### 9. Sammel-Kostenstellen sind konfigurierbar

„IT-Gemeinkosten" und „Lizenzpool IT" sind Kostenstellennamen *eines* Kunden und liegen deshalb pro
Tenant in `asset_team_settings` (`license_overflow_cost_center_id`, `license_pool_cost_center_id`) —
dieselbe Leitplanke, aus der schon `holder_type_rules` konfigurierbar ist. Ohne gesetzte Kostenstellen
verteilt die Kaskade weiter und weist die Restmengen als „ohne Kostenstelle" aus, statt Beträge
stillschweigend zu unterschlagen.

## Kennzahlen, die daraus entstehen

- **Überhang an Monatslizenzen** (Schritt 2) sollte im Normalfall leer sein. Gefüllt heißt: teure
  Monatslizenzen, die auf Jahresbindung umstellbar und damit günstiger wären.
- **Lizenzpool mit Kündigungsdatum** macht bezahlten Leerstand mit Jahresbindung zu einer belastbaren
  Kündigungsentscheidung.
- **Seats ohne Bezahlung** und **bezahlte Positionen ohne Lizenz im Tenant** sind die beiden
  Abgleichbefunde gegen Graph.

## Konsequenzen

- `CostAggregationService` zieht Lizenzpreise über `Support\LicensePriceBook`: Seat-Preis vor
  handgepflegtem SKU-Preis, plus die Restmengen auf ihren Sammel-Kostenstellen. Fünf Stellen
  (Pivot, Träger-Ranking, SKU-Kosten, Sparpotenzial, Träger-Detail) nutzen jetzt dieselbe Reihenfolge —
  vorher wäre eine Abweichung zwischen Dashboard und Pivot leicht entstanden.
- Das Sparpotenzial ungenutzter Lizenzen kommt aus dem bezahlten Pool statt aus
  `available_units × Stückpreis`. Der alte Weg ist bei mehreren Tarifen je Lizenz nicht mehr bestimmt:
  welcher der beiden Preise stünde leer? Ohne Rechnungsdaten bleibt er als Rückfall.
- Ein Stückpreis ist bei mehreren Tarifen keine sinnvolle Anzeige mehr; an seine Stelle tritt die
  Preisspanne.
- Nachvollziehbarkeit: `asset_user_licenses.contract_line_id` führt von jedem Seat zurück zur Position
  und damit zum Beleg.

## Verifikation

- `tests/local/license-invoice-import.php` — 73 Prüfungen über die ganze Kette (Preis, Menge, Bindung,
  Zuordnung, Kaskade, Mengenbilanz, Idempotenz, Handpflege, Alias-Lernen), Fixture unter
  `tests/fixtures/license-invoice-sample.csv` (synthetisch, enthält jedes sperrige Muster).
- Gegen die drei echten Rechnungen 05–07/2026 geprüft: Summenabgleich gegen `Gesamtnetto` auf den
  Cent, zurückgerechnete Rabattsätze treffen die ausgewiesenen (18 / 12 / 8 / 20 / 30 / 25 / 23 %).

## Verworfene Alternativen

- **Mischpreis je SKU** (gewichteter Durchschnitt über die Tarife). Fachlich abgelehnt: der Wert
  steht auf keiner Rechnung und ist gegen keinen Beleg prüfbar.
- **Rabatt als eigene Gutschriftsposition** in der Kostenaufteilung. Verworfen: der Rabatt ist Teil
  des verhandelten Preises, kein gegenzurechnender Vorgang.
- **Seat-Tage-Abrechnung** statt Snapshot. Verworfen: verlagert Genauigkeit dorthin, wo sie niemand
  auswertet, und macht jeden Monatspreis erklärungsbedürftig.
- **Eigene Entität „Funktionskonto"** neben dem Asset-Träger. Verworfen: widerspricht ADR 0017
  („ein Typ mit Rollen"); der Typ existiert bereits.
