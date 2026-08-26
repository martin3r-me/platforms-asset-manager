<?php

/**
 * Gemeinsamer Bootstrap für die lokalen Prüfskripte unter `tests/local/`.
 *
 * Warum es das gibt: die Feature-Tests unter `tests/Feature/` laufen nur im Host-App-Setup und
 * damit erst nach dem Deploy (siehe `tests/README.md`). Für die Logik, die beim Entwickeln am
 * häufigsten bricht — Aggregat-Queries, Betrags- und Validierungsregeln, Blade-Syntax — ist das
 * zu spät. Diese Skripte booten deshalb eine **vorhandene Host-App als Library** und legen ihr
 * Schema in einer SQLite-In-Memory-DB an. Das ist ausdrücklich **keine** zweite Testsuite und
 * ersetzt die Host-Tests nicht.
 *
 * Aufruf (aus dem Modul-Root):
 *
 *     php tests/local/cost-lines-grouping.php
 *     php tests/local/cost-lines-editor.php
 *     php tests/local/cost-line-reassign.php
 *     php tests/local/blade-lint.php resources/views/livewire/cost-lines/*.blade.php
 *
 * Host-App: wird neben dem Plattform-Verzeichnis gesucht; abweichender Pfad über die
 * Umgebungsvariable `AM_HOST_APP`.
 *
 * Grenzen, die man kennen muss:
 * - SQLite ist bei `GROUP BY` **lax**; MySQLs `ONLY_FULL_GROUP_BY` deckt das hier nicht auf.
 *   Label-Spalten in Aggregat-Queries deshalb immer bewusst mit-gruppieren.
 * - Die Modul-Migrationen laufen nicht mit; die Skripte legen nur die Spalten an, die sie
 *   anfassen. Ein neues Feld muss dort ergänzt werden.
 */

// --- Host-App finden ------------------------------------------------------
// Von tests/local aufwaerts nach einem Geschwister-Verzeichnis mit artisan + vendor suchen.
// Die Tiefe ist bewusst nicht fest: das Modul kann im Repo-Root oder in einem Worktree unter
// .claude/worktrees/<name>/ liegen, und der Abstand zur Host-App unterscheidet sich dadurch.
$host = null;

if ($fromEnv = getenv('AM_HOST_APP')) {
    $host = rtrim(str_replace(chr(92), '/', $fromEnv), '/');
} else {
    $dir = __DIR__;
    for ($level = 0; $level < 10 && $host === null; $level++) {
        foreach (glob($dir . '/*/artisan') ?: [] as $artisan) {
            $candidate = dirname($artisan);
            if (is_file($candidate . '/vendor/autoload.php') && is_file($candidate . '/bootstrap/app.php')) {
                $host = $candidate;
                break;
            }
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }
}

if ($host === null || ! is_file($host . '/vendor/autoload.php') || ! is_file($host . '/bootstrap/app.php')) {
    fwrite(STDERR, implode(PHP_EOL, [
        'Keine Host-App gefunden. Gesucht wurde aufwaerts nach einem Verzeichnis mit',
        'artisan + vendor/autoload.php + bootstrap/app.php. Pfad explizit setzen:',
        '  AM_HOST_APP=/pfad/zur/host-app php tests/local/<skript>.php',
        '',
    ]));
    exit(2);
}

// --- DB auf SQLite umbiegen, BEVOR Dotenv lädt ---------------------------
// Dotenv ist immutable: bereits gesetzte Env-Vars gewinnen. Ohne das versucht der
// AssetManagerServiceProvider beim Boot ein Schema::hasTable('modules') gegen die
// MySQL-Verbindung der Host-App, die lokal nicht läuft.
foreach (['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_FOREIGN_KEYS' => 'false'] as $key => $value) {
    putenv("$key=$value");
    $_ENV[$key]    = $value;
    $_SERVER[$key] = $value;
}

require $host . '/vendor/autoload.php';

// --- Modul-Klassen aus DIESEM Arbeitsverzeichnis laden -------------------
// Die Host-App bindet das Modul über vendor/ ein; getestet werden soll aber der lokale Stand
// (auch aus einem Worktree). prepend = true stellt den Loader vor den Composer-Autoloader.
$moduleSrc = realpath(__DIR__ . '/../../src');

spl_autoload_register(function (string $class) use ($moduleSrc): void {
    $prefix = 'Platform' . chr(92) . 'AssetManager' . chr(92);
    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $file = $moduleSrc . '/' . str_replace(chr(92), '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
}, true, true);

$app = require_once $host . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// --- Winziges Prüf-Gerüst ------------------------------------------------
$GLOBALS['am_checks'] = ['total' => 0, 'failed' => []];

/**
 * Eine Erwartung prüfen. Floats werden mit Toleranz verglichen (DECIMAL kommt als String aus PDO).
 */
function check(string $what, mixed $expected, mixed $actual): void
{
    $GLOBALS['am_checks']['total']++;

    $ok = is_float($expected)
        ? abs($expected - (float) $actual) < 0.0005
        : $expected === $actual;

    printf("%-58s %s  erwartet=%s ist=%s\n", $what, $ok ? 'ok  ' : 'FAIL',
        var_export($expected, true), var_export($actual, true));

    if (! $ok) {
        $GLOBALS['am_checks']['failed'][] = $what;
    }
}

/** Ergebnis ausgeben und mit passendem Code beenden. */
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
