<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Platform\AssetManager\Concerns\TenantScopable;

class AssetDeviceEvent extends Model
{
    use TenantScopable;

    protected $table = 'asset_device_events';

    protected $fillable = [
        'team_id',
        'tenant_id',
        'asset_device_id',
        'user_id',
        'event_type',
        'description',
        'old_value',
        'new_value',
    ];

    /**
     * Schreibt ein Geräte-Event (Audit). team_id/tenant_id werden aus dem Gerät übernommen, user_id ist
     * der Akteur (null für sync-getriebene Events). Audit ist Beiwerk: ein Fehler darf den auslösenden
     * Schreibpfad (z. B. ein Lifecycle-Update in der UI) NIE scheitern lassen.
     */
    public static function record(AssetDevice $device, string $type, string $description, ?string $old = null, ?string $new = null, ?int $userId = null): void
    {
        try {
            static::create([
                'team_id'         => $device->team_id,
                'tenant_id'       => $device->tenant_id,
                'asset_device_id' => $device->id,
                'user_id'         => $userId,
                'event_type'      => $type,
                'description'     => $description,
                'old_value'       => $old,
                'new_value'       => $new,
            ]);
        } catch (\Throwable $e) {
            Log::warning('AssetManager: Geräte-Event nicht geschrieben', [
                'asset_device_id' => $device->id,
                'event_type'      => $type,
                'error'           => $e->getMessage(),
            ]);
        }
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(AssetDevice::class, 'asset_device_id');
    }

    /** Tenant (Kundenkontext), zu dem dieses Geräte-Event gehört. */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(AssetTenant::class, 'tenant_id');
    }

    /** Akteur einer manuellen Änderung (null bei sync-getriebenen Events). */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\User::class, 'user_id');
    }

    public function eventLabel(): string
    {
        return match ($this->event_type) {
            'created'            => 'Erstmals erfasst',
            'owner_changed'      => 'Besitzer geändert',
            'compliance_changed' => 'Compliance geändert',
            'os_changed'         => 'OS aktualisiert',
            'reenrolled'         => 'Neu eingebunden',
            'lifecycle_changed'  => 'Lifecycle geändert',
            'issued'             => 'Ausgegeben',
            'returned'           => 'Zurückgenommen',
            default              => $this->description ?: $this->event_type,
        };
    }

    /** Nur Farbfamilien, die im Modul bereits als Klassen vorkommen (Tailwind-Build). */
    public function eventColor(): string
    {
        return match ($this->event_type) {
            'created'            => 'violet',
            'owner_changed'      => 'indigo',
            'compliance_changed' => 'amber',
            'os_changed'         => 'emerald',
            'reenrolled'         => 'indigo',
            'lifecycle_changed'  => 'amber',
            'issued'             => 'indigo',
            'returned'           => 'gray',
            default              => 'gray',
        };
    }
}
