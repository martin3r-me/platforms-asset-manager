# Das Belegdatum ist der Monatsletzte des Abrechnungsmonats, nicht der Tag des Laufs

Status: akzeptiert (beschlossen 2026-09-04, Max)

Kontext: Der Rechnungslauf aus [ADR 0021](0021-weiterberechnung-als-zaehlregel.md) übergibt easybill
seit jeher den **Leistungszeitraum** (`service_date` `{type: SERVICE, date_from, date_to}`), aber
**kein Belegdatum**. Ohne `document_date` setzt easybill das Erstellungsdatum ein — also den Tag,
an dem jemand den Entwurf anlegt.

Das Controlling hat das beanstandet: Die Rechnung über die Mitarbeiter-Servicepauschale für den
Leistungszeitraum 01.–31.07.2026 trug das Datum 07.08.2026 und wurde damit in den August gebucht.
Für die BHG.DIGITAL GMBH gilt **SOLL-Besteuerung** — die Umsatzsteuer ist im Leistungsmonat fällig.
DATEV zieht für die Periodenzuordnung aber das **Belegdatum**, nicht den Leistungszeitraum. Ein Lauf
in den ersten Tagen des Folgemonats — der Normalfall, denn vorher steht der Bestand nicht fest —
buchte den Umsatz also systematisch einen Monat zu spät.

Der Leistungszeitraum allein löst das nicht. Er ist nach § 14 UStG ein Pflichtbestandteil der
Rechnung und steht bereits korrekt drin, aber er steuert die Buchungsperiode nicht.

Entscheidung: **`document_date` wird immer auf den Monatsletzten des Abrechnungsmonats gesetzt.**

Berechnet wird er dort, wo der Leistungszeitraum schon herkommt (`BillingRunService::buildDocument()`,
`$end = $start->copy()->endOfMonth()`), und beide teilen sich denselben Wert — Leistungszeitraum-Ende
und Belegdatum können damit nicht auseinanderlaufen.

Verworfen: **`min(Monatsletzter, heute)`.** Das hätte für abgeschlossene Monate dasselbe geliefert
und beim Lauf für den laufenden Monat das heutige Datum behalten, also kein Datum in der Zukunft
erzeugt. Dagegen sprechen zwei Dinge: Das Ergebnis hinge vom Ausführungstag ab — derselbe Lauf
ergäbe am 15. und am 30. verschiedene Belege, was weder testbar noch erklärbar ist. Und fachlich
wäre es falsch herum: Bei SOLL-Besteuerung gehört der Umsatz einer Monatspauschale in den
Leistungsmonat, und genau das drückt der Monatsletzte aus.

Folgen:

- Ein Entwurf für den **laufenden** Monat trägt ein Belegdatum in der Zukunft (Lauf am 15.09. →
  Beleg vom 30.09.). Bewusst in Kauf genommen: die Periode stimmt, und der Beleg ist ohnehin ein
  Entwurf — festgeschrieben und versendet wird er in easybill, nicht hier.
- Der Regelfall „Lauf am 2. oder 3. des Folgemonats" bucht ab sofort im richtigen Monat.
- Bereits gestellte Rechnungen korrigiert das nicht; das ist Sache der Buchhaltung.
- Das **Erlöskonto** ist von dieser Entscheidung unberührt. Es kommt weiterhin ausschließlich vom
  Commerce-Artikel (`effective_revenue_account`, siehe `BillingUnitPriceResolver`) — die
  Kontentrennung des Controllings (440000 sonstige · 440100 ICT · 440200 Food · 440300 Marketing)
  ist reine Stammdatenpflege an den Artikeln, kein Code.
