# DATEV-Umbuchung der IT-Kosten als EXTF-Stapel aus den Monatsständen der Rechnungen

Status: akzeptiert (beschlossen 2026-10-15, Max) — Discussion #26 (Package 24, Replies 8–15)

Kontext: Die Buchhaltung bucht jede IT-Rechnung komplett auf die Sammel-Kostenstelle 1520. Verteilt wurde
bisher von Hand bzw. über Monats-Templates des Controllings (`02_EXTF/templates/*.yaml` im
Controlling-Vault) — mit eigenen, teils abweichenden Schlüsseln (Gegenprobe #26 Reply 13: HGK auf den Cent
gleich, O365/Bankettprofi/CSI weichen ab). Die richtige Verteilung steht aber nach jedem Rechnungsimport
bereits im Asset Manager: in den Kostenpositionen und der Kostenaufteilung. Gefragt war ein
DATEV-Buchungsstapel (EXTF, Datenkategorie 21), den die Buchhaltung nur noch importiert.

Zwei Hindernisse: Die Kostenpositionen halten je Rechnungskonto nur die **zuletzt** importierte Rechnung —
für einen Stapel je Monat fehlt der Stand des Monats. Und ein importierter Stapel kann in DATEV
festgeschrieben werden; doppelt oder falsch umgebucht ist teuer zurückzunehmen.

Entscheidung:

1. **Betrag = Rechnungsnetto × Anteil laut Kostenaufteilung**, centgenau nach Largest Remainder
   (ganzzahlig in Cent). Die Summe der Zielzeilen ist immer exakt der Rechnungsbetrag, auch wenn die
   Kostenaufteilung um Rundungs-Cent abweicht (`Support\DatevReallocationCalculator`).
2. **Sachkonto an der Kostenart, nie am Kreditor** (`asset_cost_types.datev_account`). Eine Rechnung
   kann mehrere Sachkonten tragen (Vodafone: 680510 Mobilfunk, 680500 Telefonie/RingCentral, 681000
   Internet, 649500 SaaS). Kostenarten ohne Sachkonto werden nicht umgebucht und als Befund gezeigt.
   Die Zuordnung ist aus der Buchungshistorie abgeleitet und braucht keine Freigabe des Controllings.
3. **Monatsstand je Rechnung** (`asset_invoice_snapshots`): jeder scharfe Rechnungsimport (Mobilfunk,
   Festnetz, Lizenz-Abrechnung) schreibt in seiner Transaktion Rechnungsnetto, Buchungsmonat und
   Verteilung auf Kostenart × KST fest; ein Probelauf hinterlässt nichts. Derselbe Beleg erneut
   importiert ersetzt seinen Stand, die nächste Rechnung legt einen neuen an.
4. **Buchungsmonat = Monat des Rechnungsdatums** — die Buchhaltung bucht die Rechnung mit ihrem
   Belegdatum auf 1520, die Umbuchung gehört in dieselbe Periode. Eine Festnetzrechnung vom 02.10. für
   den Abrechnungsmonat September landet damit im Oktober-Stapel. Ohne Rechnungsdatum kein Monat, und
   die Rechnung fehlt in jedem Stapel (Befund), statt in einem falschen zu stehen.
5. **Buchungsmuster N-Wege-Verteilung** (Controlling-Vault `05_Kontierungsregeln/Umbuchungen.md`): je
   Block (Sachkonto × Kreditor) eine Quellzeile mit der vollen Summe auf der Sammel-KST (H) und eine
   Zielzeile je KST (S), auch für den Eigenanteil der Sammel-KST; 159000 immer im Gegenkonto; S/H dreht
   bei Gutschrift, negativem Anteil und Erlöskonto. Buchungstext Stil C
   `Umb. [1520|<KST>] <Kreditor> <Kostenart> <Mon><JJ>`, Quellzeile mit `diverse`.
6. **Eine Datei je Monat und Mandant.** Der Stapelkopf (Berater, Mandant, WJ-Beginn,
   Sachkontenlänge, Diktatkürzel, Sammel-KST) steht in den Moduleinstellungen je Tenant — es sind
   Kundendaten. Foodpol (KST 11000, Mandant 10002) wird nie mit 530 gemischt; der Rechner blockiert.
7. **Protokoll gegen Doppel-Umbuchung** (`asset_datev_batches`): ein Stapel speichert seine Datei
   byte-genau (Base64, SHA-256) und sperrt die enthaltenen Monatsstände (`datev_batch_id`). Gesperrte
   Stände fallen aus jedem weiteren Stapel heraus und werden von keinem Re-Import verändert. Nachzügler
   desselben Monats kommen in „… Teil 2". Verwerfen braucht einen Grund, gibt die Stände frei und
   behält die Datei; ein bereits importierter Stapel muss in DATEV storniert werden.
8. **Alles oder nichts:** jeder Verstoß — Anteil ohne KST, unbekannte KST, falscher Mandant, jede Regel
   der EXTF-Checkliste (`Support\DatevExtfWriter`) — verhindert die Datei vollständig.

Verworfen:

- **Stapel direkt aus den aktuellen Kostenpositionen.** Funktioniert nur für die jeweils letzte
  Rechnung; rückwirkend und nach späteren KST-Änderungen wäre der Stapel nicht reproduzierbar.
- **Sachkonto am Kreditor.** Dann landete eine Vodafone-Rechnung auf einem einzigen Konto.
- **Beträge der Kostenaufteilung direkt buchen.** Sie weichen um Rundungs-Cent und bei der
  Lizenz-Abrechnung um die Zeitanteiligkeit vom Beleg ab; gebucht werden muss der Beleg.

Folgen:

- Neue Seite **Auswertungen → DATEV-Umbuchung** (`Livewire\Costs\DatevReallocation`): Monat wählen,
  Rechnungen und Vorschau prüfen, Stapel erzeugen, Datei herunterladen, verwerfen. Nach einer
  KST-Korrektur kann der Monatsstand einer offenen Rechnung neu festgeschrieben werden.
- **Nicht im Stapel, bis zur Klärung bei den Controlling-Templates:** HGK, O365 Backup, CSI Leasing,
  Druckerwartung, Bankettprofi, Grenke (Max, 2026-10-15) — ihre Kostenarten bekommen kein Sachkonto.
  Bankettprofi und Grenke-Versicherung sind RAP-Auflösungen (190003/190000), keine 159000-Umbuchungen.
- **Vodafone war im HGK bis Aug 2026 vorverteilt erfasst** (Kreditorenstapel je KST). Vor dem ersten
  Stapel ist zu klären, ab welchem Monat die Rechnung komplett auf 1520 landet — sonst wird doppelt
  verteilt.

Offen:

- **DATEV-KST-Liste als Prüfquelle** (`01_Stammdaten/Kostenstelle.md`): der Rechner kann gegen eine Liste
  prüfen, die Seite übergibt noch keine.
- **Buchungsmonat der Lizenz-Abrechnung:** die „Abrechnungsdetails" tragen kein Rechnungsdatum; heute gilt
  der Abrechnungsmonat. Die Vodafone-SaaS-Rechnung desselben Inhalts wird aber rund einen Monat später
  fakturiert (ADR 0026) — nach Regel 4 gehörte die Umbuchung in deren Monat. Mit Max/Controlling klären.
