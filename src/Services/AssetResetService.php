<?php

namespace Platform\AssetManager\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\AssetManager\Models\AssetAssignment;
use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Models\AssetCostLine;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetDeviceEvent;
use Platform\AssetManager\Models\AssetDeviceModel;
use Platform\AssetManager\Models\AssetDeviceSource;
use Platform\AssetManager\Models\AssetDeviceSyncLog;
use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Models\AssetHandover;
use Platform\AssetManager\Models\AssetItem;
use Platform\AssetManager\Models\AssetLicenseSku;
use Platform\AssetManager\Models\AssetLicenseSyncLog;
use Platform\AssetManager\Models\AssetUserLicense;
use Platform\AssetManager\Models\AssetVendor;

/**
 * Setzt ein Team vollständig auf null zurück: löscht **alle fachlichen Einträge** (Inventar, Geräte,
 * Asset-Träger, Zuordnungen, Ausgaben, Kostenzeilen, Lizenzen, Historie und team-eigene Stammdaten).
 *
 * Bewusst **nicht** angefasst (die „Anbindung" bleibt, damit Intune/Azure nicht neu eingerichtet werden muss):
 *   - asset_tenants, asset_connector_configs, asset_tenant_selections — die Intune-/Azure-Anbindung
 *   - asset_team_settings — u. a. der Controlling-Schalter ({@see ControllingContext})
 *   - asset_categories — GLOBAL (von allen Teams geteilt), niemals team-scoped löschbar
 *
 * Nach dem Reset holt der nächste Intune-Sync die Geräte automatisch zurück.
 *
 * Strikt team-scoped (Multi-Tenant-Leitplanke) — löscht ausschließlich Zeilen des übergebenen Teams,
 * dabei aber ALLE Tenants dieses Teams. Alles in einer Transaction: entweder komplett oder gar nicht.
 *
 * ⚠️ Jede Query hier ruft ausdrücklich `withoutTenantScope()`. Ohne das würde der Global Scope aus
 * docs/adr/0016 den Reset stillschweigend auf den **aktiven** Tenant einschränken — der Button heißt
 * „Team zurücksetzen", würde dann aber nur einen Kunden leeren und die übrigen unangetastet lassen.
 * Genau der Fehlermodus, den ein Reset niemals haben darf: er sähe erfolgreich aus.
 *
 * Löschreihenfolge = Kinder vor Eltern. FKs sind durchweg cascadeOnDelete (Kinder gehen mit) oder
 * nullOnDelete (kein RESTRICT-Block), daher constraint-sicher. Für die vier SoftDelete-Modelle
 * (Item, Device, Handover, CostLine) wird `withTrashed()->forceDelete()` verwendet, damit auch
 * bereits soft-gelöschte Karteileichen physisch verschwinden ("wirklich auf null").
 */
class AssetResetService
{
    /**
     * @return array<string,int> Anzahl gelöschter Zeilen je Bereich.
     */
    public function resetTeam(int $teamId): array
    {
        return DB::transaction(function () use ($teamId) {
            // Item-/Device-IDs des Teams (inkl. soft-gelöschter) für die Zuordnungs-Bereinigung.
            $itemIds   = AssetItem::withoutTenantScope()->withTrashed()->where('team_id', $teamId)->pluck('id')->all();
            $deviceIds = AssetDevice::withoutTenantScope()->withTrashed()->where('team_id', $teamId)->pluck('id')->all();

            // 1) Zuordnungs-Verlauf. asset_assignments hat kein eigenes team_id: Item-Zeilen tragen
            //    asset_item_id (FK-cascade greift zwar bei forceDelete der Items, aber Device-Zeilen
            //    hängen nur am String-Diskriminator ohne FK) → hier explizit über beide Wege scopen.
            $assignments = AssetAssignment::withoutTenantScope()
                ->where(function ($q) use ($itemIds, $deviceIds) {
                    $q->whereIn('asset_item_id', $itemIds)
                        ->orWhere(function ($qq) use ($deviceIds) {
                            $qq->where('assignable_type', AssetAssignment::SUBJECT_DEVICE)
                                ->whereIn('assignable_id', $deviceIds);
                        });
                })
                ->delete();

            // 2) Geräte-Historie / Sync-Logs (eigenständiges team_id).
            $events        = AssetDeviceEvent::withoutTenantScope()->where('team_id', $teamId)->delete();
            $sources       = AssetDeviceSource::where('team_id', $teamId)->delete();
            $deviceSync    = AssetDeviceSyncLog::withoutTenantScope()->where('team_id', $teamId)->delete();
            $licenseSync   = AssetLicenseSyncLog::withoutTenantScope()->where('team_id', $teamId)->delete();

            // 3) Kostenzeilen (vor cost_types löschen: cost_lines→cost_types ist cascadeOnDelete).
            $costLines = AssetCostLine::withoutTenantScope()->withTrashed()->where('team_id', $teamId)->forceDelete();

            // 4) Ausgabe-Protokolle (cascadet asset_handover_lines via FK), dann Kern-Inventar.
            $handovers = AssetHandover::withoutTenantScope()->withTrashed()->where('team_id', $teamId)->forceDelete();
            $items     = AssetItem::withoutTenantScope()->withTrashed()->where('team_id', $teamId)->forceDelete();
            $devices   = AssetDevice::withoutTenantScope()->withTrashed()->where('team_id', $teamId)->forceDelete();

            // 5) Lizenzen.
            $userLicenses = AssetUserLicense::withoutTenantScope()->where('team_id', $teamId)->delete();
            $licenseSkus  = AssetLicenseSku::withoutTenantScope()->where('team_id', $teamId)->delete();

            // 6) Asset-Träger (ADR 0017).
            $holders = AssetHolder::withoutTenantScope()->where('team_id', $teamId)->delete();

            // 7) Team-eigene Stammdaten (untereinander nur nullOnDelete-FKs → Reihenfolge unkritisch).
            $deviceModels = AssetDeviceModel::where('team_id', $teamId)->delete();
            $costCenters  = AssetCostCenter::withoutTenantScope()->where('team_id', $teamId)->delete();
            $vendors      = AssetVendor::withoutTenantScope()->where('team_id', $teamId)->delete();
            $costTypes    = AssetCostType::withoutTenantScope()->where('team_id', $teamId)->delete();

            $stats = [
                'assignments'   => (int) $assignments,
                'device_events' => (int) $events,
                'device_sources' => (int) $sources,
                'device_sync_logs' => (int) $deviceSync,
                'license_sync_logs' => (int) $licenseSync,
                'cost_lines'    => (int) $costLines,
                'handovers'     => (int) $handovers,
                'items'         => (int) $items,
                'devices'       => (int) $devices,
                'user_licenses' => (int) $userLicenses,
                'license_skus'  => (int) $licenseSkus,
                'holders'       => (int) $holders,
                'device_models' => (int) $deviceModels,
                'cost_centers'  => (int) $costCenters,
                'vendors'       => (int) $vendors,
                'cost_types'    => (int) $costTypes,
            ];

            // Leichter Audit-Trail (das Modul nutzt für Bulk-Aktionen kein LogsActivity; ein
            // AssetDeviceEvent wäre sinnlos, da alle Events soeben mitgelöscht wurden).
            Log::warning('AssetManager: Team-Reset ausgeführt', [
                'team_id' => $teamId,
                'user_id' => Auth::id(),
                'stats'   => $stats,
            ]);

            return $stats;
        });
    }
}
