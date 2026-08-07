<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Support\MigrationSchema;

/**
 * Umbenennung „Mitarbeiter" → **Asset-Träger** (siehe docs/adr/0017).
 *
 * Die Entität bildet eine Beziehung Person ↔ Asset ab, kein Arbeitsverhältnis: mit drin sind Externe,
 * Admin-/Verwaltungsaccounts (keine Personen), Funktionskonten und Nicht-Menschen als Lizenzträger.
 * Über die MCP-Tools lesen KI-Modelle diese Daten — und nehmen „Mitarbeiter" wörtlich, was jede
 * Kopfzahl-Auswertung verfälscht.
 *
 *  - `asset_employees` → `asset_holders`
 *  - `asset_assignments.employee_id` → `holder_id`
 *  - `asset_handovers.employee_id` → `holder_id`
 *
 * `asset_cost_lines.assignee_id` bleibt: „assignee" ist bereits neutral und meint dasselbe.
 *
 * Reine Umbenennung, kein Datenumbau — Laravels `rename`/`renameColumn` erhalten Indizes und Foreign
 * Keys. Idempotent über Existenz-Prüfungen, damit ein Teil-Abbruch nachgeholt werden kann.
 *
 * Hinweis zu MySQL: `renameColumn` auf einer FK-Spalte ist zulässig (der Constraint zeigt danach auf
 * den neuen Namen), der Constraint-NAME bleibt aber der alte (`…_employee_id_foreign`). Das ist
 * kosmetisch und wird bewusst nicht angefasst — ihn umzubenennen hieße FK droppen und neu anlegen, und
 * genau dort lauert die 1553-Falle.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('asset_employees') && ! Schema::hasTable('asset_holders')) {
            Schema::rename('asset_employees', 'asset_holders');
        }

        $this->renameEmployeeColumn('asset_assignments', 'employee_id', 'holder_id');
        $this->renameEmployeeColumn('asset_handovers', 'employee_id', 'holder_id');
    }

    public function down(): void
    {
        $this->renameEmployeeColumn('asset_handovers', 'holder_id', 'employee_id');
        $this->renameEmployeeColumn('asset_assignments', 'holder_id', 'employee_id');

        if (Schema::hasTable('asset_holders') && ! Schema::hasTable('asset_employees')) {
            Schema::rename('asset_holders', 'asset_employees');
        }
    }

    private function renameEmployeeColumn(string $table, string $from, string $to): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $from) || Schema::hasColumn($table, $to)) {
            return;
        }

        Schema::table($table, fn (Blueprint $t) => $t->renameColumn($from, $to));

        // Der Index auf der alten Spalte trägt den alten Namen weiter. Sicherstellen, dass die neue
        // Spalte indiziert bleibt (stützt auf MySQL den FK) — ensureIndex prüft die Spalten, nicht den Namen.
        MigrationSchema::ensureIndex($table, [$to]);
    }
};
