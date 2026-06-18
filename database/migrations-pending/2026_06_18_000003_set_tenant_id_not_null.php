<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * VORBEREITET, NICHT AUTO-AKTIV (liegt in database/migrations-pending/ — siehe README.md dort).
 *
 * Setzt tenant_id auf NOT NULL auf allen Inventar-Tabellen. Erst anwenden, wenn ALLE Anlage-Pfade
 * (Sync + manuelle UI-Anlage, M3-Tenant-Selektor) tenant_id garantiert setzen und live keine NULLs
 * mehr existieren. Vor dem Verschieben nach database/migrations/ den --path-Smoke-Test fahren.
 */
return new class extends Migration
{
    private array $tables = [
        'asset_devices',
        'asset_user_licenses',
        'asset_license_skus',
        'asset_device_sync_logs',
        'asset_license_sync_logs',
        'asset_device_events',
        'asset_employees',
        'asset_items',
    ];

    public function up(): void
    {
        // Schutz: NOT NULL nur setzen, wenn nachweislich keine NULLs (mehr) existieren.
        foreach ($this->tables as $table) {
            if (! Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }
            $nulls = DB::table($table)->whereNull('tenant_id')->count();
            if ($nulls > 0) {
                throw new \RuntimeException(
                    "Migration abgebrochen: {$table} hat {$nulls} Zeilen mit tenant_id=NULL. "
                    . "Erst Backfill/Sync abschließen (M3), dann erneut migrieren."
                );
            }
        }

        foreach ($this->tables as $table) {
            if (! Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('tenant_id')->nullable(false)->change();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('tenant_id')->nullable()->change();
            });
        }
    }
};
