<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Support\MigrationSchema;

/**
 * DATEV-Stapelkopf je Tenant (Discussion #26, Entscheidung Max 2026-10-15): Berater, Mandant, Beginn
 * des Wirtschaftsjahres, Sachkontenlänge, Diktatkürzel und die Sammel-Kostenstelle, auf der die
 * Buchhaltung die IT-Rechnungen bucht (Quelle jeder Umbuchung).
 *
 * In den Moduleinstellungen statt im Code: es sind Daten **eines** Kunden (Kanzlei, Mandantennummer),
 * und ein zweiter Mandant (Foodpol 10002) braucht einen eigenen Kopf — derselbe Grund wie bei den
 * Lizenz-Sammelstellen.
 *
 * **Erstbefüllung:** nur Tenants, die bereits ein DATEV-Sachkonto an einer Kostenart pflegen — das sind
 * genau die, die umbuchen wollen. Werte aus dem Controlling-Vault (`01_Stammdaten/Mandant.md`, Mandant
 * 530, verifiziert): Berater 189671, Mandant 530, WJ ab 01.01., Sachkontenlänge 6, Sammel-KST 1520, sofern
 * sie im Tenant existiert. Das Diktatkürzel bleibt leer — es ist das Kürzel der Person, die den Stapel
 * verantwortet, und wird vor dem ersten Stapel eingetragen.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('asset_team_settings')) {
            return;
        }

        MigrationSchema::addColumn('asset_team_settings', 'datev_consultant', fn (Blueprint $t) => $t->string('datev_consultant', 7)->nullable());
        MigrationSchema::addColumn('asset_team_settings', 'datev_client', fn (Blueprint $t) => $t->string('datev_client', 5)->nullable());
        // MM-DD, z. B. „01-01" — das Jahr ergibt sich aus dem Buchungsmonat.
        MigrationSchema::addColumn('asset_team_settings', 'datev_fiscal_year_start', fn (Blueprint $t) => $t->string('datev_fiscal_year_start', 5)->nullable());
        MigrationSchema::addColumn('asset_team_settings', 'datev_account_length', fn (Blueprint $t) => $t->unsignedTinyInteger('datev_account_length')->nullable());
        MigrationSchema::addColumn('asset_team_settings', 'datev_initials', fn (Blueprint $t) => $t->string('datev_initials', 2)->nullable());
        MigrationSchema::addColumn('asset_team_settings', 'datev_source_cost_center_id', fn (Blueprint $t) => $t->unsignedBigInteger('datev_source_cost_center_id')->nullable());

        if (Schema::hasTable('asset_cost_centers') && DB::getDriverName() !== 'sqlite') {
            MigrationSchema::dropForeignIfExists('asset_team_settings', 'datev_source_cost_center_id');
            // nullOnDelete wie bei den übrigen Sammelstellen.
            Schema::table('asset_team_settings', function (Blueprint $table) {
                $table->foreign('datev_source_cost_center_id')->references('id')->on('asset_cost_centers')->nullOnDelete();
            });
        }

        $this->seedExistingDatevTenants();
    }

    protected function seedExistingDatevTenants(): void
    {
        if (! Schema::hasTable('asset_cost_types') || ! Schema::hasColumn('asset_cost_types', 'datev_account')) {
            return;
        }

        $scopes = DB::table('asset_cost_types')
            ->whereNotNull('datev_account')
            ->where('datev_account', '!=', '')
            ->select('team_id', 'tenant_id')
            ->distinct()
            ->get();

        foreach ($scopes as $scope) {
            $source = Schema::hasTable('asset_cost_centers')
                ? DB::table('asset_cost_centers')->where('team_id', $scope->team_id)->where('tenant_id', $scope->tenant_id)->where('code', '1520')->value('id')
                : null;

            $values = [
                'datev_consultant'            => '189671',
                'datev_client'                => '530',
                'datev_fiscal_year_start'     => '01-01',
                'datev_account_length'        => 6,
                'datev_source_cost_center_id' => $source,
            ];

            $existing = DB::table('asset_team_settings')->where('team_id', $scope->team_id)->where('tenant_id', $scope->tenant_id)->first();

            if ($existing === null) {
                DB::table('asset_team_settings')->insert($values + [
                    'team_id' => $scope->team_id, 'tenant_id' => $scope->tenant_id, 'created_at' => now(), 'updated_at' => now(),
                ]);
                continue;
            }

            // Nie überschreiben, was schon jemand gepflegt hat (erneuter Lauf, Teil-Anwendung).
            $fill = array_filter($values, fn ($value, $column) => $value !== null && $existing->{$column} === null, ARRAY_FILTER_USE_BOTH);
            if ($fill !== []) {
                DB::table('asset_team_settings')->where('id', $existing->id)->update($fill + ['updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('asset_team_settings')) {
            return;
        }

        MigrationSchema::dropForeignIfExists('asset_team_settings', 'datev_source_cost_center_id');

        foreach (['datev_consultant', 'datev_client', 'datev_fiscal_year_start', 'datev_account_length', 'datev_initials', 'datev_source_cost_center_id'] as $column) {
            if (Schema::hasColumn('asset_team_settings', $column)) {
                Schema::table('asset_team_settings', fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
