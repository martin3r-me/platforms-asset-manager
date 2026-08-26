<?php

/**
 * Editor-Pfad der Kostenpositionen (Anlegen/Bearbeiten).
 *
 * Wichtigster Punkt: der Vertrag aus tests/Feature/CostLineScopingTest.php muss halten -
 * newLine() + nur fCostType/fLabel/fAmount/fFrequency gesetzt + save() darf KEINE
 * Validierungsfehler erzeugen. Genau daran koennen neue Pflichtfelder die Host-Suite
 * brechen. Ausserdem: Waehrung/FX nach ADR 0002, Gueltigkeit, Traeger-Scoping und die
 * unveraenderten Betragsregeln.
 *
 * Aufruf: php tests/local/cost-lines-editor.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Models\AssetCostLine;
use Platform\AssetManager\Services\TenantContext;

Schema::create('asset_cost_lines', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->unsignedBigInteger('cost_type_id')->nullable();
    $t->unsignedBigInteger('vendor_id')->nullable();
    $t->unsignedBigInteger('cost_center_id')->nullable();
    $t->unsignedBigInteger('assignee_id')->nullable();
    $t->string('label');
    $t->decimal('amount', 12, 2);
    $t->string('currency', 3)->default('EUR');
    $t->decimal('fx_rate', 12, 6)->nullable();
    $t->string('frequency');
    $t->decimal('monthly_amount', 12, 2)->default(0);
    $t->string('accounting_system')->nullable();
    $t->string('source')->default('manual');
    $t->date('valid_from')->nullable();
    $t->date('valid_to')->nullable();
    $t->boolean('active')->default(true);
    $t->timestamps();
    $t->softDeletes();
});

Schema::create('asset_cost_types', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('key');
    $t->string('name');
    $t->integer('sort_order')->default(0);
    $t->string('allocation_level')->default('cost_center');
    $t->string('aggregation_source')->default('cost_line');
    $t->boolean('allow_negative')->default(false);
    $t->string('system_default')->nullable();
    $t->unsignedBigInteger('vendor_default_id')->nullable();
    $t->timestamps();
    $t->softDeletes();
});

Schema::create('asset_cost_centers', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->unsignedBigInteger('parent_id')->nullable();
    $t->integer('depth')->default(0);
    $t->string('code');
    $t->string('name')->nullable();
    $t->boolean('is_active')->default(true);
    $t->integer('sort_order')->default(0);
    $t->timestamps();
    $t->softDeletes();
});

Schema::create('asset_vendors', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('name');
    $t->timestamps();
    $t->softDeletes();
});

Schema::create('asset_holders', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->unsignedBigInteger('cost_center_id')->nullable();
    $t->string('display_name')->nullable();
    $t->string('user_principal_name')->nullable();
    $t->boolean('is_active')->default(true);
    $t->timestamps();
    $t->softDeletes();
});

Schema::create('asset_tenants', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->string('name');
    $t->boolean('is_default')->default(false);
    $t->timestamps();
    $t->softDeletes();
});

Schema::create('asset_tenant_selections', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('user_id');
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('selected_tenant_id')->nullable();
    $t->timestamps();
});

const TEAM = 7;
const TENANT = 1;

DB::table('asset_tenants')->insert([
    ['id' => TENANT, 'team_id' => TEAM, 'name' => 'Standard', 'is_default' => true],
]);
DB::table('asset_cost_types')->insert([
    ['id' => 1, 'team_id' => TEAM, 'tenant_id' => TENANT, 'key' => 'mobilfunk', 'name' => 'Mobilfunk',
     'allocation_level' => 'person', 'aggregation_source' => 'cost_line', 'allow_negative' => 0,
     'system_default' => 'HGK'],
    ['id' => 2, 'team_id' => TEAM, 'tenant_id' => TENANT, 'key' => 'necta', 'name' => 'necta',
     'allocation_level' => 'cost_center', 'aggregation_source' => 'cost_line', 'allow_negative' => 0,
     'system_default' => null],
    ['id' => 3, 'team_id' => TEAM, 'tenant_id' => TENANT, 'key' => 'rabatt', 'name' => 'Rabatt',
     'allocation_level' => 'person', 'aggregation_source' => 'cost_line', 'allow_negative' => 1,
     'system_default' => null],
]);
DB::table('asset_holders')->insert([
    ['id' => 1, 'team_id' => TEAM, 'tenant_id' => TENANT, 'display_name' => 'MARCO ONNERTZ',
     'user_principal_name' => 'marco@example.test', 'is_active' => true],
    ['id' => 2, 'team_id' => TEAM, 'tenant_id' => TENANT, 'display_name' => 'ALT KOLLEGE',
     'user_principal_name' => 'alt@example.test', 'is_active' => false],
    ['id' => 9, 'team_id' => 99, 'tenant_id' => 42, 'display_name' => 'FREMD',
     'user_principal_name' => 'fremd@example.test', 'is_active' => true],
]);
DB::table('asset_vendors')->insert([
    ['id' => 1, 'team_id' => TEAM, 'tenant_id' => TENANT, 'name' => 'Vodafone'],
]);

TenantContext::forceTenant(TENANT);
Gate::before(fn (?object $user = null) => true);

$make = fn () => new class extends Platform\AssetManager\Livewire\CostLines\Index {
    public function teamId(): int { return TEAM; }
};


/** Ruft save() und meldet die Validierungsfehler-Keys zurueck. */
function trySave($c): array
{
    try {
        $c->save(app(Platform\AssetManager\Services\CostBootstrapService::class));
    } catch (Illuminate\Validation\ValidationException $e) {
        return array_keys($e->errors());
    }
    return array_keys($c->getErrorBag()->messages());
}

