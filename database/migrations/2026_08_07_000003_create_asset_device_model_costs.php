<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Support\MigrationSchema;

/**
 * Tenant-Welle 3/5 — Modell-Kosten vom team-weiten Hardware-Katalog trennen (siehe docs/adr/0016).
 *
 * `asset_device_models` bleibt **team-weit**: ein MacBook Air M2 ist bei jedem Kunden dasselbe Gerät,
 * Hersteller und Modell sind keine Kundendaten. Leasingrate, Kaufpreis, Kostenart und Kreditor sind
 * dagegen **pro Kunde verhandelt** und ziehen in diese Tabelle (Tenant × Modell).
 *
 * `depreciation_months` bleibt am Modell: die Nutzungsdauer ist eine Eigenschaft der Hardware, keine
 * Vertragsgröße. (Weicht bewusst von einer reinen „alle Kostenfelder wandern"-Lesart ab — der
 * AfA-Zeitraum ist dieselbe Zahl, egal wer das Gerät kauft.)
 *
 * Backfill kopiert bestehende Werte in **jeden** Tenant des Teams. Das erhält das bisherige Verhalten
 * exakt: vorher galt der Wert team-weit, also für jedes Gerät jedes Tenants. Danach kann er pro Tenant
 * abweichen, ohne dass jemand etwas nachpflegen muss.
 */
