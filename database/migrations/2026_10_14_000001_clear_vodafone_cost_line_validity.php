<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bug #930: Mobilfunk-Positionen aus dem Vodafone-Import trugen den Abrechnungszeitraum ihrer
 * Rechnung als `valid_from`/`valid_to`. Die Kostenauswertungen zählen nur `validOn(now())` — nach dem
 * Ende des Zeitraums der zuletzt importierten Rechnung fiel der gesamte Mobilfunk still heraus
 * (demo: seit 15.07.2026, rund 3.950 €/Monat).
 *
 * Der Import setzt die Gültigkeit inzwischen nicht mehr. Diese Migration räumt den Bestand auf, damit
 * die Zeilen sofort wieder zählen und nicht erst nach dem nächsten Import. Nur Zeilen dieser Quelle —
 * eine von Hand gepflegte Gültigkeit an anderen Positionen bleibt unberührt. Der Zeitraum selbst bleibt
 * in `period_label` erhalten. Idempotent; kein `down()`, der alte Zustand war der Fehler.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('asset_cost_lines')) {
            return;
        }

        DB::table('asset_cost_lines')
            ->where('source', 'vodafone_invoice')
            ->where(fn ($q) => $q->whereNotNull('valid_from')->orWhereNotNull('valid_to'))
            ->update(['valid_from' => null, 'valid_to' => null]);
    }

    public function down(): void
    {
        //
    }
};
