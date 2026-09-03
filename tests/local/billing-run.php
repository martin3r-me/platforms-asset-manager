<?php

/**
 * Weiterberechnung: Zählregel, Preisermittlung, Beleg-Aufbau und Lauf-Protokoll.
 *
 * Das ist die Stelle, an der ein Fehler unmittelbar Geld kostet — eine zu hohe Menge schreibt eine
 * zu hohe Rechnung, eine zu niedrige verschenkt Umsatz, und ein Euro-Betrag an einer Cent-
 * Schnittstelle irrt sich um den Faktor 100. Deshalb wird hier ohne easybill, ohne Commerce und
 * ohne Livewire geprüft.
 *
 * Aufruf: php tests/local/billing-run.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Platform\AssetManager\Models\AssetBillingProfile;
use Platform\AssetManager\Models\AssetBillingRun;
use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Models\AssetUserLicense;
use Platform\AssetManager\Services\BillableHolderResolver;
use Platform\AssetManager\Services\BillingGateway;
use Platform\AssetManager\Services\BillingRunService;
use Platform\AssetManager\Services\BillingUnitPriceResolver;
use Platform\AssetManager\Services\TenantContext;

// --- Minimalschema --------------------------------------------------------
Schema::create('asset_holders', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('user_principal_name')->nullable();
    $t->string('display_name')->nullable();
    $t->string('holder_type')->default('person');
    $t->boolean('is_active')->default(true);
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
    $t->string('easybill_customer_name')->nullable();
    $t->string('commerce_sku')->nullable();
    $t->unsignedInteger('fallback_unit_price_cents')->nullable();
    $t->string('basis')->default('licensed');
    $t->json('exclude_domains')->nullable();
    $t->unsignedTinyInteger('vat_percent')->nullable();
    $t->string('currency', 3)->default('EUR');
    $t->boolean('active')->default(true);
    $t->timestamps();
});

Schema::create('asset_billing_runs', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id');
    $t->unsignedBigInteger('billing_profile_id');
    $t->string('period', 7);
    $t->unsignedInteger('quantity')->default(0);
    $t->unsignedInteger('unit_price_cents')->default(0);
    $t->unsignedBigInteger('total_net_cents')->default(0);
    $t->string('currency', 3)->default('EUR');
    $t->string('price_source')->nullable();
    $t->string('status')->default('draft');
    $t->unsignedBigInteger('easybill_document_id')->nullable();
    $t->json('snapshot')->nullable();
    $t->unsignedInteger('skipped_count')->default(0);
    $t->text('error')->nullable();
    $t->unsignedBigInteger('user_id')->nullable();
    $t->timestamps();
});

TenantContext::forceTenant(1);

const TEAM = 7;

// --- Bestand ---------------------------------------------------------------
// Die Fälle bilden nach, was auf der Live-Umgebung tatsächlich im Verzeichnis steht: Personen mit
// und ohne Lizenz, eigene Mitarbeiter unter fremden Kunden, Admin-Konten, ein Datenrest ohne UPN,
// derselbe Mensch zweimal in unterschiedlicher Schreibweise.
$holders = [
    ['A.MUSTER@BROICHCATERING.COM',  'person',   true],   // mit Lizenz  -> zaehlt
    ['B.BEISPIEL@BROICHCATERING.COM','person',   true],   // mit Lizenz  -> zaehlt
    ['C.OHNE@BROICHCATERING.COM',    'person',   true],   // ohne Lizenz -> faellt bei 'licensed' raus
    ['M.WALTER@BHGDIGITAL.DE',       'person',   true],   // eigene Domain, MIT Lizenz -> ausgeschlossen
    ['ADMINCW@BROICHCATERING.COM',   'admin',    true],   // Verwaltungskonto, MIT Lizenz -> ausgeschlossen
    ['',                             'person',   true],   // Datenrest ohne UPN -> ausgeschlossen
    ['D.INAKTIV@BROICHCATERING.COM', 'person',   false],  // inaktiv -> taucht gar nicht erst auf
    ['a.muster@broichcatering.com',  'person',   true],   // derselbe Mensch, andere Schreibweise
];

foreach ($holders as [$upn, $type, $active]) {
    AssetHolder::create([
        'team_id' => TEAM, 'tenant_id' => 1,
        'user_principal_name' => $upn, 'display_name' => $upn ?: 'ohne Namen',
        'holder_type' => $type, 'is_active' => $active,
    ]);
}

// Lizenz-UPNs bewusst in Kleinbuchstaben — Graph liefert sie mal so, mal so. Der Abgleich darf
// davon nicht abhaengen, sonst zaehlt derselbe Lauf lokal (SQLite) anders als live (MySQL).
foreach (['a.muster@broichcatering.com', 'b.beispiel@broichcatering.com',
          'm.walter@bhgdigital.de', 'admincw@broichcatering.com'] as $upn) {
    AssetUserLicense::create([
        'team_id' => TEAM, 'tenant_id' => 1,
        'sku_id' => 'sku-1', 'user_principal_name' => $upn,
    ]);
}

$profile = AssetBillingProfile::create([
    'team_id' => TEAM, 'tenant_id' => 1,
    'name' => 'Broich',
    'easybill_customer_id' => 2636491004,
    'easybill_customer_name' => 'BHG.BROICHCATERING GMBH',
    'commerce_sku' => 'IT-5001',
    'fallback_unit_price_cents' => 8000,
    'basis' => AssetBillingProfile::BASIS_LICENSED,
    'exclude_domains' => ['BHGDigital.de '],   // absichtlich unsauber eingetragen
    'vat_percent' => 19,
    'currency' => 'EUR',
    'active' => true,
]);

// --- Zaehlregel -----------------------------------------------------------
$resolver = new BillableHolderResolver();
$counted  = $resolver->resolve($profile);

check('licensed: nur Personen mit Lizenz zaehlen', 2, count($counted['principals']));
check('licensed: Doppelschreibweise nur einmal', 1,
    count(array_filter($counted['principals'], fn ($u) => strtolower($u) === 'a.muster@broichcatering.com')));

$reasons = array_count_values(array_column($counted['skipped'], 'reason'));
check('Ausschluss: keine Lizenz', 1, $reasons['keine Microsoft-Lizenz'] ?? 0);
check('Ausschluss: kein Personenkonto', 1, $reasons['kein Personenkonto (admin)'] ?? 0);
check('Ausschluss: keine UPN', 1, $reasons['keine UPN hinterlegt'] ?? 0);
check('Ausschluss: eigene Domain (trotz Lizenz)', 1, $reasons['ausgeschlossene Domain (bhgdigital.de)'] ?? 0);
check('Inaktive tauchen gar nicht auf', 6, count($counted['principals']) + count($counted['skipped']));

// Zaehlregel 'all_holders': jetzt zaehlt auch der Traeger ohne Lizenz mit.
$profile->basis = AssetBillingProfile::BASIS_ALL_HOLDERS;
check('all_holders: Traeger ohne Lizenz zaehlt mit', 3, count($resolver->resolve($profile)['principals']));
$profile->basis = AssetBillingProfile::BASIS_LICENSED;

// --- Preis ----------------------------------------------------------------
// Ohne Commerce im Host faellt der Resolver auf den Rueckfallpreis zurueck — genau der Fall, den
// eine Installation ohne das Fremdmodul erlebt.
$price = (new BillingUnitPriceResolver())->resolve($profile);
check('Preis: Rueckfall greift ohne Commerce', 8000, $price['cents']);
check('Preis: Quelle wird ausgewiesen', AssetBillingRun::PRICE_SOURCE_FALLBACK, $price['source']);

$profile->commerce_sku = null;
$profile->fallback_unit_price_cents = null;
check('Preis: ohne jede Quelle null', null, (new BillingUnitPriceResolver())->resolve($profile)['cents']);
$profile->commerce_sku = 'IT-5001';
$profile->fallback_unit_price_cents = 8000;

// --- Lauf -----------------------------------------------------------------
$gateway = new class implements BillingGateway {
    public array $sent = [];
    public bool $explode = false;

    public function isAvailable(): bool { return true; }
    public function unavailableReason(): ?string { return null; }
    public function createDraftInvoice(array $document): int
    {
        if ($this->explode) {
            throw new RuntimeException('easybill: Kunde nicht gefunden');
        }
        $this->sent[] = $document;
        return 999001;
    }
    public function documentUrl(int $documentId): ?string { return null; }

    /** null = konnte nicht nachsehen, false = nachweislich weg, true = da. */
    public ?bool $exists = true;

    public function documentExists(int $documentId): ?bool { return $this->exists; }

    /** Kennt genau einen Kunden — unter interner ID UND Kundennummer, wie easybill selbst. */
    public function findCustomer(string $idOrNumber): ?array
    {
        return match (trim($idOrNumber)) {
            '2636491004', '6010200' => ['id' => 2636491004, 'name' => 'BHG.BROICHCATERING GMBH', 'number' => '6010200'],
            default                 => null,
        };
    }
};

