<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Von Hand gesetzter Träger-Typ wird festgehalten (#896).
 *
 * Bisher war „manuell gesetzt" nur implizit über „nicht Default" ableitbar — und diese Ableitung
 * stimmt für genau einen Wert nicht: wer einen falsch erkannten Träger von Hand auf `person`
 * zurückstellt, verlor das beim nächsten Sync an die Tenant-Regel. Das Flag macht die Vorrangfolge
 * im {@see \Platform\AssetManager\Services\HolderClassifier} ehrlich.
 *
 * Kein Backfill: ob ein heute gesetzter Nicht-`person`-Typ von Hand oder von einer Regel stammt, ist
 * nicht mehr feststellbar. Die bisherige Stufe „gesetzter Nicht-Default-Typ bleibt" trägt den
 * Bestand weiter; festgehalten wird ab jetzt, wo ein Mensch den Typ setzt.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('asset_holders') && ! Schema::hasColumn('asset_holders', 'holder_type_pinned')) {
            Schema::table('asset_holders', function (Blueprint $table) {
                $table->boolean('holder_type_pinned')->default(false)->after('holder_type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('asset_holders') && Schema::hasColumn('asset_holders', 'holder_type_pinned')) {
            Schema::table('asset_holders', function (Blueprint $table) {
                $table->dropColumn('holder_type_pinned');
            });
        }
    }
};
