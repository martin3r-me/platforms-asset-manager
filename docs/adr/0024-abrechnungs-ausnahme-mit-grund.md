# Die Zählregel bekommt Ausnahmen — begründet, datiert und in der Rechnung ausgewiesen

Status: akzeptiert (beschlossen 2026-09-03, Max)

Kontext: [ADR 0021](0021-weiterberechnung-als-zaehlregel.md) hat die Abrechnungsbasis als **Regel**
festgelegt und ausdrücklich entschieden: *„Am Asset-Träger wird kein Feld ergänzt."* Die Regel
kennt vier Achsen — keine UPN, kein Personenkonto, ausgeschlossene Domain, keine Microsoft-Lizenz.

Im Betrieb zeigten sich drei Fallgruppen, die keine dieser Achsen ausdrücken kann:

1. **Vertragliche Einzelfälle** — eine Person mit Lizenz, in der Kundendomain, Personenkonto, die
   laut Absprache trotzdem nicht berechnet wird (Geschäftsführung, Sonderabsprache).
2. **Test- und technische Konten**, deren Kennung wie die einer Person aussieht und die deshalb
   auch der [[Träger-Typ]]-Klassifizierung entgehen.
3. **Externe** (`holder_type = 'external'`). Sie standen bisher **auf der Rechnung**, weil
   `external` nicht in `AssetHolder::NON_PERSON_TYPES` steht — eine stille Fehlberechnung, die
   niemandem auffiel, weil sie in die richtige Richtung für uns ging.

Der naheliegende Griff wäre `is_active` gewesen: das Feld hält Träger aus jeder Zählung heraus.
Das geht nicht. Seit [ADR 0023](0023-ausgeschiedene-traeger-stilllegen.md) ist `is_active` das
Abbild von Entras `accountEnabled` und wird bei **jedem** Tenant-User-Sync überschrieben; eine von
Hand gesetzte Deaktivierung wäre am nächsten Morgen weg. Das Feld beantwortet außerdem eine andere
Frage („arbeitet dieser Mensch noch?") als die hier gestellte („bezahlt der Kunde für ihn?").

Entscheidung: **Die Zählregel wird dreistufig — und die dritte Stufe ist eine Ausnahme, keine
Positivliste.**

**Stufe 1 — die Regel aus ADR 0021 bleibt der Default.** Ein neu angelegter Träger zählt
automatisch mit. Diese Eigenschaft ist der Kern von ADR 0021 und wird nicht angetastet.

**Stufe 2 — Regel-Erweiterungen am Abrechnungsprofil**, für ganze Fallgruppen:

- `count_external` (Default **`true`**) — zählen Externe mit? Der Default hält das bestehende
  Verhalten: eine Migration darf die Menge einer laufenden Abrechnung nicht ändern.
- `exclude_patterns` — Namensmuster im Format der `holder_type_rules` des `HolderClassifier`
  (Feld `upn`/`email`/`display_name` × Vergleich `prefix`/`suffix`/`contains`/`equals`). Eine Regel
  für zwölf Testkonten ist besser als zwölf Einzelausnahmen, die niemand mehr aufräumt.

**Stufe 3 — die Einzelausnahme am Träger.** Drei Felder an `asset_holders`:
`billing_excluded_at`, `billing_excluded_reason`, `billing_excluded_by`. Gesetzt und geleert werden
sie nur gemeinsam, über den `BillingExclusionService` — die eine Wahrheit für Detailseite,
Sammelaktion und MCP-Tool.

Damit kehrt ADR 0021 in einem Punkt: es gibt jetzt ein Feld am Träger. **Nicht gekehrt ist die
Begründung dahinter** — das dort verworfene `billable`-Häkchen war eine *Positivliste* (152 Häkchen,
die bei jedem Ein- und Austritt nachgezogen werden müssen, mit einem vergessenen Häkchen als
falschem Betrag auf der Rechnung). Eine Ausnahme mit Default `NULL` ist das Gegenteil: bei einem
Eintritt ist nichts zu pflegen, gepflegt wird nur der Sonderfall.

## Bewusste Abgrenzungen / Trade-offs

