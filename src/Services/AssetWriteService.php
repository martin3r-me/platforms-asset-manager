<?php

namespace Platform\AssetManager\Services;

use Platform\AssetManager\Models\AssetAssignment;
use Platform\AssetManager\Models\AssetItem;

/**
 * Einzige Schreib-Orchestrierung für Inventar-Objekte.
 *
 * Stateless und bewusst UI-frei: KEINE Gate-/Auth-/Request-/Livewire-Imports. Der aufrufende
 * Livewire-Component prüft Rechte (Gate) und validiert die Eingaben; dieser Service persistiert
 * nur. Damit teilen sich Anlage-Modal (Inventar-Liste) und künftige Edit-Modals (Detailseite)
 * dieselbe Wahrheit, statt die Logik aus Assets/Create + Assets/Show zu duplizieren
 * (Guardrail: Services importieren keine UI/Http/Tools — vgl. tests/guardrails.php).
 *
 * Phase 1: nur `createItem` (manuelle Assets). Geräte-Schreibpfade folgen in Phase 4.
 */
class AssetWriteService
{
    /**
     * Legt ein manuelles Asset an (+ erste Zuordnung, falls ein Mitarbeiter gesetzt ist).
     * Quelle: vormals Livewire\Assets\Create::save(). Gate/Validierung liegen beim Aufrufer.
     *
     * @param array{
     *     categoryId:int, name:string, manufacturer?:?string, model?:?string,
     *     serialNumber?:?string, assigneeId?:?int, status?:string, purchaseDate?:?string,
     *     purchasePrice?:?string, depreciationMonths?:?int, notes?:?string
     * } $data
     */
    public function createItem(array $data, int $teamId, int $userId): AssetItem
    {
        $assigneeId = $data['assigneeId'] ?? null;

        // Mitarbeiter gesetzt → Status auf „assigned" forcieren + Zuordnungs-Zeitpunkt setzen.
        $status     = $assigneeId ? 'assigned' : ($data['status'] ?? 'in_stock');
        $assignedAt = $assigneeId ? now()      : null;

        $item = AssetItem::create([
            'team_id'             => $teamId,
            'tenant_id'           => TenantContext::resolveForWrite($teamId, $userId),
            'category_id'         => $data['categoryId'],
            'source'              => 'manual',
            'name'                => $data['name'],
            'manufacturer'        => ($data['manufacturer'] ?? '') ?: null,
            'model'               => ($data['model'] ?? '') ?: null,
            'serial_number'       => ($data['serialNumber'] ?? '') ?: null,
            'assignee_id'         => $assigneeId,
            'assigned_at'         => $assignedAt,
            'status'              => $status,
            'purchase_date'       => ($data['purchaseDate'] ?? null) ?: null,
            'purchase_price'      => ($data['purchasePrice'] ?? null) ?: null,
            'depreciation_months' => $data['depreciationMonths'] ?? null,
            'notes'               => ($data['notes'] ?? '') ?: null,
        ]);

        if ($assigneeId) {
            AssetAssignment::create([
                'asset_item_id'   => $item->id,
                'assignable_type' => AssetAssignment::SUBJECT_ITEM,
                'assignable_id'   => $item->id,
                'employee_id'     => $assigneeId,
                'assigned_at'     => now(),
                'source'          => AssetAssignment::SOURCE_MANUAL,
            ]);
        }

        return $item;
    }
}
