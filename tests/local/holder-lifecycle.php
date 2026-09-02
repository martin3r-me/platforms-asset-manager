<?php

/**
 * Ausgeschiedene Träger: Papierkorb-UPNs, Zusammenführen von Dubletten und das Stilllegen von
 * Konten, die das Verzeichnis nicht mehr kennt.
 *
 * Hintergrund: `is_active` wurde bis dahin nie auf `false` gesetzt — der Asset Manager erfuhr nie,
 * dass jemand geht. Und weil Entra eine gelöschte UPN umbenennt (objectId + Originaladresse), legte
 * jeder Pfad, der sie sah, einen ZWEITEN Träger für dieselbe Person an.
 *
 * Zwei Dinge werden hier besonders scharf geprüft, weil sie Daten vernichten können: dass der
 * Reconcile bei einer leeren Verzeichnis-Antwort **nichts** tut, und dass beim Zusammenführen keine
 * Zuordnung verloren geht.
 *
 * Aufruf: php tests/local/holder-lifecycle.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Platform\AssetManager\Jobs\ImportTenantUsersJob;
use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Services\HolderMergeService;
use Platform\AssetManager\Services\HolderService;
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
    $t->string('job_title')->nullable();
    $t->string('cost_center')->nullable();
    $t->unsignedBigInteger('cost_center_id')->nullable();
    $t->string('source')->default('graph');
    $t->string('holder_type')->default('person');
    $t->string('function_system')->nullable();
    $t->string('graph_id')->nullable();
    $t->timestamp('synced_at')->nullable();
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

// Spaltennamen exakt wie live NACH der Umbenennung Mitarbeiter -> Asset-Traeger (ADR 0017):
// asset_assignments/asset_handovers tragen `holder_id`, asset_cost_lines behielt `assignee_id`.
// Der erste scharfe Lauf scheiterte genau hier — der Test hatte zuvor die Annahme des Codes
// nachgebaut statt die Wirklichkeit und konnte den Fehler deshalb nicht finden.
foreach ([['asset_items', 'assignee_id'], ['asset_cost_lines', 'assignee_id'],
          ['asset_assignments', 'holder_id'], ['asset_handovers', 'holder_id']] as [$table, $column]) {
    Schema::create($table, function (Blueprint $t) use ($column) {
        $t->id();
        $t->unsignedBigInteger('team_id');
        $t->unsignedBigInteger('tenant_id')->nullable();
        $t->unsignedBigInteger($column)->nullable();
        $t->timestamps();
    });
}

// Geraete und Lizenzen finden ihren Traeger ueber die UPN, nicht ueber einen Fremdschluessel.
Schema::create('asset_devices', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('user_principal_name')->nullable();
    $t->timestamps();
});

Schema::create('asset_user_licenses', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('user_principal_name');
    $t->string('sku_id')->nullable();
    $t->timestamps();
    // Wie live: eine Zuweisung je Team, UPN und SKU.
    $t->unique(['team_id', 'user_principal_name', 'sku_id']);
});

TenantContext::forceTenant(1);

const TEAM   = 7;
const TENANT = 1;

/** So sieht eine UPN aus, deren Konto im Entra-Papierkorb liegt. */
const TRASH_UPN = '0b521d5646ee4b158a9c13727799dbd0F.TUNCHER@BROICHCATERING.COM';
const REAL_UPN  = 'F.TUNCHER@BROICHCATERING.COM';

function holder(array $attrs): AssetHolder
{
    return AssetHolder::create(array_merge([
        'team_id' => TEAM, 'tenant_id' => TENANT, 'source' => 'graph',
        'holder_type' => 'person', 'is_active' => true,
    ], $attrs));
}

// --- UPN-Muster -----------------------------------------------------------
check('Papierkorb-UPN wird erkannt', true, HolderService::isDeletedUpn(TRASH_UPN));
check('Papierkorb-UPN wird normalisiert', REAL_UPN, HolderService::normalizeUpn(TRASH_UPN));
check('Normale UPN bleibt unangetastet', REAL_UPN, HolderService::normalizeUpn(REAL_UPN));
check('Normale UPN gilt nicht als geloescht', false, HolderService::isDeletedUpn(REAL_UPN));

// Der Lookahead schuetzt echte Adressen: 32 Hex-Zeichen als LOKALTEIL duerfen nicht abgeschnitten
// werden — nach dem Praefix muesste sonst noch eine vollstaendige Adresse folgen.
$hexLocal = '0b521d5646ee4b158a9c13727799dbd0@firma.de';
check('32 Hex als reiner Lokalteil bleibt heil', $hexLocal, HolderService::normalizeUpn($hexLocal));
check('Grossschreibung im Praefix wird auch erkannt', REAL_UPN,
    HolderService::normalizeUpn('0B521D5646EE4B158A9C13727799DBD0' . REAL_UPN));

