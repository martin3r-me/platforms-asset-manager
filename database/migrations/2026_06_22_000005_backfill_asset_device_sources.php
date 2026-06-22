<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Provider-Seam (ADR 0009): Bestandsgeräte rückwirkend mit einer Intune-Quell-Zeile versorgen, damit
 * der Seam von Tag 1 vollständig ist (ABM kann später per Serial andocken + Drift rechnen). Eine Zeile
 * je Gerät: provider='intune', external_id = aktuelle intune_id. Inkl. soft-deleted Geräte (DB::table
 * umgeht den SoftDelete-Scope) — so überlebt die Quelle einen withTrashed-Restore. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('asset_device_sources') || ! Schema::hasTable('asset_devices')) {
            return;
        }

        $now = now();

        DB::table('asset_devices')->orderBy('id')->chunkById(500, function ($devices) use ($now) {
            foreach ($devices as $d) {
                // Nur Intune-Geräte mit gesetzter intune_id + Tenant (tenant_id ist FK NOT NULL).
                if (empty($d->intune_id) || empty($d->tenant_id)) {
                    continue;
                }

                DB::table('asset_device_sources')->updateOrInsert(
                    ['asset_device_id' => $d->id, 'provider' => 'intune'],
                    [
                        'team_id'       => $d->team_id,
                        'tenant_id'     => $d->tenant_id,
                        'external_id'   => $d->intune_id,
                        'serial_number' => $d->serial_number,
                        'last_seen_at'  => $d->last_check_in_at,
                        'created_at'    => $now,
                        'updated_at'    => $now,
                    ]
                );
            }
        });
    }

    public function down(): void
    {
        // Reine Daten-Migration — bewusst kein Rollback (die Tabelle selbst droppt 000003).
    }
};
