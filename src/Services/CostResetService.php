<?php

namespace Platform\AssetManager\Services;

use Illuminate\Support\Facades\DB;
use Platform\AssetManager\Models\AssetCategory;
use Platform\AssetManager\Models\AssetCostLine;
use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Models\AssetItem;

/**
 * Macht den Excel-Kosten-Import vollständig rückgängig (Stammdaten bleiben erhalten).
 *
 * Löscht ausschließlich, was der Import erzeugt hat:
 *   - Cost-Lines mit source='excel_import'
 *   - Import-Assets (source='manual') in den Import-Kategorien laptop/internet/drucker
 *   - synthetische Funktionskonten (UPN …@funktion.import.local)
 * und setzt die import-gesetzten Kostenstellen der Funktionskonten zurück (sauberer Schnitt) —
 * die Kostenstelle ist künftig manuell gepflegte Quelle der Wahrheit.
 *
 * **Tenant-scoped** (docs/adr/0016): der Import lief für genau einen Tenant, also wird auch nur
 * dessen Bestand zurückgesetzt. Der Tenant wird ausdrücklich übergeben bzw. aus dem aktiven Kontext
 * aufgelöst — nicht dem Global Scope überlassen, damit der Aufrufer sieht, was gelöscht wird.
 * Gegenstück: {@see AssetResetService} setzt ein ganzes Team über alle Tenants zurück.
 *
 * NICHT angefasst: Intune-Geräte (asset_devices, eigene Tabelle) und Stammdaten
 * (Kostenstellen-Baum, Kreditoren, Kostenarten).
 */
class CostResetService
{
    /** Kategorien, die der Import als asset_items anlegt. */
    private const IMPORT_ITEM_CATEGORIES = ['laptop', 'internet', 'drucker'];

    /**
     * @return array{cost_lines:int, assets:int, holders:int, cleared_cost_centers:int}
     */
    public function clearImport(int $teamId, ?int $tenantId = null): array
    {
        $tenantId ??= TenantContext::scopeTenantId() ?? TenantContext::defaultTenantId($teamId);

        return DB::transaction(function () use ($teamId, $tenantId) {
            // 1) Import-Cost-Lines (hart löschen → kein Konflikt mit import_hash-Upserts beim Re-Import)
            $costLines = AssetCostLine::withoutTenantScope()
                ->where('team_id', $teamId)
                ->where('tenant_id', $tenantId)
                ->where('source', 'excel_import')
                ->forceDelete();

            // 2) Import-Assets (Laptops/Internet/Drucker). asset_assignments cascaden automatisch.
            // asset_categories ist GLOBAL (kein Tenant) — hier bewusst ohne Tenant-Filter.
            $catIds = AssetCategory::whereIn('key', self::IMPORT_ITEM_CATEGORIES)->pluck('id');
            $assets = $catIds->isEmpty() ? 0 : AssetItem::withoutTenantScope()
                ->where('team_id', $teamId)
                ->where('tenant_id', $tenantId)
                ->where('source', 'manual')
                ->whereIn('category_id', $catIds)
                ->forceDelete();

            // 3) Synthetische Funktionskonten (Kostenstellen-Codes, die fälschlich als Träger angelegt wurden)
            $holders = AssetHolder::withoutTenantScope()
                ->where('team_id', $teamId)
                ->where('tenant_id', $tenantId)
                ->where('user_principal_name', 'like', '%@funktion.import.local')
                ->delete();

            // 4) Import-gesetzte Kostenstellen NUR bei import-erzeugten Konten zurücksetzen — also
            //    synthetischen Funktionskonten (holder_type='function' bzw. UPN …@funktion.import.local).
            //    NIEMALS unbedingt alle Träger nullen: echte Graph-/manuell gepflegte Träger sind die
            //    Quelle der Wahrheit für ihre Kostenstelle — würden sie hier genullt, kollabiert der
            //    Geräte-/Lizenz-Pivot in „Ohne Kostenstelle" und manuell gepflegte Zuordnungen gehen
            //    verloren. (Die @funktion.import.local-Konten sind in Schritt 3 bereits gelöscht; dieser
            //    Schritt fängt zusätzlich import-markierte Funktionskonten mit abweichender UPN ab.)
            $clearedCostCenters = AssetHolder::withoutTenantScope()
                ->where('team_id', $teamId)
                ->where('tenant_id', $tenantId)
                ->where(function ($q) {
                    $q->where('holder_type', 'function')
                      ->orWhere('user_principal_name', 'like', '%@funktion.import.local');
                })
                ->where(fn ($q) => $q->whereNotNull('cost_center_id')->orWhereNotNull('cost_center'))
                ->update(['cost_center_id' => null, 'cost_center' => null]);

            return [
                'cost_lines'           => (int) $costLines,
                'assets'               => (int) $assets,
                'holders'              => (int) $holders,
                'cleared_cost_centers' => (int) $clearedCostCenters,
            ];
        });
    }
}
