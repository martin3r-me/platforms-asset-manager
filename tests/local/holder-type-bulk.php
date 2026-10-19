<?php

/**
 * Trägertyp-Sammelaktion auf Service-Ebene: Zählregeln, Nebenwirkungen, Neuverteilung.
 *
 * Prüft {@see Platform\AssetManager\Services\HolderTypeService} ohne Livewire und ohne Gate. Die
 * beiden teuren Fehler, die hier abgefangen werden: ein `function_system`, das beim Typwechsel
 * stehen bleibt (dann rechnet die Verteilungskaskade mit einem Konto, das keins mehr ist), und eine
 * Neuverteilung, die pro Zeile statt einmal am Ende läuft (dieselbe Periodenrechnung n-mal).
 *
 * Aufruf: php tests/local/holder-type-bulk.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Services\HolderClassifier;
use Platform\AssetManager\Services\HolderTypeService;
use Platform\AssetManager\Services\LicenseSeatAllocator;
use Platform\AssetManager\Services\TenantContext;

// --- Minimalschema --------------------------------------------------------
Schema::create('asset_holders', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('user_principal_name')->nullable();
    $t->string('display_name')->nullable();
    $t->string('holder_type')->default('person');
    $t->boolean('holder_type_pinned')->default(false);   // #896
    $t->string('function_system')->nullable();
    $t->boolean('is_active')->default(true);
    $t->timestamps();
});

Schema::create('asset_team_settings', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->json('holder_type_rules')->nullable();
    $t->timestamps();
});

Schema::create('asset_license_contract_lines', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('period_label')->nullable();
    $t->timestamps();
});

TenantContext::forceTenant(1);

const TEAM   = 7;
const TENANT = 1;

/** Zählt die Allocator-Läufe, statt die echte Periodenrechnung anzuwerfen. */
$allocator = new class extends LicenseSeatAllocator {
    public int $calls = 0;

    public function allocateForPeriod(int $teamId, int $tenantId, string $periodLabel, bool $dryRun = false): array
    {
        $this->calls++;

        return [];
    }
};

$service = new HolderTypeService(new HolderClassifier(), $allocator);

function holder(string $upn, string $type = 'person', ?string $system = null, int $team = TEAM): AssetHolder
{
    return AssetHolder::create([
        'team_id'             => $team,
        'tenant_id'           => TENANT,
        'user_principal_name' => $upn,
        'display_name'        => $upn,
        'holder_type'         => $type,
        'function_system'     => $system,
        'is_active'           => true,
    ]);
}

// --- Vorschau schreibt nicht ---------------------------------------------
$a = holder('a@kunde.de');
$r = $service->setType(TEAM, TENANT, [$a->id], AssetHolder::TYPE_ADMIN, true);

check('dry_run: ok', true, $r['ok']);
check('dry_run: meldet 1 Aenderung', 1, $r['summary']['updated']);
check('dry_run: Status would_update', 'would_update', $r['results'][0]['status']);
check('dry_run: schreibt NICHT', 'person', $a->fresh()->holder_type);
check('dry_run: kein Allocator-Lauf', 0, $allocator->calls);

// --- Echter Lauf ----------------------------------------------------------
$r = $service->setType(TEAM, TENANT, [$a->id], AssetHolder::TYPE_ADMIN, false);

check('Lauf: schreibt', 'admin', $a->fresh()->holder_type);
check('Lauf: Status updated', 'updated', $r['results'][0]['status']);
check('Lauf: Ziel-Label', 'Admin-Account', $r['target']);
check('admin ist kein Funktionswechsel -> keine Neuverteilung', 0, $allocator->calls);

// --- Unveraendert zaehlt nicht als Aenderung -----------------------------
$r = $service->setType(TEAM, TENANT, [$a->id], AssetHolder::TYPE_ADMIN, false);
check('gleicher Typ: 0 geaendert', 0, $r['summary']['updated']);
check('gleicher Typ: 1 unveraendert', 1, $r['summary']['unchanged']);

// --- function_system: Verlust und Fehlen ---------------------------------
$f = holder('f@kunde.de', AssetHolder::TYPE_FUNCTION, 'Warenwirtschaft');
$r = $service->setType(TEAM, TENANT, [$f->id], AssetHolder::TYPE_PERSON, true);

