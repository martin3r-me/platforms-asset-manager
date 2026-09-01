<?php

/**
 * TableViewService — der Speicherpfad der anpassbaren Tabellen-Ansicht (docs/adr/0020).
 *
 * Prueft die drei Zusagen, an denen es sonst still schiefgeht: das Scoping auf (User × Team ×
 * Tabelle), das Pruefen des Payloads AUCH beim Schreiben, und das Aufraeumen — faellt eine Ansicht
 * auf den Werkszustand zurueck, verschwindet die Zeile statt als leerer Payload zu bleiben.
 *
 * Die reine Zustands-/Formungs-Logik liegt ohne DB in tests/local/table-view-state.php.
 *
 * Aufruf: php tests/local/table-view-service.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Models\AssetTableView;
use Platform\AssetManager\Services\TableViewService;
use Platform\AssetManager\Support\TableViewState;

Schema::create('asset_table_views', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('user_id');
    $t->unsignedBigInteger('team_id');
    $t->string('table_key', 64);
    $t->json('payload');
    $t->timestamps();
    $t->unique(['user_id', 'team_id', 'table_key']);
});

const TEAM  = 7;
const OTHER = 8;
const USER  = 22;
const MATE  = 23;
const KEY   = 'costs.allocation';

$service = new TableViewService();

// --- 1) Nichts gespeichert = Werkszustand -----------------------------------------------------
$empty = $service->load(TEAM, USER, KEY);
check('Ohne Zeile: Werkszustand',              false, $empty->isCustomized());
check('Ohne Zeile: keine Breiten',             [],    $empty->widths);

// --- 2) Speichern und wieder lesen ------------------------------------------------------------
$saved = $service->save(TEAM, USER, KEY, TableViewState::fresh()
    ->withWidths(['1' => 140, 'label' => 320])
    ->withColumnToggled('3')
    ->withRowGroupToggled('cc:100'));

check('Gespeicherter Stand kommt zurueck',     140,       $saved->widths['1']);
check('Genau eine Zeile',                      1,         AssetTableView::count());
check('Nach dem Laden identisch',              $saved->toArray(), $service->load(TEAM, USER, KEY)->toArray());
check('Breite ueberlebt den JSON-Roundtrip',   320,       $service->load(TEAM, USER, KEY)->widths['label']);
check('Ausgeblendete Spalte ueberlebt',        true,      $service->load(TEAM, USER, KEY)->isColumnHidden('3'));
check('Eingeklappte Gruppe ueberlebt',         true,      $service->load(TEAM, USER, KEY)->isRowCollapsed('cc:100'));

// --- 3) Zweites Speichern aktualisiert dieselbe Zeile ------------------------------------------
$service->save(TEAM, USER, KEY, $service->load(TEAM, USER, KEY)->withWidths(['1' => 200]));
check('Update statt zweiter Zeile',            1,   AssetTableView::count());
check('Neue Breite gewinnt',                   200, $service->load(TEAM, USER, KEY)->widths['1']);

// --- 4) Scoping: fremdes Team, fremder User, andere Tabelle sehen nichts davon -----------------
check('Anderes Team sieht nichts',             false, $service->load(OTHER, USER, KEY)->isCustomized());
check('Anderer User sieht nichts',             false, $service->load(TEAM, MATE, KEY)->isCustomized());
check('Andere Tabelle sieht nichts',           false, $service->load(TEAM, USER, 'inventory.list')->isCustomized());

$service->save(TEAM, MATE, KEY, TableViewState::fresh()->withWidths(['1' => 60]));
check('Kollege bekommt eigene Zeile',          2,   AssetTableView::count());
check('Eigener Stand bleibt unberuehrt',       200, $service->load(TEAM, USER, KEY)->widths['1']);

// --- 5) Der Payload wird auch beim SCHREIBEN geprueft ------------------------------------------
// Ein manipulierter Browser-Wert darf nicht in der DB landen: hier eine absurde Breite und ein
// Schluessel mit Markup.
$service->save(TEAM, USER, KEY, TableViewState::fromArray([
    'widths' => ['1' => 99999, '<script>' => 100],
    'order'  => ['1', '2'],
]));
$hardened = $service->load(TEAM, USER, KEY);
check('Absurde Breite geklemmt gespeichert',   TableViewState::MAX_WIDTH, $hardened->widths['1']);
check('Markup-Schluessel nicht gespeichert',   false, isset($hardened->widths['<script>']));

// --- 6) Zurueck auf Werkszustand raeumt die Zeile weg -----------------------------------------
$reset = $service->reset(TEAM, USER, KEY);
check('Reset liefert Werkszustand',            false, $reset->isCustomized());
check('Reset loescht nur die eigene Zeile',    1,     AssetTableView::count());
check('Kollegen-Zeile steht noch',             60,    $service->load(TEAM, MATE, KEY)->widths['1']);

$service->save(TEAM, MATE, KEY, TableViewState::fresh());
check('Speichern des Werkszustands raeumt auf', 0,    AssetTableView::count());

// --- 7) Tabellen-Schluessel wird normalisiert (Tippfehler statt Kollision) ---------------------
$service->save(TEAM, USER, 'costs.allocation', TableViewState::fresh()->withWidths(['1' => 111]));
check('Unerlaubte Zeichen im Schluessel fallen weg', 111, $service->load(TEAM, USER, 'costs.allocation!!')->widths['1']);

check_summary();
