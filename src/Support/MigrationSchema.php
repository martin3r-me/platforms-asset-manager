<?php

namespace Platform\AssetManager\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotente, MySQL-sichere Schema-Bausteine für die Migrationen des Moduls.
 *
 * Extrahiert aus 2026_06_17_000002 / 2026_06_18_000002, wo dieselbe Logik zweimal inline stand. Die
 * Tenant-Welle (2026_08_07_*, siehe docs/adr/0016) braucht sie ein drittes bis siebtes Mal — deshalb
 * hier gebündelt statt kopiert.
 *
 * Zwei Fallen, die dieser Helper abfängt:
 *
 *  1. **MySQL 1553** — ein Unique-Index, der die führende Spalte eines Foreign Keys abdeckt, stützt
 *     diesen FK. Ihn zu droppen scheitert live, obwohl SQLite (datenbankweiter Index-Namensraum, keine
 *     FK-Stütz-Semantik) grün durchläuft. Gegenmittel: vorher einen Plain-Index auf die FK-Spalte legen.
 *  2. **Halb angewendete Läufe** — bricht eine Migration mitten in einer Tabellen-Schleife ab, muss der
 *     erneute Lauf durchkommen. Jede Methode hier prüft daher zuerst den Ist-Zustand.
 */
class MigrationSchema
{
    /** Spalte nur anlegen, wenn sie fehlt. */
    public static function addColumn(string $table, string $column, callable $definition): void
    {
        if (Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, fn (Blueprint $t) => $definition($t));
    }

    /**
     * `tenant_id`-FK auf asset_tenants ergänzen (nullable — NOT NULL kommt nach dem Backfill).
     * cascadeOnDelete: Tenant löschen entfernt seine fachlichen Daten (F10, ADR 0003).
     */
    public static function addTenantColumn(string $table, string $after = 'team_id'): void
    {
        if (Schema::hasColumn($table, 'tenant_id')) {
            return;
        }

        $anchor = Schema::hasColumn($table, $after) ? $after : 'id';

        Schema::table($table, function (Blueprint $t) use ($anchor) {
            $t->foreignId('tenant_id')->nullable()->after($anchor)
                ->constrained('asset_tenants')->cascadeOnDelete();
        });
    }

    /**
     * Eine nullable Spalte auf NOT NULL ziehen — nur wenn kein NULL mehr drinsteht.
     * Wirft absichtlich, wenn doch: eine stillschweigend übersprungene Verschärfung wäre schlimmer
     * als ein abgebrochener Deploy, weil sie die Zusage „DB-seitig erzwungen" unbemerkt aushöhlt.
     */
    public static function tenantColumnNotNull(string $table): void
    {
        if (! Schema::hasColumn($table, 'tenant_id')) {
            return;
        }

        if (! self::isNullable($table, 'tenant_id')) {
            return; // bereits NOT NULL — wiederholter Lauf
        }

        $orphans = DB::table($table)->whereNull('tenant_id')->count();
        if ($orphans > 0) {
            throw new \RuntimeException(
                "[asset-manager] {$table}: {$orphans} Zeile(n) ohne tenant_id — Backfill unvollständig. "
                . 'NOT NULL wird nicht gesetzt, damit der Fehler sichtbar bleibt.'
            );
        }

        Schema::table($table, fn (Blueprint $t) =>
            $t->unsignedBigInteger('tenant_id')->nullable(false)->change());
    }

    /**
     * Unique-Index ersetzen — MySQL-sicher (FK-Stütze) + idempotent.
     *
     * @param  string  $fkColumn  Führende Spalte des zu droppenden Index (= die FK-Spalte, die er stützt).
     */
    public static function swapUnique(
        string $table,
        string $dropName,
        string $addName,
        array $addColumns,
        string $fkColumn,
    ): void {
        $indexes = collect(Schema::getIndexes($table));

        if ($indexes->contains(fn ($i) => $i['name'] === $dropName)) {
            $hasFkCover = $indexes->contains(
                fn ($i) => $i['name'] !== $dropName && (($i['columns'][0] ?? null) === $fkColumn)
            );
            if (! $hasFkCover) {
                Schema::table($table, fn (Blueprint $t) => $t->index($fkColumn));
            }
            Schema::table($table, fn (Blueprint $t) => $t->dropUnique($dropName));
        }

        if (! $indexes->contains(fn ($i) => $i['name'] === $addName)) {
            Schema::table($table, fn (Blueprint $t) => $t->unique($addColumns, $addName));
        }
    }

