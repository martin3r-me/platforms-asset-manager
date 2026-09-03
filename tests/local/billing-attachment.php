<?php

/**
 * Leistungsnachweis: Anreicherung der Snapshot-Zeilen für das PDF.
 *
 * Hintergrund: Läufe von vor der Snapshot-Erweiterung führen nur UPNs. Ohne Nachschlagen stünde in
 * der Namensspalte des Nachweises eine E-Mail-Adresse und bei der Abteilung ein Strich — genau das
 * war im ersten ausgelieferten PDF zu sehen.
 *
 * Die Grenze dabei: **wer** abgerechnet wurde, kommt unverrückbar aus dem Snapshot. Ergänzt werden
 * ausschließlich Name und Abteilung, und nur dort, wo sie fehlen — ein im Snapshot festgehaltener
 * Name darf sich nicht nachträglich ändern, sonst zeigte ein alter Nachweis neue Daten.
 *
 * Aufruf: php tests/local/billing-attachment.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Platform\AssetManager\Http\Controllers\BillingAttachmentPdfController;
use Platform\AssetManager\Models\AssetBillingRun;
use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Services\TenantContext;

Schema::create('asset_holders', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('user_principal_name')->nullable();
    $t->string('display_name')->nullable();
    $t->string('department')->nullable();
    $t->string('holder_type')->default('person');
    $t->boolean('is_active')->default(true);
    $t->timestamps();
});

Schema::create('asset_billing_runs', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id');
    $t->unsignedBigInteger('billing_profile_id')->nullable();
    $t->string('period', 7);
    $t->unsignedInteger('quantity')->default(0);
    $t->unsignedInteger('unit_price_cents')->default(0);
    $t->unsignedBigInteger('total_net_cents')->default(0);
    $t->string('status')->default('draft');
    $t->json('snapshot')->nullable();
    $t->unsignedInteger('skipped_count')->default(0);
    $t->timestamps();
});

TenantContext::forceTenant(1);

const TEAM   = 7;
const TENANT = 1;

AssetHolder::create([
    'team_id' => TEAM, 'tenant_id' => TENANT,
    'user_principal_name' => 'A.MUSTER@KUNDE.DE', 'display_name' => 'Anna Muster',
    'department' => 'LOGISTIK',
]);
AssetHolder::create([
    'team_id' => TEAM, 'tenant_id' => TENANT,
    'user_principal_name' => 'B.BEISPIEL@KUNDE.DE', 'display_name' => 'Bernd Beispiel',
    'department' => 'KUECHE',
]);

$controller = new BillingAttachmentPdfController();
$enrich = function (AssetBillingRun $run) use ($controller) {
    $m = new ReflectionMethod($controller, 'enrichRows');
    $m->setAccessible(true);

    return $m->invoke($controller, $run);
};

// --- Alter Lauf: nur UPNs im Snapshot ------------------------------------
$legacy = AssetBillingRun::create([
    'team_id' => TEAM, 'tenant_id' => TENANT, 'period' => '2026-08', 'quantity' => 2,
    // Schreibweise bewusst abweichend — der Abgleich darf nicht daran scheitern.
    'snapshot' => ['principals' => ['a.muster@kunde.de', 'B.BEISPIEL@KUNDE.DE']],
]);

$rows = $enrich($legacy);

check('Alter Lauf: beide Zeilen bleiben erhalten', 2, count($rows));
check('Name wird nachgeschlagen statt E-Mail zu zeigen', 'Anna Muster', $rows[0]['name']);
check('Abteilung wird nachgeschlagen', 'LOGISTIK', $rows[0]['department']);
check('Auch bei abweichender Schreibweise', 'Bernd Beispiel', $rows[1]['name']);
check('UPN bleibt unveraendert — sie ist der Nachweis', 'a.muster@kunde.de', $rows[0]['upn']);

// --- Traeger seither geloescht: UPN bleibt stehen ------------------------
$gone = AssetBillingRun::create([
    'team_id' => TEAM, 'tenant_id' => TENANT, 'period' => '2026-07', 'quantity' => 1,
    'snapshot' => ['principals' => ['weg@kunde.de']],
]);

$rows = $enrich($gone);
check('Unbekannte UPN faellt nicht heraus', 1, count($rows));
check('...und behaelt die UPN als Namen', 'weg@kunde.de', $rows[0]['name']);

// --- Neuer Lauf: Snapshot fuehrt eigene Namen ----------------------------
// Der eingefrorene Name gewinnt: heisst jemand inzwischen anders, zeigt ein alter Nachweis
// trotzdem den Stand von damals.
$fresh = AssetBillingRun::create([
    'team_id' => TEAM, 'tenant_id' => TENANT, 'period' => '2026-09', 'quantity' => 1,
    'snapshot' => [
        'principals' => ['A.MUSTER@KUNDE.DE'],
        'rows' => [['upn' => 'A.MUSTER@KUNDE.DE', 'name' => 'Anna Muster-Alt', 'department' => 'VERWALTUNG']],
    ],
]);

$rows = $enrich($fresh);
check('Eingefrorener Name bleibt stehen', 'Anna Muster-Alt', $rows[0]['name']);
check('Eingefrorene Abteilung bleibt stehen', 'VERWALTUNG', $rows[0]['department']);

// --- Teilweise gefuellt: nur die Luecke wird geschlossen -----------------
$partial = AssetBillingRun::create([
    'team_id' => TEAM, 'tenant_id' => TENANT, 'period' => '2026-06', 'quantity' => 1,
    'snapshot' => [
        'principals' => ['A.MUSTER@KUNDE.DE'],
        'rows' => [['upn' => 'A.MUSTER@KUNDE.DE', 'name' => 'Anna Muster-Alt', 'department' => null]],
    ],
]);

$rows = $enrich($partial);
check('Fehlende Abteilung wird ergaenzt', 'LOGISTIK', $rows[0]['department']);
check('...der Name aber nicht angetastet', 'Anna Muster-Alt', $rows[0]['name']);

// --- Fremdes Team wird nicht angereichert --------------------------------
$foreign = AssetBillingRun::create([
    'team_id' => 99, 'tenant_id' => TENANT, 'period' => '2026-05', 'quantity' => 1,
    'snapshot' => ['principals' => ['A.MUSTER@KUNDE.DE']],
]);

check('Fremdes Team schlaegt nichts nach', 'A.MUSTER@KUNDE.DE', $enrich($foreign)[0]['name']);

check_summary();
