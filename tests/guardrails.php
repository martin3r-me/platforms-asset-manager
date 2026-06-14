<?php

/**
 * Asset-Manager — module-local static guardrails.
 *
 * Plain PHP, NO framework / NO Core bootstrap. Run from anywhere:
 *
 *     php tests/guardrails.php
 *
 * Exit 0 = all green, exit 1 = at least one invariant violated.
 *
 * Checks (architecture-review F6 + boundary invariants):
 *   1. Tool-registration completeness — every src/Tools/**\/*Tool.php is registered in
 *      AssetManagerServiceProvider::registerTools() (no auto-discovery; adding a tool must
 *      edit the ServiceProvider).
 *   2. Dependency direction — no file in src/Models or src/Services imports the UI/Tools/Http
 *      layers (Models/Services stay framework-/delivery-agnostic).
 *   3. No alias mangling — no Blade uses the string-alias forms `<livewire:…>` or
 *      `@livewire('…')`; nested components must be class-based: `@livewire(Foo::class)`
 *      (see SecondBrain memory: Livewire folder/Index alias gets mangled).
 */

$root = dirname(__DIR__);

/** Recursively collect files under $dir whose name ends with $suffix. */
function collectFiles(string $dir, string $suffix): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $out = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), $suffix)) {
            $out[] = $file->getPathname();
        }
    }
    sort($out);
    return $out;
}

function relpath(string $root, string $path): string
{
    return ltrim(str_replace(['\\', $root], ['/', ''], $path), '/');
}

$failures = [];

// ---- Check 1: Tool-registration completeness -----------------------------------------
$spFile = $root . '/src/AssetManagerServiceProvider.php';
$spSource = is_file($spFile) ? file_get_contents($spFile) : '';
if ($spSource === '') {
    $failures[] = 'Check 1: AssetManagerServiceProvider.php not found or empty.';
}
$toolFiles = collectFiles($root . '/src/Tools', 'Tool.php');
$toolCount = count($toolFiles);
$unregistered = [];
foreach ($toolFiles as $f) {
    // src/Tools/Costs/CostSummaryTool.php -> needle "Tools\Costs\CostSummaryTool("
    $rel = substr(str_replace('\\', '/', $f), strpos(str_replace('\\', '/', $f), '/src/Tools/') + strlen('/src/Tools/'));
    $tail = str_replace(['/', '.php'], ['\\', ''], $rel);
    $needle = 'Tools\\' . $tail . '(';
    if (strpos($spSource, $needle) === false) {
        $unregistered[] = $tail;
    }
}
if ($unregistered) {
    $failures[] = 'Check 1 (tool registration): not registered in registerTools(): ' . implode(', ', $unregistered);
}

// ---- Check 2: Dependency direction (Models/Services must not depend on UI/Tools/Http) --
$bannedUse = [
    'use Platform\\AssetManager\\Livewire\\',
    'use Platform\\AssetManager\\Tools\\',
    'use Platform\\AssetManager\\Http\\',
    'use Livewire\\',
];
$layerFiles = array_merge(
    collectFiles($root . '/src/Models', '.php'),
    collectFiles($root . '/src/Services', '.php')
);
foreach ($layerFiles as $f) {
    foreach (file($f, FILE_IGNORE_NEW_LINES) as $line) {
        $trimmed = ltrim($line);
        foreach ($bannedUse as $prefix) {
            if (str_starts_with($trimmed, $prefix)) {
                $failures[] = 'Check 2 (dependency direction): ' . relpath($root, $f) . ' imports forbidden layer: ' . trim($line);
            }
        }
    }
}

// ---- Check 3: No Blade alias mangling -------------------------------------------------
$blades = collectFiles($root . '/resources/views', '.blade.php');
foreach ($blades as $f) {
    $src = file_get_contents($f);
    if (strpos($src, '<livewire:') !== false) {
        $failures[] = 'Check 3 (alias mangling): ' . relpath($root, $f) . ' uses <livewire:…> tag (use @livewire(Class::class)).';
    }
    if (preg_match('/@livewire\(\s*[\'"]/', $src)) {
        $failures[] = 'Check 3 (alias mangling): ' . relpath($root, $f) . " uses @livewire('alias') string form (use @livewire(Class::class)).";
    }
}

// ---- Report ---------------------------------------------------------------------------
echo "Asset-Manager static guardrails\n";
echo "  Tools:    {$toolCount} found, " . ($toolCount - count($unregistered)) . " registered\n";
echo '  Layers:   ' . count($layerFiles) . " Models/Services scanned\n";
echo '  Blades:   ' . count($blades) . " views scanned\n";

if ($failures) {
    echo "\nFAIL (" . count($failures) . "):\n";
    foreach ($failures as $x) {
        echo "  - {$x}\n";
    }
    exit(1);
}

echo "\nOK: all guardrails green ({$toolCount}/{$toolCount} tools registered; Models/Services layer-clean; no Blade alias mangling).\n";
exit(0);
