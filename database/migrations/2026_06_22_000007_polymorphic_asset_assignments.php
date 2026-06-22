<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Zuordnungs-Verlauf für Geräte (Track B 2b, Frage 6): `asset_assignments` wird vom Item-only-Modell
 * auf ein gemeinsames Zuordnungs-Konzept generalisiert. Statt Eloquent-`morphTo` (das Modul nutzt keine
 * Polymorphie; Plattform hatte eine enforceMorphMap-Falle) ein **stabiler String-Diskriminator**:
 * assignable_type ∈ {'item','device'} + assignable_id. `source` ∈ {'manual','intune'}.
 *
 * Item-Pfad bleibt back-compat: asset_item_id wird für Items weiter gesetzt (AssetItem::assignments()
 * hängt daran), wird aber nullable, damit Geräte-Zeilen es leer lassen können. Additiv bis auf den
 * nullable-Umbau des FK — geguardet, kein Index-Drop (der (asset_item_id, returned_at)-Index stützt den
 * FK weiter → kein MySQL-1553).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('asset_assignments', 'assignable_type')) {
            Schema::table('asset_assignments', function (Blueprint $table) {
                $table->string('assignable_type')->nullable()->after('id');
                $table->unsignedBigInteger('assignable_id')->nullable()->after('assignable_type');
                $table->index(['assignable_type', 'assignable_id', 'returned_at'], 'asset_assignments_subject_index');
            });
        }

        if (! Schema::hasColumn('asset_assignments', 'source')) {
            Schema::table('asset_assignments', function (Blueprint $table) {
                $table->string('source')->default('manual')->after('notes');
            });
        }

        // Bestehende (Item-)Zeilen auf den Diskriminator backfillen.
        DB::table('asset_assignments')
            ->whereNull('assignable_type')
            ->whereNotNull('asset_item_id')
            ->update(['assignable_type' => 'item', 'assignable_id' => DB::raw('asset_item_id')]);

        // asset_item_id nullable machen (Geräte-Zeilen tragen es nicht): FK lösen → nullable → FK neu.
        // Der (asset_item_id, returned_at)-Index bleibt und stützt den FK weiter (kein 1553). Idempotent.
        $hasItemFk = collect(Schema::getForeignKeys('asset_assignments'))
            ->contains(fn ($fk) => in_array('asset_item_id', $fk['columns'] ?? [], true));
        if ($hasItemFk) {
            Schema::table('asset_assignments', fn (Blueprint $t) => $t->dropForeign(['asset_item_id']));
        }

        Schema::table('asset_assignments', fn (Blueprint $t) => $t->foreignId('asset_item_id')->nullable()->change());

        $stillHasItemFk = collect(Schema::getForeignKeys('asset_assignments'))
            ->contains(fn ($fk) => in_array('asset_item_id', $fk['columns'] ?? [], true));
        if (! $stillHasItemFk) {
            Schema::table('asset_assignments', fn (Blueprint $t) =>
                $t->foreign('asset_item_id')->references('id')->on('asset_items')->cascadeOnDelete());
        }
    }

    public function down(): void
    {
        // Best-effort: neue Spalten/Index entfernen. asset_item_id bleibt nullable (ein erzwungenes
        // NOT NULL würde mit vorhandenen Geräte-Zeilen scheitern).
        if (Schema::hasColumn('asset_assignments', 'assignable_type')) {
            Schema::table('asset_assignments', function (Blueprint $table) {
                $table->dropIndex('asset_assignments_subject_index');
                $table->dropColumn(['assignable_type', 'assignable_id']);
            });
        }
        if (Schema::hasColumn('asset_assignments', 'source')) {
            Schema::table('asset_assignments', fn (Blueprint $t) => $t->dropColumn('source'));
        }
    }
};