echo "=== TEST-VERTRAG (CostLineScopingTest): nur die 5 alten Felder ===\n";
$c = $make();
$c->newLine();
$c->fCostType  = 1;
$c->fLabel     = 'Mobilfunk Vertrag';
$c->fAmount    = '49.90';
$c->fFrequency = 'monthly';
$errors = trySave($c);
check('save() ohne Fehler (assertHasNoErrors)', [], $errors);
check('Zeile angelegt', 1, AssetCostLine::where('label', 'Mobilfunk Vertrag')->count());

$line = AssetCostLine::where('label', 'Mobilfunk Vertrag')->first();
check('source = manual', 'manual', $line->source);
check('currency default EUR', 'EUR', $line->currency);
check('fx_rate bleibt NULL', null, $line->fx_rate);
check('valid_from bleibt NULL', null, $line->valid_from);
check('assignee_id bleibt NULL', null, $line->assignee_id);
check('monthly_amount berechnet', 49.90, (float) $line->monthly_amount);
check('accounting_system aus der Kostenart', 'HGK', $line->accounting_system);

echo "\n=== Waehrung + FX (ADR 0002) ===\n";
$c = $make();
$c->newLine();
$c->fCostType  = 1;
$c->fLabel     = 'ChatGPT USD ohne Kurs';
$c->fAmount    = '20';
$c->fFrequency = 'monthly';
$c->fCurrency  = 'usd';                      // klein geschrieben -> wird normalisiert
check('Nicht-EUR ohne Kurs -> Fehler auf fFxRate', ['fFxRate'], trySave($c));
check('nichts geschrieben', 0, AssetCostLine::where('label', 'ChatGPT USD ohne Kurs')->count());

$c->fFxRate = '0.92';
check('mit Kurs -> kein Fehler', [], trySave($c));
$usd = AssetCostLine::where('label', 'ChatGPT USD ohne Kurs')->first();
check('Waehrung gross geschrieben', 'USD', $usd->currency);
check('fx_rate gespeichert', 0.92, (float) $usd->fx_rate);
check('monthly_amount = Betrag x Kurs', 18.40, (float) $usd->monthly_amount);

// Zurueck auf EUR: der Kurs MUSS verschwinden, sonst rechnet monthly_amount dauerhaft falsch.
$c2 = $make();
$c2->edit($usd->id);
check('edit() laedt die Waehrung', 'USD', $c2->fCurrency);
check('edit() laedt den Kurs', '0.920000', $c2->fFxRate);
$c2->fCurrency = 'EUR';
check('Wechsel auf EUR ohne Fehler', [], trySave($c2));
check('Zombie-Kurs entfernt', null, $usd->fresh()->fx_rate);
check('monthly_amount ohne Kurs', 20.00, (float) $usd->fresh()->monthly_amount);

echo "\n=== Gueltigkeit ===\n";
$c = $make();
$c->newLine();
$c->fCostType  = 2;
$c->fLabel     = 'Befristet';
$c->fAmount    = '100';
$c->fFrequency = 'monthly';
$c->fValidFrom = '2026-03-01';
$c->fValidTo   = '2026-01-01';               // vor dem Beginn
check('valid_to < valid_from -> Fehler', ['fValidTo'], trySave($c));

$c->fValidTo = '2026-12-31';
check('gueltiger Zeitraum -> kein Fehler', [], trySave($c));
$bef = AssetCostLine::where('label', 'Befristet')->first();
check('valid_from gespeichert', '2026-03-01', $bef->valid_from->format('Y-m-d'));
check('valid_to gespeichert', '2026-12-31', $bef->valid_to->format('Y-m-d'));

