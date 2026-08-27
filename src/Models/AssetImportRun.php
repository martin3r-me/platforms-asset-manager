<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Platform\AssetManager\Concerns\TenantScopable;

/**
 * Ein Import-Lauf mit seinem Ergebnis — die Historie hinter den Import-Schaltflächen.
 *
 * Hält fest, was ein Lauf getan hat, statt nur was danach in der Datenbank steht: Probeläufe, Befunde
 * und Fehler gehören dazu. Angelegt von {@see \Platform\AssetManager\Support\ImportRunRecorder}.
 */
class AssetImportRun extends Model
{
    use TenantScopable;

    /** Kostenaufteilungs-Excel. */
    public const SOURCE_EXCEL = 'excel';

    /** Vodafone-Rechnungsanalyse (ADR 0018). */
    public const SOURCE_VODAFONE = 'vodafone';

    /** Lizenz-Abrechnung des Wiederverkäufers (ADR 0019). */
    public const SOURCE_LICENSE_INVOICE = 'license_invoice';

    /** Durchgelaufen, keine Auffälligkeiten. */
    public const STATUS_OK = 'ok';

    /** Durchgelaufen, aber mit Befund — die Zahlen sind nutzbar, brauchen aber einen Blick. */
    public const STATUS_WARNING = 'warning';

    /** Abgebrochen. */
    public const STATUS_ERROR = 'error';

    protected $table = 'asset_import_runs';

    protected $fillable = [
        'team_id',
        'tenant_id',
        'source',
        'file_name',
        'batch_id',
        'dry_run',
        'status',
        'headline',
        'error',
        'summary',
        'user_id',
        'started_at',
        'duration_ms',
    ];

    protected $casts = [
        'dry_run'    => 'boolean',
        'summary'    => 'array',
        'started_at' => 'datetime',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(AssetTenant::class, 'tenant_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\User::class, 'user_id');
    }

    public function sourceLabel(): string
    {
        return match ($this->source) {
            self::SOURCE_EXCEL           => 'Kostenaufteilung (Excel)',
            self::SOURCE_VODAFONE        => 'Vodafone-Rechnung',
            self::SOURCE_LICENSE_INVOICE => 'Lizenz-Abrechnung',
            default                      => $this->source,
        };
    }

    /** Badge-Farbe der Quelle. Nur Familien, die im Tailwind-Build vorkommen (ADR 0011). */
    public function sourceBadgeColor(): string
    {
        return match ($this->source) {
            self::SOURCE_EXCEL           => 'slate',
            self::SOURCE_VODAFONE        => 'violet',
            self::SOURCE_LICENSE_INVOICE => 'sky',
            default                      => 'gray',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_OK      => $this->dry_run ? 'Vorschau ok' : 'Importiert',
            self::STATUS_WARNING => 'Mit Befund',
            self::STATUS_ERROR   => 'Fehlgeschlagen',
            default              => $this->status,
        };
    }

    public function statusBadgeColor(): string
    {
        return match ($this->status) {
            self::STATUS_OK      => 'emerald',
            self::STATUS_WARNING => 'amber',
            self::STATUS_ERROR   => 'red',
            default              => 'gray',
        };
    }

    public function failed(): bool
    {
        return $this->status === self::STATUS_ERROR;
    }

    /** Laufzeit als lesbarer Wert („1,4 s"), null wenn nicht gemessen. */
    public function durationLabel(): ?string
    {
        if ($this->duration_ms === null) {
            return null;
        }

        return $this->duration_ms < 1000
            ? $this->duration_ms . ' ms'
            : number_format($this->duration_ms / 1000, 1, ',', '.') . ' s';
    }

    /**
     * Die Befunde des Laufs als flache Liste von Sätzen — quellenübergreifend, damit die Anzeige
     * nicht je Import eine eigene Sonderbehandlung braucht.
     *
     * @return list<string>
     */
    public function findings(): array
    {
        return array_values(array_filter((array) ($this->summary['findings'] ?? [])));
    }

    /**
     * Die Kennzahlen des Laufs als Label => Wert, in der Reihenfolge, in der sie geschrieben wurden.
     *
     * @return array<string, string>
     */
    public function metrics(): array
    {
        return (array) ($this->summary['metrics'] ?? []);
    }
}
