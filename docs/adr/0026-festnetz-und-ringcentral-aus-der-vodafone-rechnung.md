# Festnetz und RingCentral kommen aus der Vodafone-Rechnung, die Plätze aus der Benutzerliste

Status: akzeptiert (beschlossen 2026-10-14, Max) — Dev-Issue #931

Kontext: [ADR 0018](0018-vodafone-rechnungsanalyse-als-mobilfunk-quelle.md) hat den Mobilfunk auf die
Vodafone-Rechnung umgestellt und RingCentral bewusst ausgeklammert („eigenes Produkt, eigenes
Rechnungskonto, die Modellierung ist eine eigene Frage"). Bis hierhin standen Internet und RingCentral
nur als Excel-Zeilen im Bestand — und zwar falsch: die Lizenzen als 17,95 € + −12,21 € je Person aus
einer alten Liste, Anlagenanschluss und Verbindungen gar nicht. Gegen die Rechnung gerechnet fehlten
rund 330 €/Monat.

Die Analyse aller Vodafone-Rechnungen 08/2025–09/2026 ergab vier Rechnungskonten: Mobilfunk (ADR 0018),
zwei Festnetzkonten (Internet Düsseldorf + RingCentral; Internet Mönchengladbach) und ein Konto
„Software as a Service" — das ist **dieselbe** Microsoft-365-Rechnung wie die „Abrechnungsdetails" des
Lizenzportals (ADR 0019), je Produkt auf den Cent gleich, nur rund einen Monat später fakturiert.

Entscheidung:

1. **Quelle ist die „Rechnung CSV" je Festnetzkonto** (Portal-Detailseite, 61 Spalten, Windows-1252).
   `Support\VodafoneFixedNetCsv` bildet Blöcke: Internet je Anschlusskennung, RingCentral-Lizenzen
   (Menge aus `Dauer` bei Einheit `LIZ`), Anlagenanschluss (`KNL`), Verbindungen. Rabatte werden über
   ihren **Tarifnamen** zugeordnet. Unbekanntes wird gemeldet und als Sammelzeile gebucht, nie verteilt.
   Die Positionen ergeben in allen 22 geprüften Rechnungen exakt das Rechnungsnetto — das ist die
   Prüfsumme.
2. **Wer einen Platz belegt, kommt aus der RingCentral-Benutzerliste**, nicht aus einem Häkchen am
   Träger. Die Liste wird beim Anbieter gepflegt und hier als Snapshot (`asset_telephony_seats`)
   hochgeladen, abgeglichen per E-Mail/UPN, Rückfall Name nur bei eindeutigem Treffer. Ein Benutzer mit
   zweiter Durchwahl steht zweimal in der Liste und ist **ein** Platz.
3. **Verteilung** (Regeln von Max):
   - Lizenzen: Netto-Stückpreis = (Lizenz − Lizenzrabatt) / Rechnungsmenge (aktuell 5,744 €); je Platz
     mit Träger eine Zeile auf dessen Kostenstelle. Rest der Rechnungsmenge **und** Rundungscent als
     **eine Pool-Zeile** auf der Telefonie-Sammelstelle — so ergeben die Lizenzzeilen den Rechnungsblock
     exakt.
   - Anlagenanschluss (420 − 20 % − 10 % Kombi = 294 €) und Verbindungen (variabel, abzüglich
     Red-Business-Rabatt) auf die **Telefonie-Sammelstelle**, konfigurierbar in den Moduleinstellungen
     (`telephony_cost_center_id`; demo: 1520).
   - Internet an der Leitung im Inventar (`asset_items.external_id` = Anschlusskennung), Kostenstelle
     der bisherigen Zeile. Erstzuordnung zum Excel-Altbestand einmalig in der Vorschau.
4. **Das Lizenzkonto („Software as a Service") wird abgewiesen.** Lizenzen kommen ausschließlich über
   ADR 0019 — die Vodafone-Fassung hat keine brauchbaren Mengen und käme einen Monat zu spät.
5. Übernommen aus ADR 0018 und Bug #930: `import_hash` nur Identität; **keine Gültigkeit** aus dem
   Rechnungszeitraum (sonst fallen die Zeilen nach dessen Ende aus jeder Auswertung); eine bestehende
   Kostenstelle wird nie weggenommen; Prune **je Rechnungskonto**.
6. Der Excel-Import liest die Spalten F/G (RingCentral) und Vodafone-Internetzeilen nicht mehr; der
   erste Festnetz-Lauf entfernt die abgelösten Excel-Zeilen.

Verworfen:

- **Häkchen „RingCentral" am Asset-Träger** (erster Planentwurf). Eine Kopie der Anbieterliste, die bei
  jedem Ein-/Austritt nachgepflegt werden müsste, und Plätze ohne Person (Konferenztelefon, Lager)
  hätten keinen Ort.
- **Lizenzen brutto + Rabatt als zwei Zeilen je Person** (Excel-Modell). Der Nettopreis je Kopf ist die
  Größe, über die gesprochen wird; zwei Zeilen verdoppeln den Bestand ohne Erkenntnisgewinn.
- **Pool auf `vf_lizenz_rc`.** Die Kostenart hat die Ebene „Person"; eine Zeile ohne Träger darauf
  löst den Plausibilitätsbefund aus. Der Pool liegt deshalb auf `telefonie` (Ebene Kostenstelle).

Folgen:

- Monatlich zwei Dateien (je Festnetzkonto eine „Rechnung CSV"), bei Personalwechseln vorher die
  Benutzerliste neu hochladen.
- Bezahlte, aber ungenutzte Plätze werden sichtbar (Stand 14.10.2026: 99 Lizenzen, 91 Plätze, davon
  15 nicht aktiviert, 3 deaktiviert) — Kündigungshinweis im Import-Ergebnis.
- Der Pool schwankt mit den Rundungscent; das ist gewollt und hält die Summe exakt.
