<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entra-Anreicherung + Mobilfunk-Metadaten am Mitarbeiter (ADR 0014).
 *
 * Rufnummern kommen aus Microsoft Graph (mobilePhone / businessPhones); `phone_overridden` schützt eine
 * manuell korrigierte Nummer vor dem nächsten Sync. Die Mobilfunk-Metadaten (SIM/Vertragsnr./Volumen)
 * sind reine Stammdaten am Mitarbeiter — 1:1, KEIN eigenes Vertrags-Modell. Die Kosten bleiben eine
 * Mobilfunk-Kostenposition (`cost_line`, key='mobilfunk'), nicht hier.
 *
 * Idempotente Guards, plain Schema (keine MySQL-only-SQL) — läuft auf MySQL live wie auf SQLite/Postgres.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_employees', function (Blueprint $table) {
            if (! Schema::hasColumn('asset_employees', 'mobile_phone')) {
                $table->string('mobile_phone')->nullable()->after('job_title');
            }
            if (! Schema::hasColumn('asset_employees', 'business_phone')) {
                $table->string('business_phone')->nullable()->after('mobile_phone');
            }
            if (! Schema::hasColumn('asset_employees', 'phone_overridden')) {
                $table->boolean('phone_overridden')->default(false)->after('business_phone');
            }
            if (! Schema::hasColumn('asset_employees', 'sim_number')) {
                $table->string('sim_number')->nullable()->after('phone_overridden');
            }
            if (! Schema::hasColumn('asset_employees', 'contract_number')) {
                $table->string('contract_number')->nullable()->after('sim_number');
            }
            if (! Schema::hasColumn('asset_employees', 'data_volume')) {
                $table->string('data_volume')->nullable()->after('contract_number');
            }
        });
    }

    public function down(): void
    {
        Schema::table('asset_employees', function (Blueprint $table) {
            foreach (['mobile_phone', 'business_phone', 'phone_overridden', 'sim_number', 'contract_number', 'data_volume'] as $column) {
                if (Schema::hasColumn('asset_employees', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
