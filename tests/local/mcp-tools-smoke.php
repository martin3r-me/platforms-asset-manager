<?php

/**
 * MCP-Tools im echten Aufruf (#976) — bisher importierte kein lokales Skript ein einziges Tool; der
 * kaputte `cost-lines.POST` (#938) wäre beim ersten Aufruf aufgefallen.
 *
 * Geprüft wird je Tool der Happy-Path und die Grenzen:
 *  1. Rechte: Admin im currentTeam, aber Member im Kontext-Team → ACCESS_DENIED (#943).
 *  2. cost-lines.POST legt eine Zeile MIT tenant_id an (#938).
 *  3. Fremder Tenant → NOT_FOUND (ADR 0016), auch bei device-status.GET (#962).
 *  4. Die neuen Lese-Tools liefern (device-events, handovers, datev).
 *
 * Aufruf: php tests/local/mcp-tools-smoke.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Services\ControllingContext;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Support\TeamRole;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;

// ---- Schema --------------------------------------------------------------------------------
Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('name')->nullable(); $t->timestamps(); });
Schema::create('asset_tenants', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->string('name'); $t->boolean('is_default')->default(false); $t->timestamps(); $t->softDeletes();
});
Schema::create('asset_tenant_selections', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('selected_tenant_id')->nullable(); $t->timestamps();
});
Schema::create('asset_team_settings', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable();
    $t->boolean('controlling_enabled')->default(false); $t->timestamps();
});
Schema::create('asset_cost_types', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->string('key'); $t->string('name');
    $t->string('aggregation_source')->default('cost_line'); $t->boolean('allow_negative')->default(false);
    $t->string('system_default')->nullable(); $t->unsignedBigInteger('vendor_default_id')->nullable(); $t->timestamps(); $t->softDeletes();
});
foreach (['asset_cost_centers', 'asset_vendors'] as $table) {
    Schema::create($table, function (Blueprint $t) {
        $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->unsignedBigInteger('parent_id')->nullable();
        $t->integer('depth')->default(0); $t->string('code')->nullable(); $t->string('name')->nullable(); $t->timestamps(); $t->softDeletes();
    });
}
Schema::create('asset_holders', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->unsignedBigInteger('cost_center_id')->nullable();
    $t->string('display_name')->nullable(); $t->string('user_principal_name')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps(); $t->softDeletes();
});
Schema::create('asset_cost_lines', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id'); // NOT NULL wie live (#938)
    $t->unsignedBigInteger('cost_type_id')->nullable(); $t->unsignedBigInteger('vendor_id')->nullable(); $t->unsignedBigInteger('cost_center_id')->nullable();
    $t->unsignedBigInteger('assignee_id')->nullable(); $t->unsignedBigInteger('asset_item_id')->nullable(); $t->string('label');
    $t->decimal('amount', 12, 2); $t->string('currency', 3)->default('EUR'); $t->decimal('fx_rate', 12, 6)->nullable(); $t->string('frequency');
    $t->decimal('monthly_amount', 12, 2)->default(0); $t->string('accounting_system')->nullable(); $t->string('source')->default('manual');
    $t->date('valid_from')->nullable(); $t->date('valid_to')->nullable(); $t->boolean('active')->default(true); $t->timestamps(); $t->softDeletes();
});
Schema::create('asset_devices', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->string('intune_id')->nullable();
    $t->string('device_name')->nullable(); $t->string('serial_number')->nullable(); $t->string('user_principal_name')->nullable();
    $t->string('lifecycle_status')->nullable(); $t->timestamps(); $t->softDeletes();
});
Schema::create('asset_device_events', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->unsignedBigInteger('asset_device_id');
    $t->unsignedBigInteger('user_id')->nullable(); $t->string('event_type'); $t->string('description')->nullable();
    $t->string('old_value')->nullable(); $t->string('new_value')->nullable(); $t->timestamps();
});
Schema::create('asset_handovers', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->unsignedBigInteger('holder_id');
    $t->date('issued_at')->nullable(); $t->string('signer_name')->nullable(); $t->text('signature_data')->nullable(); $t->timestamp('signed_at')->nullable();
    $t->text('notes')->nullable(); $t->string('status')->default('open'); $t->timestamps(); $t->softDeletes();
});
Schema::create('asset_handover_lines', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('tenant_id')->nullable(); $t->unsignedBigInteger('handover_id'); $t->unsignedBigInteger('asset_device_id')->nullable();
    $t->json('accessories')->nullable(); $t->text('notes')->nullable(); $t->date('returned_at')->nullable(); $t->string('return_condition')->nullable();
    $t->json('device_snapshot')->nullable(); $t->string('status')->default('issued'); $t->timestamps();
});
Schema::create('asset_invoice_snapshots', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id'); $t->string('source', 32); $t->string('account', 64)->default('');
    $t->string('invoice', 64); $t->date('invoice_date')->nullable(); $t->date('booking_month')->nullable(); $t->string('period_label')->nullable();
    $t->decimal('total_net', 12, 2); $t->json('distribution'); $t->unsignedBigInteger('datev_batch_id')->nullable(); $t->timestamp('captured_at')->nullable(); $t->timestamps();
});
Schema::create('asset_datev_batches', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id'); $t->date('booking_month'); $t->string('client', 5); $t->string('consultant', 7);
    $t->string('label', 30); $t->string('file_name'); $t->longText('file_base64'); $t->string('file_sha256', 64); $t->unsignedInteger('bookings_count');
    $t->bigInteger('total_cents'); $t->json('summary'); $t->unsignedBigInteger('user_id')->nullable(); $t->timestamp('voided_at')->nullable();
    $t->unsignedBigInteger('voided_by')->nullable(); $t->string('void_reason')->nullable(); $t->timestamps();
});

// ---- Daten ---------------------------------------------------------------------------------
const TEAM_A = 7;   // Admin
const TEAM_B = 9;   // nur Member
const TENANT = 1;
const FOREIGN = 2;  // gehört zu TEAM_B

DB::table('asset_tenants')->insert([
    ['id' => TENANT, 'team_id' => TEAM_A, 'name' => 'Broich', 'is_default' => true],
    ['id' => FOREIGN, 'team_id' => TEAM_B, 'name' => 'Fremd', 'is_default' => true],
    ['id' => 3, 'team_id' => TEAM_B, 'name' => 'Fremd 2', 'is_default' => false],
]);
DB::table('asset_team_settings')->insert([
    ['team_id' => TEAM_A, 'tenant_id' => TENANT, 'controlling_enabled' => true],
    ['team_id' => TEAM_B, 'tenant_id' => FOREIGN, 'controlling_enabled' => true],
]);
DB::table('users')->insert(['id' => 1, 'name' => 'Max']);
DB::table('asset_cost_types')->insert(['id' => 1, 'team_id' => TEAM_A, 'tenant_id' => TENANT, 'key' => 'mobilfunk', 'name' => 'Mobilfunk']);
DB::table('asset_cost_types')->insert(['id' => 2, 'team_id' => TEAM_B, 'tenant_id' => FOREIGN, 'key' => 'mobilfunk', 'name' => 'Mobilfunk']);
DB::table('asset_holders')->insert(['id' => 1, 'team_id' => TEAM_A, 'tenant_id' => TENANT, 'display_name' => 'Anna', 'user_principal_name' => 'anna@broich.de']);
DB::table('asset_devices')->insert(['id' => 1, 'team_id' => TEAM_A, 'tenant_id' => TENANT, 'intune_id' => 'i-1', 'device_name' => 'NB-01', 'serial_number' => 'PF1']);
DB::table('asset_device_events')->insert([
    ['team_id' => TEAM_A, 'tenant_id' => TENANT, 'asset_device_id' => 1, 'user_id' => 1, 'event_type' => 'owner_changed', 'description' => 'x', 'created_at' => '2026-10-01 10:00:00'],
    ['team_id' => TEAM_A, 'tenant_id' => TENANT, 'asset_device_id' => 1, 'user_id' => null, 'event_type' => 'removed', 'description' => 'Aus Intune verschwunden', 'created_at' => '2026-10-10 10:00:00'],
]);
DB::table('asset_handovers')->insert([
    ['id' => 1, 'team_id' => TEAM_A, 'tenant_id' => TENANT, 'holder_id' => 1, 'issued_at' => '2026-09-01', 'signed_at' => '2026-09-01 12:00:00', 'signature_data' => 'data:image/png;base64,AA', 'status' => 'open'],
    ['id' => 2, 'team_id' => TEAM_A, 'tenant_id' => TENANT, 'holder_id' => 1, 'issued_at' => '2026-10-01', 'signed_at' => null, 'signature_data' => null, 'status' => 'open'],
]);
DB::table('asset_handover_lines')->insert(['tenant_id' => TENANT, 'handover_id' => 1, 'asset_device_id' => 1, 'accessories' => json_encode(['Ladegerät'])]);
DB::table('asset_invoice_snapshots')->insert([
    ['team_id' => TEAM_A, 'tenant_id' => TENANT, 'source' => 'vodafone_invoice', 'account' => 'K1', 'invoice' => 'M-SEP', 'booking_month' => '2026-09-01', 'total_net' => 100, 'distribution' => json_encode([['cost_type_id' => 1, 'cost_center_code' => '1520', 'amount' => 40], ['cost_type_id' => 1, 'cost_center_code' => '8090', 'amount' => 60]]), 'datev_batch_id' => 5],
    ['team_id' => TEAM_A, 'tenant_id' => TENANT, 'source' => 'vodafone_fixednet', 'account' => 'K2', 'invoice' => 'I-SEP', 'booking_month' => '2026-09-01', 'total_net' => 369, 'distribution' => json_encode([['cost_type_id' => 1, 'cost_center_code' => '2890', 'amount' => 369]]), 'datev_batch_id' => null],
    ['team_id' => TEAM_A, 'tenant_id' => TENANT, 'source' => 'vodafone_invoice', 'account' => 'K1', 'invoice' => 'M-OKT', 'booking_month' => '2026-10-01', 'total_net' => 50, 'distribution' => json_encode([['cost_type_id' => 1, 'cost_center_code' => '1520', 'amount' => 30], ['cost_type_id' => 1, 'cost_center_code' => '2000', 'amount' => 20]]), 'datev_batch_id' => null],
]);
DB::table('asset_datev_batches')->insert(['id' => 5, 'team_id' => TEAM_A, 'tenant_id' => TENANT, 'booking_month' => '2026-09-01', 'client' => '530', 'consultant' => '189671', 'label' => 'CTRL Umb IT Sep 2026', 'file_name' => 'x.csv', 'file_base64' => '', 'file_sha256' => str_repeat('0', 64), 'bookings_count' => 3, 'total_cents' => 10000, 'summary' => '{}']);

// ---- Kontext -------------------------------------------------------------------------------
/** User: Owner in TEAM_A (currentTeam), nur Member in TEAM_B. */
$user = new class implements \Illuminate\Contracts\Auth\Authenticatable {
    public int $id = 1;
    public ?int $current_team_id = TEAM_A;
    public string $name = 'Max';
    public function teams(): object
    {
        return new class {
            private ?int $team = null;
            public function where($column, $value = null): static { $this->team = (int) $value; return $this; }
            public function first(): ?object
            {
                $role = [TEAM_A => 'owner', TEAM_B => 'member'][$this->team] ?? null;
                return $role ? (object) ['pivot' => (object) ['role' => $role]] : null;
            }
        };
    }
    public function __get($name) { return $name === 'currentTeam' ? (object) ['id' => $this->current_team_id] : null; }
    public function getAuthIdentifierName(): string { return 'id'; }
    public function getAuthIdentifier(): int { return 1; }
    public function getAuthPassword(): string { return ''; }
    public function getAuthPasswordName(): string { return 'password'; }
    public function getRememberToken(): string { return ''; }
    public function setRememberToken($value): void {}
    public function getRememberTokenName(): string { return 'remember_token'; }
};