- **Zeitstempel statt Boolean, Grund als Pflichtfeld.** Ein Boolean hätte gesagt „zählt nicht" und
  die einzige Frage offen gelassen, die drei Monate später gestellt wird: *warum nicht, und seit
  wann?* Der Grund ist deshalb im Service Pflicht, nicht nur im Formular — ein Kanal, über den man
  Ausnahmen unbegründet setzen kann, hebelt genau die Nachvollziehbarkeit aus, um die es geht. Er
  erscheint in `skipped` jeder Vorschau und jedes Snapshots als `Einzelausnahme: <Grund> (seit
  <Datum>)` und erfüllt damit dasselbe Versprechen wie die übrigen Ausschlussgründe aus ADR 0021.

- **Gespeichert am Träger, aggregiert am Profil.** Die Ausnahme lebt dort, wo sie entdeckt und
  gepflegt wird — auf der Trägerseite, sichtbar auch ohne Schreibrechte, mit Badge in der Liste und
  eigenem Filter-Preset. Die **Regel muss trotzdem an einer Stelle vollständig lesbar bleiben**,
  deshalb listet die Rechnungsvorschau die Einzelausnahmen aggregiert und beantwortet dabei die
  Frage, die eine reine Liste offen lässt: **wie viele davon bewegen überhaupt Geld?** Eine Ausnahme
  an jemandem ohne Lizenz sieht in der Liste gleich aus, wirkt aber nicht.

- **Die Wirkung steht vor der Entscheidung.** Jeder Schreibweg zeigt zuerst eine Vorschau mit
  *Menge vorher → nachher* und Betrag je aktivem Profil. Gerechnet wird sie mit der Produktionsregel
  selbst (`BillableHolderResolver::resolve()` mit übersteuerten Ausnahmen), nicht mit einer zweiten
  Zählung: eine nachgebaute Schnittmenge aus Auswahl und `principals` wäre in dem Moment falsch, in
  dem derselbe Mensch zweimal im Verzeichnis steht und nur eine der beiden Zeilen ausgenommen wird.
  Mehrere Profile werden **nicht** zu einer Zahl summiert — sie gehören zu verschiedenen Kunden.

- **Ausnahmen verrotten, und das muss sichtbar sein.** Der klassische Fehler einer Ausnahmeliste
  ist nicht der falsche Eintrag, sondern der vergessene: eine Ausnahme von 2026, die 2029 noch
  steht, berechnet dem Kunden seit drei Jahren zu wenig. Gegenmittel statt Ablauffrist: die Vorschau
  weist das **Alter** jeder Ausnahme aus und warnt, wenn die älteste über ein Jahr alt ist. Eine
  automatische Befristung wurde verworfen — sie würde eine Ausnahme irgendwann still aufheben und
  damit einen Menschen auf eine Rechnung setzen, ohne dass jemand hinsieht.

- **Kein Eingriff in `is_active`.** Weder UI noch Tool schreiben das Feld für Abrechnungszwecke.
  Die Tool-Beschreibungen sagen das ausdrücklich, weil ein Modell sonst den naheliegenden falschen
  Griff tut. `is_active` = Verzeichniszustand (ADR 0023), Ausnahme = kaufmännische Entscheidung.

- **Die Ausnahme gilt je Träger, nicht je Profil.** Ein Träger gehört zu genau einem [[Tenant]],
  ein Profil zeigt auf genau einen Tenant — die Aussage „dieser Mensch wird diesem Kunden nicht
  berechnet" trifft also zu. Existieren für einen Tenant mehrere Profile, wirkt die Ausnahme auf
  alle. Eine Ausnahme *je Profil* wäre die feinere Modellierung, hätte aber eine Pivot-Tabelle für
  einen Fall gebraucht, den es heute nicht gibt.

- **Das UI pflegt nur die einfache Musterform** (`upn contains`), weil das die Fallgruppe
  Test-/technische Konten abdeckt. Reichere Muster bleiben beim Speichern **erhalten** statt von der
  Vereinfachung überschrieben zu werden — sonst löschte ein Klick auf „Speichern" stillschweigend
  eine `email suffix`-Regel.

- **Kein Zweitkonto-Modell.** Zwei lizenzierte Konten desselben Menschen wären keine Ausnahme,
  sondern ein Identitätsproblem (die Rechnung zählt dann Konten statt Menschen). Der Resolver
  entdoppelt heute nur nach UPN. Sollte der Fall auftreten, ist der Weg „Zweitkonto verweist auf
  Primär-Träger" und nicht eine Ausnahme mit dem Grund „Zweitkonto" — die würde das Problem
  zukleben.
