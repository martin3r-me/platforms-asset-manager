<?php

/**
 * DATEV-Stapelkopf in den Moduleinstellungen (Discussion #26, Entscheidung Max 2026-10-15).
 *
 *  1. Die Migration befüllt nur Tenants, die schon ein DATEV-Sachkonto pflegen — mit den Werten des
 *     Mandanten 530 und der Sammel-KST 1520; ein Diktatkürzel trägt sie nicht ein.
 *  2. `datevMissing()` / `datevMeta()` am Einstellungs-Model, inklusive Wirtschaftsjahr ≠ Kalenderjahr.
 *  3. Speichern in den Einstellungen: Validierung und Normalisierung (TT.MM. ↔ MM-DD).
 *
 * Aufruf: php tests/local/datev-header-settings.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Models\AssetTeamSetting;
use Platform\AssetManager\Services\TenantContext;

// ---- Schema (Stand vor der Migration) ------------------------------------------------------

Schema::create('asset_tenants', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->string('name'); $t->boolean('is_default')->default(false); $t->timestamps();
});
Schema::create('asset_cost_centers', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->string('code')->nullable(); $t->string('name'); $t->timestamps();
});
Schema::create('asset_cost_types', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable(); $t->string('key'); $t->string('name');
    $t->string('datev_account', 8)->nullable(); $t->timestamps();
});
Schema::create('asset_team_settings', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('team_id'); $t->unsignedBigInteger('tenant_id')->nullable();
    $t->boolean('controlling_enabled')->default(false); $t->text('holder_type_rules')->nullable();
    $t->unsignedBigInteger('license_overflow_cost_center_id')->nullable(); $t->unsignedBigInteger('license_pool_cost_center_id')->nullable();
    $t->unsignedBigInteger('telephony_cost_center_id')->nullable(); $t->timestamps();
});

const TEAM = 1;

// Tenant 1 bucht per DATEV um (Sachkonto gepflegt, KST 1520 vorhanden, Einstellungszeile da).
// Tenant 2 nutzt nur das Inventar. Tenant 3 pflegt ein Sachkonto, hat aber weder 1520 noch Einstellungen.
DB::table('asset_tenants')->insert([
    ['id' => 1, 'team_id' => TEAM, 'name' => 'BHG', 'is_default' => true],
    ['id' => 2, 'team_id' => TEAM, 'name' => 'Andere', 'is_default' => false],
    ['id' => 3, 'team_id' => TEAM, 'name' => 'Neu', 'is_default' => false],
]);
$cc1520 = DB::table('asset_cost_centers')->insertGetId(['team_id' => TEAM, 'tenant_id' => 1, 'code' => '1520', 'name' => 'IT']);
$cc2000 = DB::table('asset_cost_centers')->insertGetId(['team_id' => TEAM, 'tenant_id' => 1, 'code' => '2000', 'name' => 'Event']);
DB::table('asset_cost_centers')->insert(['team_id' => TEAM, 'tenant_id' => 2, 'code' => '1520', 'name' => 'IT fremd']);
DB::table('asset_cost_types')->insert([
    ['team_id' => TEAM, 'tenant_id' => 1, 'key' => 'mobilfunk', 'name' => 'Mobilfunk', 'datev_account' => '680510'],
    ['team_id' => TEAM, 'tenant_id' => 1, 'key' => 'hardware_afa', 'name' => 'AfA', 'datev_account' => null],
    ['team_id' => TEAM, 'tenant_id' => 2, 'key' => 'mobilfunk', 'name' => 'Mobilfunk', 'datev_account' => null],
    ['team_id' => TEAM, 'tenant_id' => 3, 'key' => 'mobilfunk', 'name' => 'Mobilfunk', 'datev_account' => '680510'],
]);
DB::table('asset_team_settings')->insert([
    ['team_id' => TEAM, 'tenant_id' => 1, 'controlling_enabled' => true, 'telephony_cost_center_id' => $cc1520],
    ['team_id' => TEAM, 'tenant_id' => 2, 'controlling_enabled' => false, 'telephony_cost_center_id' => null],
]);

// ---- 1: Migration ----------------------------------------------------------------------------

$migration = require dirname(__DIR__, 2) . '/database/migrations/2026_10_15_000004_add_datev_header_to_team_settings.php';
$migration->up();
$migration->up(); // zweiter Lauf: idempotent

$row = fn (int $tenant) => DB::table('asset_team_settings')->where('tenant_id', $tenant)->first();

check('Tenant 1: Kopf vorbelegt', ['189671', '530', '01-01', 6], [$row(1)->datev_consultant, $row(1)->datev_client, $row(1)->datev_fiscal_year_start, (int) $row(1)->datev_account_length]);
check('Tenant 1: Sammel-KST 1520 des eigenen Tenants', $cc1520, (int) $row(1)->datev_source_cost_center_id);
check('Tenant 1: kein Diktatkürzel', null, $row(1)->datev_initials);
check('Tenant 1: übrige Einstellungen unberührt', [1, $cc1520], [(int) $row(1)->controlling_enabled, (int) $row(1)->telephony_cost_center_id]);
check('Tenant 2 (ohne Sachkonto): nichts vorbelegt', [null, null], [$row(2)->datev_consultant, $row(2)->datev_source_cost_center_id]);
check('Tenant 3: Einstellungszeile angelegt, ohne Sammel-KST', ['530', null], [$row(3)?->datev_client, $row(3)?->datev_source_cost_center_id]);
check('Keine doppelten Zeilen', 3, DB::table('asset_team_settings')->count());

DB::table('asset_team_settings')->where('tenant_id', 1)->update(['datev_client' => '532']);
$migration->up();
check('Erneuter Lauf überschreibt Gepflegtes nicht', '532', $row(1)->datev_client);
DB::table('asset_team_settings')->where('tenant_id', 1)->update(['datev_client' => '530']);

// ---- 2: Model ------------------------------------------------------------------------------

TenantContext::forceTenant(1);
$settings = AssetTeamSetting::withoutTenantScope()->where('tenant_id', 1)->first();

check('Fehlt nur das Diktatkürzel', ['Diktatkürzel'], $settings->datevMissing());
$settings->update(['datev_initials' => 'MW']);
check('Vollständig', [], $settings->fresh()->datevMissing());
check('Tenant 3: Sammel-KST fehlt', ['Diktatkürzel', 'Sammel-Kostenstelle (mit Code)'],
    AssetTeamSetting::withoutTenantScope()->where('tenant_id', 3)->first()->datevMissing());

$meta = $settings->fresh()->datevMeta(new DateTimeImmutable('2026-09-01'));
check('Meta für den Writer', ['189671', '530', '2026-01-01', 6, 'MW'],
    [$meta['consultant'], $meta['client'], $meta['fiscal_year_start']->format('Y-m-d'), $meta['account_length'], $meta['initials']]);

check('WJ ab 01.07., Buchungsmonat März → Vorjahr', '2025-07-01', AssetTeamSetting::fiscalYearStart('07-01', new DateTimeImmutable('2026-03-01'))->format('Y-m-d'));
check('WJ ab 01.07., Buchungsmonat Juli → laufendes Jahr', '2026-07-01', AssetTeamSetting::fiscalYearStart('07-01', new DateTimeImmutable('2026-07-31'))->format('Y-m-d'));
$err = null;
try { AssetTeamSetting::fiscalYearStart('13-01', new DateTimeImmutable('2026-03-01')); } catch (InvalidArgumentException $e) { $err = $e->getMessage(); }
check('Ungültiges WJ-Datum wird abgelehnt', true, $err !== null);

// ---- 3: Speichern in den Einstellungen -----------------------------------------------------

Gate::before(fn (?object $user = null) => true);
$make = fn () => new class extends Platform\AssetManager\Livewire\Settings\Index {
    public function teamId(): int { return TEAM; }
};

/** Ruft saveDatevHeader() und liefert die Fehler-Keys. */
function trySave($c): array
{
    try {
        $c->saveDatevHeader();
    } catch (Illuminate\Validation\ValidationException $e) {
        return array_keys($e->errors());
    }

    return array_keys($c->getErrorBag()->messages());
}

