<?php

/**
 * Abdeckung der Übergabeprotokolle (#887): {@see \Platform\AssetManager\Services\HandoverCoverageService}.
 *
 * Aufruf: php tests/local/handover-coverage.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Services\HandoverCoverageService;
use Platform\AssetManager\Services\TenantContext;

Schema::create('asset_devices', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('device_name')->nullable(); $t->string('serial_number')->nullable(); $t->string('user_principal_name')->nullable();
    $t->string('lifecycle_status')->nullable(); $t->timestamps(); $t->softDeletes();
});
Schema::create('asset_assignments', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id')->nullable(); $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('assignable_type')->nullable(); $t->unsignedBigInteger('assignable_id')->nullable(); $t->unsignedBigInteger('holder_id')->nullable();
    $t->timestamp('assigned_at')->nullable(); $t->timestamp('returned_at')->nullable(); $t->timestamps();
});
Schema::create('asset_handovers', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->unsignedBigInteger('holder_id')->nullable();
    $t->timestamp('signed_at')->nullable(); $t->string('status')->default('open'); $t->timestamps(); $t->softDeletes();
});
Schema::create('asset_handover_lines', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('tenant_id')->nullable(); $t->unsignedBigInteger('handover_id'); $t->unsignedBigInteger('asset_device_id')->nullable();
    $t->date('returned_at')->nullable(); $t->timestamps();
});

const TEAM = 7;
TenantContext::forceTenant(1);

$dev = fn (int $id, ?string $lifecycle) => DB::table('asset_devices')->insert(['id' => $id, 'team_id' => TEAM, 'tenant_id' => 1, 'device_name' => "NB-{$id}", 'lifecycle_status' => $lifecycle]);
$assign = fn (int $id, ?string $returned = null) => DB::table('asset_assignments')->insert(['tenant_id' => 1, 'assignable_type' => 'device', 'assignable_id' => $id, 'holder_id' => 1, 'returned_at' => $returned]);

$dev(1, 'in_use'); $assign(1);           // signiert → abgedeckt
$dev(2, null);     $assign(2);           // Protokoll unsigniert → offen
$dev(3, 'in_use'); $assign(3);           // gar kein Protokoll → offen
$dev(4, 'spare');  $assign(4);           // Reserve → zählt nicht
$dev(5, 'in_use');                       // ohne Träger → zählt nicht
$dev(6, 'in_use'); $assign(6, '2026-01-01'); // Zuordnung beendet → zählt nicht
$dev(7, 'in_use'); $assign(7);           // signiert, aber zurückgegeben → offen

DB::table('asset_handovers')->insert([
    ['id' => 1, 'team_id' => TEAM, 'tenant_id' => 1, 'signed_at' => '2026-09-01 10:00:00'],
    ['id' => 2, 'team_id' => TEAM, 'tenant_id' => 1, 'signed_at' => null],
]);
DB::table('asset_handover_lines')->insert([
    ['tenant_id' => 1, 'handover_id' => 1, 'asset_device_id' => 1, 'returned_at' => null],
    ['tenant_id' => 1, 'handover_id' => 2, 'asset_device_id' => 2, 'returned_at' => null],
    ['tenant_id' => 1, 'handover_id' => 1, 'asset_device_id' => 7, 'returned_at' => '2026-10-01'],
]);

$c = app(HandoverCoverageService::class)->coverage(TEAM);
check('Basis: in Nutzung/ohne Lifecycle mit offener Zuordnung', 4, $c['base']);
check('abgedeckt: nur Gerät 1', 1, $c['covered']);
check('offen: 2, 3, 7', [2, 3, 7], array_column($c['open_devices'], 'id'));

check_summary();
