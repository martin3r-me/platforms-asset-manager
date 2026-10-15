<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;
use Platform\AssetManager\Concerns\TenantScopable;

/**
 * Monatsstand einer importierten Rechnung: Rechnungsbetrag, Buchungsmonat und Verteilung auf
 * Kostenart × Kostenstelle, festgeschrieben beim scharfen Import (Discussion #26). Grundlage der
 * DATEV-Umbuchung je Monat und ihres Protokolls. Geschrieben von
 * {@see \Platform\AssetManager\Services\InvoiceSnapshotService}.
 */
class AssetInvoiceSnapshot extends Model
{
    use TenantScopable;

    protected $table = 'asset_invoice_snapshots';

    protected $fillable = [
        'team_id',
        'tenant_id',
        'source',
        'account',
        'invoice',
        'invoice_date',
        'booking_month',
        'period_label',
        'total_net',
        'distribution',
        'datev_batch_id',
        'captured_at',
    ];

    protected $casts = [
        'invoice_date'  => 'date',
        'booking_month' => 'date',
        'total_net'     => 'decimal:2',
        'distribution'  => 'array',
        'captured_at'   => 'datetime',
    ];

    /** Schon in einem DATEV-Stapel gebucht — dann ändert ihn kein Import mehr. */
    public function isBooked(): bool
    {
        return $this->datev_batch_id !== null;
    }
}
