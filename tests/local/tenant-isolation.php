<?php

/**
 * Tenant-Isolation (#901, ADR 0016): JEDES Model mit {@see \Platform\AssetManager\Concerns\TenantScopable}
 * liefert ohne expliziten Filter nur die Zeilen des aktiven Tenants.
 *
 * Generisch über alle Models: eine neue tenant-gebundene Tabelle ist damit automatisch abgedeckt. Das
 * Risiko ist heute nicht der vergessene Filter (den setzt der Global Scope), sondern ein Model, das den
 * Trait nicht trägt — oder ein Ausstieg per withoutTenantScope(), der zu breit gesetzt ist. Den ersten
 * Fall fängt dieser Test, für den zweiten prüft er, dass der Ausstieg wirklich nur bewusst öffnet.
 *
 * Aufruf: php tests/local/tenant-isolation.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Concerns\TenantScopable;
use Platform\AssetManager\Services\TenantContext;

const TEAM = 7;

$models = [];
foreach (glob(__DIR__ . '/../../src/Models/*.php') as $file) {
    $class = 'Platform\\AssetManager\\Models\\' . basename($file, '.php');
    if (in_array(TenantScopable::class, class_uses_recursive($class), true)) {
        $models[] = $class;
    }
}

check('Models mit TenantScopable gefunden', true, count($models) >= 20);

foreach ($models as $class) {
    $table = (new $class())->getTable();
    if (! Schema::hasTable($table)) {
        Schema::create($table, function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('team_id')->nullable();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
    }
    DB::table($table)->insert([
        ['team_id' => TEAM, 'tenant_id' => 1],
        ['team_id' => TEAM, 'tenant_id' => 2],
    ]);
}

TenantContext::forceTenant(1);
$leaks = [];
foreach ($models as $class) {
    if ($class::query()->count() !== 1 || (int) $class::query()->value('tenant_id') !== 1) {
        $leaks[] = class_basename($class);
    }
}
check('ohne Filter nur der aktive Tenant (alle Models)', [], $leaks);

TenantContext::forceTenant(2);
$leaks = [];
foreach ($models as $class) {
    if ((int) $class::query()->value('tenant_id') !== 2) {
        $leaks[] = class_basename($class);
    }
}
check('Tenant-Wechsel folgt der Kontext-Auswahl', [], $leaks);

$open = [];
foreach ($models as $class) {
    if ($class::query()->withoutTenantScope()->count() !== 2) {
        $open[] = class_basename($class);
    }
}
check('withoutTenantScope() öffnet bewusst (beide Zeilen)', [], $open);

$pinned = [];
foreach ($models as $class) {
    if ((int) $class::query()->withoutTenantScope()->forTenant(1)->value('tenant_id') !== 1) {
        $pinned[] = class_basename($class);
    }
}
check('withoutTenantScope()->forTenant() trifft genau den Tenant', [], $pinned);

// Models mit tenant_id-Spalte, aber OHNE Trait wären ungeschützt — die Liste muss bewusst sein.
$withoutTrait = [];
foreach (glob(__DIR__ . '/../../src/Models/*.php') as $file) {
    $code  = (string) file_get_contents($file);
    $class = 'Platform\\AssetManager\\Models\\' . basename($file, '.php');
    if (str_contains($code, "'tenant_id'") && ! in_array($class, $models, true)) {
        $withoutTrait[] = basename($file, '.php');
    }
}
sort($withoutTrait);
// Bewusste Ausnahmen — eine NEUE Zeile hier ist eine Entscheidung, kein Versehen:
//  - AssetTenant: der Tenant selbst ('tenant_id' nur in der Relation).
//  - AssetConnectorConfig: die Anbindung, team-gescopt; der Sync setzt den Tenant aus ihr.
//  - AssetDeviceModel: team-weiter Hardware-Katalog (CONTEXT.md), tenant-scoped sind nur die Kosten.
//  - AssetDeviceSource: wird nur über die Geräte-ID gelesen (Sync) und team-weit gelöscht (Reset), nie
//    gelistet. Kandidat für den Trait, sobald eine Oberfläche Quellen auflistet (ADR 0009, #891).
check('Models mit tenant_id ohne Trait sind nur die bekannten Ausnahmen',
    ['AssetConnectorConfig', 'AssetDeviceModel', 'AssetDeviceSource', 'AssetTenant'], $withoutTrait);

check_summary();