// Leere Datumsfelder muessen als NULL landen, nicht als Leerstring.
$c = $make();
$c->newLine();
$c->fCostType  = 2;
$c->fLabel     = 'Unbefristet';
$c->fAmount    = '10';
$c->fFrequency = 'monthly';
check('leere Datumsfelder -> kein Fehler', [], trySave($c));
check('valid_from ist NULL', null, AssetCostLine::where('label', 'Unbefristet')->first()->valid_from);

echo "\n=== Asset-Traeger ===\n";
$c = $make();
$c->newLine();
$c->fCostType  = 1;
$c->fLabel     = 'Mit Traeger';
$c->fAmount    = '30';
$c->fFrequency = 'monthly';
$c->fAssignee  = 1;
check('Traeger des eigenen Teams -> kein Fehler', [], trySave($c));
check('assignee_id gespeichert', 1, (int) AssetCostLine::where('label', 'Mit Traeger')->first()->assignee_id);

// Fremd-Traeger (anderes Team UND anderer Tenant) muss abgelehnt werden - M2-Leitplanke.
$c = $make();
$c->newLine();
$c->fCostType  = 1;
$c->fLabel     = 'Fremd-Traeger';
$c->fAmount    = '30';
$c->fFrequency = 'monthly';
$c->fAssignee  = 9;
check('Fremd-Traeger -> Fehler auf fAssignee', ['fAssignee'], trySave($c));
check('nichts geschrieben', 0, AssetCostLine::where('label', 'Fremd-Traeger')->count());

// Traeger wieder entfernen
$mit = AssetCostLine::where('label', 'Mit Traeger')->first();
$c3  = $make();
$c3->edit($mit->id);
check('edit() laedt den Traeger', 1, $c3->fAssignee);
$c3->clearHolder();
check('clearHolder + save ohne Fehler', [], trySave($c3));
check('assignee_id wieder NULL', null, $mit->fresh()->assignee_id);

echo "\n=== Traeger-Suche ===\n";
$c = $make();
$c->newLine();                                // showEditor = true
$c->fHolderSearch = 'marco';
check('Suche findet den Traeger', 1, $c->holderOptions()->count());
$c->fHolderSearch = 'example.test';           // UPN-Treffer, beide
check('Suche greift auch auf den UPN', 2, $c->holderOptions()->count());
check('aktive zuerst', true, (bool) $c->holderOptions()->first()->is_active);
$c->fHolderSearch = 'gibtsnicht';
check('kein Treffer', 0, $c->holderOptions()->count());

$closed = $make();
$closed->fHolderSearch = 'marco';
check('geschlossener Editor laedt keine Traeger', 0, $closed->holderOptions()->count());

echo "\n=== Betragsregeln bleiben ===\n";
$c = $make();
$c->newLine();
$c->fCostType  = 1;
$c->fLabel     = 'Null';
$c->fAmount    = '0';
$c->fFrequency = 'monthly';
trySave($c);
check('Betrag 0 abgelehnt', true, $c->getErrorBag()->has('fAmount'));

$c = $make();
$c->newLine();
$c->fCostType  = 1;                            // allow_negative = false
$c->fLabel     = 'Negativ verboten';
$c->fAmount    = '-5';
$c->fFrequency = 'monthly';
trySave($c);
check('negativ ohne allow_negative abgelehnt', true, $c->getErrorBag()->has('fAmount'));

$c = $make();
$c->newLine();
$c->fCostType  = 3;                            // allow_negative = true
$c->fLabel     = 'Gutschrift erlaubt';
$c->fAmount    = '-5';
$c->fFrequency = 'monthly';
check('negativ mit allow_negative erlaubt', [], trySave($c));
check('negativer monthly_amount', -5.0, (float) AssetCostLine::where('label', 'Gutschrift erlaubt')->first()->monthly_amount);

echo "\n=== resetEditor ===\n";
$c = $make();
$c->newLine();
$c->fCurrency  = 'USD';
$c->fFxRate    = '0.9';
$c->fAssignee  = 1;
$c->fValidFrom = '2026-01-01';
$c->cancelEdit();
check('Waehrung zurueck auf EUR', 'EUR', $c->fCurrency);
check('Kurs geleert', '', $c->fFxRate);
check('Traeger geleert', null, $c->fAssignee);
check('Gueltigkeit geleert', '', $c->fValidFrom);

echo "\n";
check_summary();