$ctxA = new ToolContext($user, (object) ['id' => TEAM_A]);
$ctxB = new ToolContext($user, (object) ['id' => TEAM_B]);

/** Tool ausführen und Ergebnis als Array liefern. */
function run(string $class, array $args, ToolContext $ctx): array
{
    TenantContext::clearOverride();
    ControllingContext::forget();
    TeamRole::flushResolved();
    $result = (new $class())->execute($args, $ctx);

    return json_decode(json_encode($result->toArray()), true);
}
function ok(array $r): bool { return ($r['ok'] ?? false) === true; }
function code(array $r): ?string { return $r['error']['code'] ?? null; }
function data(array $r): array { return (array) ($r['data'] ?? []); }

// ---- 1 + 2: cost-lines.POST ------------------------------------------------------------------
$r = run(\Platform\AssetManager\Tools\CostLines\CreateCostLineTool::class,
    ['cost_type_id' => 1, 'label' => 'Handy', 'amount' => 29.9, 'frequency' => 'monthly'], $ctxA);
check('cost-lines.POST als Owner: angelegt', true, ok($r) && code($r) === null);
check('cost-lines.POST: tenant_id gesetzt (#938)', TENANT, (int) DB::table('asset_cost_lines')->value('tenant_id'));

$r = run(\Platform\AssetManager\Tools\CostLines\CreateCostLineTool::class,
    ['cost_type_id' => 2, 'label' => 'Handy', 'amount' => 29.9, 'frequency' => 'monthly'], $ctxB);
