/**
 * Prueft die Browser-Logik der anpassbaren Tabellen-Ansicht (docs/adr/0020).
 *
 * Seit dem Umbau entscheidet der Browser ueber Sichtbarkeit, Faltung und Breiten — als eine
 * generierte CSS-Regel, damit ein Klick kein Neu-Rendern und damit keine komplette
 * Kostenaggregation kostet. Diese Logik waere sonst die einzige ungetestete Stelle des Features.
 *
 * Der Test liest die Factory `window.amPivotGrid` direkt aus dem Blade-Partial (eine Quelle, keine
 * Kopie) und ruft sie ohne Alpine auf: die Getter sind gewoehnliche JS-Getter, und alles ausser
 * `autofit()` (DOM-Messung) und `drop()`s `$wire`-Aufruf laeuft ohne Browser.
 *
 * Aufruf (aus dem Modul-Root):
 *
 *     node tests/local/pivot-grid.mjs
 */

import { readFileSync } from 'node:fs';

const BLADE = new URL('../../resources/views/livewire/costs/_pivot-table.blade.php', import.meta.url);

// --- Factory aus dem Blade holen -------------------------------------------------------------
const source = readFileSync(BLADE, 'utf8');
const start = source.indexOf('window.amPivotGrid =');
const end = source.indexOf('</script>', start);

if (start === -1 || end === -1) {
  console.error('FAIL: window.amPivotGrid nicht im Blade gefunden — Partial umgebaut?');
  process.exit(2);
}

const factorySource = source.slice(source.indexOf('function (config)', start), end).replace(/;\s*$/, '');
const amPivotGrid = eval('(' + factorySource + ')');

// --- Pruefgeruest ----------------------------------------------------------------------------
let total = 0;
const failed = [];

function check(what, expected, actual) {
  total++;
  const a = JSON.stringify(actual);
  const e = JSON.stringify(expected);
  const ok = a === e;

  console.log(`${what.padEnd(60)} ${ok ? 'ok  ' : 'FAIL'}  erwartet=${e} ist=${a}`);
  if (!ok) failed.push(what);
}

/** Baut ein Grid wie das Blade: 3 Kostenarten (die dritte leer), Baum 100 > 110 > 111, 200 leer. */
function grid(view = {}) {
  return amPivotGrid({
    scope: '.am-grid--costs-allocation',
    view: {
      widths: {},
      order: [],
      hidden_columns: [],
      hidden_rows: [],
      collapsed_rows: [],
      hide_empty_columns: false,
      hide_empty_rows: false,
      ...view,
    },
    fixed: [{ key: 'label', def: 240 }, { key: 'total', def: 112 }],
    cols: [
      { key: '1', def: 96, empty: false },
      { key: '2', def: 96, empty: false },
      { key: '3', def: 96, empty: true },
    ],
    rows: [
      { key: 'cc:100', empty: false, anc: [] },
      { key: 'cc:110', empty: false, anc: ['cc:100'] },
      { key: 'cc:111', empty: false, anc: ['cc:100', 'cc:110'] },
      { key: 'cc:200', empty: true, anc: [] },
      { key: 'x:unassigned', empty: false, anc: [] },
    ],
    min: 56,
    max: 720,
  });
}

const S = '.am-grid--costs-allocation';

console.log('\n=== 1) Werkszustand: nichts versteckt, Breiten aus den Defaults ===');
{
  const g = grid();
  check('Alle Spalten sichtbar', 3, g.visibleCols.length);
  check('Keine Zeile versteckt', 0, g.hiddenRowCount);
  check('Nicht angepasst', false, g.customized);
  check('Kein Hinweistext', '', g.hint);
  check('Tabellenbreite = Summe der Defaults', `${S} table{width:640px}`,
    g.css.split('\n').find((l) => l.includes('table{')));
  check('Breite steht auf der Kopfzelle', true,
    g.css.includes(`${S} thead th[data-col="1"]{width:96px}`));
  check('Keine display:none-Regel', false, g.css.includes('display:none'));
}