check('Gateway findet den Kunden ueber die interne ID', 2636491004, $gateway->findCustomer('2636491004')['id']);
check('Gateway findet ihn auch ueber die Kundennummer', 2636491004, $gateway->findCustomer('6010200')['id']);
check('Gateway liefert den Namen mit', 'BHG.BROICHCATERING GMBH', $gateway->findCustomer('6010200')['name']);
check('Unbekannte Nummer -> null', null, $gateway->findCustomer('999'));

$runs    = new BillingRunService($resolver, new BillingUnitPriceResolver(), $gateway);
$preview = $runs->preview($profile, '2026-09');

check('Vorschau: Menge', 2, $preview['quantity']);
check('Vorschau: Summe in Cent', 16000, $preview['total_net_cents']);
check('Vorschau: keine Beanstandungen', [], $preview['problems']);
check('Vorschau: Monatsname deutsch', 'September 2026', $preview['period_label']);

$doc = $preview['document'];
check('Beleg: Typ', 'INVOICE', $doc['type']);
check('Beleg: Kunde', 2636491004, $doc['customer_id']);
check('Beleg: Stueckpreis als Integer-Cent', 8000, $doc['items'][0]['single_price_net']);
check('Beleg: Menge', 2, $doc['items'][0]['quantity']);
check('Beleg: Steuersatz', 19, $doc['items'][0]['vat_percent']);
check('Beleg: Artikelnummer', 'IT-5001', $doc['items'][0]['number']);
check('Beleg: Leistungszeitraum von', '2026-09-01', $doc['service_date']['date_from']);
check('Beleg: Leistungszeitraum bis', '2026-09-30', $doc['service_date']['date_to']);

