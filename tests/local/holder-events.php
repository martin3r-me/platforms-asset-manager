<?php

/**
 * Verlauf am Asset-Träger (#897): Kostenstelle und Abrechnungs-Ausnahme.
 *
 * Der Verlauf wird aus den Model-Events des Trägers geschrieben, nicht an jeder Aufrufstelle. Geprüft
 * wird deshalb über **jeden** Schreibweg, dass ein Eintrag mit dem richtigen Urheber entsteht:
 * direktes Speichern (UI-Detailseite), BillingExclusionService (Detailseite/Sammelaktion), MCP
 * `holder.PUT`, `holders.cost-center.bulk.PUT`, `holders.billing-exclusion.bulk.PUT`, das Löschen
 * einer Kostenstelle — und dass nichts entsteht, wo sich nichts ändert. Dazu die Grenzen:
 * Anonymisierung leert den Freitext-Grund auch im Verlauf, Zusammenführen hängt ihn um.
 *
 * Aufruf: php tests/local/holder-events.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Models\AssetHolderEvent;
use Platform\AssetManager\Services\BillingExclusionService;
use Platform\AssetManager\Services\ControllingContext;
use Platform\AssetManager\Services\HolderMergeService;
use Platform\AssetManager\Services\HolderService;
use Platform\AssetManager\Services\MasterDataDeletionService;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Support\TeamRole;
use Platform\AssetManager\Tools\Holders\BulkAssignCostCenterTool;
use Platform\AssetManager\Tools\Holders\BulkSetBillingExclusionTool;
use Platform\AssetManager\Tools\Holders\UpdateHolderTool;
use Platform\Core\Contracts\ToolContext;

// --- Minimalschema --------------------------------------------------------
Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('name')->nullable(); $t->timestamps(); });
Schema::create('asset_tenants', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->string('name'); $t->boolean('is_default')->default(false); $t->timestamps(); $t->softDeletes();
});
Schema::create('asset_tenant_selections', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('selected_tenant_id')->nullable(); $t->timestamps();
});
Schema::create('asset_holders', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('user_principal_name')->nullable();
    $t->string('display_name')->nullable();
    $t->string('email')->nullable();
    $t->string('department')->nullable();
    $t->string('job_title')->nullable();
    $t->string('cost_center')->nullable();
    $t->unsignedBigInteger('cost_center_id')->nullable();
    $t->string('source')->default('graph');
    $t->string('holder_type')->default('person');
    $t->boolean('holder_type_pinned')->default(false);
    $t->string('function_system')->nullable();
    $t->boolean('is_active')->default(true);
    $t->timestamp('billing_excluded_at')->nullable();
    $t->string('billing_excluded_reason')->nullable();
    $t->unsignedBigInteger('billing_excluded_by')->nullable();
    $t->json('raw_data')->nullable();
    $t->timestamps();
});
Schema::create('asset_holder_events', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->unsignedBigInteger('holder_id');
    $t->unsignedBigInteger('user_id')->nullable(); $t->string('event_type'); $t->string('old_value')->nullable(); $t->string('new_value')->nullable();
    $t->string('description'); $t->timestamps();
});
Schema::create('asset_cost_centers', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->unsignedBigInteger('parent_id')->nullable();
    $t->integer('depth')->default(0); $t->string('code')->nullable(); $t->string('name')->nullable(); $t->boolean('is_active')->default(true);
    $t->integer('sort_order')->default(0); $t->timestamps(); $t->softDeletes();
});
Schema::create('asset_team_settings', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable();
    $t->boolean('controlling_enabled')->default(false); $t->json('holder_type_rules')->nullable();
    $t->unsignedBigInteger('license_pool_cost_center_id')->nullable(); $t->unsignedBigInteger('license_overflow_cost_center_id')->nullable();
    $t->unsignedBigInteger('telephony_cost_center_id')->nullable(); $t->unsignedBigInteger('datev_source_cost_center_id')->nullable();
    $t->timestamps();
});
Schema::create('asset_billing_profiles', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id'); $t->string('name');
    $t->boolean('active')->default(true); $t->string('currency')->nullable(); $t->timestamps();
});
foreach (['asset_cost_lines', 'asset_devices', 'asset_user_licenses'] as $table) {
    Schema::create($table, function (Blueprint $t) {
        $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable();
        $t->unsignedBigInteger('cost_center_id')->nullable(); $t->string('user_principal_name')->nullable();
        $t->string('display_name')->nullable(); $t->string('user_display_name')->nullable(); $t->string('sku_id')->nullable();
        $t->json('raw_data')->nullable(); $t->timestamps(); $t->softDeletes();
    });
}
Schema::create('asset_handovers', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->unsignedBigInteger('holder_id')->nullable();
    $t->string('signer_name')->nullable(); $t->string('status')->default('open'); $t->timestamps(); $t->softDeletes();
});

const TEAM   = 7;
const TEAM_B = 9;
const TENANT = 1;

DB::table('users')->insert([['id' => 1, 'name' => 'Max'], ['id' => 2, 'name' => 'Eva']]);
DB::table('asset_tenants')->insert(['id' => TENANT, 'team_id' => TEAM, 'name' => 'Broich', 'is_default' => true]);
DB::table('asset_cost_centers')->insert([
    ['id' => 10, 'team_id' => TEAM, 'tenant_id' => TENANT, 'code' => '2599', 'name' => 'IT'],
    ['id' => 11, 'team_id' => TEAM, 'tenant_id' => TENANT, 'code' => '2600', 'name' => 'Küche'],
    ['id' => 12, 'team_id' => TEAM, 'tenant_id' => TENANT, 'code' => '8090', 'name' => 'Lager'],
]);
DB::table('asset_team_settings')->insert(['team_id' => TEAM, 'tenant_id' => TENANT]);

TenantContext::forceTenant(TENANT);

function holder(string $upn, array $attrs = []): AssetHolder
{
    return AssetHolder::create(array_merge([
        'team_id' => TEAM, 'tenant_id' => TENANT, 'user_principal_name' => $upn, 'display_name' => $upn,
        'holder_type' => 'person', 'is_active' => true,
    ], $attrs));
}

/** @return list<array{type: string, old: ?string, new: ?string, by: ?int}> */
function events(AssetHolder $h): array
{
    return DB::table('asset_holder_events')->where('holder_id', $h->id)->orderBy('id')->get()
        ->map(fn ($e) => ['type' => $e->event_type, 'old' => $e->old_value, 'new' => $e->new_value, 'by' => $e->user_id !== null ? (int) $e->user_id : null])
        ->all();
}

