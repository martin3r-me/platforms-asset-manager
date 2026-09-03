<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Id des Anhangs, den der Lauf an den easybill-Beleg gehängt hat (die User-Pauschale als PDF).
 *
 * Nullable und ohne Fremdschlüssel: easybill ist ein fremdes System, der Anhang kann dort gelöscht
 * werden, und ältere Läufe haben gar keinen. Der Wert ist eine Spur, kein Bezug — er beantwortet
 * die Frage „hat der Lauf die Aufstellung mitgeschickt, und welche?".
 *
 * Ohne die Spalte ließe sich ein Lauf mit fehlgeschlagenem Anhang nicht von einem unterscheiden, bei
 * dem der Anhang gelang — und genau das entscheidet, ob jemand von Hand nachlegen muss.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('asset_billing_runs')) {
            return;
        }

        if (Schema::hasColumn('asset_billing_runs', 'easybill_attachment_id')) {
            return;
        }

        Schema::table('asset_billing_runs', function (Blueprint $table) {
            $table->unsignedBigInteger('easybill_attachment_id')->nullable()->after('easybill_document_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('asset_billing_runs', 'easybill_attachment_id')) {
            Schema::table('asset_billing_runs', function (Blueprint $table) {
                $table->dropColumn('easybill_attachment_id');
            });
        }
    }
};
