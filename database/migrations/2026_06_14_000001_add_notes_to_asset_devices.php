<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Freitext-Notizen je Gerät. Operative Annotation für IT-Admins (z. B. "Leihgerät",
     * "wartet auf Rückgabe", "Display-Schaden gemeldet") — bleibt beim Intune-Sync erhalten,
     * da der Sync nur die Graph-Felder via updateOrCreate schreibt und notes nie anfasst.
     */
    public function up(): void
    {
        Schema::table('asset_devices', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('cost_center_id');
        });
    }

    public function down(): void
    {
        Schema::table('asset_devices', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
