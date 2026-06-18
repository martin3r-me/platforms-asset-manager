<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Connector vom Team an den Tenant umhängen (siehe docs/adr/0003):
 * - bisher genau 1 Connector je Team (unique team_id) → künftig 0..1 je Tenant
 * - team_id bleibt erhalten (Scoping + bestehender Sync-Code unverändert)
 *
 * Namens-Bereinigung: die bestehende Spalte `tenant_id` war die (verschlüsselte) Azure-Tenant-GUID
 * → wird zu `azure_tenant_id`. Der Name `tenant_id` wird damit für den FK auf `asset_tenants` frei.
 *
 * IDEMPOTENT + MySQL-SICHER: Diese Migration ist so gebaut, dass sie einen halb-angewendeten Stand
 * (z. B. abgebrochener Lauf auf MySQL) sauber zu Ende führt und beliebig oft wiederholbar ist.
 * Der `team_id`-Unique stützt auf MySQL den `team_id`-Foreign-Key → er lässt sich NICHT direkt droppen
 * (Fehler 1553). Daher wird zuvor ein einfacher Index auf `team_id` als FK-Stütze angelegt.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = 'asset_connector_configs';

        // 1) Azure-GUID-Spalte umbenennen (gibt 'tenant_id' frei) — nur falls noch nicht geschehen.
        if (! Schema::hasColumn($table, 'azure_tenant_id')) {
            Schema::table($table, fn (Blueprint $t) => $t->renameColumn('tenant_id', 'azure_tenant_id'));
        }

        // 2) Tenant-FK ergänzen — nur falls die Spalte noch nicht existiert.
        if (! Schema::hasColumn($table, 'tenant_id')) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('tenant_id')->nullable()->after('team_id')
                    ->constrained('asset_tenants')->cascadeOnDelete();
            });
        }

        // 3) team_id-Unique lösen — MySQL-sicher: erst Plain-Index als FK-Stütze, dann Unique droppen.
        $indexes = collect(Schema::getIndexes($table));
        $teamIdUnique = $indexes->first(fn ($i) => ($i['unique'] ?? false) && ($i['columns'] ?? []) === ['team_id']);

        if ($teamIdUnique) {
            $hasFkCover = $indexes->contains(
                fn ($i) => ! ($i['unique'] ?? false) && (($i['columns'][0] ?? null) === 'team_id')
            );
            if (! $hasFkCover) {
                Schema::table($table, fn (Blueprint $t) => $t->index('team_id'));
            }
            Schema::table($table, fn (Blueprint $t) => $t->dropUnique($teamIdUnique['name']));
        }

        // 4) Bestehende Connectoren auf je einen Default-Tenant zeigen lassen (idempotent: nur NULLs).
        foreach (DB::table($table)->whereNull('tenant_id')->get() as $connector) {
            $tenantId = DB::table('asset_tenants')
                ->where('team_id', $connector->team_id)
                ->where('is_default', true)
                ->value('id');

            if (! $tenantId) {
                $tenantId = DB::table('asset_tenants')->insertGetId([
                    'team_id'    => $connector->team_id,
                    'name'       => 'Standard',
                    'is_default' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table($table)->where('id', $connector->id)->update(['tenant_id' => $tenantId]);
        }
    }

    public function down(): void
    {
        $table = 'asset_connector_configs';

        if (Schema::hasColumn($table, 'tenant_id')) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropForeign(['tenant_id']);
                $t->dropColumn('tenant_id');
            });
        }

        // 1:1-Team-Bindung wiederherstellen, dann die FK-Stütze (Plain-Index) entfernen.
        $indexes = collect(Schema::getIndexes($table));
        $hasUnique = $indexes->contains(fn ($i) => ($i['unique'] ?? false) && ($i['columns'] ?? []) === ['team_id']);
        if (! $hasUnique) {
            Schema::table($table, fn (Blueprint $t) => $t->unique('team_id'));
        }
        $hasPlain = $indexes->contains(fn ($i) => ! ($i['unique'] ?? false) && ($i['columns'] ?? []) === ['team_id']);
        if ($hasPlain) {
            Schema::table($table, fn (Blueprint $t) => $t->dropIndex(['team_id']));
        }

        if (Schema::hasColumn($table, 'azure_tenant_id')) {
            Schema::table($table, fn (Blueprint $t) => $t->renameColumn('azure_tenant_id', 'tenant_id'));
        }
    }
};
