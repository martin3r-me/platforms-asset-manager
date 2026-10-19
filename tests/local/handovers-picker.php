<?php

/**
 * Geräteausgaben (#977): Geräte und Träger für den Editor nur laden, wenn er offen ist.
 *
 * Vorher lud jeder Render der Liste — Seitenaufruf, Suche, Blättern, auch für Member ohne Editor —
 * alle Geräte und alle Träger des Tenants. Die Abdeckung (#887) bleibt: sie ist Seiteninhalt.
 *
 * Aufruf: php tests/local/handovers-picker.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Services\TenantContext;

Schema::create('asset_devices', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('device_name')->nullable(); $t->string('serial_number')->nullable(); $t->string('model')->nullable();
    $t->string('user_principal_name')->nullable(); $t->string('lifecycle_status')->nullable(); $t->timestamps(); $t->softDeletes();
});
Schema::create('asset_holders', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('user_principal_name')->nullable(); $t->string('display_name')->nullable(); $t->timestamps(); $t->softDeletes();
});
Schema::create('asset_assignments', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id')->nullable(); $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('assignable_type')->nullable(); $t->unsignedBigInteger('assignable_id')->nullable(); $t->unsignedBigInteger('holder_id')->nullable();
    $t->timestamp('assigned_at')->nullable(); $t->timestamp('returned_at')->nullable(); $t->timestamps();
});
Schema::create('asset_handovers', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->unsignedBigInteger('holder_id')->nullable();
    $t->date('issued_at')->nullable(); $t->string('signer_name')->nullable(); $t->timestamp('signed_at')->nullable();
    $t->string('status')->default('open'); $t->timestamps(); $t->softDeletes();
});
Schema::create('asset_handover_lines', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('tenant_id')->nullable(); $t->unsignedBigInteger('handover_id'); $t->unsignedBigInteger('asset_device_id')->nullable();
    $t->date('returned_at')->nullable(); $t->timestamps();
});

const TEAM = 7;
TenantContext::forceTenant(1);

DB::table('asset_devices')->insert([
    ['id' => 1, 'team_id' => TEAM, 'tenant_id' => 1, 'device_name' => 'NB-1'],
    ['id' => 2, 'team_id' => TEAM, 'tenant_id' => 1, 'device_name' => 'NB-2'],
]);
DB::table('asset_holders')->insert([['id' => 1, 'team_id' => TEAM, 'tenant_id' => 1, 'user_principal_name' => 'a@k.de']]);
DB::table('asset_handovers')->insert([['id' => 1, 'team_id' => TEAM, 'tenant_id' => 1, 'holder_id' => 1, 'issued_at' => '2026-10-01']]);
DB::table('asset_handover_lines')->insert([['tenant_id' => 1, 'handover_id' => 1, 'asset_device_id' => 1]]);

$component = new class extends Platform\AssetManager\Livewire\Handovers\Index {
    public function teamId(): int { return TEAM; }
};
$data = fn () => $component->render()->getData();

$component->showEditor = false;
$d = $data();
check('Editor zu: keine Geräte', 0, $d['devices']->count());
check('Editor zu: keine Träger', 0, $d['holders']->count());
check('Editor zu: Liste + Abdeckung trotzdem da', [1, true], [$d['handovers']->total(), isset($d['coverage']['base'])]);

$component->showEditor = true;
$d = $data();
check('Editor offen ohne Recht: nichts geladen', 0, $d['devices']->count());

Gate::before(fn (?object $user = null) => true);
$d = $data();
check('Editor offen: Geräte geladen', 2, $d['devices']->count());
check('Editor offen: Träger geladen', 1, $d['holders']->count());
check('Editor offen: belegtes Gerät markiert', [1], array_map('intval', $d['openDeviceIds']));

check_summary();
