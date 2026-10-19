<?php

/**
 * Spaltenreihenfolge der Intune-Geräteliste (`Devices\Index::normalizeColumnOrder`).
 *
 * Die Reihenfolge ist per Drag&Drop verstellbar und liegt in der Session. Wer sie einmal angefasst
 * hat, hat dort eine Liste OHNE die später hinzugefügten Spalten — `serial` und `cost` kamen so
 * dazu. Würden fehlende Spalten ans Ende gehängt (das alte Verhalten), landet eine neue Spalte
 * genau bei den Nutzern hinter allem, die die Tabelle aktiv nutzen: der Umbau wäre unsichtbar,
 * ohne dass etwas kaputt aussieht. Deshalb hier festgenagelt.
 *
 * Aufruf: php tests/local/device-columns.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Platform\AssetManager\Livewire\Devices\Index as DevicesIndex;

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
    protected function controllingEnabled(): bool { return false; }
};
$off->columnOrder = ['status', 'cost', 'device', 'serial', 'user', 'os', 'lastCheckIn'];
$off->reorderColumns(['device', 'status', 'serial', 'user', 'os', 'lastCheckIn']);
check('Reorder ohne Controlling: cost behält ihre Position', ['device', 'cost', 'status', 'serial', 'user', 'os', 'lastCheckIn'],
    $off->columnOrder);

check_summary();