check('function -> person: meldet clears_system', 1, $r['summary']['clears_system']);
check('function -> person: Vorschau laesst System stehen', 'Warenwirtschaft', $f->fresh()->function_system);

$service->setType(TEAM, TENANT, [$f->id], AssetHolder::TYPE_PERSON, false);
check('function -> person: System wird genullt', null, $f->fresh()->function_system);
check('function -> person: loest Neuverteilung aus (ohne Vertragszeile: kein Lauf)', 0, $allocator->calls);

$p = holder('p@kunde.de');
$r = $service->setType(TEAM, TENANT, [$p->id], AssetHolder::TYPE_FUNCTION, true);
check('person -> function ohne System: meldet needs_system', 1, $r['summary']['needs_system']);

$q = holder('q@kunde.de', AssetHolder::TYPE_FUNCTION, 'Kasse');
$service->setType(TEAM, TENANT, [$q->id], AssetHolder::TYPE_FUNCTION, false);
check('function -> function: System bleibt', 'Kasse', $q->fresh()->function_system);

// --- Neuverteilung: genau EINMAL, nicht je Zeile -------------------------
// Erst ab hier existiert eine Vertragszeile — vorher steigt redistribute() bewusst frueh aus.
DB::table('asset_license_contract_lines')->insert([
    'team_id' => TEAM, 'tenant_id' => TENANT, 'period_label' => '2026-08',
]);

$allocator->calls = 0;
$many = [];
for ($i = 0; $i < 20; $i++) {
    $many[] = holder("bulk{$i}@kunde.de")->id;
}

$r = $service->setType(TEAM, TENANT, $many, AssetHolder::TYPE_FUNCTION, false);

check('20 Traeger auf function: alle geaendert', 20, $r['summary']['updated']);
check('20 Traeger auf function: Allocator GENAU EINMAL', 1, $allocator->calls);
check('Meldung nennt die Neuverteilung', true, str_contains($r['message'], 'Lizenzverteilung'));

// Ein Wechsel, der die Funktionskonto-Grenze nicht ueberquert, laesst sie in Ruhe.
$allocator->calls = 0;
$s1 = holder('s1@kunde.de', AssetHolder::TYPE_ADMIN);
$service->setType(TEAM, TENANT, [$s1->id], AssetHolder::TYPE_SERVICE, false);
check('admin -> service: keine Neuverteilung', 0, $allocator->calls);

check('affectsAllocation person->function', true, $service->affectsAllocation('person', 'function'));
check('affectsAllocation function->admin', true, $service->affectsAllocation('function', 'admin'));
check('affectsAllocation admin->service', false, $service->affectsAllocation('admin', 'service'));

// --- overrides_rule + Festhalten (#896) -----------------------------------
// Frueher `reverts_on_sync`: der naechste Sync stellte den Typ zurueck. Seit dem Festhalten tut er
// das nicht mehr — der Hinweis bleibt als `overrides_rule`, und der Klassifizierer laesst den Typ stehen.
// Synthetische Funktions-UPN: Regel 1 des Classifiers.
$syn = holder('kasse' . HolderClassifier::FUNCTION_UPN_SUFFIX, AssetHolder::TYPE_FUNCTION);
$r   = $service->setType(TEAM, TENANT, [$syn->id], AssetHolder::TYPE_PERSON, true);
check('synthetische UPN -> person: overrides_rule', 1, $r['summary']['overrides_rule']);
$service->setType(TEAM, TENANT, [$syn->id], AssetHolder::TYPE_PERSON, false);
check('synthetische UPN -> person: festgehalten', true, $syn->fresh()->holder_type_pinned);
check('… und der Klassifizierer laesst person stehen', 'person', (new HolderClassifier())->classify($syn->fresh(), TENANT));

// Tenant-Regel: adm-Praefix wuerde ohne Festhalten wieder als admin erkannt.
DB::table('asset_team_settings')->insert([
    'team_id'           => TEAM,
    'tenant_id'         => TENANT,
    'holder_type_rules' => json_encode([['type' => 'admin', 'field' => 'upn', 'match' => 'prefix', 'value' => 'adm-']]),
]);
HolderClassifier::forget();   // Regeln sind request-lokal memoisiert