// --- Direktes Speichern (Detailseite) -------------------------------------
$a = holder('a@broich.de');
check('Anlage ohne Kostenstelle: kein Eintrag', [], events($a));

$a->update(['cost_center' => '2599', 'cost_center_id' => 10]);
check('Kostenstelle gesetzt: Eintrag (System, ohne Auth)', [['type' => 'cost_center_changed', 'old' => null, 'new' => '2599', 'by' => null]], events($a));

$a->update(['department' => 'Neu', 'job_title' => 'Koch']);
check('Abteilung/Position: kein Eintrag (nicht geldwirksam)', 1, count(events($a)));

$a->update(['cost_center' => '2599', 'cost_center_id' => 10]);
check('gleiche Kostenstelle erneut: kein Eintrag', 1, count(events($a)));

AssetHolderEvent::actingAs(2, fn () => $a->update(['cost_center' => '2600', 'cost_center_id' => 11]));
check('Wechsel mit Urheber', ['type' => 'cost_center_changed', 'old' => '2599', 'new' => '2600', 'by' => 2], events($a)[1]);

// --- BillingExclusionService ----------------------------------------------
$svc = app(BillingExclusionService::class);
$b   = holder('b@broich.de');

$svc->setExclusion(TEAM, TENANT, [$b->id], true, 'Geschäftsführung', 1, true);
check('Vorschau schreibt keinen Verlauf', [], events($b));

$svc->setExclusion(TEAM, TENANT, [$b->id], true, 'Geschäftsführung', 1);
check('Ausnahme gesetzt: Eintrag mit Grund + Urheber', [['type' => 'billing_excluded', 'old' => null, 'new' => 'Geschäftsführung', 'by' => 1]], events($b));

$svc->setExclusion(TEAM, TENANT, [$b->id], true, 'Geschäftsführung', 1);
check('dieselbe Ausnahme erneut: kein Eintrag', 1, count(events($b)));

$svc->setExclusion(TEAM, TENANT, [$b->id], true, 'Sonderabsprache', 2);
check('Grund geändert', ['type' => 'billing_reason_changed', 'old' => 'Geschäftsführung', 'new' => 'Sonderabsprache', 'by' => 2], events($b)[1]);

AssetHolderEvent::actingAs(1, fn () => $svc->setExclusion(TEAM, TENANT, [$b->id], false, null, 1));
check('Ausnahme aufgehoben (Urheber aus actingAs)', ['type' => 'billing_included', 'old' => 'Sonderabsprache', 'new' => null, 'by' => 1], events($b)[2]);

