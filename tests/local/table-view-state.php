<?php

/**
 * Prueft die anpassbare Tabellen-Ansicht (docs/adr/0020): TableViewState (Zustand + Sanitizing) und
 * PivotTableShaper (Reihenfolge, Sichtbarkeit, Faltung des Kostenstellen-Baums).
 *
 * Laeuft absichtlich **ohne** tests/local/bootstrap.php und damit ohne Host-App und ohne DB: beide
 * Klassen sind framework-frei. Genau deshalb liegt die Logik dort und nicht im Blade oder in der
 * Livewire-Komponente.
 *
 * Aufruf (aus dem Modul-Root):
 *
 *     php tests/local/table-view-state.php
 */

require __DIR__ . '/../../src/Support/TableViewState.php';
require __DIR__ . '/../../src/Support/PivotTableShaper.php';

use Platform\AssetManager\Support\PivotTableShaper;
use Platform\AssetManager\Support\TableViewState;

$GLOBALS['am_checks'] = ['total' => 0, 'failed' => []];

function check(string $what, mixed $expected, mixed $actual): void
{
    $GLOBALS['am_checks']['total']++;

    $ok = is_float($expected)
        ? abs($expected - (float) $actual) < 0.0005
        : $expected === $actual;

    printf("%-62s %s  erwartet=%s ist=%s\n", $what, $ok ? 'ok  ' : 'FAIL',
        var_export($expected, true), var_export($actual, true));

    if (! $ok) {
        $GLOBALS['am_checks']['failed'][] = $what;
    }
}

function check_summary(): never
{
    $total  = $GLOBALS['am_checks']['total'];
    $failed = $GLOBALS['am_checks']['failed'];

    echo "\n";
    if ($failed === []) {
        echo "ALLE {$total} PRUEFUNGEN GRUEN\n";
        exit(0);
    }

    echo count($failed) . " von {$total} FEHLGESCHLAGEN:\n - " . implode("\n - ", $failed) . "\n";
    exit(1);
}

/**
 * Testpivot: Baum 100 (Gruppe) → 110 (Gruppe) → 111 (Blatt), dann 200 (Blatt, leer),
 * plus Auffangzeile „ohne Kostenstelle". Drei Kostenarten, die dritte ist ueberall 0.
 */
function am_pivot(): array
{
    $cells = fn (float $a, float $b, float $c) => [1 => $a, 2 => $b, 3 => $c];

    $node = fn (int $id, string $code, int $depth, bool $group, array $own, array $rollup) => [
        'cost_center_id' => $id,
        'code'           => $code,
        'name'           => 'KST ' . $code,
        'depth'          => $depth,
        'parent_code'    => '',
        'has_children'   => $group,
        'cells'          => $own,
        'rowTotal'       => array_sum($own),
        'rollupCells'    => $rollup,
        'rollupTotal'    => array_sum($rollup),
    ];

    return [
        'types' => [
            ['id' => 1, 'key' => 'ms_lizenz', 'name' => 'MS Lizenz'],
            ['id' => 2, 'key' => 'mobilfunk', 'name' => 'Mobilfunk'],
            ['id' => 3, 'key' => 'leer',      'name' => 'Nie benutzt'],
        ],
        'rows' => [
            $node(100, '100', 0, true,  $cells(10, 0, 0),  $cells(60, 5, 0)),
            $node(110, '110', 1, true,  $cells(20, 0, 0),  $cells(50, 5, 0)),
            $node(111, '111', 2, false, $cells(30, 5, 0),  $cells(30, 5, 0)),
            $node(200, '200', 0, false, $cells(0, 0, 0),   $cells(0, 0, 0)),
        ],
        'unassigned' => [
            'cost_center_id' => null,
            'code'           => '—',
            'name'           => 'Ohne Kostenstelle',
            'depth'          => 0,
            'parent_code'    => '',
            'has_children'   => false,
            'cells'          => $cells(7, 0, 0),
            'rowTotal'       => 7.0,
            'rollupCells'    => $cells(7, 0, 0),
            'rollupTotal'    => 7.0,
        ],
        'orphan'     => null,
        'colTotals'  => [1 => 67.0, 2 => 5.0, 3 => 0.0],
        'grandTotal' => 72.0,
        'meta'       => [
            1 => ['vendor' => 'Microsoft', 'system' => 'HGK', 'frequency' => 'monthly'],
            2 => ['vendor' => 'Vodafone',  'system' => 'HGK', 'frequency' => 'monthly'],
            3 => ['vendor' => null,        'system' => null,  'frequency' => null],
        ],
    ];
}

/** @return array<int,string> Zeilen-Schluessel der sichtbaren Zeilen */
function am_row_keys(array $shaped): array
{
    return array_column($shaped['rows'], 'key');
}

echo "\n=== 1) Sanitizing: der Payload kommt aus dem Browser und wird nicht geglaubt ===\n";

