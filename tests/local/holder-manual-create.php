<?php

/**
 * Träger von Hand anlegen (#881) und festgehaltener Träger-Typ (#896).
 *
 * Geprüft wird der ganze Weg, an dem ein manueller Träger hängt: Anlage über den HolderService
 * (Normalisierung, Eindeutigkeit ohne Rücksicht auf Schreibweise, Klassifizierung, Kostenstelle
 * tenant-gebunden), dann der **echte** Verzeichnis-Abgleich (`ImportTenantUsersJob::handle()` mit
 * einer Graph-Attrappe): er muss den manuellen Träger per UPN finden statt einen zweiten anzulegen,
 * ihn anreichern und darf ihn beim Stilllegen nicht anfassen. Dazu das MCP-Tool
 * `asset-manager.holders.POST` mit Rechte- und Tenant-Grenze.
 *
 * Aufruf: php tests/local/holder-manual-create.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Jobs\ImportTenantUsersJob;
use Platform\AssetManager\Models\AssetConnectorConfig;
use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Services\ControllingContext;
use Platform\AssetManager\Services\HolderClassifier;
use Platform\AssetManager\Services\HolderService;
use Platform\AssetManager\Services\IntuneGraphService;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Support\TeamRole;
use Platform\AssetManager\Tools\Holders\CreateHolderTool;
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
    $t->string('mobile_phone')->nullable();
    $t->string('business_phone')->nullable();
    $t->boolean('phone_overridden')->default(false);
    $t->string('source')->default('derived');
    $t->string('holder_type')->default('person');
    $t->boolean('holder_type_pinned')->default(false);
    $t->string('function_system')->nullable();
    $t->string('graph_id')->nullable();
    $t->timestamp('synced_at')->nullable();
    $t->boolean('is_active')->default(true);
    $t->timestamp('billing_excluded_at')->nullable();
    $t->string('billing_excluded_reason')->nullable();
    $t->unsignedBigInteger('billing_excluded_by')->nullable();
    $t->timestamps();
    // Wie live. SQLite vergleicht hier case-sensitiv — der Rückfall im Service muss es trotzdem finden.
    $t->unique(['tenant_id', 'user_principal_name']);
});
Schema::create('asset_team_settings', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable();
    $t->json('holder_type_rules')->nullable(); $t->boolean('controlling_enabled')->default(false); $t->timestamps();
});
Schema::create('asset_cost_centers', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->unsignedBigInteger('parent_id')->nullable();
    $t->integer('depth')->default(0); $t->string('code')->nullable(); $t->string('name')->nullable(); $t->timestamps();
});
Schema::create('asset_holder_events', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->unsignedBigInteger('holder_id');
    $t->unsignedBigInteger('user_id')->nullable(); $t->string('event_type'); $t->string('old_value')->nullable(); $t->string('new_value')->nullable();
    $t->string('description'); $t->timestamps();
});
Schema::create('asset_connector_configs', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->boolean('enabled')->default(true);
    $t->string('azure_tenant_id')->nullable(); $t->string('client_id')->nullable(); $t->text('client_secret')->nullable();
    $t->timestamp('consent_confirmed_at')->nullable(); $t->timestamp('last_sync_at')->nullable(); $t->timestamps();
});
Schema::create('asset_import_runs', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id'); $t->string('source'); $t->string('file_name')->nullable();
    $t->boolean('dry_run')->default(false); $t->string('status'); $t->string('headline')->nullable(); $t->text('error')->nullable();
    $t->json('summary')->nullable(); $t->unsignedBigInteger('user_id')->nullable(); $t->timestamp('started_at')->nullable();
    $t->integer('duration_ms')->nullable(); $t->timestamps();
});

const TEAM    = 7;
const TEAM_B  = 9;
const TENANT  = 1;
const FOREIGN = 2;

DB::table('users')->insert(['id' => 1, 'name' => 'Max']);
DB::table('asset_tenants')->insert([
    ['id' => TENANT, 'team_id' => TEAM, 'name' => 'Manuell-Kunde', 'is_default' => true],
    ['id' => FOREIGN, 'team_id' => TEAM_B, 'name' => 'Fremd', 'is_default' => true],
]);
DB::table('asset_cost_centers')->insert([
    ['id' => 10, 'team_id' => TEAM, 'tenant_id' => TENANT, 'code' => '2599', 'name' => 'IT'],
    ['id' => 11, 'team_id' => TEAM_B, 'tenant_id' => FOREIGN, 'code' => '9999', 'name' => 'Fremd'],
]);
DB::table('asset_team_settings')->insert([
    'team_id' => TEAM, 'tenant_id' => TENANT,
    'holder_type_rules' => json_encode([['type' => 'admin', 'field' => 'upn', 'match' => 'prefix', 'value' => 'adm-']]),
]);

TenantContext::forceTenant(TENANT);
HolderClassifier::forget();

$service = app(HolderService::class);

// --- Anlage: Validierung ---------------------------------------------------
$r = $service->createManual(TEAM, TENANT, ['user_principal_name' => '']);
check('leere UPN: abgelehnt', 'VALIDATION_ERROR', $r['code']);
$r = $service->createManual(TEAM, TENANT, ['user_principal_name' => 'kein-at-zeichen']);
check('UPN ohne @: abgelehnt', 'VALIDATION_ERROR', $r['code']);
$r = $service->createManual(TEAM, FOREIGN, ['user_principal_name' => 'a@b.de']);
check('fremder Tenant: NOT_FOUND', 'NOT_FOUND', $r['code']);
$r = $service->createManual(TEAM, TENANT, ['user_principal_name' => 'x@firma.de', 'holder_type' => 'chef']);
check('unbekannter Typ: abgelehnt', 'VALIDATION_ERROR', $r['code']);
$r = $service->createManual(TEAM, TENANT, ['user_principal_name' => 'x@firma.de', 'cost_center_id' => 11]);
check('Kostenstelle eines fremden Teams: abgelehnt', 'COST_CENTER_NOT_FOUND', $r['code']);
$r = $service->createManual(TEAM, TENANT, ['user_principal_name' => 'x@firma.de', 'email' => 'quatsch']);
check('ungültige E-Mail: abgelehnt', 'VALIDATION_ERROR', $r['code']);
check('nach Ablehnungen: nichts angelegt', 0, AssetHolder::withoutTenantScope()->count());

// --- Anlage: Erfolg -------------------------------------------------------
// Papierkorb-Präfix wird abgeschnitten (ADR 0023), Kostenstelle über die ID, Typ aus den Regeln.
$r = $service->createManual(TEAM, TENANT, [
    'user_principal_name' => '0b521d5646ee4b158a9c13727799dbd0max.muster@firma.de',
    'display_name'        => 'Max Muster',
    'cost_center_id'      => 10,
    'department'          => 'Küche',
]);
check('Anlage: ok', true, $r['ok']);
$max = $r['holder'];
check('Anlage: Papierkorb-Präfix entfernt', 'max.muster@firma.de', $max->user_principal_name);
check('Anlage: source manual', 'manual', $max->source);
check('Anlage: E-Mail = UPN', 'max.muster@firma.de', $max->email);
check('Anlage: Kostenstelle Code + FK', ['2599', 10], [$max->cost_center, (int) $max->cost_center_id]);
check('Anlage: Typ aus Regeln (person), nicht festgehalten', ['person', false], [$max->holder_type, (bool) $max->holder_type_pinned]);
check('Anlage: aktiv', true, (bool) $max->is_active);
check('Anlage: Verlauf zeigt die erste Kostenstelle', ['cost_center_changed', null, '2599'],
    [DB::table('asset_holder_events')->value('event_type'), DB::table('asset_holder_events')->value('old_value'), DB::table('asset_holder_events')->value('new_value')]);

$r = $service->createManual(TEAM, TENANT, ['user_principal_name' => 'adm-max@firma.de']);
check('Regel greift bei Anlage ohne Typ (adm-)', 'admin', $r['holder']->holder_type);

$r = $service->createManual(TEAM, TENANT, ['user_principal_name' => 'adm-ext@firma.de', 'holder_type' => 'external']);
check('angegebener Typ wird festgehalten', ['external', true], [$r['holder']->holder_type, (bool) $r['holder']->holder_type_pinned]);
check('… und schlägt die adm-Regel im Klassifizierer', 'external', (new HolderClassifier())->classify($r['holder']->fresh(), TENANT));

// --- Eindeutigkeit ohne Rücksicht auf Schreibweise -----------------------
$r = $service->createManual(TEAM, TENANT, ['user_principal_name' => 'MAX.Muster@Firma.DE']);
check('gleiche UPN in anderer Schreibweise: DUPLICATE', 'DUPLICATE', $r['code']);
check('… mit existing_id', (int) $max->id, $r['existing_id'] ?? null);

// --- Verzeichnis-Abgleich (echter Job, Graph-Attrappe) --------------------
DB::table('asset_connector_configs')->insert([
    'id' => 1, 'team_id' => TEAM, 'tenant_id' => TENANT, 'enabled' => true,
    // Verschlüsselt wie live — die Accessoren entschlüsseln beim Lesen.
    'azure_tenant_id' => Crypt::encryptString('aad'), 'client_id' => Crypt::encryptString('cid'),
    'client_secret' => Crypt::encryptString('sec'), 'consent_confirmed_at' => now(),
]);
// Ein Graph-Träger, den das Verzeichnis nicht mehr liefert — der Gegenbeweis, dass der Abgleich läuft.
$gone = AssetHolder::create(['team_id' => TEAM, 'tenant_id' => TENANT, 'user_principal_name' => 'weg@firma.de', 'source' => 'graph', 'holder_type' => 'person', 'is_active' => true]);
// Ein manueller Träger, den das Verzeichnis gar nicht kennt — darf NICHT stillgelegt werden.
$onlyManual = $service->createManual(TEAM, TENANT, ['user_principal_name' => 'extern@dienstleister.de'])['holder'];

$graph = new class extends IntuneGraphService {
    public ?string $lastError = null;
    public function __construct() {}
    public function getUsersWithLicenses(AssetConnectorConfig $config): ?array
    {
        return [
            // Entra schreibt die UPN anders als von Hand erfasst.
            ['id' => 'g-1', 'userPrincipalName' => 'Max.Muster@FIRMA.de', 'displayName' => 'Max Muster (Entra)',
             'department' => 'Verwaltung', 'jobTitle' => 'Koch', 'accountEnabled' => true],
            ['id' => 'g-2', 'userPrincipalName' => 'neu@firma.de', 'displayName' => 'Neu', 'accountEnabled' => true],
        ];
    }
};

(new ImportTenantUsersJob(1))->handle($graph, $service);

check('Sync: kein zweiter Max (case-insensitiv gefunden)', 1,
    AssetHolder::withoutTenantScope()->whereRaw('LOWER(user_principal_name) = ?', ['max.muster@firma.de'])->count());
$maxFresh = $max->fresh();
check('Sync: manueller Träger bleibt manual', 'manual', $maxFresh->source);
check('Sync: angereichert (Abteilung aus Entra)', 'Verwaltung', $maxFresh->department);
check('Sync: graph_id gesetzt', 'g-1', $maxFresh->graph_id);
check('Sync: Kostenstelle bleibt', 10, (int) $maxFresh->cost_center_id);
check('Sync: neuer Verzeichnis-Träger angelegt', 1, AssetHolder::withoutTenantScope()->where('user_principal_name', 'neu@firma.de')->count());
check('Reconcile: Graph-Träger ohne Verzeichnis-Eintrag stillgelegt', false, (bool) $gone->fresh()->is_active);
check('Reconcile: manueller Träger NICHT stillgelegt', true, (bool) $onlyManual->fresh()->is_active);

// --- Festhalten gegen die Entra-Gastregel (#896) --------------------------
$guest = AssetHolder::create(['team_id' => TEAM, 'tenant_id' => TENANT, 'user_principal_name' => 'gast@firma.de', 'holder_type' => 'person', 'holder_type_pinned' => true]);
$service->applyGraphProfile($guest, ['userType' => 'Guest']);
check('Gast-Kennzeichen lässt festgehaltenes person stehen', 'person', $guest->holder_type);
$guest2 = AssetHolder::create(['team_id' => TEAM, 'tenant_id' => TENANT, 'user_principal_name' => 'gast2@firma.de', 'holder_type' => 'person']);
$service->applyGraphProfile($guest2, ['userType' => 'Guest']);
check('nicht festgehalten: Gast wird external', 'external', $guest2->holder_type);
$ext = AssetHolder::make(['team_id' => TEAM, 'tenant_id' => TENANT, 'user_principal_name' => 'p_x.de#EXT#@firma.onmicrosoft.com', 'holder_type' => 'person', 'holder_type_pinned' => true]);
check('#EXT#-UPN: festgehaltenes person bleibt', 'person', (new HolderClassifier())->classify($ext, TENANT));

// --- MCP-Tool asset-manager.holders.POST ----------------------------------
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

$run = function (array $args, int $team) use ($user): array {
    TenantContext::clearOverride();
    ControllingContext::forget();
    TeamRole::flushResolved();
    $result = (new CreateHolderTool())->execute($args, new ToolContext($user, (object) ['id' => $team]));

    return json_decode(json_encode($result->toArray()), true);
};

$r = $run(['user_principal_name' => 'tool@firma.de', 'display_name' => 'Per Tool', 'cost_center' => '2599', 'holder_type' => 'service'], TEAM);
check('holders.POST als Owner: angelegt', true, $r['ok'] ?? false);
check('holders.POST: source manual + festgehalten', ['manual', true], [$r['data']['source'] ?? null, $r['data']['holder_type_pinned'] ?? null]);
check('holders.POST: Tenant aus Kontext', TENANT, $r['data']['tenant_id'] ?? null);
check('holders.POST: Verlauf mit Urheber', 1, (int) DB::table('asset_holder_events')->where('holder_id', $r['data']['id'] ?? 0)->value('user_id'));

$r = $run(['user_principal_name' => 'TOOL@firma.de'], TEAM);
check('holders.POST doppelt: DUPLICATE', 'DUPLICATE', $r['error']['code'] ?? null);

$r = $run(['user_principal_name' => 'member@firma.de'], TEAM_B);
check('holders.POST als Member: ACCESS_DENIED', 'ACCESS_DENIED', $r['error']['code'] ?? null);
check('… nichts angelegt', 0, AssetHolder::withoutTenantScope()->where('user_principal_name', 'member@firma.de')->count());

$r = $run(['user_principal_name' => 'x2@firma.de', 'tenant_id' => FOREIGN], TEAM);
check('holders.POST fremder Tenant: NOT_FOUND', 'NOT_FOUND', $r['error']['code'] ?? null);

$r = $run(['user_principal_name' => 'x3@firma.de', 'cost_center' => '7777'], TEAM);
check('holders.POST unbekannte Kostenstelle: abgelehnt', 'COST_CENTER_NOT_FOUND', $r['error']['code'] ?? null);

check_summary();