// --- Keine neuen Dubletten mehr ------------------------------------------
$service  = app(HolderService::class);
$original = holder(['user_principal_name' => REAL_UPN, 'display_name' => 'Fahridin Tuncher']);

$found = $service->findOrCreateByUpn(TEAM, TENANT, TRASH_UPN, 'Fahridin Tuncher');
check('Papierkorb-UPN findet den bestehenden Traeger', $original->id, $found->id);
check('...und legt keinen zweiten an', 1, AssetHolder::withoutTenantScope()->count());

// --- Zusammenfuehren bestehender Dubletten -------------------------------
$duplicate = holder([
    'user_principal_name' => TRASH_UPN,
    'email'               => TRASH_UPN,
    'display_name'        => 'Fahridin Tuncher',
    'source'              => 'derived',
    'cost_center'         => '5000',
    'cost_center_id'      => 180,
]);

// An der Dublette haengt Historie — genau die darf beim Aufraeumen nicht verlorengehen.
DB::table('asset_items')->insert(['team_id' => TEAM, 'tenant_id' => TENANT, 'assignee_id' => $duplicate->id]);
DB::table('asset_cost_lines')->insert(['team_id' => TEAM, 'tenant_id' => TENANT, 'assignee_id' => $duplicate->id]);
DB::table('asset_assignments')->insert(['team_id' => TEAM, 'tenant_id' => TENANT, 'holder_id' => $duplicate->id]);
DB::table('asset_handovers')->insert(['team_id' => TEAM, 'tenant_id' => TENANT, 'holder_id' => $duplicate->id]);

// Ein Geraet und zwei Lizenzen haengen an der Papierkorb-UPN — sie finden ihren Traeger ueber die
// Adresse, nicht ueber einen Fremdschluessel. Genau das uebersieht ein Merge, der nur FKs umhaengt.
DB::table('asset_devices')->insert(['team_id' => TEAM, 'tenant_id' => TENANT, 'user_principal_name' => TRASH_UPN]);
DB::table('asset_user_licenses')->insert([
    ['team_id' => TEAM, 'tenant_id' => TENANT, 'user_principal_name' => TRASH_UPN, 'sku_id' => 'e3'],
    // Diese SKU traegt das Original bereits — beim Umschreiben liefe sie in den Unique-Index.
    ['team_id' => TEAM, 'tenant_id' => TENANT, 'user_principal_name' => TRASH_UPN, 'sku_id' => 'e1'],
    ['team_id' => TEAM, 'tenant_id' => TENANT, 'user_principal_name' => REAL_UPN,  'sku_id' => 'e1'],
]);

$merger = app(HolderMergeService::class);

check('Dublette wird gefunden', 1, $merger->findDeletedUpnHolders(TEAM, TENANT)->count());
check('Referenzen zaehlen FK und UPN zusammen', 7,
    array_sum($merger->countReferences($duplicate->id, TRASH_UPN)));

$preview = $merger->mergeDeletedUpnHolders(TEAM, TENANT, true);
check('Probelauf meldet 1 Zusammenfuehrung', 1, $preview['merged']);
check('Probelauf: Aktion would_merge', 'would_merge', $preview['results'][0]['action']);
check('Probelauf aendert nichts', 2, AssetHolder::withoutTenantScope()->count());
check('Probelauf laesst Referenzen stehen', 4, array_sum($merger->countReferences($duplicate->id)));

$result = $merger->mergeDeletedUpnHolders(TEAM, TENANT, false);

check('Lauf meldet 1 Zusammenfuehrung', 1, $result['merged']);
check('Dublette ist weg', null, AssetHolder::withoutTenantScope()->find($duplicate->id));
check('Original bleibt', true, AssetHolder::withoutTenantScope()->find($original->id) !== null);
check('Alle Referenzen zeigen jetzt aufs Original', 4, array_sum($merger->countReferences($original->id)));
check('An der Dublette haengt nichts mehr', 0, array_sum($merger->countReferences($duplicate->id)));

$original->refresh();
check('Kostenstelle wurde aus der Dublette uebernommen', '5000', $original->cost_center);
check('Kostenstellen-FK ebenso', 180, (int) $original->cost_center_id);

// UPN-gebundene Verweise: das Geraet muss mitwandern, sonst zeigt es ins Leere.
check('Geraet zeigt jetzt auf die richtige UPN', 1,
    DB::table('asset_devices')->where('user_principal_name', REAL_UPN)->count());
