<?php

/**
 * Trägerliste: Offboarding-Sicht (#884) und CSV-Export (#886).
 *
 * Offboarding: stillgelegte Träger mit Restbestand — Lizenz, Intune-Gerät, manuelles Asset, offene
 * Zuordnung oder offenes Übergabeprotokoll. Was zurückgegeben ist (Zuordnung mit Ende, Protokoll
 * „zurück"), darf die Sicht nicht füllen; aktive Träger und fremde Teams auch nicht.
 *
 * Export: folgt `filteredQuery()` — die Zeilenzahl ist die Trefferzahl, für jedes Preset. Kostenspalten
 * nur bei aktivem Controlling. Die gebündelte Kostenrechnung (`holderCostsFor`) muss für jeden Träger
 * dieselbe Zahl liefern wie die Einzelrechnung der Detailseite (`holderCost`).
 *
 * Aufruf: php tests/local/holders-offboarding-export.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Services\ControllingContext;
use Platform\AssetManager\Services\CostAggregationService;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Support\LicensePriceBook;

// --- Minimalschema --------------------------------------------------------
Schema::create('asset_holders', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('user_principal_name')->nullable();
    $t->string('display_name')->nullable();
    $t->string('email')->nullable();
    $t->string('department')->nullable();
    $t->string('cost_center')->nullable();
    $t->unsignedBigInteger('cost_center_id')->nullable();
    $t->string('source')->default('graph');
    $t->string('holder_type')->default('person');
    $t->boolean('holder_type_pinned')->default(false);
    $t->boolean('is_active')->default(true);
    $t->timestamp('billing_excluded_at')->nullable();
    $t->string('billing_excluded_reason')->nullable();
    $t->timestamps();
});
Schema::create('asset_devices', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('user_principal_name')->nullable(); $t->timestamps(); $t->softDeletes();
});
Schema::create('asset_categories', function (Blueprint $t) { $t->id(); $t->string('name')->nullable(); $t->timestamps(); });
Schema::create('asset_items', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->unsignedBigInteger('category_id')->nullable();
    $t->unsignedBigInteger('assignee_id')->nullable(); $t->decimal('purchase_price', 12, 2)->nullable(); $t->integer('depreciation_months')->nullable();
    $t->date('purchase_date')->nullable(); $t->timestamps(); $t->softDeletes();
});
Schema::create('asset_user_licenses', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->string('sku_id')->nullable();
    $t->string('user_principal_name')->nullable(); $t->decimal('unit_price', 10, 2)->nullable(); $t->unsignedBigInteger('contract_line_id')->nullable();
    $t->timestamps();
});
Schema::create('asset_license_skus', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->string('sku_id');
    $t->decimal('unit_price', 10, 2)->nullable(); $t->timestamps();
});
Schema::create('asset_license_contract_lines', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->string('period_label')->nullable(); $t->timestamps();
});
Schema::create('asset_team_settings', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable();
    $t->boolean('controlling_enabled')->default(false); $t->json('holder_type_rules')->nullable(); $t->timestamps();
});
Schema::create('asset_cost_types', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->string('key')->nullable();
    $t->string('aggregation_source')->default('cost_line'); $t->timestamps(); $t->softDeletes();
});
Schema::create('asset_cost_lines', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->unsignedBigInteger('asset_item_id')->nullable();
    $t->boolean('active')->default(true); $t->date('valid_from')->nullable(); $t->date('valid_to')->nullable(); $t->timestamps(); $t->softDeletes();
});
Schema::create('asset_assignments', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('tenant_id')->nullable(); $t->unsignedBigInteger('asset_item_id')->nullable();
    $t->string('assignable_type')->nullable(); $t->unsignedBigInteger('assignable_id')->nullable(); $t->unsignedBigInteger('holder_id')->nullable();
    $t->timestamp('assigned_at')->nullable(); $t->timestamp('returned_at')->nullable(); $t->string('source')->default('manual'); $t->timestamps();
});
Schema::create('asset_handovers', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->unsignedBigInteger('holder_id')->nullable();
    $t->string('status')->default('open'); $t->timestamps(); $t->softDeletes();
});

const TEAM   = 7;
const TENANT = 1;

TenantContext::forceTenant(TENANT);

function holder(string $upn, bool $active, array $attrs = []): AssetHolder
{
    return AssetHolder::create(array_merge([
        'team_id' => TEAM, 'tenant_id' => TENANT, 'user_principal_name' => $upn, 'display_name' => strtoupper($upn[0]) . substr($upn, 1, 4),
        'holder_type' => 'person', 'is_active' => $active,
    ], $attrs));
}

// --- Bestand --------------------------------------------------------------
$offLic  = holder('olic@k.de', false);     // Lizenz noch zugewiesen
$offAsg  = holder('oasg@k.de', false);     // offene Zuordnung + Asset (Kosten)
$offHo   = holder('oho@k.de', false);      // Protokoll teilweise zurück
$offDev  = holder('odev@k.de', false);     // Intune-Gerät noch auf der UPN
$offDone = holder('odone@k.de', false);    // alles zurückgegeben → NICHT in der Sicht
$active  = holder('act@k.de', true);       // aktiv mit Lizenz → NICHT in der Sicht
$foreign = AssetHolder::create(['team_id' => 99, 'tenant_id' => TENANT, 'user_principal_name' => 'fremd@k.de', 'holder_type' => 'person', 'is_active' => false]);

DB::table('asset_user_licenses')->insert([
    ['team_id' => TEAM, 'tenant_id' => TENANT, 'sku_id' => 'e3', 'user_principal_name' => 'olic@k.de', 'unit_price' => 10.50],
    ['team_id' => TEAM, 'tenant_id' => TENANT, 'sku_id' => 'e3', 'user_principal_name' => 'act@k.de', 'unit_price' => 10.50],
    ['team_id' => 99, 'tenant_id' => TENANT, 'sku_id' => 'e3', 'user_principal_name' => 'fremd@k.de', 'unit_price' => 10.50],
]);
DB::table('asset_items')->insert([
    ['id' => 1, 'team_id' => TEAM, 'tenant_id' => TENANT, 'assignee_id' => $offAsg->id, 'purchase_price' => 1200, 'depreciation_months' => 36],
]);
DB::table('asset_assignments')->insert([
    ['tenant_id' => TENANT, 'asset_item_id' => 1, 'assignable_type' => 'item', 'assignable_id' => 1, 'holder_id' => $offAsg->id, 'returned_at' => null],
    ['tenant_id' => TENANT, 'asset_item_id' => 1, 'assignable_type' => 'item', 'assignable_id' => 1, 'holder_id' => $offDone->id, 'returned_at' => '2026-09-01 00:00:00'],
]);
DB::table('asset_handovers')->insert([
    ['team_id' => TEAM, 'tenant_id' => TENANT, 'holder_id' => $offHo->id, 'status' => 'partially_returned'],
    ['team_id' => TEAM, 'tenant_id' => TENANT, 'holder_id' => $offDone->id, 'status' => 'returned'],
]);
DB::table('asset_devices')->insert(['team_id' => TEAM, 'tenant_id' => TENANT, 'user_principal_name' => 'odev@k.de']);
DB::table('asset_team_settings')->insert(['team_id' => TEAM, 'tenant_id' => TENANT, 'controlling_enabled' => false]);

// --- Komponente ohne Auth -------------------------------------------------
$component = new class extends Platform\AssetManager\Livewire\Holders\Index {
    public function teamId(): int { return TEAM; }
    public function activeTenantId(): int { return TENANT; }
};
$call = function (string $method, ...$args) use ($component) {
    $m = new ReflectionMethod($component, $method);
    $m->setAccessible(true);

    return $m->invoke($component, ...$args);
};
$fresh = function () use (&$component, &$call): void {
    // Memo der Zugehörigkeits-Listen leeren — wie ein neuer Request.
    $p = new ReflectionProperty($component, 'lookupMemo');
    $p->setAccessible(true);
    $p->setValue($component, []);
};
$ids = function () use ($call, $fresh): array {
    $fresh();
    $out = $call('filteredQuery', TEAM)->pluck('id')->map(fn ($i) => (int) $i)->all();
    sort($out);

    return $out;
};
$sorted = function (AssetHolder ...$models): array {
    $out = array_map(fn ($m) => (int) $m->id, $models);
    sort($out);

    return $out;
};

$component->filterHolderType = '';

// --- Offboarding-Preset ---------------------------------------------------
$component->preset = 'offboarding';
check('Offboarding: genau die stillgelegten mit Restbestand', $sorted($offLic, $offAsg, $offHo, $offDev), $ids());

// Tracer Bullet: „Inaktiv" mit dem Lizenz-Filter kombinierbar.
$component->preset           = 'inactive';
$component->filterHasLicense = true;
check('Inaktiv + Hat Lizenz kombinierbar', $sorted($offLic), $ids());
$component->filterHasLicense = false;
$component->filterHasDevice  = true;
check('Inaktiv + Hat Gerät kombinierbar', $sorted($offDev), $ids());
$component->filterHasDevice  = false;

// Je Zeile Stückzahl.
$component->preset = 'offboarding';
$fresh();
$rows  = $call('filteredQuery', TEAM)->get();
$stats = $call('rowStats', TEAM, $rows, true, false);
check('Stückzahl: offene Zuordnung', 1, (int) ($stats['open_assignments'][$offAsg->id] ?? 0));
check('Stückzahl: zurückgegebene Zuordnung zählt nicht', 0, (int) ($stats['open_assignments'][$offDone->id] ?? 0));
check('Stückzahl: offenes Protokoll', 1, (int) ($stats['open_handovers'][$offHo->id] ?? 0));
check('Stückzahl: Lizenz', 1, (int) ($stats['licenses']['olic@k.de'] ?? 0));
check('Stückzahl: Gerät', 1, (int) ($stats['devices']['odev@k.de'] ?? 0));
check('ohne Controlling: keine Beträge', [], $stats['costs']);

$stats = $call('rowStats', TEAM, $rows, true, true);
check('mit Controlling: Monatsbetrag Lizenz', 10.5, $stats['costs'][$offLic->id]['total']);
check('mit Controlling: Monatsbetrag Asset (AfA)', 33.33, $stats['costs'][$offAsg->id]['total']);

// --- holderCostsFor == holderCost ------------------------------------------
LicensePriceBook::flush();
$agg   = app(CostAggregationService::class);
$batch = $agg->holderCostsFor(TEAM, AssetHolder::where('team_id', TEAM)->get());
$diff  = [];
foreach (AssetHolder::where('team_id', TEAM)->get() as $h) {
    if ($batch[$h->id] !== $agg->holderCost(TEAM, $h)) {
        $diff[] = $h->user_principal_name;
    }
}
check('holderCostsFor stimmt je Träger mit holderCost überein', [], $diff);

// --- CSV-Export -----------------------------------------------------------
$csv = function () use ($call, $fresh): array {
    $fresh();
    ControllingContext::forget();

    return iterator_to_array($call('csvRows', TEAM), false);
};

$mismatch = [];
foreach (['all', 'active', 'inactive', 'offboarding', 'unassigned', 'with_license', 'billing_excluded'] as $preset) {
    $component->preset = $preset;
    $rows = $csv();
    $fresh();
    if (count($rows) - 1 !== $call('filteredQuery', TEAM)->count()) {
        $mismatch[] = $preset;
    }
}
check('Export: Zeilenzahl = Trefferzahl in jedem Preset', [], $mismatch);

$component->preset = 'all';
$rows = $csv();
check('Export: ohne Controlling keine Kostenspalte', false, in_array('Gesamt EUR/Monat', $rows[0], true));
check('Export: ohne Offboarding keine Offboarding-Spalten', false, in_array('Offene Zuordnungen', $rows[0], true));
check('Export: fremdes Team nicht enthalten', false, in_array('fremd@k.de', array_column(array_slice($rows, 1), 6), true));

DB::table('asset_team_settings')->update(['controlling_enabled' => true]);
$component->preset = 'offboarding';
$rows   = $csv();
$header = $rows[0];
check('Export: mit Controlling Kostenspalten', true, in_array('Gesamt EUR/Monat', $header, true));
check('Export: Offboarding-Spalten im Offboarding-Preset', true, in_array('Offene Zuordnungen', $header, true));
$byUpn = [];
foreach (array_slice($rows, 1) as $row) {
    $byUpn[$row[array_search('UPN', $header, true)]] = $row;
}
check('Export: Betrag als Dezimal mit Komma', '10,50', $byUpn['olic@k.de'][array_search('Gesamt EUR/Monat', $header, true)] ?? null);
check('Export: offene Zuordnung je Zeile', 1, $byUpn['oasg@k.de'][array_search('Offene Zuordnungen', $header, true)] ?? null);

// Sortierung folgt der Liste.
$component->preset        = 'all';
$component->sortField     = 'user_principal_name';
$component->sortDirection = 'desc';
$rows = $csv();
$upns = array_column(array_slice($rows, 1), 6);
$expected = $upns;
rsort($expected);
check('Export: Sortierung wie in der Liste', $expected, $upns);

check_summary();