console.log('\n=== 2) Spalte ausblenden ===');
{
  const g = grid({ hidden_columns: ['2'] });
  check('Zaehler stimmt', 1, g.hiddenColCount);
  check('Regel versteckt ALLE Zellen der Spalte', true,
    g.css.includes(`${S} [data-col="2"]{display:none}`));
  check('Keine Breite fuer die versteckte Spalte', false,
    g.css.includes('th[data-col="2"]{width'));
  check('Tabellenbreite ohne die Spalte', `${S} table{width:544px}`,
    g.css.split('\n').find((l) => l.includes('table{')));
  check('Hinweistext benennt es', '1 Spalte ausgeblendet', g.hint);
}

console.log('\n=== 3) Leere Spalten / Nullzeilen als Schnellfilter ===');
{
  const g = grid({ hide_empty_columns: true });
  check('Leere Kostenart verschwindet', 2, g.visibleCols.length);
  check('Regel greift ueber den Schluessel', true, g.css.includes(`${S} [data-col="3"]{display:none}`));
  check('Bespielte Spalten bleiben', true, g.css.includes('th[data-col="1"]{width:96px}'));

  const r = grid({ hide_empty_rows: true });
  check('Nullzeile zaehlt als versteckt', 1, r.hiddenRowCount);
  check('Eine Regel fuer alle Nullzeilen', true, r.css.includes(`${S} [data-empty-row="1"]{display:none}`));
  check('Sichtbare Zeilen', 4, r.visibleRowCount);
}

console.log('\n=== 4) Faltung: eine Regel schluckt den ganzen Teilbaum ===');
{
  const g = grid({ collapsed_rows: ['cc:100'] });
  check('Kind und Enkel gelten als versteckt', 2, g.hiddenRowCount);
  check('Die Gruppe selbst bleibt sichtbar', false, g.isRowHidden({ key: 'cc:100', empty: false, anc: [] }));
  check('Vorfahren-Regel steht im CSS', true,
    g.css.includes(`${S} [data-ancestors~="cc:100"]{display:none}`));
  check('Gruppe ist als eingeklappt bekannt', true, g.isCollapsed('cc:100'));
  check('Hinweistext zaehlt Gruppen', '2 Zeilen ausgeblendet · 1 Gruppe eingeklappt', g.hint);

  const inner = grid({ collapsed_rows: ['cc:110'] });
  check('Faltung auf Ebene 1 trifft nur den Enkel', 1, inner.hiddenRowCount);
}

console.log('\n=== 5) Zeile ausblenden, alles wieder einblenden ===');
{
  const g = grid();
  g.save = () => {};                       // kein $wire im Test
  g.hideRow('cc:111');
  g.hideRow('cc:111');                     // idempotent
  check('Eine Zeile versteckt', ['cc:111'], g.view.hidden_rows);
  check('Regel fuer die Zeile', true, g.css.includes(`${S} [data-row="cc:111"]{display:none}`));

  g.toggleGroup('cc:100');
  g.toggleFlag('hide_empty_rows');
  g.showAllRows();
  check('Alles zurueck: Zeilen', [], g.view.hidden_rows);
  check('Alles zurueck: Faltung', [], g.view.collapsed_rows);
  check('Alles zurueck: Nullzeilen-Filter', false, g.view.hide_empty_rows);

  g.toggleCol('2');
  check('Spalte umgeschaltet', ['2'], g.view.hidden_columns);
  g.toggleCol('2');
  check('Zweiter Klick blendet ein', [], g.view.hidden_columns);

  g.toggleFlag('hide_empty_columns');
  g.showAllCols();
  check('Alle Spalten einblenden loescht den Filter', false, g.view.hide_empty_columns);
}

console.log('\n=== 6) Breiten: klemmen und zuruecksetzen ===');
{
  const g = grid({ widths: { 1: 300, label: 400 } });
  check('Gezogene Breite gewinnt', 300, g.width('1'));
  check('Default fuer ungezogene Spalte', 96, g.width('2'));
  check('Label-Breite', 400, g.width('label'));
  check('Tabellenbreite rechnet mit', `${S} table{width:1004px}`,
    g.css.split('\n').find((l) => l.includes('table{')));
  check('Unter Minimum wird geklemmt', 56, g.clamp(10));
  check('Ueber Maximum wird geklemmt', 720, g.clamp(99999));

  g.save = () => {};
  g.resetWidths();
  check('Zuruecksetzen faellt auf Defaults', 96, g.width('1'));
  check('Danach nicht mehr angepasst', false, g.customized);
}

