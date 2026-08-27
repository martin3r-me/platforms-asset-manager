<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Platform\AssetManager\Concerns\TenantScopable;

/**
 * Gelernte Zuordnung Rechnungsposition → Graph-SKU.
 *
 * Der Wiederverkäufer benennt seine Artikel anders als Microsoft Graph („Power Automate per user with
 * attended RPA plan" gegen `POWERAUTOMATE_ATTENDED_RPA"). Was die automatische Erkennung nicht sicher
 * trifft, ordnet ein Mensch **einmal** zu — und das bleibt. Ohne dieses Gedächtnis müsste dieselbe
 * Entscheidung jeden Monat neu getroffen werden.
 *
 * `sku_id = null` heißt bewusst „gehört zu keiner Lizenz" und ist eine Entscheidung, keine offene
 * Lücke: die Position taucht dann nicht mehr als ungeklärt auf.
 */
class AssetLicenseBillingAlias extends Model
{
    use TenantScopable;

    protected $table = 'asset_license_billing_aliases';

    protected $fillable = [
        'team_id',
        'tenant_id',
        'edition_key',
        'sku_id',
        'note',
        'created_by',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(AssetTenant::class, 'tenant_id');
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(AssetLicenseSku::class, 'sku_id', 'sku_id');
    }

    /** Bewusst keiner Lizenz zugeordnet — im Unterschied zu „noch nicht zugeordnet". */
    public function isIgnore(): bool
    {
        return $this->sku_id === null;
    }
}
