# Vodafone-Rechnungsanalyse ist die einzige Quelle für Mobilfunk

Status: akzeptiert (beschlossen 2026-08-10)

Kontext: Mobilfunk kam bisher aus der Kostenaufteilungs-Excel — Spalte L des Übersicht-Blatts, ein
Betrag je Person, händisch gepflegt. Vodafone bietet Geschäftskunden im Firmenkundenportal die
**Rechnungs-Analyse** mit einem Export „Rechnungsdaten" je Rechnung. Ein echter Export (07/2026)
wurde geprüft: 115 Rufnummern, 3.949,04 € netto, eine Zeile je Anschluss, **mit Kostenstelle**, und
die Summe rechnet auf den Cent gegen den Rechnungskopf auf.

Damit ist die Rechnung eine bessere Quelle als die Excel-Spalte: aktueller, vollständig, und sie
zeigt Preisschwankungen durch Zusatzbuchungen, die in einer gepflegten Tabelle untergehen.

Vorab geklärt: **keine Pool-/M2M-SIMs**, eine Rufnummer je Person. Damit bleibt
[ADR 0014](0014-mobilfunk-ohne-eigene-vertrags-entitaet.md) unverändert gültig — es gibt weiterhin
keine Vertrags- oder SIM-Entität.

## Entscheidung

Ein eigener Importer ({@see `Services\VodafoneInvoiceImportService`}) liest den Portal-Export und
richtet die Mobilfunk-Kostenpositionen des Tenants darauf aus. Die Excel-Spalte L entfällt.

### Dateiformat

Blatt `Rechnungsdaten`, Spalte A trennt drei Zeilenarten:

| Typ | Bedeutung |
|---|---|
| `Rechnung` | Rechnungskopf — Gesamtbetrag, dient als Prüfsumme |
| `Rufnummer` | eine Zeile je Anschluss, Spalte K = D2-Nummer |
| `Rechnungsposten` | kontobezogen ohne Nummer (VPN-Grundpreis, Rabatt) |

Gerechnet wird mit **Netto** (Spalte J) — die Kostenaufteilung ist eine Netto-Sicht. Die Kostenstelle
steht in Spalte M als `2599 - HKS Team Düsseldorf`.

### Fünf Festlegungen

1. **Der `import_hash` enthält den Betrag NICHT.** Er trägt nur die Identität: Quelle, Team, Tenant,
   Kostenart, Rufnummer. Das weicht bewusst vom Excel-Importer ab, wo der Betrag mitgehasht wird und
   eine Preisänderung deshalb eine neue Zeile erzeugt. Hier ist der Preis die Größe, die sich jeden
   Monat ändert — die Zeile bekäme sonst monatlich eine neue ID, und alles, was daran hängt, verliert
   den Bezug.

2. **Unsere Kostenstellen-Zuordnung gewinnt.** Der Wert aus dem Export landet als Referenz in
   `raw_data`, Abweichungen werden im Import-Ergebnis aufgelistet, aber nicht übernommen — der
   gepflegte Stammsatz am Asset-Träger ist die Wahrheit. Nur wenn kein Träger matcht, greift die
   Kostenstelle aus dem Export als Fallback.

3. **Nicht zuordenbare Nummern werden trotzdem angelegt** — ohne `assignee`, markiert über
   `raw_data.unmatched`. Ein Betrag, der still verschwindet, ist schlimmer als eine Zeile, die
   auffällt; dieselbe Begründung wie die Auffangzeile im Pivot.

4. **Unbekannte Kostenstellen werden gemeldet, nicht angelegt.** Ein Import bucht Kosten; er
   erweitert nicht den Stammdaten-Baum. Im echten Export war genau eine dabei (`8000`).

5. **Der Excel-Import gibt Mobilfunk ab.** Spalte L wird nicht mehr gelesen, und der erste
   Vodafone-Lauf entfernt die bestehenden `excel_import`-Zeilen dieser Kostenart. Ohne diesen Schritt
   stünden beide Quellen nebeneinander: der Prune des Excel-Importers fasst nur `excel_import` an,
   der des Vodafone-Importers nur `vodafone_invoice` — Mobilfunk zählte doppelt und verletzte die
   Doppelzählungs-Invariante aus [ADR 0001](0001-cost-lines-modell.md).

### Rufnummern-Normalisierung

Der Abgleich läuft über die Rufnummer, und die steht je nach Quelle völlig verschieden da:
Export `1721234567`, Entra `+49 172 1234567`, manuelle Pflege `0172/123 45 67`.
`Support\PhoneNumber::e164()` bringt alle auf `+491721234567`, inklusive der deutschen
Klammer-Null (`+49 (0)172 …`). Ohne das matcht keine einzige Zeile, und der Import legt 115
Kostenpositionen ohne Zuordnung an — technisch fehlerfrei und fachlich wertlos.

### Prüfsumme als Selbsttest

Posten + Rufnummern müssen den Kopfbetrag ergeben. Stimmt das nicht, wurde beim Export gefiltert
oder eine Zeilenart übersehen; das Ergebnis weist es als `reconciles: false` aus und die Oberfläche
zeigt es rot **über** den Zahlen — unvollständige Zahlen sollen nicht wie vollständige aussehen.

## Bewusste Abgrenzungen / Trade-offs

- **Keine Aufschlüsselung je Position.** Der Export nennt nur einen Nettobetrag je Nummer, keine
  Trennung nach Grundgebühr / Option / Verbrauch. Zusatzbuchungen sind trotzdem erkennbar, weil der
  Normalfall eine glatte Tarifstufe ist (21,00 · 31,00 · 46,00 · 104,00) — eine 22,34 fällt auf.
  Käme später ein Export mit Aufschlüsselung, wären das mehrere Zeilen je Nummer; der stabile Hash
  müsste dann um die Positionsart erweitert werden.
- **Ist-Stand, keine Historie.** Jeder Lauf richtet die Positionen auf die aktuelle Rechnung aus;
  `valid_from`/`valid_to` tragen den Abrechnungszeitraum, aber alte Perioden werden nicht behalten.
  Das Portal hält 15 Monate vor — eine Historie ließe sich nachrüsten, ohne dieses Modell zu ändern.
- **Der Abrechnungszeitraum ist kein Kalendermonat** (im Beispiel 15.06.–14.07.). Die Beträge zählen
  trotzdem als `frequency=monthly` — ein Mobilfunkvertrag ist eine Monatsleistung, und eine
  taggenaue Abgrenzung brächte im Controlling keinen Erkenntnisgewinn.
- **Nur der Mobilfunk-Teil — und das ist keine Übergangslösung.** `VF Lizenz RC` und
  `VF Lizenz RC Rabatt` sind **Vodafone RingCentral**, also Cloud-Telefonie-Lizenzen (UCaaS) — ein
  anderes Produkt auf einem anderen Rechnungskonto, das mit der Mobilfunkrechnung nichts zu tun hat.
  Sie gehören **nicht** in diesen Importer. `MobileIron` ist MDM-Lizenzierung als Sammelposten und
  bleibt ebenfalls außen vor. Die Modellierung von RingCentral ist eine eigene Frage: die Kosten
  stehen bereits als `cost_line` je Person, aber eine Lizenz-/Seat-Sicht wie bei M365 fehlt.

Verweise: [ADR 0001](0001-cost-lines-modell.md), [ADR 0014](0014-mobilfunk-ohne-eigene-vertrags-entitaet.md),
[ADR 0016](0016-tenant-als-zugriffsgrenze.md).
