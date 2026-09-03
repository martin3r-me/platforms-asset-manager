<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Abrechnungs-Ausnahme: die Zählregel bekommt eine dritte Stufe (siehe docs/adr/0024).
 *
 * **Am Träger** drei Felder statt eines Häkchens. Ein Boolean hätte gesagt „zählt nicht" und die
 * einzige Frage offen gelassen, die drei Monate später gestellt wird: *warum nicht, und seit wann?*
 * Der Zeitstempel ist zugleich die Unterscheidung zwischen „nie angefasst" (NULL — die Regel
 * entscheidet) und „bewusst herausgenommen".
 *
 * `billing_excluded_at` ist indiziert, weil die Trägerliste, die Vorschau des Rechnungslaufs und die
 * Aggregation am Profil jeweils darauf zählen.
 *
 * **Am Profil** zwei Regel-Erweiterungen, die ganze Fallgruppen erledigen, ohne dass jemand pro Kopf
 * etwas pflegen muss:
 *
 * - `count_external` — Externe (`holder_type = external`) sind heute mitgezählt, weil sie nicht in
 *   `AssetHolder::NON_PERSON_TYPES` stehen. Default `true` hält genau dieses Verhalten: ein
 *   bestehendes Profil darf seine Menge nicht dadurch ändern, dass eine Migration läuft. Wer Externe
 *   nicht berechnet, schaltet es bewusst ab und sieht die Zahl vorher in der Vorschau.
 * - `exclude_patterns` — Namensmuster (Testkonten, technische Kennungen) im Format der
 *   `holder_type_rules` des HolderClassifier. Eine Regel für zwölf Testkonten ist besser als zwölf
 *   Einzelausnahmen, die niemand mehr aufräumt.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('asset_holders')) {
            Schema::table('asset_holders', function (Blueprint $table) {
                if (! Schema::hasColumn('asset_holders', 'billing_excluded_at')) {
                    $table->timestamp('billing_excluded_at')->nullable()->after('is_active');
                }

                if (! Schema::hasColumn('asset_holders', 'billing_excluded_reason')) {
                    $table->string('billing_excluded_reason')->nullable()->after('billing_excluded_at');
                }

                if (! Schema::hasColumn('asset_holders', 'billing_excluded_by')) {
                    $table->unsignedBigInteger('billing_excluded_by')->nullable()->after('billing_excluded_reason');
                }
            });

            // Getrennter Aufruf: `hasIndex()` würde die eben in derselben Closure angelegte Spalte
            // noch nicht sehen. Der Name ist explizit, weil ein automatisch erzeugter je Datenbank
            // anders lautet und ein zweiter Lauf ihn dann nicht wiederfindet.
            Schema::table('asset_holders', function (Blueprint $table) {
                if (Schema::hasColumn('asset_holders', 'billing_excluded_at')
                    && ! $this->hasIndex('asset_holders', 'asset_holders_billing_excluded_at_index')) {
                    $table->index('billing_excluded_at', 'asset_holders_billing_excluded_at_index');
                }
            });
        }

        if (Schema::hasTable('asset_billing_profiles')) {
            Schema::table('asset_billing_profiles', function (Blueprint $table) {
                if (! Schema::hasColumn('asset_billing_profiles', 'count_external')) {
                    $table->boolean('count_external')->default(true)->after('basis');
                }

                if (! Schema::hasColumn('asset_billing_profiles', 'exclude_patterns')) {
                    $table->json('exclude_patterns')->nullable()->after('exclude_domains');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('asset_holders')) {
            Schema::table('asset_holders', function (Blueprint $table) {
                if ($this->hasIndex('asset_holders', 'asset_holders_billing_excluded_at_index')) {
                    $table->dropIndex('asset_holders_billing_excluded_at_index');
                }

                foreach (['billing_excluded_at', 'billing_excluded_reason', 'billing_excluded_by'] as $column) {
                    if (Schema::hasColumn('asset_holders', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('asset_billing_profiles')) {
            Schema::table('asset_billing_profiles', function (Blueprint $table) {
                foreach (['count_external', 'exclude_patterns'] as $column) {
                    if (Schema::hasColumn('asset_billing_profiles', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }

    /**
     * Index-Existenz ohne Doctrine und ohne MySQL-only-SQL — SQLite und MySQL antworten hier beide
     * (siehe die Migrations-Fallen in der Modul-Doku: `SHOW INDEX` allein hätte lokal nie gegriffen).
     */
    protected function hasIndex(string $table, string $index): bool
    {
        try {
            return collect(Schema::getIndexes($table))
                ->contains(fn ($i) => ($i['name'] ?? null) === $index);
        } catch (\Throwable) {
            return false;
        }
    }
};