check('cost-lines.POST im Kontext-Team als Member: ACCESS_DENIED (#943)', 'ACCESS_DENIED', code($r));
check('… und nichts angelegt', 1, DB::table('asset_cost_lines')->count());

// ---- 3: fremder Tenant -----------------------------------------------------------------------
$r = run(\Platform\AssetManager\Tools\Devices\DeviceStatusOverviewTool::class, ['tenant_id' => FOREIGN], $ctxA);
check('device-status.GET fremder Tenant: NOT_FOUND (#962)', 'NOT_FOUND', code($r));
$r = run(\Platform\AssetManager\Tools\Devices\DeviceStatusOverviewTool::class, [], $ctxA);
check('device-status.GET eigener Tenant: 1 Gerät', 1, (int) (data($r)['total'] ?? -1));

// ---- 4: neue Lese-Tools ----------------------------------------------------------------------
$r = run(\Platform\AssetManager\Tools\Devices\ListDeviceEventsTool::class, [], $ctxA);
check('device-events.GET: zwei Ereignisse, neueste zuerst', ['removed', 'owner_changed'], array_column(data($r)['events'] ?? [], 'event_type'));
check('device-events.GET: Urheber aufgelöst', 'Max', data($r)['events'][1]['by'] ?? null);
$r = run(\Platform\AssetManager\Tools\Devices\ListDeviceEventsTool::class, ['event_type' => 'removed'], $ctxA);
check('device-events.GET: Filter event_type', 1, (int) (data($r)['total'] ?? -1));

$r = run(\Platform\AssetManager\Tools\Handovers\ListHandoversTool::class, ['signed' => false], $ctxA);
check('handovers.GET: nur nicht unterschriebene', [2], array_column(data($r)['handovers'] ?? [], 'id'));
$r = run(\Platform\AssetManager\Tools\Handovers\ListHandoversTool::class, ['device_id' => 1], $ctxA);
check('handovers.GET: mit Positionen', 'NB-01', data($r)['handovers'][0]['lines'][0]['device_name'] ?? null);

$r = run(\Platform\AssetManager\Tools\Costs\DatevStatusTool::class, ['month' => '2026-09'], $ctxA);
$sep = data($r)['months'][0] ?? [];
$statusByInvoice = array_column($sep['invoices'] ?? [], 'status', 'invoice');
ksort($statusByInvoice);
check('datev.GET: September gebucht + direkt', ['I-SEP' => 'direct', 'M-SEP' => 'booked'], $statusByInvoice);
check('datev.GET: September vollständig', true, $sep['fully_booked'] ?? null);
check('datev.GET: Stapel genannt', 'CTRL Umb IT Sep 2026', $sep['batches'][0]['label'] ?? null);

check_summary();