// Ungueltige Periode faellt auf den laufenden Monat zurueck statt zu raten.
check('Periode: Unsinn faellt auf jetzt zurueck', now()->format('Y-m'), $runs->normalizePeriod('Herbst'));

$run = $runs->createDraft($profile, '2026-09');

check('Lauf: Beleg-ID gespeichert', 999001, $run->easybill_document_id);
check('Lauf: Status', AssetBillingRun::STATUS_DRAFT, $run->status);
check('Lauf: Snapshot friert die Traeger ein', 2, count($run->snapshotPrincipals()));

// Der Leistungsnachweis entsteht aus dem Snapshot — er muss die Namen mitfuehren, sonst zeigte er
// Monate spaeter die heutige Belegschaft statt der damals abgerechneten.
check('Snapshot fuehrt Namen mit', 2, count($run->snapshotRows()));
check('Snapshot-Zeile hat UPN', true, isset($run->snapshotRows()[0]['upn']));
check('Snapshot-Zeile hat Namen', true, isset($run->snapshotRows()[0]['name']));

// Aelterer Lauf ohne `rows`: faellt auf die UPN als Namen zurueck, statt heutige Daten zu erfinden.
$legacy = new AssetBillingRun(['snapshot' => ['principals' => ['alt@kunde.de']]]);
check('Alter Snapshot: Rueckfall auf die UPN', 'alt@kunde.de', $legacy->snapshotRows()[0]['name']);
check('Alter Snapshot: genau eine Zeile', 1, count($legacy->snapshotRows()));
check('Lauf: Uebersprungene mitgezaehlt', 4, $run->skipped_count);
check('Lauf: Euro-Betrag fuer die Anzeige', 160.0, $run->totalNet());
check('Gateway: genau ein Beleg gesendet', 1, count($gateway->sent));

// --- Schutz gegen die zweite Rechnung ------------------------------------
$second = $runs->preview($profile, '2026-09');
check('Zweiter Lauf desselben Monats wird beanstandet', 1, count($second['problems']));
check('Anderer Monat bleibt moeglich', [], $runs->preview($profile, '2026-10')['problems']);

// --- Beleg in easybill geloescht -----------------------------------------
// Die gefaehrliche Verwechslung: "konnte nicht nachsehen" darf NICHT wie "geloescht" wirken,
// sonst entstuende fuer denselben Monat eine zweite Rechnung.
$gateway->exists = null;
check('Unerreichbares easybill gibt die Periode NICHT frei', 1,
    count($runs->preview($profile, '2026-09')['problems']));

$gateway->exists = false;
check('Geloeschter Beleg gibt die Periode frei', [], $runs->preview($profile, '2026-09')['problems']);

$discarded = AssetBillingRun::withoutTenantScope()->where('period', '2026-09')->latest('id')->first();
check('Lauf ist als verworfen markiert', AssetBillingRun::STATUS_DISCARDED, $discarded->status);
check('Beleg-ID bleibt zur Nachvollziehbarkeit stehen', 999001, $discarded->easybill_document_id);
check('Grund ist vermerkt', true, str_contains((string) $discarded->error, 'gelöscht'));

// Ein verworfener Lauf blockiert auch spaeter nicht mehr — auch dann nicht, wenn easybill
// zwischenzeitlich wieder erreichbar ist.
$gateway->exists = true;
check('Verworfener Lauf blockiert dauerhaft nicht mehr', [], $runs->preview($profile, '2026-09')['problems']);

$gateway->exists = true;

// --- Fehlschlag wird protokolliert ---------------------------------------
$gateway->explode = true;
$failed = null;

try {
    $runs->createDraft($profile, '2026-11');
} catch (Throwable $e) {
    $failed = $e->getMessage();
}

check('Fehlschlag: Ausnahme kommt durch', 'easybill: Kunde nicht gefunden', $failed);

$failedRun = AssetBillingRun::withoutTenantScope()->where('period', '2026-11')->first();
check('Fehlschlag: Lauf trotzdem protokolliert', AssetBillingRun::STATUS_FAILED, $failedRun?->status);
check('Fehlschlag: keine Beleg-ID', null, $failedRun?->easybill_document_id);
check('Fehlschlag: blockiert den zweiten Versuch nicht', [], $runs->preview($profile, '2026-11')['problems']);

check_summary();
