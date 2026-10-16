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
4. **Buchungsmonat = Leistungsmonat** (Hendric, 2026-10-16: alle Rechnungen werden nach Leistungsdatum
   gebucht). Leistungsmonat ist der Monat, in dem der Abrechnungszeitraum endet — so benennt ihn
   Vodafone selbst („Abrechnungsmonat 202609" für 26.08.–25.09.). Mobilfunk 15.08.–14.09. → September;
   die Festnetzrechnung vom 02.10. für September → September; Lizenz-Abrechnung → ihr Abrechnungsmonat.
   Ohne Leistungszeitraum kein Monat, und die Rechnung fehlt in jedem Stapel (Befund), statt in einem
   falschen zu stehen. *(Erste Fassung 2026-10-15: Monat des Rechnungsdatums — verworfen, siehe unten.)*
5. **Eine Rechnung, die vollständig auf genau einer Kostenstelle liegt, bucht die Buchhaltung direkt
   dorthin** (Max, 2026-10-16; Beispiel Internet Mönchengladbach, eigene Rechnung, KST 2890). Sie kommt
   nicht in den Stapel — eine Umbuchung stellte sie auf der Ziel-KST doppelt und 1520 ins Minus. Die
   Seite zeigt sie als „direkt auf KST …".
6. **Buchungsmuster N-Wege-Verteilung** (Controlling-Vault `05_Kontierungsregeln/Umbuchungen.md`): je
   Block (Sachkonto × Kreditor) eine Quellzeile mit der vollen Summe auf der Sammel-KST (H) und eine
   Zielzeile je KST (S), auch für den Eigenanteil der Sammel-KST; 159000 immer im Gegenkonto; S/H dreht
   bei Gutschrift, negativem Anteil und Erlöskonto. Buchungstext Stil C
   `Umb. [1520|<KST>] <Kreditor> <Kostenart> <Mon><JJ>`, Quellzeile mit `diverse`.
7. **Eine Datei je Monat und Mandant.** Der Stapelkopf (Berater, Mandant, WJ-Beginn,
   Sachkontenlänge, Diktatkürzel, Sammel-KST) steht in den Moduleinstellungen je Tenant — es sind
   Kundendaten. Foodpol (KST 11000, Mandant 10002) wird nie mit 530 gemischt; der Rechner blockiert.
8. **Protokoll gegen Doppel-Umbuchung** (`asset_datev_batches`): ein Stapel speichert seine Datei
   byte-genau (Base64, SHA-256) und sperrt die enthaltenen Monatsstände (`datev_batch_id`). Gesperrte
   Stände fallen aus jedem weiteren Stapel heraus und werden von keinem Re-Import verändert. Nachzügler
   desselben Monats kommen in „… Teil 2". Verwerfen braucht einen Grund, gibt die Stände frei und
   behält die Datei; ein bereits importierter Stapel muss in DATEV storniert werden.
9. **Umstellungsmonat** (`asset_team_settings.datev_start_month`): erster Leistungsmonat, den der Asset
   Manager umbucht. Davor hat die Buchhaltung die Rechnungen selbst je KST verteilt (Hendric, 2026-10-16:
   Vodafone September noch vorverteilt in DATEV KOST) — Monatsstände dieser Monate heißen „vorverteilt",
   sind nie offen und kommen nie in einen Stapel. Erstbefüllt mit Oktober 2026 (vorgeschlagen, mit
   Hendric abzustimmen); ein zu früher Monat bucht doppelt, ein zu später hält nur eine Umbuchung zurück.
10. **Alles oder nichts:** jeder Verstoß — Anteil ohne KST, unbekannte KST, falscher Mandant, jede Regel
   der EXTF-Checkliste (`Support\DatevExtfWriter`) — verhindert die Datei vollständig.

Verworfen:

- **Stapel direkt aus den aktuellen Kostenpositionen.** Funktioniert nur für die jeweils letzte
  Rechnung; rückwirkend und nach späteren KST-Änderungen wäre der Stapel nicht reproduzierbar.
- **Sachkonto am Kreditor.** Dann landete eine Vodafone-Rechnung auf einem einzigen Konto.
- **Beträge der Kostenaufteilung direkt buchen.** Sie weichen um Rundungs-Cent und bei der
  Lizenz-Abrechnung um die Zeitanteiligkeit vom Beleg ab; gebucht werden muss der Beleg.
- **Buchungsmonat = Monat des Rechnungsdatums** (erste Fassung, 2026-10-15). Folgte der Annahme, die
  Buchhaltung buche mit dem Belegdatum der Rechnung — die Historie bis August zeigt das auch
  (Festnetzrechnung vom 03.08. für Juli in „Kreditoren AUG2026"). Hendric stellt auf Leistungsdatum um;
  sonst landete jede Festnetzrechnung (Rechnungsstellung Anfang Folgemonat) einen Monat zu spät.

Folgen:

- Neue Seite **Auswertungen → DATEV-Umbuchung** (`Livewire\Costs\DatevReallocation`): Monat wählen,
  Rechnungen und Vorschau prüfen, Stapel erzeugen, Datei herunterladen, verwerfen. Nach einer
  KST-Korrektur kann der Monatsstand einer offenen Rechnung neu festgeschrieben werden.
- **Nicht im Stapel, bis zur Klärung bei den Controlling-Templates:** HGK, O365 Backup, CSI Leasing,
  Druckerwartung, Bankettprofi, Grenke (Max, 2026-10-15) — ihre Kostenarten bekommen kein Sachkonto.
  Bankettprofi und Grenke-Versicherung sind RAP-Auflösungen (190003/190000), keine 159000-Umbuchungen.
- **Vodafone ist bis einschließlich September 2026 vorverteilt gebucht** (Mobilfunk 18 KST, Festnetz
  16 KST; Hendric 2026-10-16) — deshalb Umstellungsmonat Oktober 2026 (Regel 9). Die Verteilung der
  Buchhaltung folgt der Vodafone-Kostenstelle, unsere dem Asset-Träger: nach der Umstellung ändern sich
  die KST-Werte in DATEV sichtbar (Sep: 1900, 9920, 1520).
- **Keine Prüfung gegen die DATEV-KST-Liste** (Max, 2026-10-16): die Seite übergibt dem Rechner keine
  Liste, er prüft nur fehlende KST und Foodpol. Die Möglichkeit im Rechner bleibt ungenutzt.
- **Leistung im Buchungstext bleibt die größte Kostenart** (Max, 2026-10-16): für 680500 also
  „Vodafone VF Lizenz RC" — keine eigene Bezeichnung je Sachkonto.

Offen:

- **Umstellungsmonat mit Hendric bestätigen** (Vorschlag Oktober 2026).
- **Vodafone-Beleg 4452768764** (24.08.2026, 548,91 € brutto = 461,27 € netto, 680510, KST 8000
  „Kita & Schule", Stapel „Kreditoren AUG2026 (01/09)"): einmalig Jan–Aug, Nummernkreis 445… passt zu
  keinem der vier Vodafone-Rechnungskonten (Mobilfunk 122…, Festnetz 37…) und steht in keinem
  Portal-Export. Belegbild in DATEV prüfen — einmalige Rechnung (z. B. Endgerät) oder ein eigenes
  Kundenkonto für Kita & Schule.