$dirty = TableViewState::fromArray([
    'widths' => [
        '1'                => 5,        // unter MIN → geklemmt
        '2'                => 99999,    // ueber MAX → geklemmt
        '3'                => '120.4',  // numerischer String → gerundet
        'boese key'        => 100,      // Leerzeichen → raus
        '<script>'         => 100,      // Markup → raus
        'label'            => 'breit',  // nicht numerisch → raus
    ],
    'order'              => [2, '1', '1', 'ok:1', 'nicht erlaubt!'],
    'hidden_columns'      => 'kein array',
    'hidden_rows'        => ['cc:1', 'cc:1', 'cc:2'],
    'collapsed_rows'     => ['cc:100'],
    'hide_empty_columns' => 'ja',
    'hide_empty_rows'    => 0,
    'unbekanntes_feld'   => 'egal',
]);

check('Breite unter Minimum wird geklemmt',      TableViewState::MIN_WIDTH, $dirty->widths['1']);
check('Breite ueber Maximum wird geklemmt',      TableViewState::MAX_WIDTH, $dirty->widths['2']);
check('Numerischer String wird gerundet',        120,                       $dirty->widths['3']);
check('Schluessel mit Leerzeichen faellt weg',   false,                     isset($dirty->widths['boese key']));
check('Markup-Schluessel faellt weg',            false,                     isset($dirty->widths['<script>']));
check('Nicht-numerische Breite faellt weg',      false,                     isset($dirty->widths['label']));
check('Reihenfolge dedupliziert + gefiltert',    ['2', '1', 'ok:1'],        $dirty->order);
check('Kein Array → leere Liste',                [],                        $dirty->hiddenColumns);
check('Doppelte Zeilen-Schluessel entfallen',    ['cc:1', 'cc:2'],          $dirty->hiddenRows);
check('Wahrheitswerte werden gecastet',          true,                      $dirty->hideEmptyColumns);
check('Wahrheitswert 0 bleibt false',            false,                     $dirty->hideEmptyRows);
check('Roundtrip bleibt stabil',                 $dirty->toArray(),         TableViewState::fromArray($dirty->toArray())->toArray());
check('Werkszustand gilt als unangepasst',       false,                     TableViewState::fresh()->isCustomized());
check('Angepasster Zustand wird erkannt',        true,                      $dirty->isCustomized());

echo "\n=== 2) Spalten-Reihenfolge: gespeicherter Wunsch trifft echte Spaltenliste ===\n";

$ordered = new TableViewState(order: ['3', '1', '9']); // 9 existiert nicht (mehr)

check('Gespeicherte zuerst, neue hinten',        ['3', '1', '2'], $ordered->orderColumns(['1', '2', '3']));
check('Verschwundene Spalte wird ignoriert',     false,           in_array('9', $ordered->orderColumns(['1', '2', '3']), true));
check('Ohne gespeicherte Reihenfolge: original', ['1', '2', '3'], TableViewState::fresh()->orderColumns(['1', '2', '3']));

$moved = TableViewState::fresh()->withColumnMovedBefore(['1', '2', '3'], '3', '1');
check('Spalte vor Ziel einsortiert',            ['3', '1', '2'], $moved->order);
check('Spalte ans Ende (Ziel null)',            ['3', '2', '1'], $moved->withColumnMovedBefore(['1', '2', '3'], '1', null)->order);
check('Auf sich selbst verschieben: no-op',     ['3', '1', '2'], $moved->withColumnMovedBefore(['1', '2', '3'], '1', '1')->order);
check('Unbekannte Spalte verschieben: no-op',   ['3', '1', '2'], $moved->withColumnMovedBefore(['1', '2', '3'], '77', '1')->order);

echo "\n=== 3) Umschalter ===\n";

$toggled = TableViewState::fresh()->withColumnToggled('2');
check('Spalte ausgeblendet',                    true,  $toggled->isColumnHidden('2'));
check('Zweiter Klick blendet wieder ein',       false, $toggled->withColumnToggled('2')->isColumnHidden('2'));
check('Alle einblenden loescht den Filter',    false, $toggled->withFlagToggled('hide_empty_columns')->withAllColumnsVisible()->hideEmptyColumns);
check('Unbekannter Filter: no-op',              false, TableViewState::fresh()->withFlagToggled('rm -rf')->isCustomized());
check('Zeile ausblenden ist idempotent',        ['cc:1'], TableViewState::fresh()->withRowHidden('cc:1')->withRowHidden('cc:1')->hiddenRows);
check('Alle Zeilen einblenden raeumt auf',      false, TableViewState::fresh()
    ->withRowHidden('cc:1')->withRowGroupToggled('cc:2')->withFlagToggled('hide_empty_rows')
    ->withAllRowsVisible()->isCustomized());

echo "\n=== 4) Shaper: Default-Ansicht zeigt alles ===\n";

$pivot = am_pivot();
$plain = (new PivotTableShaper(TableViewState::fresh()))->shape($pivot);

