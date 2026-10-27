<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Doppelte Kategorie „Drucker" zusammenführen (Discussion #27).
 *
 * Die Grunddaten legen „Drucker" unter dem Schlüssel `printer` an. Der Excel-Import suchte nach `drucker`,
 * fand nichts und legte eine zweite Kategorie gleichen Namens an — im Inventar-Filter standen danach zwei
 * „Drucker". Seit dieser Änderung nutzt der Import `printer`; hier werden die vorhandenen Assets umgehängt
 * und die Dublette entfernt. Kategorien sind global (kein Team), die Assets aller Teams ziehen mit.
 *
 * Idempotent: ohne `drucker` passiert nichts. Fehlt `printer` (Grunddaten gelöscht), wird `drucker`
 * stattdessen auf den Schlüssel `printer` umgestellt.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('asset_categories')) {
            return;
        }

        $duplicate = DB::table('asset_categories')->where('key', 'drucker')->first();
        if ($duplicate === null) {
            return;
        }

        $canonical = DB::table('asset_categories')->where('key', 'printer')->first();

        DB::transaction(function () use ($duplicate, $canonical) {
            if ($canonical === null) {
                DB::table('asset_categories')->where('id', $duplicate->id)->update(['key' => 'printer', 'updated_at' => now()]);

                return;
            }

            if (Schema::hasTable('asset_items')) {
                // Auch gelöschte Assets umhängen — sonst zeigte ein Wiederherstellen auf eine Kategorie, die es nicht mehr gibt.
                DB::table('asset_items')->where('category_id', $duplicate->id)->update(['category_id' => $canonical->id]);
            }

            DB::table('asset_categories')->where('id', $duplicate->id)->delete();
        });
    }

    public function down(): void
    {
        // Nicht umkehrbar: welche Assets vorher an der Dublette hingen, ist nach dem Zusammenführen nicht mehr bekannt.
    }
};
