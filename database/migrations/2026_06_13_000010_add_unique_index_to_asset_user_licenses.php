<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Macht (team_id, user_principal_name, sku_id) eindeutig — eine Lizenz-Zuweisung pro User & SKU & Team.
 * Damit ist SyncLicensesJob::updateOrCreate atomar und parallele Läufe können keine Dubletten erzeugen
 * (zusätzlich serialisiert über ShouldBeUnique im Job). Bestehende Duplikate werden vorher bereinigt.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Bestehende Duplikate bereinigen: je (team_id, user_principal_name, sku_id) nur die jüngste
        //    Zeile (höchste id) behalten, ältere hart entfernen — sonst schlägt der Unique-Index fehl.
        $dupes = DB::table('asset_user_licenses')
            ->select('team_id', 'user_principal_name', 'sku_id', DB::raw('MAX(id) as keep_id'), DB::raw('COUNT(*) as cnt'))
            ->groupBy('team_id', 'user_principal_name', 'sku_id')
            ->having('cnt', '>', 1)
            ->get();

        foreach ($dupes as $d) {
            DB::table('asset_user_licenses')
                ->where('team_id', $d->team_id)
                ->where('user_principal_name', $d->user_principal_name)
                ->where('sku_id', $d->sku_id)
                ->where('id', '!=', $d->keep_id)
                ->delete();
        }

        // 2) Unique-Index setzen.
        Schema::table('asset_user_licenses', function (Blueprint $table) {
            $table->unique(['team_id', 'user_principal_name', 'sku_id'], 'asset_user_licenses_team_upn_sku_unique');
        });
    }

    public function down(): void
    {
        Schema::table('asset_user_licenses', function (Blueprint $table) {
            $table->dropUnique('asset_user_licenses_team_upn_sku_unique');
        });
    }
};