check('Alle Spalten sichtbar',                  3, count($plain['visibleColumns']));
check('Alle Zeilen sichtbar (4 + Auffangzeile)', 5, count($plain['rows']));
check('Zeilen-Schluessel aus der Kostenstelle', ['cc:100', 'cc:110', 'cc:111', 'cc:200', 'x:unassigned'], am_row_keys($plain));
check('Gruppe zeigt den Rollup (60 + 5)',       65.0, (float) $plain['rows'][0]['total']);
check('Blatt zeigt eigene Zahlen',              35.0, (float) $plain['rows'][2]['total']);
check('Default-Breite je Kostenart',            PivotTableShaper::DEFAULT_COLUMN_WIDTH, $plain['visibleColumns'][0]['width']);
check('Tabellenbreite = Summe der Spalten',
    PivotTableShaper::DEFAULT_LABEL_WIDTH + PivotTableShaper::DEFAULT_TOTAL_WIDTH + 3 * PivotTableShaper::DEFAULT_COLUMN_WIDTH,
    $plain['tableWidth']);
check('Einrueckung waechst mit der Tiefe',      28, $plain['rows'][2]['indent']);
check('Leere Kostenart ist als leer markiert',  true, $plain['columns'][2]['empty']);

echo "\n=== 5) Shaper: Reihenfolge, Breiten, ausgeblendete Spalten ===\n";

$custom = new TableViewState(
    widths: ['2' => 200, 'label' => 300],
    order: ['2', '1', '3'],
    hiddenColumns: ['1'],
);
$shaped = (new PivotTableShaper($custom))->shape($pivot);

check('Reihenfolge folgt der Ansicht',          ['2', '1', '3'], array_column($shaped['columns'], 'key'));
check('Ausgeblendete Spalte fehlt in der Tabelle', ['2', '3'],   array_column($shaped['visibleColumns'], 'key'));
check('Ausgeblendete bleibt im Menue erhalten', true,            $shaped['columns'][1]['hiddenManually']);
check('Gezogene Breite gewinnt',                200,             $shaped['visibleColumns'][0]['width']);
check('Label-Breite gezogen',                   300,             $shaped['labelWidth']);
check('Zaehler der ausgeblendeten Spalten',     1,               $shaped['stats']['hiddenColumns']);
check('Nur abweichende Breiten gehen an den Browser', ['2' => 200, 'label' => 300], $shaped['widths']);
check('Spaltensummen bleiben unberuehrt',       67.0,            (float) $pivot['colTotals'][1]);

echo "\n=== 6) Shaper: leere Spalten ausblenden ===\n";

$noEmpty = (new PivotTableShaper(new TableViewState(hideEmptyColumns: true)))->shape($pivot);
check('Leere Kostenart verschwindet',           ['1', '2'], array_column($noEmpty['visibleColumns'], 'key'));
check('Als Filter markiert, nicht als manuell', true,       $noEmpty['columns'][2]['hiddenByFilter']);
check('Manuell-Flag bleibt aus',                false,      $noEmpty['columns'][2]['hiddenManually']);

echo "\n=== 7) Shaper: Faltung schluckt ALLE Nachfahren, nicht nur Kinder ===\n";

$folded = (new PivotTableShaper(new TableViewState(collapsedRows: ['cc:100'])))->shape($pivot);
check('Kind und Enkel verschwinden',            ['cc:100', 'cc:200', 'x:unassigned'], am_row_keys($folded));
check('Gruppe bleibt sichtbar und eingeklappt', true,  $folded['rows'][0]['collapsed']);
check('Eingeklappte Gruppe traegt den Rollup',  65.0,  (float) $folded['rows'][0]['total']);
check('Zaehler eingeklappter Gruppen',          1,     $folded['stats']['collapsedGroups']);
check('Zaehler gefalteter Zeilen',              2,     $folded['stats']['foldedRows']);

$foldedInner = (new PivotTableShaper(new TableViewState(collapsedRows: ['cc:110'])))->shape($pivot);
check('Faltung auf Ebene 1 laesst Ebene 0 stehen', ['cc:100', 'cc:110', 'cc:200', 'x:unassigned'], am_row_keys($foldedInner));

echo "\n=== 8) Shaper: Zeilen ausblenden und Nullzeilen-Filter ===\n";

$hiddenRow = (new PivotTableShaper(new TableViewState(hiddenRows: ['cc:110', 'x:unassigned'])))->shape($pivot);
check('Ausgeblendete Zeile fehlt',              ['cc:100', 'cc:111', 'cc:200'], am_row_keys($hiddenRow));
check('Kinder bleiben ohne Faltung sichtbar',   true, in_array('cc:111', am_row_keys($hiddenRow), true));
check('Zaehler ausgeblendeter Zeilen',          2,    $hiddenRow['stats']['hiddenRows']);

$noZeroRows = (new PivotTableShaper(new TableViewState(hideEmptyRows: true)))->shape($pivot);
check('Nullzeile verschwindet',                 false, in_array('cc:200', am_row_keys($noZeroRows), true));
check('Zeilen mit Betrag bleiben',              4,     count($noZeroRows['rows']));

echo "\n=== 9) Ansicht, die alles versteckt, bleibt bedienbar ===\n";

$blind = (new PivotTableShaper(new TableViewState(hiddenColumns: ['1', '2', '3'])))->shape($pivot);
check('Keine sichtbaren Spalten',               0,    count($blind['visibleColumns']));
check('Als angepasst erkannt (Reset-Hinweis)',  true, $blind['stats']['customized']);

check_summary();
