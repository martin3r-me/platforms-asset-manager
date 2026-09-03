<?php

/**
 * Abrechnungs-Ausnahme: die drei Stufen der Zählregel (ADR 0024).
 *
 * Geprüft wird, was Geld kostet, wenn es falsch ist:
 *  - dass eine Einzelausnahme mit **Grund und Datum** in der Vorschau landet und nicht bloß als
 *    fehlende Zeile,
 *  - dass die Regel-Erweiterungen am Profil (Externe, Namensmuster) greifen, ohne die bestehenden
 *    Ausschlussgründe zu verändern,
 *  - dass die Mengen-/Geldwirkung einer Sammelaktion **vor** dem Schreiben stimmt — auch im
 *    Grenzfall, in dem derselbe Mensch zweimal im Verzeichnis steht,
 *  - dass eine Ausnahme ohne Grund abgelehnt wird.
 *
 * Aufruf: php tests/local/billing-exclusion.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Models\AssetBillingProfile;
use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Models\AssetUserLicense;
use Platform\AssetManager\Services\BillableHolderResolver;
use Platform\AssetManager\Services\BillingExclusionService;
use Platform\AssetManager\Services\BillingUnitPriceResolver;
use Platform\AssetManager\Services\TenantContext;

// --- Minimalschema --------------------------------------------------------
Schema::create('asset_holders', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('user_principal_name')->nullable();
    $t->string('display_name')->nullable();
    $t->string('email')->nullable();
    $t->string('department')->nullable();
    $t->string('holder_type')->default('person');
    $t->boolean('is_active')->default(true);
    $t->timestamp('billing_excluded_at')->nullable();
    $t->string('billing_excluded_reason')->nullable();
    $t->unsignedBigInteger('billing_excluded_by')->nullable();
    $t->timestamps();
});

Schema::create('asset_user_licenses', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('sku_id')->nullable();
    $t->string('user_principal_name')->nullable();
    $t->timestamps();
});

Schema::create('asset_billing_profiles', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id');
    $t->string('name');
    $t->unsignedBigInteger('easybill_customer_id')->nullable();
    $t->string('commerce_sku')->nullable();
    $t->unsignedInteger('fallback_unit_price_cents')->nullable();
    $t->string('basis')->default('licensed');
    $t->boolean('count_external')->default(true);
    $t->json('exclude_domains')->nullable();
    $t->json('exclude_patterns')->nullable();
    $t->unsignedTinyInteger('vat_percent')->nullable();
    $t->string('currency', 3)->default('EUR');
    $t->boolean('active')->default(true);
    $t->timestamps();
});

TenantContext::forceTenant(1);

const TEAM = 7;

// --- Bestand --------------------------------------------------------------
// Die drei Fallgruppen, die der Anlass für ADR 0024 waren, plus die Grenzfälle der Kaskade.
$holders = [
    // [UPN, Typ, ausgenommen-Grund oder null]
    ['a.muster@broichcatering.com',   'person',   null],   // zaehlt
    ['b.beispiel@broichcatering.com', 'person',   null],   // zaehlt
    ['gf@broichcatering.com',         'person',   'Geschäftsführung, laut Vertrag nicht berechnet'],
    ['test-kasse@broichcatering.com', 'person',   null],   // faellt per Muster heraus
    ['dienstleister@broichcatering.com', 'external', null], // faellt, sobald count_external=false
    ['c.ohne@broichcatering.com',     'person',   null],   // ohne Lizenz -> Regel haelt heraus
];

foreach ($holders as [$upn, $type, $excludedReason]) {
    AssetHolder::create([
        'team_id' => TEAM, 'tenant_id' => 1,
        'user_principal_name' => $upn, 'display_name' => $upn,
        'holder_type' => $type, 'is_active' => true,
        'billing_excluded_at'     => $excludedReason ? now()->subDays(400) : null,
        'billing_excluded_reason' => $excludedReason,
        'billing_excluded_by'     => $excludedReason ? 22 : null,
    ]);
}

foreach (['a.muster@broichcatering.com', 'b.beispiel@broichcatering.com', 'gf@broichcatering.com',
          'test-kasse@broichcatering.com', 'dienstleister@broichcatering.com'] as $upn) {
    AssetUserLicense::create([
        'team_id' => TEAM, 'tenant_id' => 1, 'sku_id' => 'sku-1', 'user_principal_name' => $upn,
    ]);
}

$profile = AssetBillingProfile::create([
    'team_id' => TEAM, 'tenant_id' => 1,
    'name' => 'Broich',
    'easybill_customer_id' => 2636491004,
    'fallback_unit_price_cents' => 8000,
    'basis' => AssetBillingProfile::BASIS_LICENSED,
    'count_external' => true,
    'currency' => 'EUR',
    'active' => true,
]);

$resolver = new BillableHolderResolver();

// --- Stufe 3: Einzelausnahme ---------------------------------------------
$counted = $resolver->resolve($profile);
$reasons = array_column($counted['skipped'], 'reason', 'upn');

check('Ausnahme zaehlt nicht mit', 4, count($counted['principals']));
check('Ausschlussgrund nennt Grund UND Datum', true,
    str_starts_with($reasons['gf@broichcatering.com'] ?? '', 'Einzelausnahme: Geschäftsführung')
    && str_contains($reasons['gf@broichcatering.com'] ?? '', 'seit '));
check('Ausnahmen werden aggregiert ausgewiesen', 1, count($counted['exceptions']));
check('Ausnahme kennt ihr Alter (Verrottungsschutz)', 400, $counted['exceptions'][0]['days']);
check('Regel-Ausschluss bleibt unberuehrt', 'keine Microsoft-Lizenz', $reasons['c.ohne@broichcatering.com'] ?? null);

// --- Stufe 2: Namensmuster ----------------------------------------------
$profile->exclude_patterns = [
    ['field' => 'upn', 'match' => 'prefix', 'value' => 'TEST-', 'label' => 'Testkonten'],
    ['field' => 'upn', 'match' => 'prefix', 'value' => '', 'label' => 'kaputt — muss ignoriert werden'],
];
$counted = $resolver->resolve($profile);
$reasons = array_column($counted['skipped'], 'reason', 'upn');

check('Muster greift case-insensitiv', 'Ausschlussmuster (Testkonten)', $reasons['test-kasse@broichcatering.com'] ?? null);
check('Muster: Menge sinkt um eins', 3, count($counted['principals']));
check('Kaputtes Muster wirft nichts weg', 3, count($counted['principals']));

// --- Stufe 2: Externe ----------------------------------------------------
$profile->count_external = false;
$counted = $resolver->resolve($profile);
$reasons = array_column($counted['skipped'], 'reason', 'upn');

check('Externe fallen mit dem Schalter heraus', 2, count($counted['principals']));
check('Externe: eigener Ausschlussgrund', 'Externe zählen in diesem Profil nicht mit',
    $reasons['dienstleister@broichcatering.com'] ?? null);

// Zurueck auf den Ausgangszustand: die folgende Wirkungsrechnung soll gegen die Regel pruefen,
// nicht gegen die eben gesetzten Sonderfaelle.
$profile->count_external   = true;
$profile->exclude_patterns = null;
$profile->save();

// --- Wirkung einer Sammelaktion -----------------------------------------
$service = new BillingExclusionService($resolver, new BillingUnitPriceResolver());

$muster = AssetHolder::where('user_principal_name', 'a.muster@broichcatering.com')->first();
$ohne   = AssetHolder::where('user_principal_name', 'c.ohne@broichcatering.com')->first();

$dry = $service->setExclusion(TEAM, 1, [$muster->id], true, 'Testlauf', 22, true);

check('Vorschau schreibt nicht', null, AssetHolder::find($muster->id)->billing_excluded_at);
check('Vorschau: Menge vorher', 4, $dry['impact'][0]['quantity_before']);
check('Vorschau: Menge nachher', 3, $dry['impact'][0]['quantity_after']);
check('Vorschau: Geldwirkung in Cent', -8000, $dry['impact'][0]['delta_net_cents']);
check('Vorschau: Meldung nennt die Mengen', true, str_contains($dry['message'], '4 → 3'));

// Ein Traeger, den die Regel ohnehin heraushaelt, aendert die Menge NICHT — die Vorschau muss das
// sagen, sonst wirkt die Aktion wie ein Rabatt, der nie eintritt.
$dryNoop = $service->setExclusion(TEAM, 1, [$ohne->id], true, 'ohne Lizenz sowieso', 22, true);
check('Ohne Mengenwirkung: delta 0', 0, $dryNoop['impact'][0]['delta_quantity']);
check('Ohne Mengenwirkung: Meldung sagt es', true, str_contains($dryNoop['message'], 'ändert sich dadurch nicht'));

// --- Grenzfall: derselbe Mensch zweimal im Verzeichnis -------------------
// Eine Schnittmengen-Rechnung ueber UPNs wuerde hier −1 melden, obwohl die Menge gleich bleibt:
// die zweite Zeile zaehlt weiter. Deshalb rechnet der Service mit der Regel selbst.
$twin = AssetHolder::create([
    'team_id' => TEAM, 'tenant_id' => 1,
    'user_principal_name' => 'A.MUSTER@BROICHCATERING.COM', 'display_name' => 'Doppelter Muster',
    'holder_type' => 'person', 'is_active' => true,
]);
AssetUserLicense::create([
    'team_id' => TEAM, 'tenant_id' => 1, 'sku_id' => 'sku-1',
    'user_principal_name' => 'A.MUSTER@BROICHCATERING.COM',
]);

$dryTwin = $service->setExclusion(TEAM, 1, [$twin->id], true, 'Zweitzeile', 22, true);
check('Doppelzeile: Menge bleibt gleich', 0, $dryTwin['impact'][0]['delta_quantity']);

$twin->delete();

// --- Schreiben -----------------------------------------------------------
$done = $service->setExclusion(TEAM, 1, [$muster->id], true, 'Sonderabsprache 2026', 22, false);
$muster->refresh();

check('Schreiben: Zeitstempel gesetzt', true, $muster->billing_excluded_at !== null);
check('Schreiben: Grund gesetzt', 'Sonderabsprache 2026', $muster->billing_excluded_reason);
check('Schreiben: Urheber gesetzt', 22, (int) $muster->billing_excluded_by);
check('Schreiben: Menge sinkt tatsaechlich', 3, count($resolver->resolve($profile)['principals']));
check('Schreiben: Meldung nennt Geldwirkung', true, str_contains($done['message'], '80,00 EUR'));

// Derselbe Grund erneut = Nichtereignis; ein ANDERER Grund ist eine bewusste Korrektur.
$again = $service->setExclusion(TEAM, 1, [$muster->id], true, 'Sonderabsprache 2026', 22, false);
check('Gleicher Grund erneut: unveraendert', 1, $again['summary']['unchanged']);

$corrected = $service->setExclusion(TEAM, 1, [$muster->id], true, 'ausgeschieden 09/2026', 22, false);
check('Anderer Grund: gilt als Aenderung', 1, $corrected['summary']['updated']);
check('Anderer Grund: wird uebernommen', 'ausgeschieden 09/2026', AssetHolder::find($muster->id)->billing_excluded_reason);
check('Korrektur: Menge bleibt gleich', 0, $corrected['impact'][0]['delta_quantity']);

// --- Aufheben ------------------------------------------------------------
$back = $service->setExclusion(TEAM, 1, [$muster->id], false, null, 22, false);
$muster->refresh();

check('Aufheben: Zeitstempel geleert', null, $muster->billing_excluded_at);
check('Aufheben: Grund geleert', null, $muster->billing_excluded_reason);
check('Aufheben: Urheber geleert', null, $muster->billing_excluded_by);
check('Aufheben: Menge steigt wieder', 4, count($resolver->resolve($profile)['principals']));
check('Aufheben: Geldwirkung positiv', 8000, $back['impact'][0]['delta_net_cents']);

// --- Ablehnungen ---------------------------------------------------------
$noReason = $service->setExclusion(TEAM, 1, [$muster->id], true, '   ', 22, true);
check('Ausnahme ohne Grund wird abgelehnt', false, $noReason['ok']);
check('Ablehnung nennt einen Code', 'VALIDATION_ERROR', $noReason['code']);
check('Aufheben braucht keinen Grund', true,
    $service->setExclusion(TEAM, 1, [$muster->id], false, null, 22, true)['ok']);
check('Leere Auswahl wird abgelehnt', false, $service->setExclusion(TEAM, 1, [], true, 'x', 22, true)['ok']);

// Fremdes Team / fremder Tenant darf nicht getroffen werden.
$foreign = $service->setExclusion(TEAM, 99, [$muster->id], true, 'fremder Tenant', 22, true);
check('Fremder Tenant trifft niemanden', 1, $foreign['summary']['missing']);

check_summary();
