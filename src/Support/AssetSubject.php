<?php

namespace Platform\AssetManager\Support;

use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetItem;

/**
 * Read-only Anzeige-Wrapper für die vereinte Detailseite (Inventory/Show) — das Detail-Gegenstück
 * zu {@see InventoryRow} (Liste). Liefert die TYP-ÜBERGREIFENDEN Anzeigefelder (Header + Quick-Stats),
 * damit die gemeinsamen Karten frei von `@if($type === …)`-Verzweigungen bleiben. KEINE Schreibpfade.
 *
 * Status-Farben laufen über {@see InventoryRow::safeColor()} (Tailwind-Build-Whitelist; `sky`→`indigo`),
 * damit kein gepurgtes `bg-sky-*` entsteht.
 */
class AssetSubject
{
    public function __construct(
        public string  $type,            // 'manual' | 'intune'
        public int     $id,
        public string  $name,
        public ?string $manufacturer,
        public ?string $model,
        public ?string $serialNumber,
        public ?string $assignedToLabel,
        public string  $statusLabel,
        public string  $statusColor,
        public float   $monthlyCost,
        public string  $icon,
        public string  $typeLabel,
        public string  $typeColor,
    ) {}

    public static function fromItem(AssetItem $item): self
    {
        return new self(
            type:            'manual',
            id:              $item->id,
            name:            $item->name ?: '—',
            manufacturer:    $item->manufacturer,
            model:           $item->model,
            serialNumber:    $item->serial_number,
            assignedToLabel: $item->assignee?->name,
            statusLabel:     $item->statusLabel(),
            statusColor:     InventoryRow::safeColor($item->statusBadgeColor()),
            monthlyCost:     $item->monthlyCost(),
            icon:            $item->category?->icon ?: 'heroicon-o-cube',
            typeLabel:       'Manuell',
            typeColor:       'gray',
        );
    }

    public static function fromDevice(AssetDevice $device): self
    {
        $hasLifecycle = filled($device->lifecycle_status);

        return new self(
            type:            'intune',
            id:              $device->id,
            name:            $device->device_name ?: '—',
            manufacturer:    $device->manufacturer,
            model:           $device->model,
            serialNumber:    $device->serial_number,
            assignedToLabel: $device->user_display_name ?: $device->user_principal_name,
            statusLabel:     $hasLifecycle ? $device->lifecycleLabel() : '—',
            statusColor:     $hasLifecycle ? InventoryRow::safeColor($device->lifecycleBadgeColor()) : 'gray',
            monthlyCost:     $device->resolvedMonthlyCost(),
            icon:            'heroicon-o-computer-desktop',
            typeLabel:       'Intune',
            typeColor:       'violet',
        );
    }
}