return new class extends Migration
{
    /** Felder, die vom Modell in die tenant-scoped Kostentabelle umziehen. */
    private array $movedColumns = ['monthly_cost', 'purchase_price', 'cost_type_id', 'vendor_id'];

    public function up(): void
    {
        $this->createTable();
        $this->backfillFromModels();
        $this->dropMovedColumns();
    }

    private function createTable(): void
    {
        if (Schema::hasTable('asset_device_model_costs')) {
            return;
        }

        Schema::create('asset_device_model_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('asset_tenants')->cascadeOnDelete();
            $table->foreignId('device_model_id')->constrained('asset_device_models')->cascadeOnDelete();

            $table->decimal('monthly_cost', 12, 2)->nullable();   // Leasing-Rate pro Monat
            $table->decimal('purchase_price', 12, 2)->nullable(); // Kaufpreis (AfA)
            $table->unsignedBigInteger('cost_type_id')->nullable();
            $table->unsignedBigInteger('vendor_id')->nullable();

            $table->timestamps();

            // Genau eine Kostenzeile je Tenant und Modell — updateOrCreate-Schlüssel.
            $table->unique(['tenant_id', 'device_model_id'], 'asset_device_model_costs_tenant_model_unique');

            $table->foreign('cost_type_id')->references('id')->on('asset_cost_types')->nullOnDelete();
            $table->foreign('vendor_id')->references('id')->on('asset_vendors')->nullOnDelete();
        });
    }

    /**
     * Bestehende Modell-Kosten in jeden Tenant des Teams kopieren. Kostenart und Kreditor werden dabei
     * auf die tenant-eigenen Stammdaten umgeschlüsselt (Welle 1 hat sie tenant-gebunden gemacht) —
     * ein `cost_type_id` aus einem anderen Tenant wäre nach der Welle eine Grenzverletzung.
     */
    private function backfillFromModels(): void
    {
        // Nur die Spalten betrachten, die noch da sind — ein abgebrochener Vorlauf kann einen
        // Teil-Zustand hinterlassen haben, und die Wiederholung darf daran nicht scheitern.
        $present = array_values(array_filter(
            $this->movedColumns,
            fn ($column) => Schema::hasColumn('asset_device_models', $column),
        ));

        if ($present === []) {
            return; // Spalten schon umgezogen — wiederholter Lauf
        }

        $models = DB::table('asset_device_models')
            ->where(function ($q) use ($present) {
                foreach ($present as $column) {
                    $q->orWhereNotNull($column);
                }
            })
            ->orderBy('id')
            ->get();

        foreach ($models as $model) {
            $tenantIds = DB::table('asset_tenants')->where('team_id', $model->team_id)->pluck('id');

            foreach ($tenantIds as $tenantId) {
                $exists = DB::table('asset_device_model_costs')
                    ->where('tenant_id', $tenantId)
                    ->where('device_model_id', $model->id)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('asset_device_model_costs')->insert([
                    'team_id'         => $model->team_id,
                    'tenant_id'       => $tenantId,
                    'device_model_id' => $model->id,
                    'monthly_cost'    => $model->monthly_cost ?? null,
                    'purchase_price'  => $model->purchase_price ?? null,
                    'cost_type_id'    => $this->remap('asset_cost_types', $model->cost_type_id ?? null, $tenantId, 'key'),
                    'vendor_id'       => $this->remap('asset_vendors', $model->vendor_id ?? null, $tenantId, 'name'),
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
            }
        }
    }

    /**
     * Stammdaten-Referenz auf den Zieltenant umschlüsseln: gleicher fachlicher Schlüssel, aber die Zeile
     * des Zieltenants. Existiert dort keine Entsprechung, bleibt die Referenz leer statt tenant-fremd
     * zu zeigen — ein leeres Feld ist sichtbar und korrigierbar, ein Fremdverweis nicht.
     */
    private function remap(string $table, ?int $id, int $tenantId, string $keyColumn): ?int
    {
        if (! $id) {
            return null;
        }

        $key = DB::table($table)->where('id', $id)->value($keyColumn);
        if ($key === null) {
            return null;
        }

        $target = DB::table($table)
            ->where('tenant_id', $tenantId)
            ->where($keyColumn, $key)
            ->value('id');

        return $target !== null ? (int) $target : null;
    }

    private function dropMovedColumns(): void
    {
        foreach (['cost_type_id', 'vendor_id'] as $column) {
            if (Schema::hasColumn('asset_device_models', $column)) {
                MigrationSchema::dropForeignIfExists('asset_device_models', $column);
            }
        }

        $present = array_values(array_filter(
            $this->movedColumns,
            fn ($column) => Schema::hasColumn('asset_device_models', $column),
        ));

        if ($present !== []) {
            Schema::table('asset_device_models', fn (Blueprint $t) => $t->dropColumn($present));
        }
    }

    public function down(): void
    {
        MigrationSchema::addColumn('asset_device_models', 'monthly_cost', function (Blueprint $t) {
            $t->decimal('monthly_cost', 12, 2)->nullable()->after('model');
        });
        MigrationSchema::addColumn('asset_device_models', 'purchase_price', function (Blueprint $t) {
            $t->decimal('purchase_price', 12, 2)->nullable()->after('monthly_cost');
        });
        MigrationSchema::addColumn('asset_device_models', 'cost_type_id', function (Blueprint $t) {
            $t->unsignedBigInteger('cost_type_id')->nullable();
            $t->foreign('cost_type_id')->references('id')->on('asset_cost_types')->nullOnDelete();
        });
        MigrationSchema::addColumn('asset_device_models', 'vendor_id', function (Blueprint $t) {
            $t->unsignedBigInteger('vendor_id')->nullable();
            $t->foreign('vendor_id')->references('id')->on('asset_vendors')->nullOnDelete();
        });

        // Rückweg kann nur EINEN Wert je Modell tragen — der des Default-Tenants gewinnt.
        if (Schema::hasTable('asset_device_model_costs')) {
            $rows = DB::table('asset_device_model_costs as c')
                ->join('asset_tenants as t', 't.id', '=', 'c.tenant_id')
                ->orderByDesc('t.is_default')
                ->orderBy('c.id')
                ->get(['c.device_model_id', 'c.monthly_cost', 'c.purchase_price', 'c.cost_type_id', 'c.vendor_id']);

            $seen = [];
            foreach ($rows as $row) {
                if (isset($seen[$row->device_model_id])) {
                    continue;
                }
                $seen[$row->device_model_id] = true;

                DB::table('asset_device_models')->where('id', $row->device_model_id)->update([
                    'monthly_cost'   => $row->monthly_cost,
                    'purchase_price' => $row->purchase_price,
                    'cost_type_id'   => $row->cost_type_id,
                    'vendor_id'      => $row->vendor_id,
                    'updated_at'     => now(),
                ]);
            }
        }

        Schema::dropIfExists('asset_device_model_costs');
    }
};
