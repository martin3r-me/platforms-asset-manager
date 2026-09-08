<?php

/**
 * Der Verzeichnis-Abgleich der Asset-Träger muss planbar sein — und vor dem Lizenz-Sync laufen.
 *
 * Hintergrund: `accountEnabled` → `is_active` und das Stilllegen ausgeschiedener Träger
 * (docs/adr/0023) passieren ausschließlich im `ImportTenantUsersJob`. Der hatte bis 2026-09-08
 * weder ein Kommando noch einen Zeitplan — auslösbar nur per Klick auf der Konnektoren-Seite oder
 * über `asset-manager.sync.POST` mit `target=users`. Weil die Zählregel der Weiterberechnung nur
 * aktive Träger sieht, zählte der Rechnungslauf ausgeschiedene Träger so lange mit, bis jemand
 * daran dachte.
 *
 * Geprüft wird deshalb genau der Vertrag, den der Fix herstellt: das Kommando existiert, es steht
 * im Zeitplan, und es steht **vor** dem Lizenz-Sync. Das Stilllegen selbst deckt
 * `tests/local/holder-lifecycle.php` ab; der Graph-Aufruf im Job ist lokal nicht nachstellbar.
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Platform\AssetManager\Console\Commands\ImportTenantUsersCommand;

// --- 1. Kommando ist registriert -----------------------------------------
// Ohne Registrierung im ServiceProvider gibt es das Kommando im Host gar nicht — dann wäre der
// Scheduler-Eintrag ein Aufruf ins Leere, der still fehlschlägt.
$commands = Artisan::all();

check('Kommando asset-manager:import-users registriert', true, isset($commands['asset-manager:import-users']));
check(
    'Kommando ist ImportTenantUsersCommand',
    ImportTenantUsersCommand::class,
    isset($commands['asset-manager:import-users']) ? $commands['asset-manager:import-users']::class : null,
);
check(
    'Option --team vorhanden (Einzel-Team-Lauf)',
    true,
    isset($commands['asset-manager:import-users'])
        && $commands['asset-manager:import-users']->getDefinition()->hasOption('team'),
);

// --- 2. Zeitplan enthält den Lauf ----------------------------------------
// Schedule explizit auflösen, damit das callAfterResolving() des ServiceProviders feuert.
$schedule = app(Schedule::class);

/** @var array<string, Event> $byCommand */
$byCommand = [];
foreach ($schedule->events() as $event) {
    foreach (['asset-manager:import-users', 'asset-manager:sync-licenses', 'asset-manager:sync-intune'] as $needle) {
        if (str_contains((string) $event->command, $needle)) {
            $byCommand[$needle] = $event;
        }
    }
}

check('Zeitplan kennt asset-manager:import-users', true, isset($byCommand['asset-manager:import-users']));
check('Zeitplan kennt asset-manager:sync-licenses', true, isset($byCommand['asset-manager:sync-licenses']));
check('Zeitplan kennt asset-manager:sync-intune', true, isset($byCommand['asset-manager:sync-intune']));

// --- 3. Reihenfolge: Abgleich VOR dem Lizenz-Sync ------------------------
// Der Lizenz-Sync soll auf einem Bestand arbeiten, dessen Ausgeschiedene bereits stillgelegt sind.
// Beide laufen täglich; verglichen wird die Tagesminute aus dem Cron-Ausdruck ("30 1 * * *").
$minuteOfDay = static function (?Event $event): ?int {
    if ($event === null) {
        return null;
    }

    $parts = preg_split('/\s+/', trim((string) $event->expression));

    if (! is_array($parts) || count($parts) < 2 || ! ctype_digit($parts[0]) || ! ctype_digit($parts[1])) {
        return null;
    }

    return ((int) $parts[1]) * 60 + (int) $parts[0];
};

$importAt   = $minuteOfDay($byCommand['asset-manager:import-users'] ?? null);
$licensesAt = $minuteOfDay($byCommand['asset-manager:sync-licenses'] ?? null);

check('Abgleich läuft zu einer festen Tageszeit', true, $importAt !== null);
check('Lizenz-Sync läuft zu einer festen Tageszeit', true, $licensesAt !== null);
check(
    'Abgleich liegt VOR dem Lizenz-Sync',
    true,
    $importAt !== null && $licensesAt !== null && $importAt < $licensesAt,
);

// Der Abstand darf nicht auf Kante genäht sein: der Abgleich iteriert /users vollständig und
// braucht Luft, bevor der Lizenz-Sync startet.
check(
    'Abstand zum Lizenz-Sync mindestens 15 Minuten',
    true,
    $importAt !== null && $licensesAt !== null && ($licensesAt - $importAt) >= 15,
);

check_summary();
