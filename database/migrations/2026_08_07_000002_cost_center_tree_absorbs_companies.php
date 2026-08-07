<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Support\MigrationSchema;

/**
 * Tenant-Welle 2/5 — Kostenstellen werden ein Baum, `asset_companies` geht darin auf
 * (siehe docs/adr/0016).
 *
 * Vorher zwei feste Ebenen: Gesellschaft (`asset_companies`) → Kostenstelle. Das trägt die reale
 * Struktur nicht — „Broich Verwaltung" ist ein *Kind* von „Broich Catering", nicht ihr Geschwister.
 * Nachher: ein `parent_id`/`depth`-Baum beliebiger Tiefe, dessen oberste Knoten die Gesellschaften
 * sind. Strukturell war „Gesellschaft" nie etwas anderes als eine Kostenstelle ohne Eltern.
 *
 * Ablauf (jeder Schritt idempotent, übersteht einen Teil-Abbruch):
 *  1. parent_id + depth ergänzen.
 *  2. Je Gesellschaft einen Wurzelknoten in asset_cost_centers anlegen (Code aus dem key, bei
 *     Kollision mit einem echten Kostenstellen-Code suffigiert).
 *  3. Kostenstellen mit company_id unter ihren Wurzelknoten hängen (depth=1).
 *  4. company_id + FK entfernen, asset_companies droppen.
 *
 * Die Rückrichtung stellt die Tabelle wieder her und hängt die Wurzelknoten zurück — sie kann die
 * ursprünglichen Company-IDs allerdings nicht rekonstruieren (neue Auto-Increment-Werte).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addTreeColumns();

        if (Schema::hasTable('asset_companies') && Schema::hasColumn('asset_cost_centers', 'company_id')) {
            $this->absorbCompanies();
        }

        $this->dropCompanyLink();
    }

    private function addTreeColumns(): void
    {
        MigrationSchema::addColumn('asset_cost_centers', 'parent_id', function (Blueprint $t) {
            $t->unsignedBigInteger('parent_id')->nullable()->after('tenant_id');
            // nullOnDelete: einen Knoten löschen macht seine Kinder zu Wurzeln, statt sie mitzureißen.
            // Ein versehentlicher Klick soll keinen halben Kostenstellen-Baum kosten.
            $t->foreign('parent_id')->references('id')->on('asset_cost_centers')->nullOnDelete();
        });

        MigrationSchema::addColumn('asset_cost_centers', 'depth', function (Blueprint $t) {
            // Denormalisiert: erlaubt sortierte Baum-Ausgabe ohne recursive CTE (MySQL 5.7-tauglich)
            // und ist über recomputeDepths() jederzeit neu herleitbar.
            $t->unsignedTinyInteger('depth')->default(0)->after('parent_id');
        });

        MigrationSchema::ensureIndex('asset_cost_centers', ['tenant_id', 'parent_id']);
    }

    /** Gesellschaften zu Wurzel-Kostenstellen machen und ihre Kostenstellen darunter hängen. */
    private function absorbCompanies(): void
    {
        $companies = DB::table('asset_companies')->orderBy('id')->get();

        foreach ($companies as $company) {
            $tenantId = $this->tenantForTeam((int) $company->team_id);

            $rootId = DB::table('asset_cost_centers')
                ->where('tenant_id', $tenantId)
                ->where('code', $this->rootCode($company, $tenantId))
                ->value('id');

            if (! $rootId) {
                $rootId = DB::table('asset_cost_centers')->insertGetId([
                    'team_id'    => $company->team_id,
                    'tenant_id'  => $tenantId,
                    'parent_id'  => null,
                    'depth'      => 0,
                    'code'       => $this->rootCode($company, $tenantId),
                    'name'       => $company->name,
                    'is_active'  => true,
                    'sort_order' => $company->sort_order ?? 100,
                    'notes'      => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Kinder unterhängen — nur die, die noch keinen Eltern haben (Wiederholbarkeit).
            DB::table('asset_cost_centers')
                ->where('company_id', $company->id)
                ->whereNull('parent_id')
                ->where('id', '!=', $rootId)
                ->update(['parent_id' => $rootId, 'depth' => 1, 'updated_at' => now()]);
        }
    }

    /**
     * Code des Wurzelknotens. Der Company-`key` ist ein Slug ('rhein-ruhr'), Kostenstellen-Codes sind
     * Nummern ('2599') — eine Kollision ist unwahrscheinlich, aber die Spalte ist je Tenant unique.
     * Deshalb bei Bedarf suffigieren statt die Migration am Constraint scheitern zu lassen.
     */
    private function rootCode(object $company, int $tenantId): string
    {
        $key = (string) $company->key;

        $collides = DB::table('asset_cost_centers')
            ->where('tenant_id', $tenantId)
            ->where('code', $key)
            ->whereNotNull('company_id')
            ->exists();

        return $collides ? $key . '-gruppe' : $key;
    }

    private function tenantForTeam(int $teamId): int
    {
        static $cache = [];

        return $cache[$teamId] ??= MigrationSchema::defaultTenantIdsByTeam([$teamId])[$teamId];
    }

    /** company_id + FK entfernen, danach die Tabelle. */
    private function dropCompanyLink(): void
    {
        if (Schema::hasColumn('asset_cost_centers', 'company_id')) {
            MigrationSchema::dropForeignIfExists('asset_cost_centers', 'company_id');

            // Der (team_id, company_id)-Index würde den Spalten-Drop auf MySQL blockieren.
            $indexes = collect(Schema::getIndexes('asset_cost_centers'));
            foreach ($indexes as $index) {
                if (in_array('company_id', $index['columns'] ?? [], true)) {
                    Schema::table('asset_cost_centers', fn (Blueprint $t) => $t->dropIndex($index['name']));
                }
            }

            Schema::table('asset_cost_centers', fn (Blueprint $t) => $t->dropColumn('company_id'));
        }

        Schema::dropIfExists('asset_companies');
    }

    public function down(): void
    {
        if (! Schema::hasTable('asset_companies')) {
            Schema::create('asset_companies', function (Blueprint $table) {
                $table->id();
                $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
                $table->string('key');
                $table->string('name');
                $table->unsignedInteger('sort_order')->default(100);
                $table->timestamps();

                $table->unique(['team_id', 'key']);
            });
        }

        MigrationSchema::addColumn('asset_cost_centers', 'company_id', function (Blueprint $t) {
            $t->unsignedBigInteger('company_id')->nullable()->after('tenant_id');
            $t->foreign('company_id')->references('id')->on('asset_companies')->nullOnDelete();
        });

        // Nur Wurzelknoten MIT Kindern zurück in asset_companies spiegeln: sie waren vorher
        // Gesellschaften. Kinderlose Wurzeln sind echte Kostenstellen ohne Gesellschaft (die
        // „unmapped" Codes aus dem Bootstrap) und bleiben stehen — down() darf keine Daten wegwerfen.
        $roots = DB::table('asset_cost_centers')->whereNull('parent_id')->orderBy('id')->get();

        foreach ($roots as $root) {
            $children = DB::table('asset_cost_centers')->where('parent_id', $root->id);

            if (! $children->exists()) {
                continue;
            }

            $companyId = DB::table('asset_companies')->insertGetId([
                'team_id'    => $root->team_id,
                'key'        => $root->code,
                'name'       => $root->name ?? $root->code,
                'sort_order' => $root->sort_order ?? 100,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $children->update(['company_id' => $companyId, 'parent_id' => null, 'updated_at' => now()]);

            DB::table('asset_cost_centers')->where('id', $root->id)->delete();
        }

        foreach (['depth', 'parent_id'] as $column) {
            if (! Schema::hasColumn('asset_cost_centers', $column)) {
                continue;
            }
            if ($column === 'parent_id') {
                MigrationSchema::dropForeignIfExists('asset_cost_centers', 'parent_id');
            }
            Schema::table('asset_cost_centers', fn (Blueprint $t) => $t->dropColumn($column));
        }
    }
};
