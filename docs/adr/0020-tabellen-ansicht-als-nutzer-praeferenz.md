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

### 4. Der Server rendert alles, der Browser versteckt — als eine CSS-Regel

Die erste Fassung liess jede Aenderung serverseitig rendern. Das war unbenutzbar: `render()` ruft
`CostAggregationService::costCenterByType()`, und dessen `normalizedLines()` laedt die Kostenzeilen,
**alle** Asset-Traeger, **alle** Items, das Lizenz-Preisbuch und die Geraetekosten — dazu je Knoten
die Rollups ueber seine Nachfahren. Ein Klick auf „Spalte ausblenden" kostete damit die Rechenzeit
einer ganzen Auswertung, und die Seite ruckelte bei jedem Handgriff.

Deshalb jetzt: der Server rendert **immer alle** Spalten und **alle** Zeilen und markiert sie
(`data-col`, `data-row`, `data-ancestors`, `data-empty-row`). Der Browser haelt den Zustand und
erzeugt daraus **eine** CSS-Regel in einem `<style x-text="css">`:

```
.am-grid--costs-allocation thead th[data-col="12"]{width:140px}
.am-grid--costs-allocation [data-col="17"]{display:none}
.am-grid--costs-allocation [data-ancestors~="cc:100"]{display:none}
.am-grid--costs-allocation [data-empty-row="1"]{display:none}
.am-grid--costs-allocation table{width:1408px}
```

Aus-/Einblenden, Falten und Breiten kosten damit **keinen** Roundtrip und **kein** Neu-Rendern.
Gespeichert wird im Hintergrund (`syncTableView` + `skipRender()`): der Aufruf rendert nichts und
kostet nur Laden und Schreiben. Bewusst **ohne** Sammelfenster — ein Timer kann von einem Voll-Render
ueberholt werden (Klick auf „Quartal" direkt nach dem Ausblenden), und dann rendert der Server den
noch nicht gespeicherten Stand, die Ansicht springt zurueck. Mehrere Aufrufe im selben Tick fasst
Livewire selbst zusammen.

Zwei Details, die dabei entschieden wurden:

- **Die Breite steht auf der Kopfzelle, nicht im `<colgroup>`.** Die `col`-Zuordnung ist positionell,
  die Kopfzelle traegt ihren Schluessel selbst. Am statischen Prototyp geprueft: eine per
  `display:none` ausgeblendete Spalte kollabiert sauber, die uebrigen Breiten bleiben exakt
  (Spaltengrenzen auf 200/260/320/420 px). Ebenso geprueft, dass `[data-ancestors~="cc:100"]` Kind
  **und** Enkel trifft.
- **`wire:ignore` am `<style>`**, sonst ueberschreibt ein Morph den erzeugten Regel-Text mit dem
  leeren Server-HTML, und `x-text` feuert erst bei der naechsten Zustandsaenderung wieder — die
  Ansicht spraenge fuer einen Moment auf den Server-Stand zurueck.
- **Ein `wire:key`-Fingerprint** aus (Zustand × Spaltenreihenfolge × Zeilenzahl) haengt am Wrapper.
  Eine gemorphte Alpine-Komponente wertet ihr `x-data` nicht neu aus; ohne den Schluessel behielte
  der Browser nach „Spalte verschieben" oder „Ansicht zuruecksetzen" seinen alten Spiegel. Aendert
  sich der Fingerprint, ersetzt Livewire das Element und Alpine startet mit dem gepruefen Zustand.

Serverseitig bleiben genau drei Aktionen: `syncTableView` (speichert, rendert nicht),
`moveColumn` und `resetTableView` (rendern, weil sich das DOM aendern muss — CSS kann Tabellenzellen
nicht umsortieren).

**Der Preis:** die Sichtbarkeitsregeln leben jetzt im Browser, nicht in PHP. Damit sie nicht
ungetestet sind, liest `tests/local/pivot-grid.mjs` die Factory **direkt aus dem Blade** und prueft
sie unter Node ohne Alpine (55 Pruefungen: erzeugtes CSS, Zaehler, Faltung, Drop-Ziel-Berechnung).
Der Shaper wird dadurch schlanker — er filtert nichts mehr, sondern liefert Reihenfolge, Breiten,
Flags und die Vorfahren-Kette.

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
- Die Tabelle hat einen **eigenen Scrollbereich** (max. 70 vh) mit festgehaltener Kopfzeile,
  Kostenstellen-Spalte und Summenzeile. Dafuer musste `border-collapse` auf `border-separate` +
  `border-spacing: 0` weichen: mit `collapse` zeichnen Browser die Rahmen sticky-positionierter
  Zellen nicht zuverlaessig. Optisch identisch, weil die Rahmen an den Zellen sitzen.
- Offen und absichtlich nicht mitgebaut: **Sortieren nach Spaltenwert** (die Zeilen sind ein Baum —
  eine Sortierung müsste erst beantworten, was mit den Ebenen passiert), **benannte Ansichten/Presets**
  und **Spaltenbreiten je Zeitraum-Umschalter** (monatlich/quartal teilen heute eine Ansicht).
