<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M6/N13 (Entscheidung E3, siehe docs/adr/0003) — Tenant-Löschung soll die Betriebs-/Nachweis-Historie
 * NICHT mit vernichten: die beiden Sync-Log-Tabellen (keine PII) tragen tenant_id künftig mit
 * nullOnDelete statt cascadeOnDelete. Ein gelöschter Tenant lässt seine Sync-Logs als verwaiste
 * Historie zurück (tenant_id = NULL).
 *
 * asset_device_events bleibt bewusst cascadeOnDelete (enthält PII: Besitzerwechsel etc.) — wird hier
 * NICHT angefasst.
 *
 * Idempotent: nur umstellen, wenn der bestehende FK noch 'cascade' ist. tenant_id ist nullable
 * (2026_06_17_000004) → nullOnDelete zulässig. dropForeign lässt den stützenden Index stehen → der neue
 * FK findet ihn wieder (kein Fehler 1553).
 */
return new class extends Migration
{
    private array $tables = ['asset_device_sync_logs', 'asset_license_sync_logs'];

    public function up(): void
    {
        $this->swapTenantOnDelete('cascade', fn (Blueprint $t) => $t->foreign('tenant_id')
            ->references('id')->on('asset_tenants')->nullOnDelete());
    }

    public function down(): void
    {
        $this->swapTenantOnDelete('set null', fn (Blueprint $t) => $t->foreign('tenant_id')
            ->references('id')->on('asset_tenants')->cascadeOnDelete());
    }

    /**
     * Stellt den tenant_id-FK der Sync-Log-Tabellen um — aber nur, wenn er aktuell die zu ersetzende
     * on_delete-Regel ($from) trägt (idempotent, beliebig wiederholbar).
     */
    private function swapTenantOnDelete(string $from, \Closure $addForeign): void
    {
        foreach ($this->tables as $name) {
            if (! Schema::hasColumn($name, 'tenant_id')) {
                continue;
            }

            $fk = collect(Schema::getForeignKeys($name))
                ->first(fn ($fk) => in_array('tenant_id', $fk['columns'] ?? [], true));

            // Nur umstellen, wenn ein FK mit der zu ersetzenden Regel existiert.
            if (! $fk || strtolower((string) ($fk['on_delete'] ?? '')) !== $from) {
                continue;
            }

            Schema::table($name, fn (Blueprint $t) => $t->dropForeign(['tenant_id']));
            Schema::table($name, $addForeign);
        }
    }
};