$adm = holder('adm-hans@kunde.de', AssetHolder::TYPE_ADMIN);
$r   = $service->setType(TEAM, TENANT, [$adm->id], AssetHolder::TYPE_PERSON, true);
check('Tenant-Regel -> person: overrides_rule', 1, $r['summary']['overrides_rule']);
$service->setType(TEAM, TENANT, [$adm->id], AssetHolder::TYPE_PERSON, false);
check('Tenant-Regel -> person: Sync-Klassifizierung bleibt person', 'person', (new HolderClassifier())->classify($adm->fresh(), TENANT));

$plain = holder('plain@kunde.de', AssetHolder::TYPE_ADMIN);
$r     = $service->setType(TEAM, TENANT, [$plain->id], AssetHolder::TYPE_PERSON, true);
check('ohne passende Regel: kein overrides_rule', 0, $r['summary']['overrides_rule']);

$r = $service->setType(TEAM, TENANT, [$plain->id], AssetHolder::TYPE_SERVICE, true);
check('Regel ergibt nur Default: kein overrides_rule', 0, $r['summary']['overrides_rule']);

// Typ stimmt schon, stammt aber aus einer Regel: nur festhalten.
$ruled = holder('adm-eva@kunde.de', AssetHolder::TYPE_ADMIN);
$r     = $service->setType(TEAM, TENANT, [$ruled->id], AssetHolder::TYPE_ADMIN, true);
check('gleicher Typ, nicht festgehalten: pinned_only', 1, $r['summary']['pinned_only']);
check('… kein Typwechsel gezaehlt', 0, $r['summary']['updated']);
$service->setType(TEAM, TENANT, [$ruled->id], AssetHolder::TYPE_ADMIN, false);
check('… danach festgehalten', true, $ruled->fresh()->holder_type_pinned);

// Festhalten loesen: Regeln entscheiden sofort neu, Stufe 2 haelt den alten Typ NICHT fest.
$r = $service->releasePin(TEAM, TENANT, [$adm->id], true);
check('releasePin Vorschau: 1 wuerde freigegeben', 1, $r['summary']['updated']);
check('releasePin Vorschau: schreibt nicht', true, $adm->fresh()->holder_type_pinned);
$service->setType(TEAM, TENANT, [$plain->id], AssetHolder::TYPE_SERVICE, false);
$r = $service->releasePin(TEAM, TENANT, [$adm->id, $plain->id]);
check('releasePin: adm-Traeger wieder admin (Regel)', 'admin', $adm->fresh()->holder_type);
check('releasePin: nicht mehr festgehalten', false, $adm->fresh()->holder_type_pinned);
check('releasePin: Typwechsel gezaehlt (beide)', 2, $r['summary']['type_changed']);
$plainFresh = $plain->fresh();
check('releasePin: plain (festgehalten service) faellt auf person', 'person', $plainFresh->holder_type);
check('releasePin: ruleTypeFor plain', 'person', $service->ruleTypeFor($plainFresh, TENANT));

// --- Grenzen --------------------------------------------------------------
$fremd = holder('fremd@kunde.de', AssetHolder::TYPE_PERSON, null, 99);
$r     = $service->setType(TEAM, TENANT, [$fremd->id], AssetHolder::TYPE_ADMIN, false);

check('fremdes Team: als missing gemeldet', 1, $r['summary']['missing']);
check('fremdes Team: unveraendert', 'person', $fremd->fresh()->holder_type);

$r = $service->setType(TEAM, TENANT, [], AssetHolder::TYPE_ADMIN, false);
check('leere Liste: nicht ok', false, $r['ok']);
check('leere Liste: Grund', 'no_holders', $r['reason']);

$r = $service->setType(TEAM, TENANT, [$a->id], 'quatsch', false);
check('unbekannter Typ: nicht ok', false, $r['ok']);
check('unbekannter Typ: Grund', 'bad_type', $r['reason']);

$b = holder('b@kunde.de');
$r = $service->setType(TEAM, TENANT, [$b->id, $b->id, $b->id], AssetHolder::TYPE_ADMIN, true);
check('doppelte IDs zaehlen einmal', 1, $r['summary']['total']);

check_summary();
