<?php

/**
 * Geräteliste: Sortierung und Seitengröße pro User × Team gemerkt (#979, ADR 0020) —
 * {@see \Platform\AssetManager\Concerns\RemembersListState}.
 *
 * Geprüft: Speichern nach Sortier-Klick und Seitengrößen-Wechsel, Wiederherstellen in einem neuen
 * Request, Vorrang der URL (Deep-Link), Scoping auf den Nutzer, Whitelists beim Schreiben UND beim
 * Lesen (ein manipulierter Payload wird nie angewendet) und das Aufräumen bei Rückkehr zum Default.
 * Die Spaltenreihenfolge liegt unter einem eigenen Schlüssel (tests/local/device-columns.php).
 *
 * Aufruf: php tests/local/device-list-state.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
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

const TEAM = 7;
const LIST_KEY = 'devices.index.list';

$proto = new class extends DevicesIndex {
    public static int $user = 22;
    protected function teamId(): int { return TEAM; }
    protected function currentUserId(): int { return static::$user; }
};
$class = get_class($proto);

/** Neuer Request: frische Instanz, optional vorbelegt wie aus dem Query-String, dann mount(). */
$open = function (string $query = '', array $fromUrl = []) use ($class): DevicesIndex {
    app()->instance('request', Request::create('/asset-manager/devices' . ($query !== '' ? '?' . $query : '')));
    $c = new $class();
    foreach ($fromUrl as $prop => $value) {
        $c->{$prop} = $value;
    }
    $c->mount();

    return $c;
};
$stored = fn (): ?array => AssetTableView::where('team_id', TEAM)->where('user_id', $class::$user)
    ->where('table_key', LIST_KEY)->value('payload');

// --- 1) Werkszustand ----------------------------------------------------------------------------
$c = $open();
check('Ohne Ansicht: Default-Sortierung',        'device_name', $c->sortField);
check('Ohne Ansicht: Default-Richtung',          'asc',         $c->sortDirection);
check('Ohne Ansicht: Default-Seitengröße',       25,            $c->perPage);
check('Ohne Ansicht: keine Zeile',               null,          $stored());

// --- 2) Sortier-Klick speichert -----------------------------------------------------------------
$c->sortBy('last_check_in_at');
check('Sortier-Klick gespeichert',               ['sort:last_check_in_at'], $stored()['order'] ?? null);
$c->sortBy('last_check_in_at');
check('Zweiter Klick: Richtung mitgespeichert',  ['sort:last_check_in_at', 'dir:desc'], $stored()['order'] ?? null);

// --- 3) Seitengröße über den Livewire-Trait-Hook (wire:model.live) -------------------------------
$c->perPage = 50;
$c->updatedRemembersListState('perPage', 50);
check('perPage gespeichert',                     ['sort:last_check_in_at', 'dir:desc', 'per:50'], $stored()['order'] ?? null);

// Andere Properties lösen kein Speichern aus (Suche tippen soll keine Schreiblast erzeugen).
$queries = 0;
Illuminate\Support\Facades\DB::listen(function () use (&$queries) { $queries++; });
$c->search = 'abc';
$c->updatedRemembersListState('search', 'abc');
check('Suche löst keine Abfrage aus',            0, $queries);

// --- 4) Neuer Request stellt alles wieder her ----------------------------------------------------
$c = $open();
check('Neu geladen: Sortierfeld',                'last_check_in_at', $c->sortField);
check('Neu geladen: Richtung',                   'desc',             $c->sortDirection);
check('Neu geladen: Seitengröße',                50,                 $c->perPage);
check('Spaltenreihenfolge unberührt (eigener Schlüssel)', DevicesIndex::COLUMN_KEYS, $c->columnOrder);

// --- 5) Deep-Link gewinnt -----------------------------------------------------------------------
// Livewire setzt URL-Werte je nach Hook-Reihenfolge vor oder nach mount(); beides muss halten.
$c = $open('sortField=serial_number&perPage=15', ['sortField' => 'serial_number', 'perPage' => 15]);
check('URL-Sortierung bleibt (vorbelegt)',       'serial_number', $c->sortField);
check('URL-Seitengröße bleibt (vorbelegt)',      15,              $c->perPage);
check('Nicht in der URL: gespeicherte Richtung', 'desc',          $c->sortDirection);

