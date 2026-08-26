<?php

/**
 * Blade-Lint für Modul-Views.
 *
 * Kompiliert jede übergebene Datei echt (Blade → PHP) und lintet das Ergebnis mit `php -l`.
 * Ein nicht erkanntes `@endif` oder ein unbalanciertes `<x-…>`-Tag fällt nur so auf —
 * Direktiv-Zähler übersehen es, und die Seite antwortet dann live mit HTTP 500.
 *
 * Aufruf (aus dem Modul-Root):
 *
 *     php tests/local/blade-lint.php resources/views/livewire/cost-lines/*.blade.php
 *     php tests/local/blade-lint.php $(git diff --name-only --diff-filter=d | grep '\.blade\.php$')
 *
 * Was NICHT geprüft wird: ob eine Komponente oder ein Heroicon wirklich existiert (das löst
 * Blade erst zur Laufzeit auf) und ob die verwendeten Variablen gesetzt sind.
 */

require __DIR__ . '/bootstrap.php';

$files = array_slice($argv, 1);

if ($files === []) {
    fwrite(STDERR, 'Aufruf: php tests/local/blade-lint.php <datei.blade.php> [...]' . PHP_EOL);
    exit(2);
}

$compiler = Illuminate\Support\Facades\Blade::getFacadeRoot();
$failed   = 0;

foreach ($files as $file) {
    if (! is_file($file)) {
        echo 'FEHLT    ' . $file . PHP_EOL;
        $failed++;
        continue;
    }

    try {
        $php = $compiler->compileString(file_get_contents($file));
    } catch (Throwable $e) {
        echo 'COMPILE  ' . basename($file) . ' -> ' . $e::class . ': ' . $e->getMessage() . PHP_EOL;
        $failed++;
        continue;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'blade') . '.php';
    file_put_contents($tmp, $php);
    $output = [];
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp) . ' 2>&1', $output, $code);
    unlink($tmp);

    if ($code !== 0) {
        echo 'SYNTAX   ' . basename($file) . PHP_EOL . '         ' . implode(PHP_EOL . '         ', $output) . PHP_EOL;
        $failed++;
        continue;
    }

    echo 'ok       ' . basename($file) . PHP_EOL;
}

echo PHP_EOL . ($failed === 0
    ? 'Alle ' . count($files) . ' Blades kompilieren.' . PHP_EOL
    : $failed . ' von ' . count($files) . ' fehlerhaft.' . PHP_EOL);

exit($failed === 0 ? 0 : 1);