$c = $make();
$c->datevConsultant = ' 189671 ';
$c->datevClient = '530';
$c->datevFiscalYearStart = '1.7';
$c->datevAccountLength = '6';
$c->datevInitials = 'KI';
$c->datevSourceCostCenterId = $cc2000;
check('Speichern: ohne Fehler', [], trySave($c));
$saved = $row(1);
check('Speichern: WJ normalisiert (MM-DD), Anzeige TT.MM.', ['07-01', '01.07.'], [$saved->datev_fiscal_year_start, $c->datevFiscalYearStart]);
check('Speichern: getrimmt, Kürzel, Sammel-KST', ['189671', 'KI', $cc2000], [$saved->datev_consultant, $saved->datev_initials, (int) $saved->datev_source_cost_center_id]);

$c = $make();
$c->datevConsultant = '12345678';
$c->datevClient = '53O';
$c->datevFiscalYearStart = '31.02.';
$c->datevAccountLength = '9';
$c->datevInitials = 'ABC';
check('Ungültig: Formatfehler je Feld', ['datevConsultant', 'datevClient', 'datevAccountLength', 'datevInitials'], trySave($c));

$c = $make();
$c->datevConsultant = '189671'; $c->datevClient = '530'; $c->datevAccountLength = '6'; $c->datevInitials = 'KI';
$c->datevFiscalYearStart = '31.02.';
check('Ungültig: 31.02. ist kein Datum', ['datevFiscalYearStart'], trySave($c));
check('… und nichts gespeichert', '07-01', $row(1)->datev_fiscal_year_start);

$c = $make();
$c->datevConsultant = '189671'; $c->datevClient = '530'; $c->datevAccountLength = '6'; $c->datevInitials = 'KI'; $c->datevFiscalYearStart = '01.01.';
$c->datevSourceCostCenterId = (int) DB::table('asset_cost_centers')->where('tenant_id', 2)->value('id');
trySave($c);
check('Kostenstelle eines anderen Tenants wird nicht übernommen', null, $row(1)->datev_source_cost_center_id);

check_summary();
