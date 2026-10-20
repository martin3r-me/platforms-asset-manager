<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Platform\AssetManager\Concerns\TenantScopable;

/**
 * Ein Rechnungsmonat einer importierten Kostenposition (ADR 0029) — der Betrag, den die Rechnung für
 * diese Rufnummer bzw. diesen Posten auswies. Geschrieben von
 * {@see \Platform\AssetManager\Services\CostLineHistoryService}, nie von Hand.
 */
class AssetCostLineHistory extends Model
{
    use TenantScopable;

    protected $table = 'asset_cost_line_history';

    protected $fillable = [
        'team_id',
        'tenant_id',
        'cost_line_id',
        'import_hash',
        'source',
        'account',
        'invoice',
        'period_from',
        'period_to',
        'booking_month',
        'label',
        'amount',
        'cost_type_id',
        'cost_center_id',
        'assignee_id',
    ];

    protected $casts = [
        'period_from'   => 'date',
        'period_to'     => 'date',
        'booking_month' => 'date',
        'amount'        => 'decimal:2',
    ];

    public function costLine(): BelongsTo
    {
        return $this->belongsTo(AssetCostLine::class, 'cost_line_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(AssetHolder::class, 'assignee_id');
    }
}
