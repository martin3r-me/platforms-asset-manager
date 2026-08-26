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
 *   4. Eager-load names exist — every relation segment named in `with()`/`load()` is a real
 *      relation on some module model. Catches a renamed relation left standing in an
 *      eager-load string; Eloquent only raises that at runtime, and only once the outer
 *      query returns rows.
 *   5. Device-model cost is loaded with its cost type + vendor — a bare `with('cost')` costs
 *      two queries per row in lists that are deliberately unpaginated.
 *   6. Per-instance device cost resolution stays on single-object paths — lists and
 *      aggregations build one `DeviceCostResolver` up front instead of one per row.
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
    // Beide Seiten ERST auf Slashes normalisieren: str_replace() arbeitet die Ersetzungen
    // sequenziell ab, also passte ein Windows-$root mit Backslashes nach dem ersten Schritt
    // nicht mehr auf den bereits umgeschriebenen Pfad — und die Meldung trug den absoluten Pfad.
    $root = str_replace('\\', '/', $root);
    $path = str_replace('\\', '/', $path);

    return ltrim(str_starts_with($path, $root) ? substr($path, strlen($root)) : $path, '/');
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

// ---- Check 4: every relation named in with()/load() exists as a relation --------------
// Catches the class of bug that `with('handover.employee')` was: a relation renamed in the
// model (holder()) but left standing in an eager-load string. Eloquent only raises it at
// runtime, and only when the outer query returns rows — so it can sit unnoticed for months.
// Approximation on purpose: the check asks whether the segment is a relation on ANY module
// model, not on the specific query root. That is enough to catch names that exist nowhere.
$relationNames = [];
foreach (collectFiles($root . '/src/Models', '.php') as $f) {
    if (preg_match_all(
        '/public function ([a-zA-Z_][a-zA-Z0-9_]*)\(\s*\)\s*:\s*\\\\?(BelongsTo|HasMany|HasOne|HasManyThrough|HasOneThrough|MorphTo|MorphMany|MorphOne|MorphToMany|BelongsToMany)\b/',
        file_get_contents($f),
        $m
    )) {
        foreach ($m[1] as $name) {
            $relationNames[$name] = true;
        }
    }
}
if ($relationNames === []) {
    $failures[] = 'Check 4 (eager loads): no relations found in src/Models — the scan pattern broke, not the code.';
}

/** Relation paths named in with()/load() calls, per file. Skips comment lines. */
function eagerLoadPaths(string $file): array
{
    $paths = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
        $trimmed = ltrim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*')) {
            continue;
        }
        // Beide Aufrufformen: die Instanz-Kette ->with(…) UND der statische Einstieg
        // Model::with(…). Der handover.employee-Fehler stand in der statischen Form — ein
        // Muster nur auf -> haette ihn durchgelassen.
        if (! preg_match_all('/(?:->|::)(?:with|load|loadMissing)\(\s*(\[[^\]]*\]|\'[A-Za-z0-9_.]+\')/', $line, $calls)) {
            continue;
        }
        foreach ($calls[1] as $arg) {
            if (preg_match_all('/\'([A-Za-z0-9_.]+)\'/', $arg, $found)) {
                foreach ($found[1] as $path) {
                    $paths[] = $path;
                }
            }
        }
    }

    return $paths;
}

$eagerFiles = array_merge(
    collectFiles($root . '/src', '.php'),
    collectFiles($root . '/resources/views', '.blade.php')
);
$eagerPathCount = 0;
foreach ($eagerFiles as $f) {
    foreach (eagerLoadPaths($f) as $path) {
        $eagerPathCount++;
        foreach (explode('.', $path) as $segment) {
            if (! isset($relationNames[$segment])) {
                $failures[] = "Check 4 (eager loads): " . relpath($root, $f) . " eager-loads '{$path}',"
                    . " but '{$segment}' is not a relation on any module model.";
            }
        }
    }
}

// ---- Check 5: loading a device-model cost also loads its cost type + vendor -----------
// Every list that shows a model cost shows the cost type and the creditor next to it, so a bare
// with('cost') means two extra queries per row — and those lists are deliberately unpaginated.
foreach (collectFiles($root . '/src', '.php') as $f) {
    $src = file_get_contents($f);
    if (preg_match('/(?:->|::)(?:with|load)\(\s*\'cost\'\s*\)/', $src)
        || preg_match('/(?:->|::)(?:with|load)\(\s*\[\s*\'cost\'\s*[,\]]/', $src)) {
        $failures[] = 'Check 5 (device-model cost): ' . relpath($root, $f)
            . " eager-loads bare 'cost' — use ['cost.costType', 'cost.vendor'] (the lists show both).";
    }
}

// ---- Check 6: single-object paths for the device cost resolution ----------------------
// AssetDevice::resolvedMonthlyCost()/resolvedCostTypeId()/deviceModel() memoise per INSTANCE, so
// each row in a list builds its own DeviceCostResolver — two queries per device, each loading the
// whole model catalogue. Lists and aggregations must build one DeviceCostResolver up front
// (InventoryService, CostAggregationService, ListDevicesTool, Reports\DeviceModels do). The list
// below is the set of legitimate SINGLE-object callers; a new entry is a decision, not an accident.
$singleObjectCallers = [
    'src/Support/AssetSubject.php',                            // one item or one device (detail page)
    'src/Tools/Devices/GetDeviceTool.php',                     // exactly one device
    'src/Tools/Devices/UpdateDeviceTool.php',                  // echoes back the one device written
    'resources/views/livewire/inventory/show.blade.php',       // detail page, one device
];
$unexpectedCallers = [];
foreach ($eagerFiles as $f) {
    $rel = relpath($root, $f);
    if ($rel === 'src/Models/AssetDevice.php' || $rel === 'src/Support/DeviceCostResolver.php') {
        continue; // the implementation itself
    }
    foreach (file($f, FILE_IGNORE_NEW_LINES) as $line) {
        $trimmed = ltrim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*')) {
            continue;
        }
        if (preg_match('/->(?:resolvedMonthlyCost|resolvedCostTypeId|deviceModel)\(\s*\)/', $line)
            && ! in_array($rel, $singleObjectCallers, true)) {
            $unexpectedCallers[$rel] = true;
        }
    }
}
foreach (array_keys($unexpectedCallers) as $rel) {
    $failures[] = "Check 6 (cost resolution): {$rel} calls the per-instance device cost resolution."
        . ' Build one DeviceCostResolver::for($teamId, $tenantId) up front, or add the file to'
        . ' $singleObjectCallers in this guardrail if it really handles a single device.';
}

// ---- Report ---------------------------------------------------------------------------
echo "Asset-Manager static guardrails\n";
echo "  Tools:    {$toolCount} found, " . ($toolCount - count($unregistered)) . " registered\n";
echo '  Layers:   ' . count($layerFiles) . " Models/Services scanned\n";
echo '  Blades:   ' . count($blades) . " views scanned\n";
echo '  Eager:    ' . $eagerPathCount . ' relation paths checked against ' . count($relationNames) . " model relations\n";

if ($failures) {
    echo "\nFAIL (" . count($failures) . "):\n";
    foreach ($failures as $x) {
        echo "  - {$x}\n";
    }
    exit(1);
}

echo "\nOK: all guardrails green ({$toolCount}/{$toolCount} tools registered; Models/Services layer-clean; no Blade alias mangling).\n";
exit(0);
