<?php

/**
 * Spaltenreihenfolge der Intune-Geräteliste (`Devices\Index::normalizeColumnOrder`).
 *
 * Die Reihenfolge ist per Drag&Drop verstellbar und liegt seit #979 pro User × Team im
 * TableViewService (ADR 0020) statt in der Session. Wer sie einmal angefasst
 * hat, hat dort eine Liste OHNE die später hinzugefügten Spalten — `serial` und `cost` kamen so
 * dazu. Würden fehlende Spalten ans Ende gehängt (das alte Verhalten), landet eine neue Spalte
 * genau bei den Nutzern hinter allem, die die Tabelle aktiv nutzen: der Umbau wäre unsichtbar,
 * ohne dass etwas kaputt aussieht. Deshalb hier festgenagelt.
 *
 * Aufruf: php tests/local/device-columns.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Livewire\Devices\Index as DevicesIndex;
use Platform\AssetManager\Models\AssetTableView;

Schema::create('asset_table_views', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('user_id');
    $t->unsignedBigInteger('team_id');
    $t->string('table_key', 64);
    $t->json('payload');
    $t->timestamps();
    $t->unique(['user_id', 'team_id', 'table_key']);
});

$component = new DevicesIndex();
$method    = new ReflectionMethod($component, 'normalizeColumnOrder');
$method->setAccessible(true);

$normalize = fn (array $order): array => $method->invoke($component, $order);

// --- Vollständige Reihenfolge bleibt unangetastet -------------------------
check('vollständige Reihenfolge bleibt', DevicesIndex::COLUMN_KEYS,
    $normalize(DevicesIndex::COLUMN_KEYS));

// --- Eigene Reihenfolge des Nutzers bleibt erhalten -----------------------
$eigene = ['status', 'device', 'serial', 'user', 'os', 'lastCheckIn', 'cost'];
check('eigene Reihenfolge bleibt erhalten', $eigene, $normalize($eigene));

// --- Der reale Fall: alte Session ohne serial/cost ------------------------
// Erwartung: an ihrer definierten Position, nicht am Ende.
check('serial landet hinter dem Gerät, nicht am Ende', ['device', 'serial', 'user', 'os', 'status', 'lastCheckIn', 'cost'],
    $normalize(['device', 'user', 'os', 'status', 'lastCheckIn']));

// Alte Session MIT eigener Reihenfolge: die Vorlieben bleiben, das Neue kommt an seinen Platz.
check('eigene Reihenfolge + neue Spalten einsortiert', ['device', 'serial', 'status', 'user', 'os', 'lastCheckIn', 'cost'],
    $normalize(['device', 'status', 'user', 'os', 'lastCheckIn']));

// --- Unbekannte Keys fliegen raus, nichts geht verloren -------------------
$mit_muell = $normalize(['device', 'quatsch', 'user']);
check('unbekannte Spalte wird verworfen', false, in_array('quatsch', $mit_muell, true));
check('trotzdem sind alle echten Spalten da', count(DevicesIndex::COLUMN_KEYS), count($mit_muell));

// --- Leere/kaputte Eingabe fällt auf die Standardreihenfolge zurück -------
check('leere Eingabe → Standard', DevicesIndex::COLUMN_KEYS, $normalize([]));

// --- Format von wotz/livewire-sortablejs: [['order' =>, 'value' =>], …] ---
check('sortablejs-Format wird ausgepackt', ['device', 'serial', 'user', 'os', 'status', 'lastCheckIn', 'cost'],
    $normalize([
        ['order' => 0, 'value' => 'device'],
        ['order' => 1, 'value' => 'user'],
        ['order' => 2, 'value' => 'os'],
        ['order' => 3, 'value' => 'status'],
        ['order' => 4, 'value' => 'lastCheckIn'],
    ]));

// --- Keine Dubletten, egal was hereinkommt -------------------------------
// array_intersect behält Dubletten: ohne array_unique wurde 'device' zweimal gerendert (doppelter
// wire:key) und verdrängte dabei eine echte Spalte ans Ende.
check('doppelte Eingabe ergibt saubere Reihenfolge', ['device', 'serial', 'user', 'os', 'status', 'lastCheckIn', 'cost'],
    $normalize(['device', 'device', 'user']));

// --- Controlling aus: Kosten-Spalte verschwinden lassen, ohne die Reihenfolge zu zerstören (#874) ---
// Die Session behält `cost`; nur die gerenderte Liste lässt sie weg (Präsentations-Gate, ADR 0008).
$visible = new ReflectionMethod($component, 'visibleColumns');
$visible->setAccessible(true);

$component->columnOrder = ['status', 'cost', 'device', 'serial', 'user', 'os', 'lastCheckIn'];
check('Controlling an → cost wird gerendert', $component->columnOrder, $visible->invoke($component, true));
check('Controlling aus → cost fehlt, Rest in Nutzer-Reihenfolge', ['status', 'device', 'serial', 'user', 'os', 'lastCheckIn'],
    $visible->invoke($component, false));
check('Controlling aus → gespeicherte Reihenfolge unangetastet', 'cost', $component->columnOrder[1]);

// Umsortieren bei aus geschaltetem Controlling: die gerenderte Liste kommt OHNE cost zurück. Die
// Spalte muss an der Stelle bleiben, an der der Nutzer sie hatte — nicht an die Standardposition rutschen.
$off = new class extends DevicesIndex {
    public static int $user = 22;
    protected function controllingEnabled(): bool { return false; }
    protected function teamId(): int { return 7; }
    protected function currentUserId(): int { return static::$user; }
};
$off->columnOrder = ['status', 'cost', 'device', 'serial', 'user', 'os', 'lastCheckIn'];
$off->reorderColumns(['device', 'status', 'serial', 'user', 'os', 'lastCheckIn']);
check('Reorder ohne Controlling: cost behält ihre Position', ['device', 'cost', 'status', 'serial', 'user', 'os', 'lastCheckIn'],
    $off->columnOrder);

// --- Persistenz statt Session (#979) ------------------------------------------------------------
// Ein neuer Request (neue Instanz + mount) sieht die gespeicherte Reihenfolge — inklusive `cost` an
// der Stelle des Nutzers, auch wenn Controlling gerade aus ist.
$reload = function () use ($off): DevicesIndex {
    $fresh = new (get_class($off))();
    $fresh->mount();

    return $fresh;
};
check('Neu geladen: gespeicherte Reihenfolge kommt zurück', ['device', 'cost', 'status', 'serial', 'user', 'os', 'lastCheckIn'],
    $reload()->columnOrder);
check('Genau eine gespeicherte Ansicht', 1, AssetTableView::where('table_key', 'devices.index')->count());

// Anderer Nutzer im selben Team: Werkszustand.
$off::$user = 23;
check('Anderer Nutzer: Standardreihenfolge', DevicesIndex::COLUMN_KEYS, $reload()->columnOrder);
$off::$user = 22;

// Sortablejs-Format mit `order`-Feld: die Position zählt, nicht die Array-Reihenfolge.
$off->reorderColumns([
    ['order' => 2, 'value' => 'device'],
    ['order' => 1, 'value' => 'status'],
]);
check('sortablejs: nach order sortiert, Rest behält Position', ['status', 'cost', 'device', 'serial', 'user', 'os', 'lastCheckIn'],
    $off->columnOrder);

// Zurück auf die Standardreihenfolge räumt die Zeile weg (ADR 0020: „angepasst" = Existenz).
$off->reorderColumns(DevicesIndex::COLUMN_KEYS);
check('Standardreihenfolge gespeichert → keine Zeile', 0, AssetTableView::where('table_key', 'devices.index')->count());

$off->reorderColumns(['os', 'device']);
check('Neu sortiert → Zeile da', 1, AssetTableView::where('table_key', 'devices.index')->count());
$off->resetColumnOrder();
check('Spalten zurücksetzen → Standard', DevicesIndex::COLUMN_KEYS, $off->columnOrder);
check('Spalten zurücksetzen → Zeile weg', 0, AssetTableView::where('table_key', 'devices.index')->count());

// Manipulierte gespeicherte Ansicht: unbekannte Spalten fallen beim Laden weg, nichts fehlt.
AssetTableView::create(['user_id' => 22, 'team_id' => 7, 'table_key' => 'devices.index',
    'payload' => ['order' => ['quatsch', 'lastCheckIn', 'lastCheckIn', '<b>']]]);
$manipulated = $reload()->columnOrder;
$sortedKeys  = DevicesIndex::COLUMN_KEYS;
sort($sortedKeys);
$sortedLoaded = $manipulated;
sort($sortedLoaded);
check('Manipulierter Payload: genau die echten Spalten, je einmal', $sortedKeys, $sortedLoaded);

// Default ohne gespeicherte Ansicht: alle Spalten (vorher fehlten serial/cost, bis man einmal sortierte).
check('Default-Property = alle Spalten', DevicesIndex::COLUMN_KEYS, (new DevicesIndex())->columnOrder);

check_summary();
