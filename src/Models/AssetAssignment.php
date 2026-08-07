<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Platform\AssetManager\Concerns\TenantScopable;

class AssetAssignment extends Model
{
    use TenantScopable;

    protected $table = 'asset_assignments';

    /** Diskriminator-Werte (kein Eloquent-morphTo → unabhängig von der plattformweiten Morph-Map). */
    public const SUBJECT_ITEM   = 'item';
    public const SUBJECT_DEVICE = 'device';

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_INTUNE = 'intune';

    protected $fillable = [
        'tenant_id',
        'asset_item_id',
        'assignable_type',
        'assignable_id',
        'holder_id',
        'assigned_at',
        'returned_at',
        'notes',
        'source',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'returned_at' => 'datetime',
    ];

    /**
     * `tenant_id` vom zugeordneten Objekt erben, wenn es nicht ausdrücklich gesetzt wurde.
     *
     * Die Tabelle hat kein `team_id` und seit docs/adr/0016 ein `tenant_id` **NOT NULL** — ohne diesen
     * Hook müssten alle fünf `create()`-Aufrufe im Modul (Anlage, Bulk-Anlage, Zuordnen, Umziehen,
     * Sync) den Tenant einzeln mitgeben, und ein vergessener Aufruf wäre ein harter SQL-Fehler beim
     * Nutzer. Die Zuordnung gehört zwangsläufig zum Tenant ihres Subjekts — es gibt keine sinnvolle
     * abweichende Wahl, also wird sie hier abgeleitet statt durchgeschleift.
     */
    protected static function booted(): void
    {
        static::creating(function (self $assignment): void {
            if ($assignment->tenant_id !== null) {
                return;
            }

            $assignment->tenant_id = $assignment->resolveTenantFromSubject();
        });
    }

    /** Tenant des Subjekts: Item, Gerät oder — als letzter Ausweg — des Trägers. */
    protected function resolveTenantFromSubject(): ?int
    {
        if ($this->asset_item_id) {
            $tenantId = AssetItem::withoutTenantScope()->whereKey($this->asset_item_id)->value('tenant_id');
            if ($tenantId !== null) {
                return (int) $tenantId;
            }
        }

        if ($this->assignable_type === self::SUBJECT_DEVICE && $this->assignable_id) {
            $tenantId = AssetDevice::withoutTenantScope()->whereKey($this->assignable_id)->value('tenant_id');
            if ($tenantId !== null) {
                return (int) $tenantId;
            }
        }

        if ($this->holder_id) {
            $tenantId = AssetHolder::withoutTenantScope()->whereKey($this->holder_id)->value('tenant_id');
            if ($tenantId !== null) {
                return (int) $tenantId;
            }
        }

        return null;
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(AssetItem::class, 'asset_item_id');
    }

    /** Geräte-Subjekt (nur gültig, wenn assignable_type = 'device' — über subject() ansteuern). */
    public function device(): BelongsTo
    {
        return $this->belongsTo(AssetDevice::class, 'assignable_id');
    }

    public function holder(): BelongsTo
    {
        return $this->belongsTo(AssetHolder::class, 'holder_id');
    }

    /** Das zugeordnete Objekt (Item oder Gerät) gemäß Diskriminator. */
    public function subject(): ?Model
    {
        return match ($this->assignable_type) {
            self::SUBJECT_DEVICE => $this->device,
            default              => $this->item,
        };
    }

    public function isOpen(): bool
    {
        return $this->returned_at === null;
    }
}
