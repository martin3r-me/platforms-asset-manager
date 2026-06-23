<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\AssetManager\Concerns\TenantScopable;

/**
 * Geräteausgabe-Protokoll (Kopf). Deckt mehrere Geräte ab (eine Unterschrift), Rückgabe erfolgt
 * zeilenweise über AssetHandoverLine. Status wird aus den Zeilen abgeleitet (recomputeStatus()).
 */
class AssetHandover extends Model
{
    use HasFactory;
    use SoftDeletes;
    use TenantScopable;

    protected $table = 'asset_handovers';

    protected static function newFactory(): \Illuminate\Database\Eloquent\Factories\Factory
    {
        return \Platform\AssetManager\Database\Factories\AssetHandoverFactory::new();
    }

    public const STATUS_OPEN               = 'open';
    public const STATUS_PARTIALLY_RETURNED = 'partially_returned';
    public const STATUS_RETURNED           = 'returned';

    public const STATUS_LABELS = [
        'open'               => 'Ausgegeben',
        'partially_returned' => 'Teilweise zurück',
        'returned'           => 'Vollständig zurück',
    ];

    protected $fillable = [
        'team_id',
        'tenant_id',
        'employee_id',
        'created_by_user_id',
        'issued_at',
        'signer_name',
        'signature_data',
        'signed_at',
        'notes',
        'status',
    ];

    protected $casts = [
        'issued_at' => 'date',
        'signed_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(AssetHandoverLine::class, 'handover_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(AssetEmployee::class, 'employee_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class);
    }

    /** Unterschrift vorhanden? (optional/nachholbar — bis dahin „nicht unterschrieben") */
    public function isSigned(): bool
    {
        return $this->signature_data !== null && $this->signature_data !== '';
    }

    public function isFullyReturned(): bool
    {
        return $this->status === self::STATUS_RETURNED;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? '—';
    }

    /** Nur Farbfamilien verwenden, die im Modul bereits als Klassen vorkommen (Tailwind-Build). */
    public function statusBadgeColor(): string
    {
        return match ($this->status) {
            self::STATUS_OPEN               => 'emerald',
            self::STATUS_PARTIALLY_RETURNED => 'amber',
            self::STATUS_RETURNED           => 'gray',
            default                         => 'gray',
        };
    }

    /**
     * Kopf-Status aus den Zeilen ableiten und (nur bei Änderung) speichern.
     * open = keine Zeile zurück · partially_returned = einige zurück · returned = alle zurück.
     */
    public function recomputeStatus(): void
    {
        $lines    = $this->relationLoaded('lines') ? $this->lines : $this->lines()->get();
        $total    = $lines->count();
        $returned = $lines->whereNotNull('returned_at')->count();

        $status = match (true) {
            $total === 0        => self::STATUS_OPEN,
            $returned === 0     => self::STATUS_OPEN,
            $returned < $total  => self::STATUS_PARTIALLY_RETURNED,
            default             => self::STATUS_RETURNED,
        };

        if ($status !== $this->status) {
            $this->status = $status;
            $this->save();
        }
    }
}