console.log('\n=== 7) Verschieben: rechte Haelfte heisst „dahinter" ===');
{
  const moves = [];
  const g = grid();
  g.$wire = { moveColumn: (c, t) => moves.push([c, t]) };

  // linke Haelfte von Spalte 3 → davor einsortieren
  g.dragged = '1';
  g.dropTarget = '3';
  g.dropAfter = false;
  g.drop();
  check('Drop links: vor das Ziel', ['1', '3'], moves.at(-1));

  // rechte Haelfte von Spalte 2 → vor die naechste sichtbare Spalte (3)
  g.dragged = '1';
  g.dropTarget = '2';
  g.dropAfter = true;
  g.drop();
  check('Drop rechts: vor die naechste Spalte', ['1', '3'], moves.at(-1));

  // rechte Haelfte der letzten Spalte → ans Ende (null)
  g.dragged = '1';
  g.dropTarget = '3';
  g.dropAfter = true;
  g.drop();
  check('Drop hinter der letzten: ans Ende', ['1', null], moves.at(-1));

  // Drop auf die Summen-Spalte = ans Ende
  g.dragged = '2';
  g.dropTarget = 'END';
  g.dropAfter = true;
  g.drop();
  check('Drop auf „Summe": ans Ende', ['2', null], moves.at(-1));

  // Auf sich selbst: kein Aufruf
  const before = moves.length;
  g.dragged = '2';
  g.dropTarget = '2';
  g.dropAfter = false;
  g.drop();
  check('Auf sich selbst: kein Server-Aufruf', before, moves.length);

  // Ohne Drag-Quelle: kein Aufruf
  g.dragged = null;
  g.dropTarget = '1';
  g.drop();
  check('Ohne Drag-Quelle: kein Server-Aufruf', before, moves.length);

  // Eine versteckte Nachbarspalte wird beim „dahinter" uebersprungen
  const h = grid({ hidden_columns: ['3'] });
  const hits = [];
  h.$wire = { moveColumn: (c, t) => hits.push([c, t]) };
  h.dragged = '1';
  h.dropTarget = '2';
  h.dropAfter = true;
  h.drop();
  check('Versteckte Nachbarspalte wird uebersprungen', ['1', null], hits.at(-1));
}

console.log('\n=== 8) Einfuegemarke ===');
{
  const g = grid();
  g.dragged = '1';
  g.dropTarget = '2';
  g.dropAfter = false;
  check('Marke links am Ziel', 'am-drop-before', g.dropMark('2'));
  g.dropAfter = true;
  check('Marke rechts am Ziel', 'am-drop-after', g.dropMark('2'));
  check('Keine Marke an anderen Spalten', '', g.dropMark('3'));
  check('Keine Marke an der Quelle', '', g.dropMark('1'));
  g.dragged = null;
  check('Ohne Drag keine Marke', '', g.dropMark('2'));
}

console.log('\n=== 9) Alles versteckt bleibt erkennbar ===');
{
  const g = grid({ hidden_columns: ['1', '2', '3'] });
  check('Keine sichtbare Spalte', 0, g.visibleCols.length);
  check('Als angepasst erkannt', true, g.customized);

  const r = grid({ hidden_rows: ['cc:100', 'cc:110', 'cc:111', 'cc:200', 'x:unassigned'] });
  check('Keine sichtbare Zeile', 0, r.visibleRowCount);
}

console.log('');
if (failed.length === 0) {
  console.log(`ALLE ${total} PRUEFUNGEN GRUEN`);
  process.exit(0);
}

console.log(`${failed.length} von ${total} FEHLGESCHLAGEN:\n - ${failed.join('\n - ')}`);
process.exit(1);
