<?php

namespace Platform\AssetManager\Support;

use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetItem;

/**
 * Normalisierte Inventar-Zeile über die ZWEI getrennten Hardware-Welten:
 * `asset_items` (manuelle Assets) und `asset_devices` (Intune-Geräte).
 *
 * Reines Read-Model für die gemeinsame „Inventar"-Sicht — KEINE DB-Brücke, KEINE Schreibpfade.
 * Jede Zeile trägt ihren typ-korrekten Detail-Link, sodass IDs der beiden Tabellen nie kollidieren.
 *
 * Monatskosten werden von außen injiziert (Service löst sie N+1-frei auf) und sind die rohe
 * AfA/Leasing-Kosten JE OBJEKT — nicht die doppelzählungsfreie Aggregat-Zahl des Kosten-Dashboards.
 */
class InventoryRow
{
    public function __construct(
        public string $type,            // 'manual' | 'intune'
        public int $id,
        public string $name,
        public ?string $manufacturer,
        public ?string $model,
        public ?string $serialNumber,
        public ?string $assignedTo,
        public string $statusLabel,
        public string $statusColor,     // nur im Tailwind-Build vorhandene Farbfamilien
        public string $statusSortKey,
        public float $monthlyCost,
        public string $detailRoute,
    ) {}

    public static function fromItem(AssetItem $item): self
    {
        return new self(
            type:          'manual',
            id:            $item->id,
            name:          $item->name ?: '—',
            manufacturer:  $item->manufacturer,
            model:         $item->model,
            serialNumber:  $item->serial_number,
            assignedTo:    $item->assignee?->name,
            statusLabel:   $item->statusLabel(),
            statusColor:   self::safeColor($item->statusBadgeColor()),
            statusSortKey: (string) ($item->status ?? ''),
            monthlyCost:   $item->monthlyCost(),
            detailRoute:   route('asset-manager.inventory.show', ['type' => 'manual', 'id' => $item->id]),
        );
    }

    public static function fromDevice(AssetDevice $device, float $monthlyCost): self
    {
        $hasLifecycle = filled($device->lifecycle_status);

        return new self(
            type:          'intune',
            id:            $device->id,
            name:          $device->device_name ?: '—',
            manufacturer:  $device->manufacturer,
            model:         $device->model,
            serialNumber:  $device->serial_number,
            assignedTo:    $device->user_display_name ?: $device->user_principal_name,
            statusLabel:   $hasLifecycle ? $device->lifecycleLabel() : '—',
            statusColor:   $hasLifecycle ? self::safeColor($device->lifecycleBadgeColor()) : 'gray',
            statusSortKey: (string) ($device->lifecycle_status ?? ''),
            monthlyCost:   $monthlyCost,
            detailRoute:   route('asset-manager.inventory.show', ['type' => 'intune', 'id' => $device->id]),
        );
    }

    /**
     * Tailwind baut im Modul nur eine feste Menge Farbfamilien (dynamische `bg-{{ }}`-Klassen
     * werden sonst weggepurged). `sky` (AssetItem-Status „Lager") ist NICHT im Build → auf `indigo`
     * abbilden (deckt sich farblich mit dem Geräte-Status „Reserve/Lager" = spare).
     */
    public static function safeColor(string $color): string
    {
        $built = ['emerald', 'indigo', 'amber', 'orange', 'gray', 'red', 'violet'];

        if ($color === 'sky') {
            return 'indigo';
        }

        return in_array($color, $built, true) ? $color : 'gray';
    }
}
