<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetAssignment extends Model
{
    protected $table = 'asset_assignments';

    /** Diskriminator-Werte (kein Eloquent-morphTo → unabhängig von der plattformweiten Morph-Map). */
    public const SUBJECT_ITEM   = 'item';
    public const SUBJECT_DEVICE = 'device';

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_INTUNE = 'intune';

    protected $fillable = [
        'asset_item_id',
        'assignable_type',
        'assignable_id',
        'employee_id',
        'assigned_at',
        'returned_at',
        'notes',
        'source',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'returned_at' => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(AssetItem::class, 'asset_item_id');
    }

    /** Geräte-Subjekt (nur gültig, wenn assignable_type = 'device' — über subject() ansteuern). */
    public function device(): BelongsTo
    {
        return $this->belongsTo(AssetDevice::class, 'assignable_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(AssetEmployee::class, 'employee_id');
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
