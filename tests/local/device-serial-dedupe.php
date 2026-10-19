<?php

/**
 * Doppel-Enrollment (#945): {@see \Platform\AssetManager\Jobs\SyncIntuneDevicesJob::dedupeBySerial()}.
 *
 * Aufruf: php tests/local/device-serial-dedupe.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Platform\AssetManager\Jobs\SyncIntuneDevicesJob;

$devices = [
    ['id' => 'alt',   'serialNumber' => 'PF1ABC23', 'lastSyncDateTime' => '2026-09-01T08:00:00Z'],
    ['id' => 'other', 'serialNumber' => 'XYZ999',   'lastSyncDateTime' => '2026-10-01T08:00:00Z'],
    ['id' => 'neu',   'serialNumber' => 'pf1abc23', 'lastSyncDateTime' => '2026-10-18T08:00:00Z'],
    ['id' => 'noser', 'serialNumber' => '',         'lastSyncDateTime' => '2026-10-18T08:00:00Z'],
    ['id' => 'noser2','serialNumber' => null,       'lastSyncDateTime' => '2026-10-18T08:00:00Z'],
];

[$kept, $shadow] = SyncIntuneDevicesJob::dedupeBySerial($devices);

check('jüngster Eintrag gewinnt', ['other', 'neu', 'noser', 'noser2'], array_column($kept, 'id'));
check('verdrängte ID gemerkt', ['alt'], $shadow);

[$kept, $shadow] = SyncIntuneDevicesJob::dedupeBySerial(array_reverse($devices));
check('Reihenfolge egal: derselbe Gewinner', true, in_array('neu', array_column($kept, 'id'), true) && ! in_array('alt', array_column($kept, 'id'), true));
check('Reihenfolge egal: dieselbe verdrängte ID', ['alt'], $shadow);

[$kept, $shadow] = SyncIntuneDevicesJob::dedupeBySerial([]);
check('leere Antwort bleibt leer', [[], []], [$kept, $shadow]);

check_summary();
