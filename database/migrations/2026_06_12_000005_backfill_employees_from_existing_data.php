<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Einmaliger Backfill: legt für jede UPN aus asset_devices und asset_user_licenses
     * einen Employee an. Idempotent — bestehende werden nicht überschrieben.
     */
    public function up(): void
    {
        // Sicherheits-Check: alle benötigten Tabellen vorhanden?
        if (!Schema::hasTable('asset_employees') || !Schema::hasTable('asset_devices')) {
            return;
        }

        $now = now();

        // Schritt 1: UPNs aus asset_devices
        $deviceRows = DB::table('asset_devices')
            ->whereNotNull('user_principal_name')
            ->select('team_id', 'user_principal_name', 'user_display_name')
            ->distinct()
            ->get();

        foreach ($deviceRows as $row) {
            $exists = DB::table('asset_employees')
                ->where('team_id', $row->team_id)
                ->where('user_principal_name', $row->user_principal_name)
                ->exists();

            if (!$exists) {
                DB::table('asset_employees')->insert([
                    'team_id'             => $row->team_id,
                    'user_principal_name' => $row->user_principal_name,
                    'display_name'        => $row->user_display_name,
                    'email'               => $row->user_principal_name,
                    'is_active'           => true,
                    'source'              => 'derived',
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ]);
            }
        }

        // Schritt 2: UPNs aus asset_user_licenses
        if (Schema::hasTable('asset_user_licenses')) {
            $licenseRows = DB::table('asset_user_licenses')
                ->whereNotNull('user_principal_name')
                ->select('team_id', 'user_principal_name', 'display_name')
                ->distinct()
                ->get();

            foreach ($licenseRows as $row) {
                $existing = DB::table('asset_employees')
                    ->where('team_id', $row->team_id)
                    ->where('user_principal_name', $row->user_principal_name)
                    ->first();

                if (!$existing) {
                    DB::table('asset_employees')->insert([
                        'team_id'             => $row->team_id,
                        'user_principal_name' => $row->user_principal_name,
                        'display_name'        => $row->display_name,
                        'email'               => $row->user_principal_name,
                        'is_active'           => true,
                        'source'              => 'derived',
                        'created_at'          => $now,
                        'updated_at'          => $now,
                    ]);
                } elseif (empty($existing->display_name) && !empty($row->display_name)) {
                    // Display-Name nachziehen wenn vorher leer
                    DB::table('asset_employees')
                        ->where('id', $existing->id)
                        ->update([
                            'display_name' => $row->display_name,
                            'updated_at'   => $now,
                        ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Nicht reversibel — Daten bleiben drin
    }
};
