<?php

namespace Platform\AssetManager\Services;

use Illuminate\Support\Facades\DB;
use Platform\AssetManager\Models\AssetCategory;
use Platform\AssetManager\Models\AssetCostLine;
use Platform\AssetManager\Models\AssetEmployee;
use Platform\AssetManager\Models\AssetItem;

/**
 * Macht den Excel-Kosten-Import vollständig rückgängig (Stammdaten bleiben erhalten).
 *
 * Löscht ausschließlich, was der Import erzeugt hat:
 *   - Cost-Lines mit source='excel_import'
 *   - Import-Assets (source='manual') in den Import-Kategorien laptop/internet/drucker
 *   - synthetische Funktionskonten (UPN …@funktion.import.local)
 * und setzt die import-gesetzten Kostenstellen aller echten Mitarbeiter zurück (sauberer Schnitt) —
 * die Kostenstelle ist künftig manuell gepflegte Quelle der Wahrheit.
 *
 * NICHT angefasst: Intune-Geräte (asset_devices, eigene Tabelle) und Stammdaten
 * (Gesellschaften, Kostenstellen, Kreditoren, Kostenarten).
 */
class CostResetService
{
    /** Kategorien, die der Import als asset_items anlegt. */
    private const IMPORT_ITEM_CATEGORIES = ['laptop', 'internet', 'drucker'];

    /**
     * @return array{cost_lines:int, assets:int, employees:int, cleared_cost_centers:int}
     */
    public function clearImport(int $teamId): array
    {
        return DB::transaction(function () use ($teamId) {
            // 1) Import-Cost-Lines (hart löschen → kein Konflikt mit import_hash-Upserts beim Re-Import)
            $costLines = AssetCostLine::where('team_id', $teamId)
                ->where('source', 'excel_import')
                ->forceDelete();

            // 2) Import-Assets (Laptops/Internet/Drucker). asset_assignments cascaden automatisch.
            $catIds = AssetCategory::whereIn('key', self::IMPORT_ITEM_CATEGORIES)->pluck('id');
            $assets = $catIds->isEmpty() ? 0 : AssetItem::where('team_id', $teamId)
                ->where('source', 'manual')
                ->whereIn('category_id', $catIds)
                ->forceDelete();

            // 3) Synthetische Funktionskonten (Kostenstellen-Codes, die fälschlich als Mitarbeiter angelegt wurden)
            $employees = AssetEmployee::where('team_id', $teamId)
                ->where('user_principal_name', 'like', '%@funktion.import.local')
                ->delete();

            // 4) Import-gesetzte Kostenstellen NUR bei import-erzeugten Konten zurücksetzen — also
            //    synthetischen Funktionskonten (account_type='function' bzw. UPN …@funktion.import.local).
            //    NIEMALS unbedingt alle Mitarbeiter nullen: echte Graph-/manuell gepflegte Mitarbeiter
            //    sind die Quelle der Wahrheit für ihre Kostenstelle — würden sie hier genullt, kollabiert
            //    der Geräte-/Lizenz-Pivot in „Ohne Kostenstelle" und manuell gepflegte Zuordnungen gehen
            //    verloren. (Die @funktion.import.local-Konten sind in Schritt 3 bereits gelöscht; dieser
            //    Schritt fängt zusätzlich import-markierte Funktionskonten mit abweichender UPN ab.)
            $clearedCostCenters = AssetEmployee::where('team_id', $teamId)
                ->where(function ($q) {
                    $q->where('account_type', 'function')
                      ->orWhere('user_principal_name', 'like', '%@funktion.import.local');
                })
                ->where(fn ($q) => $q->whereNotNull('cost_center_id')->orWhereNotNull('cost_center'))
                ->update(['cost_center_id' => null, 'cost_center' => null]);

            return [
                'cost_lines'           => (int) $costLines,
                'assets'               => (int) $assets,
                'employees'            => (int) $employees,
                'cleared_cost_centers' => (int) $clearedCostCenters,
            ];
        });
    }
}
