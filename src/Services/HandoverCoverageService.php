<?php

namespace Platform\AssetManager\Services;

use Platform\AssetManager\Models\AssetAssignment;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetHandoverLine;

/**
 * Abdeckung der Übergabeprotokolle (#887): für welche Geräte in Nutzung gibt es ein signiertes Protokoll?
 *
 * Basis (siehe ADR 0028): Geräte im Lifecycle `in_use` — oder ohne gepflegten Lifecycle, der bei
 * Intune-Geräten der Normalfall ist — MIT offener Zuordnung an einen Träger. „Abgedeckt" heißt: eine
 * noch nicht zurückgegebene Protokollzeile, deren Protokoll unterschrieben ist. Reserve, Reparatur und
 * terminale Geräte zählen nicht, ebenso Geräte ohne Träger.
 *
 * Team-gescopt; der Tenant kommt über den Global Scope.
 */
class HandoverCoverageService
{
    /** Obergrenze der Liste offener Geräte. */
    public const MAX_LIST = 50;

    /**
     * @return array{base: int, covered: int, open: int, open_devices: list<array{id: int, name: string, serial: ?string, holder: ?string}>}
     */
    public function coverage(int $teamId): array
    {
        $devices = AssetDevice::where('team_id', $teamId)
            ->where(fn ($q) => $q->whereNull('lifecycle_status')->orWhere('lifecycle_status', 'in_use'))
            ->whereIn('id', AssetAssignment::query()
                ->where('assignable_type', AssetAssignment::SUBJECT_DEVICE)
                ->whereNull('returned_at')
                ->select('assignable_id'))
            ->orderBy('device_name')
            ->get(['id', 'device_name', 'serial_number', 'user_principal_name']);

        $covered = AssetHandoverLine::query()
            ->whereIn('asset_device_id', $devices->pluck('id'))
            ->whereNull('returned_at')
            ->whereHas('handover', fn ($q) => $q->where('team_id', $teamId)->whereNotNull('signed_at'))
            ->pluck('asset_device_id')
            ->unique()
            ->flip();

        $open = $devices->reject(fn (AssetDevice $d) => $covered->has($d->id))->values();

        return [
            'base'         => $devices->count(),
            'covered'      => $devices->count() - $open->count(),
            'open'         => $open->count(),
            'open_devices' => $open->take(self::MAX_LIST)->map(fn (AssetDevice $d) => [
                'id'     => (int) $d->id,
                'name'   => (string) ($d->device_name ?: '#' . $d->id),
                'serial' => $d->serial_number,
                'holder' => $d->user_principal_name,
            ])->all(),
        ];
    }
}
