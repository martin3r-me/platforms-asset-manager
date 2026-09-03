<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Platform\AssetManager\Concerns\TenantScopable;

/**
 * Ein Rechnungslauf: was zu einem Zeitpunkt gezählt und an easybill übergeben wurde
 * (siehe docs/adr/0021).
 *
 * Die Zeile ist bewusst redundant zum aktuellen Bestand — Menge, Stückpreis und Trägerliste stehen
 * hier fest, statt bei Bedarf neu berechnet zu werden. Eine Rechnung ist ein Vorgang mit Datum; sie
 * darf sich nicht rückwirkend ändern, nur weil jemand eine Lizenz umgehängt hat.
 */
class AssetBillingRun extends Model
{
    use TenantScopable;

    protected $table = 'asset_billing_runs';

    /** Beleg liegt als Entwurf in easybill. Festschreiben und Versenden passieren dort. */
    public const STATUS_DRAFT = 'draft';

    /** Lauf ist nicht bis zum Beleg gekommen — der Grund steht in `error`. */
    public const STATUS_FAILED = 'failed';

    /**
     * Der Beleg wurde in easybill gelöscht. Der Lauf bleibt als Vorgang stehen (wer wann welche
     * Menge erzeugt hat, ist auch dann eine Auskunft), gibt die Periode aber wieder frei — sonst
     * ließe sich für diesen Monat nie wieder eine Rechnung erzeugen.
     */
    public const STATUS_DISCARDED = 'discarded';

    /** Stückpreis kam aus dem Commerce-Artikel. */
    public const PRICE_SOURCE_COMMERCE = 'commerce';

    /** Stückpreis kam aus dem am Profil hinterlegten Rückfallwert. */
    public const PRICE_SOURCE_FALLBACK = 'fallback';

    protected $fillable = [
        'team_id',
        'tenant_id',
        'billing_profile_id',
        'period',
        'quantity',
        'unit_price_cents',
        'total_net_cents',
        'currency',
        'price_source',
        'status',
        'easybill_document_id',
        'snapshot',
        'skipped_count',
        'error',
        'user_id',
    ];

    protected $casts = [
        'snapshot'             => 'array',
        'quantity'             => 'integer',
        'unit_price_cents'     => 'integer',
        'total_net_cents'      => 'integer',
        'skipped_count'        => 'integer',
        'easybill_document_id' => 'integer',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(AssetBillingProfile::class, 'billing_profile_id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /** Beträge in Euro — für Anzeige und Summen, nie für den Weg zurück an easybill. */
    public function unitPrice(): float
    {
        return $this->unit_price_cents / 100;
    }

    public function totalNet(): float
    {
        return $this->total_net_cents / 100;
    }

    /** Die eingefrorenen UPNs dieses Laufs. */
    public function snapshotPrincipals(): array
    {
        return $this->snapshot['principals'] ?? [];
    }
}
