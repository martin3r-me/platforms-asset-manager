<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Position eines Geräteausgabe-Protokolls = ein ausgegebenes Gerät. Rückgabe/Tausch erfolgt
 * zeilenweise (returned_at). Kein eigenes tenant_id — scoped über den Kopf (whereHas('handover'))
 * bzw. die ohnehin tenant-gebundene asset_device_id.
 */
class AssetHandoverLine extends Model
{
    use HasFactory;

    protected $table = 'asset_handover_lines';

    protected static function newFactory(): \Illuminate\Database\Eloquent\Factories\Factory
    {
        return \Platform\AssetManager\Database\Factories\AssetHandoverLineFactory::new();
    }

    public const STATUS_ISSUED   = 'issued';
    public const STATUS_RETURNED = 'returned';

    protected $fillable = [
        'handover_id',
        'asset_device_id',
        'accessories',
        'notes',
        'returned_at',
        'return_condition',
        'returned_by_user_id',
        'device_snapshot',
        'status',
    ];

    protected $casts = [
        'accessories'     => 'array',
        'device_snapshot' => 'array',
        'returned_at'     => 'date',
    ];

    public function handover(): BelongsTo
    {
        return $this->belongsTo(AssetHandover::class, 'handover_id');
    }

    /** Gerät — withTrashed, damit retired/soft-deleted Geräte im Protokoll weiter auflösbar bleiben. */
    public function device(): BelongsTo
    {
        return $this->belongsTo(AssetDevice::class, 'asset_device_id')->withTrashed();
    }

    public function isOpen(): bool
    {
        return $this->returned_at === null;
    }

    /** Anzeigename: Snapshot zum Ausgabezeitpunkt hat Vorrang (stabil), sonst Live-Gerät. */
    public function deviceName(): string
    {
        return $this->device_snapshot['device_name']
            ?? $this->device?->device_name
            ?? '—';
    }

    public function serialNumber(): ?string
    {
        return $this->device_snapshot['serial_number'] ?? $this->device?->serial_number;
    }

    /** Friert den relevanten Gerätestand fürs Protokoll ein. */
    public static function captureDeviceSnapshot(AssetDevice $device): array
    {
        return [
            'device_name'         => $device->device_name,
            'serial_number'       => $device->serial_number,
            'manufacturer'        => $device->manufacturer,
            'model'               => $device->model,
            'user_principal_name' => $device->user_principal_name,
            'user_display_name'   => $device->user_display_name,
        ];
    }
}