check('Keine Geraete mehr auf der Papierkorb-UPN', 0,
    DB::table('asset_devices')->where('user_principal_name', TRASH_UPN)->count());

// Die kollidierende SKU wurde entfernt, die andere umgeschrieben — kein Unique-Verstoss.
check('Lizenzen liegen jetzt beim Original', 2,
    DB::table('asset_user_licenses')->where('user_principal_name', REAL_UPN)->count());
check('Keine Lizenz mehr auf der Papierkorb-UPN', 0,
    DB::table('asset_user_licenses')->where('user_principal_name', TRASH_UPN)->count());
check('Die doppelte SKU wurde nicht zweimal angelegt', 1,
    DB::table('asset_user_licenses')->where('user_principal_name', REAL_UPN)->where('sku_id', 'e1')->count());

// --- Dublette ohne Gegenstueck: umbenennen, nicht loeschen ---------------
$orphan = holder([
    'user_principal_name' => '11112222333344445555666677778888A.WAISE@FIRMA.DE',
    'email'               => '11112222333344445555666677778888A.WAISE@FIRMA.DE',
    'source'              => 'derived',
]);

DB::table('asset_devices')->insert([
    'team_id' => TEAM, 'tenant_id' => TENANT,
    'user_principal_name' => '11112222333344445555666677778888A.WAISE@FIRMA.DE',
]);

$result = $merger->mergeDeletedUpnHolders(TEAM, TENANT, false);
$orphan->refresh();

check('Ohne Gegenstueck: umbenannt statt geloescht', 1, $result['renamed']);
check('Auch beim Umbenennen wandert das Geraet mit', 1,
    DB::table('asset_devices')->where('user_principal_name', 'A.WAISE@FIRMA.DE')->count());
check('UPN ist bereinigt', 'A.WAISE@FIRMA.DE', $orphan->user_principal_name);
check('E-Mail ebenfalls', 'A.WAISE@FIRMA.DE', $orphan->email);
check('Und stillgelegt — das Konto ist im Verzeichnis geloescht', false, (bool) $orphan->is_active);
check('Datensatz existiert weiter', true, AssetHolder::withoutTenantScope()->find($orphan->id) !== null);

// --- Reconcile: wer nicht mehr im Verzeichnis steht, wird stillgelegt ----
AssetHolder::withoutTenantScope()->delete();

$stays    = holder(['user_principal_name' => 'BLEIBT@FIRMA.DE']);
$gone     = holder(['user_principal_name' => 'WEG@FIRMA.DE']);
$imported = holder(['user_principal_name' => 'EXCEL@FIRMA.DE', 'source' => 'derived']);
$already  = holder(['user_principal_name' => 'ALT@FIRMA.DE', 'is_active' => false]);

$job  = new ImportTenantUsersJob(1);
$call = function (string $method, ...$args) use ($job) {
    $m = new ReflectionMethod($job, $method);
    $m->setAccessible(true);

    return $m->invoke($job, ...$args);
};

// Gemischte Schreibweise: der Abgleich darf nicht daran scheitern (MySQL vergleicht ohne Ruecksicht
// auf Gross-/Kleinschreibung, SQLite aber schon — sonst laeuft live und lokal auseinander).
$deactivated = $call('reconcileHolders', TEAM, TENANT, ['bleibt@firma.de']);

check('Reconcile legt genau den Verschwundenen still', 1, $deactivated);
check('Der Anwesende bleibt aktiv', true, (bool) $stays->fresh()->is_active);
check('Der Verschwundene ist stillgelegt', false, (bool) $gone->fresh()->is_active);
check('Er wird NICHT geloescht — Historie haengt dran', true, $gone->fresh() !== null);
check('Abgeleiteter Traeger bleibt unangetastet', true, (bool) $imported->fresh()->is_active);

// Die wichtigste Sicherung: eine leere Verzeichnis-Antwort darf NIE den Bestand stilllegen.
// Zuruecksetzen per Query-Builder: der Reconcile hat ebenso geschrieben, die Model-Instanzen halten
// also noch `is_active = true` — ein `$model->update(['is_active' => true])` waere nicht dirty und
// wuerde gar nichts schreiben.
AssetHolder::withoutTenantScope()->whereIn('id', [$stays->id, $gone->id])->update(['is_active' => true]);

check('Leere Verzeichnis-Antwort legt nichts still', 0, $call('reconcileHolders', TEAM, TENANT, []));
check('...und laesst alle aktiv', true, (bool) $stays->fresh()->is_active && (bool) $gone->fresh()->is_active);

check_summary();
