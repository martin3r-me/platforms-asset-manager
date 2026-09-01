# Die Tabellen-Ansicht ist eine Nutzer-Präferenz, kein Auswertungs-Parameter

Status: akzeptiert (beschlossen 2026-09-01)

Kontext: Die Kostenaufteilung (`Costs\Allocation`, Pivot Kostenstellen-Baum × Kostenart) hatte feste
Spaltenbreiten — `min-w-[220px]` für die Kostenstellen-Spalte, `min-w-[90px]` je Kostenart — und keine
Möglichkeit, etwas auszublenden oder umzusortieren. Bei einem realen Tenant sind das 15+ Kostenarten
und rund 70 Kostenstellen-Zeilen: die Tabelle scrollt horizontal, mehrere Spalten sind über die ganze
Höhe leer („–"), und die Spalte, die man vergleichen will, steht am anderen Ende. Die Anforderung war
ausdrücklich „wie in Excel": Breiten ziehen, Spalten schieben, Zeilen ausblenden.

Zwei Dinge machen das mehr als eine CSS-Frage:

1. **Wo lebt der Zustand?** Eine Anpassung, die beim nächsten Login weg ist, wird nicht benutzt. Eine,
   die im Browser lebt, ist am zweiten Gerät weg. Eine, die team-weit gilt, ändert die Ansicht der
   Kollegen mit.
2. **Was darf sie verändern?** Die Kostenaufteilung ist eine Auswertung, die gegen Rechnungen geprüft
   wird. Wenn das Ausblenden einer Spalte die Summe senkt, sind zwei Bildschirme derselben Tabelle
   nicht mehr vergleichbar — und niemand sieht, warum.

## Entscheidung

### 1. Gespeichert pro (User × Team × Tabelle), in der Datenbank

Neue Tabelle `asset_table_views` (Model `AssetTableView`): `user_id`, `team_id`, `table_key`, `payload`
(JSON), unique über das Tripel. Gebaut wie `asset_tenant_selections` — Auto-Increment-ID, kein UuidV7,
kein SoftDeletes, **kein Activity-Log**: eine Historie über Spaltenbreiten wäre nur Rauschen im
Audit-Trail.

**Kein `tenant_id`.** Die Ansicht gehört zum Blick, nicht zu den Daten: beim Tenant-Wechsel soll
dieselbe Spaltenbreite gelten. Das ist die einzige bewusste Ausnahme von „alles Fachliche ist
tenant-gebunden" (ADR 0016) — und sie ist keine, weil hier nichts Fachliches gespeichert wird.

**localStorage wurde verworfen**: schneller gebaut, aber die Ansicht wäre am Laptop anders als am
Terminal, und nach einem Cache-Leeren weg. **Team-weite oder geteilte Ansichten ebenfalls** — eine
Auswertungs-Ansicht ist eine persönliche Arbeitsweise; wer sie teilen will, teilt einen CSV-Export.

Fällt eine Ansicht auf den Werkszustand zurück, wird die Zeile **gelöscht** statt ein leerer Payload
gespeichert. „Hat der Nutzer etwas angepasst" bleibt so eine Frage der Existenz, nicht des Inhalts.

### 2. Die Ansicht verändert keine Zahl

Ausgeblendete Spalten und Zeilen sowie eingeklappte Gruppen zählen **weiter** in Spaltensummen,
Zeilensummen und Gesamtsumme — genau wie `SUM` in Excel über versteckte Zellen summiert. Sind Teile
verborgen, heißt die Fußzeile „Summe (alle Daten)", und die Bedienleiste benennt, was fehlt („2 Spalten
ausgeblendet · 1 Gruppe eingeklappt"). Der Shaper rechnet nichts nach: `colTotals` und `grandTotal`
kommen unverändert aus `CostAggregationService::costCenterByType()`.

Der **CSV-Export** folgt der Ansicht in der **Reihenfolge**, lässt aber nichts weg: ausgeblendete
Spalten stehen hinten und tragen „(ausgeblendet)" im Kopf; alle Zeilen werden exportiert. Eine
Bildschirm-Entscheidung ist kein Grund, Zahlen aus einer Auswertung zu tilgen.

### 3. Zustand und Formung sind framework-frei, die Persistenz ist es nicht

Drei Schichten mit klaren Aufgaben:

- `Support\TableViewState` — unveränderlicher Zustand (Breiten, Reihenfolge, ausgeblendete Spalten und
  Zeilen, eingeklappte Gruppen, Schnellfilter) samt **Sanitizing**. Der Payload kommt aus dem Browser
  und wird beim Lesen **und** beim Schreiben geprüft: Schlüssel nur `[A-Za-z0-9:_.-]{1,64}`, Breiten auf
  56…720 px geklemmt, Listen dedupliziert und gedeckelt. Ein manipulierter Payload kann darum nur die
  eigene Ansicht verunstalten — es gibt keinen Pfad, über den er beeinflusst, **welche** Daten geladen
  werden.
- `Support\PivotTableShaper` — formt den Pivot nach dem Zustand: Spalten in Reihenfolge und Breite,
  Sichtbarkeit, Faltung des Baums.
- `Services\TableViewService` + `Concerns\CustomizesTableView` — Laden/Speichern und die Livewire-Aktionen.

Beide Support-Klassen kennen weder Eloquent noch Request noch Blade. Deshalb liegt die Logik dort und
nicht im Blade: `tests/local/table-view-state.php` prüft sie **ohne Host-App und ohne DB**
(60 Prüfungen), `tests/local/table-view-service.php` den Speicherpfad gegen SQLite (22 Prüfungen).

Der Zustand liegt **nicht** als Livewire-Property auf dem Draht, sondern wird pro Request gelesen
(memoisiert, eine Query). Sonst wäre der Payload Ballast in jeder Interaktion der Seite.

### 4. Im Browser lebt nur der Spiegel der Breiten

Das Ziehen einer Spaltenbreite läuft in Alpine gegen ein lokales Breiten-Modell und `<colgroup>`;
gespeichert wird **einmal beim Loslassen** (`setColumnWidths`). Ein Server-Roundtrip pro Mausbewegung
wäre unbenutzbar. Damit ein serverseitiges Zurücksetzen nicht am Spiegel vorbeiläuft, schickt jede
Mutation ein `am-table-view-updated`-Event, das Alpine nachzieht.

Alles Übrige — Verschieben, Aus-/Einblenden, Einklappen — ist ein normaler Livewire-Call: dort
entscheidet der Server, was gerendert wird, und es gibt keinen zweiten Zustand.

Kein Bundling, kein npm-Paket: das Modul bringt keine Asset-Pipeline mit, das Skript steht inline im
Partial und ist idempotent (`window.amPivotGrid || function …`).

### 5. Die gespeicherte Reihenfolge ist ein Wunsch, keine Wahrheit

Kostenarten kommen und gehen. `orderColumns()` nimmt zuerst die gespeicherten Schlüssel, die es noch
gibt, und hängt jede weitere Spalte in ihrer Ursprungsreihenfolge an. Eine neu angelegte Kostenart
erscheint damit **immer**, statt still zu fehlen, nur weil eine ältere Ansicht sie nicht kennt.

Zeilen-Schlüssel hängen an der Kostenstellen-**ID** (`cc:<id>`), nicht am Code: Codes sind im Baum
nicht garantiert eindeutig, und ein umbenannter Code würde die Ansicht verlieren. Die Auffangzeilen
haben feste Schlüssel (`x:unassigned`, `x:orphan`).

### 6. Gefaltet wird über die echte Tiefe

Eine eingeklappte Gruppe schluckt **alle** Nachfahren, nicht nur die direkten Kinder: die Zeilen kommen
in Baum-Reihenfolge, also gehören alle folgenden mit größerer Tiefe dazu. Ein Filter auf `parent_code`
würde Enkel durchlassen und die Faltung wäre löchrig. Die **Anzeige**-Einrückung ist bei 6 Ebenen
gedeckelt, die **Logik** rechnet weiter mit der echten Tiefe — ein gedeckelter Wert würde tiefe Zweige
fälschlich als Geschwister behandeln.

## Konsequenzen

- Die Kostenaufteilung startet mit schmaleren Defaults (Kostenstelle 240 px, Kostenart 96 px, Summe
  112 px) und `table-fixed`; Kopfzeilen werden abgeschnitten und tragen den vollen Text im `title`.
  Doppelklick auf den Zieh-Greifer setzt auf Inhaltsbreite — gemessen über unsichtbare
  `[data-measure]`-Zwillinge, weil die sichtbaren Zellen abgeschnitten sind und sonst immer die
  aktuelle Spaltenbreite zurückmelden würden.
- `CustomizesTableView` ist bewusst tabellen-unabhängig: eine weitere Tabelle braucht nur
  `tableViewKey()`, `tableViewColumnKeys()` und ein Blade, das dem Shaper-Modell folgt. Ausgerollt ist
  zunächst **nur** die Kostenaufteilung.
- **Kein Gate.** Die Anpassung ändert keine Daten, nur den eigenen Blick darauf; jede Aktion arbeitet
  ausschließlich auf (aktueller User × aktuelles Team). Ein `asset-manager.manage`-Gate würde Membern
  das Lesen erschweren, ohne etwas zu schützen.
- Offen und absichtlich nicht mitgebaut: **Sortieren nach Spaltenwert** (die Zeilen sind ein Baum —
  eine Sortierung müsste erst beantworten, was mit den Ebenen passiert), **benannte Ansichten/Presets**
  und **Spaltenbreiten je Zeitraum-Umschalter** (monatlich/quartal teilen heute eine Ansicht).