    /**
     * Wie swapUnique, adressiert den zu droppenden Index aber über seine Spalten statt seinen Namen —
     * für Indizes, die Laravel implizit benannt hat und deren Name je Migrationsalter abweichen kann.
     */
    public static function swapUniqueByColumns(
        string $table,
        array $dropColumns,
        string $addName,
        array $addColumns,
        string $fkColumn,
    ): void {
        $indexes = collect(Schema::getIndexes($table));
        $drop = $indexes->first(fn ($i) => ($i['unique'] ?? false) && ($i['columns'] ?? []) === $dropColumns);

        if ($drop) {
            self::swapUnique($table, $drop['name'], $addName, $addColumns, $fkColumn);

            return;
        }

        if (! $indexes->contains(fn ($i) => $i['name'] === $addName)) {
            Schema::table($table, fn (Blueprint $t) => $t->unique($addColumns, $addName));
        }
    }

    /** Plain-Index nur anlegen, wenn kein Index mit genau diesen Spalten existiert. */
    public static function ensureIndex(string $table, array $columns, ?string $name = null): void
    {
        $indexes = collect(Schema::getIndexes($table));
        if ($indexes->contains(fn ($i) => ($i['columns'] ?? []) === $columns)) {
            return;
        }

        Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
    }

    /**
     * Foreign Key über seine Spalte lösen — nur wenn er existiert. Auf SQLite ein No-op-Versuch,
     * der abgefangen wird (SQLite kann FKs nicht einzeln droppen, braucht es aber auch nicht).
     */
    public static function dropForeignIfExists(string $table, string $column): void
    {
        $exists = collect(Schema::getForeignKeys($table))
            ->contains(fn ($fk) => in_array($column, $fk['columns'] ?? [], true));

        if (! $exists) {
            return;
        }

        try {
            Schema::table($table, fn (Blueprint $t) => $t->dropForeign([$column]));
        } catch (\Throwable) {
            // SQLite: FK lebt in der Tabellendefinition, nicht als eigenes Objekt.
        }
    }

    /**
     * `tenant_id` aus einer verknüpften Tabelle erben — für Tabellen ohne eigenes `team_id`.
     *
     * Bewusst als **korrelierte Subquery**, nicht als `UPDATE … INNER JOIN`: letzteres ist MySQL-only
     * und hat im Plattform-Bestand schon ~14 Migrationen auf SQLite/Postgres zerlegt. Diese Form läuft
     * auf MySQL, SQLite und Postgres gleich.
     *
     * Setzt nur, wo der Elterndatensatz selbst einen Tenant hat — überschreibt nie ein gesetztes
     * `tenant_id` und schreibt nie NULL über NULL.
     *
     * @return int Anzahl aktualisierter Zeilen.
     */
    public static function inheritTenantFrom(
        string $table,
        string $foreignKey,
        string $parentTable,
        ?string $discriminatorColumn = null,
        ?string $discriminatorValue = null,
    ): int {
        if (! Schema::hasColumn($table, 'tenant_id') || ! Schema::hasColumn($table, $foreignKey)) {
            return 0;
        }

        $lookup = "(SELECT p.tenant_id FROM {$parentTable} p WHERE p.id = {$table}.{$foreignKey})";

        $query = DB::table($table)
            ->whereNull('tenant_id')
            ->whereNotNull($foreignKey)
            ->whereRaw("{$lookup} IS NOT NULL");

        if ($discriminatorColumn !== null) {
            $query->where($discriminatorColumn, $discriminatorValue);
        }

        return $query->update(['tenant_id' => DB::raw($lookup)]);
    }

    /** Default-Tenant je Team (legt bei Bedarf einen „Standard"-Tenant an) — für Backfills. */
    public static function defaultTenantIdsByTeam(array $teamIds): array
    {
        $map = [];

        foreach (array_unique(array_filter($teamIds)) as $teamId) {
            $tenantId = DB::table('asset_tenants')
                ->where('team_id', $teamId)
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->value('id');

            if (! $tenantId) {
                $tenantId = DB::table('asset_tenants')->insertGetId([
                    'team_id'    => $teamId,
                    'name'       => 'Standard',
                    'is_default' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $map[(int) $teamId] = (int) $tenantId;
        }

        return $map;
    }

    /** Alle Team-IDs, die in einer der Tabellen vorkommen. */
    public static function teamIdsAcross(array $tables): array
    {
        $ids = collect();

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'team_id')) {
                $ids = $ids->merge(DB::table($table)->distinct()->pluck('team_id'));
            }
        }

        return $ids->filter()->unique()->values()->all();
    }

    private static function isNullable(string $table, string $column): bool
    {
        $definition = collect(Schema::getColumns($table))->firstWhere('name', $column);

        return (bool) ($definition['nullable'] ?? true);
    }
}