$c = $open('sortField=serial_number');
check('URL-Parameter: restore überschreibt nicht', 'device_name', $c->sortField);
check('Ohne URL-Parameter: gespeicherte Seitengröße', 50,         $c->perPage);
check('Deep-Link allein speichert nichts um',    ['sort:last_check_in_at', 'dir:desc', 'per:50'], $stored()['order'] ?? null);

// --- 6) Scoping: anderer Nutzer sieht nichts ----------------------------------------------------
$class::$user = 23;
$c = $open();
check('Anderer Nutzer: Default-Sortierung',      'device_name', $c->sortField);
check('Anderer Nutzer: Default-Seitengröße',     25,            $c->perPage);
$class::$user = 22;

// --- 7) Whitelists beim Schreiben ---------------------------------------------------------------
$c = $open();
$c->perPage = 7;                       // nicht erlaubt (ClampsListState klemmt, wir speichern nicht)
$c->updatedRemembersListState('perPage', 7);
check('Unerlaubte Seitengröße wird nicht gespeichert', false, in_array('per:7', $stored()['order'] ?? [], true));
$c->sortBy('password');                // unbekannte Spalte → geklemmt auf den Rückfall
check('Unbekannte Sortierspalte → Rückfall',     'device_name', $c->sortField);
check('Unbekannte Sortierspalte nicht gespeichert', false, (bool) preg_grep('/password/', $stored()['order'] ?? []));

// --- 8) Whitelists beim Lesen: manipulierter Payload wird nie angewendet ---------------------------
AssetTableView::where('table_key', LIST_KEY)->delete();
AssetTableView::create(['user_id' => 22, 'team_id' => TEAM, 'table_key' => LIST_KEY,
    'payload' => ['order' => ['sort:password', 'dir:sideways', 'per:0', 'per:9999', 'junk', 'per:abc']]]);
$c = $open();
check('Manipuliert: Sortierung bleibt Default',  'device_name', $c->sortField);
check('Manipuliert: Richtung bleibt Default',    'asc',         $c->sortDirection);
check('Manipuliert: Seitengröße bleibt Default', 25,            $c->perPage);

// --- 9) Zurück zum Default räumt die Zeile weg ----------------------------------------------------
$c->sortBy('serial_number');
check('Abweichung → Zeile da',                   ['sort:serial_number'], $stored()['order'] ?? null);
$c->sortBy('device_name');
check('Zurück auf Default → Zeile weg',          null, $stored());

// --- 10) Kodierung direkt -----------------------------------------------------------------------
$encode = new ReflectionMethod($class, 'encodeListState');
$encode->setAccessible(true);
$decode = new ReflectionMethod($class, 'decodeListState');
$decode->setAccessible(true);
$defaults = ['sortField' => 'device_name', 'sortDirection' => 'asc', 'perPage' => 25];
$sortable = ['device_name', 'serial_number'];
$options  = [15, 25, 50, 100];

check('Encode: nur Abweichungen', ['dir:desc', 'per:100'],
    $encode->invoke(null, ['sortField' => 'device_name', 'sortDirection' => 'desc', 'perPage' => 100], $defaults, $sortable, $options));
check('Encode: Unerlaubtes fällt weg', [],
    $encode->invoke(null, ['sortField' => 'x; drop', 'sortDirection' => 'up', 'perPage' => 0], $defaults, $sortable, $options));
check('Decode: Roundtrip', ['sortField' => 'serial_number', 'sortDirection' => 'desc', 'perPage' => 15],
    $decode->invoke(null, ['sort:serial_number', 'dir:desc', 'per:15'], $sortable, $options));
check('Decode: fremde Token ignoriert', [],
    $decode->invoke(null, ['order:1', 'sort:', 'per:-5', 'per:15.5', ':'], $sortable, $options));

check_summary();
