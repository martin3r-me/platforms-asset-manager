<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Support\MigrationSchema;

/**
 * Telefonie-Seats (Dev-Issue #931): wer einen Cloud-Telefonie-Platz (RingCentral) belegt.
 *
 * **Warum eine eigene Tabelle und kein Häkchen am Träger:** Die Vodafone-Rechnung nennt nur eine
 * Menge („99 Lizenzen"), keine Namen. Wer einen Platz hat, steht in der Benutzerliste des
 * Telefonie-Anbieters — die wird dort gepflegt und hier als **Snapshot** hochgeladen. Ein Häkchen am
 * Träger wäre eine Kopie dieser Liste, die bei jedem Ein- und Austritt von Hand nachgezogen werden
 * müsste (dieselbe Begründung wie gegen das `billable`-Häkchen, ADR 0021). Außerdem gehören zur
 * Liste Plätze ohne Person (Konferenztelefon, Lager, Azubi-Apparat), die gar keinen Träger haben.
 *
 * `holder_id` ist das Ergebnis des Abgleichs beim Upload (E-Mail ↔ UPN/E-Mail, sonst Name) und
 * darf leer sein. `status` und `device` bleiben wörtlich, wie der Anbieter sie liefert — sie sind
 * Auskunft („nicht aktiviert, aber bezahlt"), keine Steuerung.
 *
 * Dazu: die **Telefonie-Sammelstelle** in den Moduleinstellungen (Anlagenanschluss, Verbindungen und
 * Plätze ohne Person) — konfigurierbar wie die Lizenz-Sammelstellen, weil ihr Code eine
 * Kundenentscheidung ist — und die neutrale Kostenart `telefonie` für Tenants, die vor ihrer
 * Aufnahme in die Erst-Defaults angelegt wurden.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('asset_telephony_seats')) {
            Schema::create('asset_telephony_seats', function (Blueprint $table) {
                $table->id();
                $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
                $table->foreignId('tenant_id')->constrained('asset_tenants')->cascadeOnDelete();

                // Anbieter des Platzes — heute nur `ringcentral`, als Spalte, damit ein zweiter
                // Anbieter keinen Umbau braucht und sein Snapshot den ersten nicht überschreibt.
                $table->string('provider', 32)->default('ringcentral');

                // Stabile ID des Platzes beim Anbieter (RingCentral: Postfach-ID).
                $table->string('external_id', 64);

                $table->string('email')->nullable();
                $table->string('display_name')->nullable();
                $table->string('extension', 16)->nullable();
                $table->string('direct_number', 96)->nullable();
                $table->string('device')->nullable();
                $table->string('status', 32)->nullable();

                $table->unsignedBigInteger('holder_id')->nullable();
                // exact = E-Mail/UPN · name = normalisierter Name · null = kein Träger
                $table->string('match', 16)->nullable();

                $table->timestamp('imported_at')->nullable();
                $table->json('raw_data')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'provider', 'external_id'], 'asset_telephony_seats_identity_unique');
                $table->index(['team_id', 'tenant_id', 'provider'], 'asset_telephony_seats_scope_index');
                $table->index('holder_id', 'asset_telephony_seats_holder_index');
            });
        }

        if (Schema::hasTable('asset_team_settings')) {
            MigrationSchema::addColumn('asset_team_settings', 'telephony_cost_center_id', function (Blueprint $table) {
                $table->unsignedBigInteger('telephony_cost_center_id')->nullable();
            });

            if (Schema::hasTable('asset_cost_centers')) {
                MigrationSchema::dropForeignIfExists('asset_team_settings', 'telephony_cost_center_id');
                // nullOnDelete wie bei den Lizenz-Sammelstellen (2026_08_26_000005).
                Schema::table('asset_team_settings', function (Blueprint $table) {
                    $table->foreign('telephony_cost_center_id')->references('id')->on('asset_cost_centers')->nullOnDelete();
                });
            }
        }

        $this->seedTelephonyCostType();
    }

    /**
     * `telefonie` steht in den neutralen Erst-Defaults (CostBootstrap::NEUTRAL_COST_TYPES), fehlt aber
     * bei Tenants, deren Kostenarten vorher angelegt wurden. Nur dort ergänzen, wo es schon Kostenarten
     * gibt — ein Tenant ohne Stammdaten bekommt sie beim regulären Bootstrap.
     */
    protected function seedTelephonyCostType(): void
    {
        if (! Schema::hasTable('asset_cost_types')) {
            return;
        }

        $scopes = DB::table('asset_cost_types')
            ->select('team_id', 'tenant_id')
            ->distinct()
            ->get();

        foreach ($scopes as $scope) {
            $exists = DB::table('asset_cost_types')
                ->where('team_id', $scope->team_id)
                ->where('tenant_id', $scope->tenant_id)
                ->where('key', 'telefonie')
                ->exists();

            if ($exists) {
                continue;
            }

            $row = [
                'team_id'            => $scope->team_id,
                'tenant_id'          => $scope->tenant_id,
                'key'                => 'telefonie',
                'name'               => 'Telefonie',
                'frequency_default'  => 'monthly',
                'allocation_level'   => 'cost_center',
                'aggregation_source' => 'cost_line',
                'allow_negative'     => false,
                'created_at'         => now(),
                'updated_at'         => now(),
            ];

            // Spalten, die es nicht in jedem Altstand gibt, nur setzen, wenn vorhanden.
            $row = array_filter($row, fn ($column) => Schema::hasColumn('asset_cost_types', $column), ARRAY_FILTER_USE_KEY);

            DB::table('asset_cost_types')->insert($row);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('asset_team_settings') && Schema::hasColumn('asset_team_settings', 'telephony_cost_center_id')) {
            MigrationSchema::dropForeignIfExists('asset_team_settings', 'telephony_cost_center_id');
            Schema::table('asset_team_settings', function (Blueprint $table) {
                $table->dropColumn('telephony_cost_center_id');
            });
        }

        Schema::dropIfExists('asset_telephony_seats');
    }
};
