# Ausgeschiedene Träger werden stillgelegt, nicht gelöscht — und Papierkorb-UPNs normalisiert

Status: akzeptiert (beschlossen 2026-09-02, Max)

Kontext: In der Trägerliste stand ein Mitarbeiter **doppelt**, obwohl sein Entra-Konto längst
gelöscht war. Die Untersuchung förderte zwei voneinander unabhängige Lücken zutage.

**Der Asset Manager erfuhr nie, dass jemand geht.** Das Feld `is_active` existiert seit dem
Anfang und wird an fünf Stellen ausgewertet — Preset „Inaktiv", Kosten-Anomalien
(`inactive_employees`), Plausibilitätsprüfung, der Lizenz-Auszug. Geschrieben wurde es von
**keinem** Codepfad je auf `false`; `HolderService` setzt es beim Anlegen und bei jedem Sync hart
auf `true`. `accountEnabled` — die einzige Auskunft von Entra darüber, ob ein Konto noch benutzt
wird — stand nicht einmal im `$select` der Graph-Abfrage. Auf der Live-Umgebung waren dadurch
**290 von 290** Trägern aktiv und die Anomalie-Auswertung „inaktive Mitarbeiter mit Kosten" konnte
per Konstruktion nie etwas finden.

**Und das Löschen im Verzeichnis verdoppelte den Bestand, statt ihn zu bereinigen.** Entra benennt
eine gelöschte UPN um — objectId ohne Bindestriche, davor gesetzt vor die ursprüngliche Adresse
(`0b521d5646ee4b158a9c13727799dbd0F.MUSTER@firma.de`), solange das Konto im Papierkorb liegt
(30 Tage). Weil die UPN der Identitätsanker ist ([ADR 0017](0017-asset-traeger-statt-mitarbeiter.md)),
war das für uns eine **andere Person**: jeder Pfad, der die umbenannte Adresse sah, legte einen
zweiten Träger an.

Entscheidung:

- **`accountEnabled` wird mitgelesen** und auf `is_active` abgebildet. Fehlt das Feld in der
  Antwort, bleibt der bisherige Wert stehen — lieber nichts wissen als fälschlich deaktivieren.
- **Reconcile:** Träger, die das Verzeichnis nicht mehr kennt, werden **stillgelegt, nicht
  gelöscht**.
- **UPN-Normalisierung** in `HolderService::findOrCreateByUpn()`: das Papierkorb-Präfix wird
  entfernt, bevor gesucht oder angelegt wird. Neue Dubletten entstehen dadurch nicht mehr.
- **`HolderMergeService`** führt die bereits entstandenen zusammen; `asset-manager:merge-deleted-holders`
  fährt ihn mit `--dry-run`.

## Bewusste Abgrenzungen / Trade-offs

- **Stilllegen statt löschen** ist der Kern. An einem Träger hängen Übergabeprotokolle,
  Zuordnungsverläufe und Kostenzeilen vergangener Monate. Ein gelöschter Träger würde die
  Auswertungen der Vorjahre still verfälschen — dieselbe Überlegung wie beim Lifecycle-Pin für
  Geräte ([ADR 0007](0007-lifecycle-pinnt-gegen-reconcile.md)). Der Preis: die Liste wächst
  monoton, und „Inaktiv" wird auf Dauer der größte Chip.

- **Drei Sicherungen am Reconcile**, weil ein falsch laufender schlimmer ist als gar keiner:
  eine leere Verzeichnis-Antwort legt **nichts** still (ein erfolgreiches `value: []` ist ein
  Tenant-Glitch, kein Massenaustritt); nur `source = 'graph'` wird angefasst (abgeleitete Träger aus
  Excel-Import oder Rechnungen hat das Verzeichnis nie gekannt und kann sie nicht bestätigen); und
  nur bereits aktive. Fehlerhafte Abrufe fallen ohnehin vorher heraus — `getUsersWithLicenses()`
  gibt in jedem Fehlerpfad `null` zurück, eine nicht-leere Antwort ist also vollständig.

- **Der UPN-Abgleich läuft in PHP, nicht als `whereNotIn`.** UPNs kommen in gemischter
  Schreibweise; MySQL vergleicht standardmäßig ohne Rücksicht darauf, SQLite aber schon. Derselbe
  Lauf käme lokal und live sonst auf verschiedene Ergebnisse.

- **Das Präfix-Muster verlangt einen nicht leeren Lokalteil** nach den 32 Hex-Zeichen
  (`/^[0-9a-f]{32}(?=[^@]+@)/i`). Ohne dieses `+` würde ein Dienstkonto, dessen Lokalteil zufällig
  aus genau 32 Hex-Zeichen besteht, auf `@firma.de` zusammengestrichen. Der lokale Test deckte genau
  das auf.

- **Zusammenführen räumt erst, löscht dann.** Alle vier Fremdschlüssel auf einen Träger
  (`asset_items`, `asset_cost_lines`, `asset_assignments`, `asset_handovers`) wandern auf den
  bestehenden Datensatz, Lücken dort werden aus der Dublette gefüllt — sie trägt oft die
  Kostenstelle aus dem Import, die dem Verzeichnis-Datensatz fehlt. Erst der dann leere Datensatz
  verschwindet, alles in einer Transaktion. Kommt eine fünfte Referenz dazu, gehört sie in
  `HolderMergeService::REFERENCES`, sonst bliebe sie beim Zusammenführen zurück.

- **Gibt es kein Gegenstück, wird nichts gelöscht** — die Dublette bekommt nur ihre richtige UPN
  zurück und wird stillgelegt. Das ist verlustfrei und macht den Träger wieder auffindbar, falls das
  Konto wiederhergestellt wird.

- **Folge für die Weiterberechnung** ([ADR 0021](0021-weiterberechnung-als-zaehlregel.md)):
  Ausgeschiedene fallen künftig aus der Zählung, weil die Zählregel nur aktive Träger sieht. Bis zu
  diesem Schritt zählten sie mit, solange sie eine Lizenz trugen.
