<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Platform\AssetManager\Concerns\TenantScopable;

/**
 * Ein erzeugter DATEV-EXTF-Buchungsstapel (Baustein 4, Discussion #26): die Datei, wie sie in DATEV
 * importiert wird, und die Monatsstände, die er umbucht. Angelegt und verworfen von
 * {@see \Platform\AssetManager\Services\DatevBatchService}.
 */
class AssetDatevBatch extends Model
{
    use TenantScopable;

    protected $table = 'asset_datev_batches';

    protected $fillable = [
        'team_id',
        'tenant_id',
        'booking_month',
        'client',
        'consultant',
        'label',
        'file_name',
        'file_base64',
        'file_sha256',
        'bookings_count',
        'total_cents',
        'summary',
        'user_id',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    protected $casts = [
        'booking_month'  => 'date',
        'bookings_count' => 'integer',
        'total_cents'    => 'integer',
        'summary'        => 'array',
        'voided_at'      => 'datetime',
    ];

    protected $hidden = ['file_base64'];

    public function snapshots(): HasMany
    {
        return $this->hasMany(AssetInvoiceSnapshot::class, 'datev_batch_id');
    }

    /** Die Datei als Bytes (Windows-1252), unverändert wie erzeugt. */
    public function contents(): string
    {
        return (string) base64_decode((string) $this->file_base64, true);
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }
}