// --- MCP-Tools ------------------------------------------------------------
$user = new class implements \Illuminate\Contracts\Auth\Authenticatable {
    public int $id = 1;
    public ?int $current_team_id = TEAM;
    public string $name = 'Max';
    public function teams(): object
    {
        return new class {
            private ?int $team = null;
            public function where($column, $value = null): static { $this->team = (int) $value; return $this; }
            public function first(): ?object
            {
                $role = [TEAM => 'owner', TEAM_B => 'member'][$this->team] ?? null;
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
$ctx = new ToolContext($user, (object) ['id' => TEAM]);

$run = function (string $class, array $args) use ($ctx): array {
    TenantContext::clearOverride();
    ControllingContext::forget();
    TeamRole::flushResolved();

    return json_decode(json_encode((new $class())->execute($args, $ctx)->toArray()), true);
};

$c = holder('c@broich.de');
$r = $run(UpdateHolderTool::class, ['id' => $c->id, 'cost_center' => '8090', 'billing_excluded' => true, 'billing_exclusion_reason' => 'Elternzeit']);
check('holder.PUT: ok', true, $r['ok'] ?? false);
check('holder.PUT: zwei Einträge mit Urheber', [
    ['type' => 'cost_center_changed', 'old' => null, 'new' => '8090', 'by' => 1],
    ['type' => 'billing_excluded', 'old' => null, 'new' => 'Elternzeit', 'by' => 1],
], events($c));

$r = $run(UpdateHolderTool::class, ['id' => $c->id, 'billing_excluded' => true]);
check('holder.PUT ohne Grund: abgelehnt, kein Eintrag (Rollback)', [false, 2], [$r['ok'] ?? null, count(events($c))]);

$d = holder('d@broich.de');
$e = holder('e@broich.de', ['cost_center' => '2599', 'cost_center_id' => 10]);
$before = count(events($e));
$run(BulkAssignCostCenterTool::class, ['cost_center_code' => '2599', 'employee_ids' => [$d->id, $e->id], 'dry_run' => true]);
check('cost-center.bulk Vorschau: kein Eintrag', [0, $before], [count(events($d)), count(events($e))]);
$run(BulkAssignCostCenterTool::class, ['cost_center_code' => '2599', 'employee_ids' => [$d->id, $e->id]]);
check('cost-center.bulk: Eintrag nur beim geänderten, mit Urheber', [[['type' => 'cost_center_changed', 'old' => null, 'new' => '2599', 'by' => 1]], $before],
    [events($d), count(events($e))]);

$run(BulkSetBillingExclusionTool::class, ['holder_ids' => [$d->id], 'excluded' => true, 'reason' => 'Test']);
$run(BulkSetBillingExclusionTool::class, ['holder_ids' => [$d->id], 'excluded' => false]);
check('billing-exclusion.bulk: setzen + aufheben, beide mit Urheber', [['billing_excluded', 1], ['billing_included', 1]],
    array_map(fn ($x) => [$x['type'], $x['by']], array_slice(events($d), 1)));

// --- Kostenstelle löschen -------------------------------------------------
$f = holder('f@broich.de', ['cost_center' => '8090', 'cost_center_id' => 12]);
$r = app(MasterDataDeletionService::class)->deleteCostCenter(TEAM, 12);
check('Kostenstelle löschen: ok', true, $r['ok']);
check('… Träger verliert Code und FK', [null, null], [$f->fresh()->cost_center, $f->fresh()->cost_center_id]);
check('… und der Verlust steht im Verlauf', ['type' => 'cost_center_changed', 'old' => '8090', 'new' => null, 'by' => null], events($f)[1] ?? null);

// --- Anonymisierung leert den Grund auch im Verlauf (ADR 0005) -----------
$g = holder('g@broich.de');
$svc->setExclusion(TEAM, TENANT, [$g->id], true, 'Elternzeit Frau G.', 1);
$svc->setExclusion(TEAM, TENANT, [$g->id], true, 'ausgeschieden 09/2026', 1);
app(HolderService::class)->anonymize($g->fresh());
check('Anonymisierung: kein neuer Eintrag', 2, count(events($g)));
check('Anonymisierung: Freitext im Verlauf geleert', [[null, null], [null, null]], array_map(fn ($x) => [$x['old'], $x['new']], events($g)));
check('Anonymisierung: Tatsache bleibt (Art + Urheber)', [['billing_excluded', 1], ['billing_reason_changed', 1]], array_map(fn ($x) => [$x['type'], $x['by']], events($g)));

// --- Zusammenführen hängt den Verlauf um ----------------------------------
$orig = holder('h@broich.de');
$dup  = holder('0b521d5646ee4b158a9c13727799dbd0h@broich.de', ['cost_center' => '2599', 'cost_center_id' => 10]);
check('Dublette hat Verlauf', 1, count(events($dup)));
app(HolderMergeService::class)->mergeInto($dup, $orig);
check('nach dem Zusammenführen hängt kein Verlauf mehr an der gelöschten Dublette', 0, DB::table('asset_holder_events')->where('holder_id', $dup->id)->count());
check('… sondern am Ziel (umgehängt + Lückenfüllung)', 2, count(events($orig)));

// --- Tenant-Scope des Verlaufs --------------------------------------------
DB::table('asset_holder_events')->insert(['team_id' => TEAM, 'tenant_id' => 2, 'holder_id' => $a->id, 'event_type' => 'cost_center_changed', 'description' => 'fremd']);
check('Verlauf am Träger: fremder Tenant unsichtbar', 2, $a->events()->count());

check_summary();
