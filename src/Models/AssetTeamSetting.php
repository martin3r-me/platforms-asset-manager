<?php

namespace Platform\AssetManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modul-Einstellungen je **Team × Tenant** (ADR 0008, tenant-gebunden seit docs/adr/0016).
 *
 * Ursprünglich team-weit — konsistent damit, dass das Kostenmodell team-weit war. Mit dem
 * tenant-gebundenen Kostenmodell ist auch der Controlling-Schalter tenant-eigen: derselbe Betreuer
 * kann für einen Kunden Kosten führen und für den nächsten nur das Inventar. Der Bestand wurde je
 * Tenant kopiert, also ändert sich für vorhandene Teams nichts.
 *
 * Zugriff bevorzugt über {@see \Platform\AssetManager\Services\ControllingContext}.
 */
class AssetTeamSetting extends Model
{
    use \Platform\AssetManager\Concerns\TenantScopable;

    protected $table = 'asset_team_settings';

    protected $fillable = [
        'team_id',
        'tenant_id',
        'controlling_enabled',
        // Klassifizierungsregeln für Asset-Träger je Tenant (ADR 0017): Präfix-/Muster-Listen,
        // damit keine Kundenkonvention im Code landet.
        'holder_type_rules',
        // Ziele der Lizenz-Verteilungs-Kaskade für Mengen ohne Träger. Konfigurierbar, weil
        // „IT-Gemeinkosten" und „Lizenzpool IT" Kostenstellennamen *eines* Kunden sind.
        'license_overflow_cost_center_id',
        'license_pool_cost_center_id',
        // Sammelstelle der Telefonie (#931): Anlagenanschluss, Verbindungen, Plätze ohne Person.
        'telephony_cost_center_id',
        // DATEV-Stapelkopf (Discussion #26): Kanzlei-/Mandantendaten eines Kunden, deshalb hier.
        'datev_consultant',
        'datev_client',
        'datev_fiscal_year_start',
        'datev_account_length',
        'datev_initials',
        'datev_source_cost_center_id',
        // Erster Leistungsmonat, den der Asset Manager umbucht — davor verteilt die Buchhaltung selbst.
        'datev_start_month',
    ];

    protected $casts = [
        'controlling_enabled' => 'boolean',
        'holder_type_rules'   => 'array',
        'datev_account_length' => 'integer',
        'datev_start_month'   => 'date',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(AssetTenant::class, 'tenant_id');
    }

    /** Sammelstelle für Monats-Seats über dem Bedarf an Funktionskonten. */
    public function licenseOverflowCostCenter(): BelongsTo
    {
        return $this->belongsTo(AssetCostCenter::class, 'license_overflow_cost_center_id');
    }

    /** Sammelstelle für bezahlte, aber unbesetzte Seats. */
    public function licensePoolCostCenter(): BelongsTo
    {
        return $this->belongsTo(AssetCostCenter::class, 'license_pool_cost_center_id');
    }

    /** Sammelstelle der Telefonie: Anlagenanschluss, Verbindungen und Plätze ohne Person. */
    public function telephonyCostCenter(): BelongsTo
    {
        return $this->belongsTo(AssetCostCenter::class, 'telephony_cost_center_id');
    }

    /** Sammel-KST, auf der die Buchhaltung die IT-Rechnungen bucht — Quelle jeder DATEV-Umbuchung. */
    public function datevSourceCostCenter(): BelongsTo
    {
        return $this->belongsTo(AssetCostCenter::class, 'datev_source_cost_center_id');
    }

    /**
     * Was am DATEV-Stapelkopf fehlt; leer = ein Stapel kann erzeugt werden. Die Formatprüfung
     * (Ziffern, Längen) macht der Writer — hier nur, ob überhaupt etwas gepflegt ist.
     *
     * @return list<string>
     */
    public function datevMissing(): array
    {
        $missing = [];

        foreach ([
            'datev_consultant'        => 'Berater',
            'datev_client'            => 'Mandant',
            'datev_fiscal_year_start' => 'Beginn Wirtschaftsjahr',
            'datev_account_length'    => 'Sachkontenlänge',
            'datev_initials'          => 'Diktatkürzel',
        ] as $column => $label) {
            if (trim((string) $this->{$column}) === '') {
                $missing[] = $label;
            }
        }

        if ($this->datev_source_cost_center_id === null || trim((string) $this->datevSourceCostCenter?->code) === '') {
            $missing[] = 'Sammel-Kostenstelle (mit Code)';
        }

        return $missing;
    }

    /**
     * Kopfdaten für {@see \Platform\AssetManager\Services\DatevBatchService::create()}.
     *
     * @return array{consultant: string, client: string, fiscal_year_start: \DateTimeImmutable, account_length: int, initials: string}
     */
    public function datevMeta(\DateTimeInterface $month): array
    {
        return [
            'consultant'        => (string) $this->datev_consultant,
            'client'            => (string) $this->datev_client,
            'fiscal_year_start' => self::fiscalYearStart((string) $this->datev_fiscal_year_start, $month),
            'account_length'    => (int) $this->datev_account_length,
            'initials'          => (string) $this->datev_initials,
        ];
    }

    /**
     * Liegt der Buchungsmonat vor der Umstellung? Dann hat die Buchhaltung die Rechnungen selbst je KST
     * verteilt — kein Stapel, sonst doppelt gebucht. Ohne Umstellungsmonat gibt es keine Grenze.
     */
    public function datevBeforeStart(\DateTimeInterface $month): bool
    {
        return $this->datev_start_month !== null
            && $month->format('Y-m') < $this->datev_start_month->format('Y-m');
    }

    /**
     * Beginn des Wirtschaftsjahres, in dem der Buchungsmonat liegt: „07-01" und März 2026 → 01.07.2025.
     */
    public static function fiscalYearStart(string $monthDay, \DateTimeInterface $month): \DateTimeImmutable
    {
        if (! preg_match('/^(\d{2})-(\d{2})$/', $monthDay, $m) || ! checkdate((int) $m[1], (int) $m[2], 2001)) {
            throw new \InvalidArgumentException("Beginn Wirtschaftsjahr „{$monthDay}“ ist kein gültiges MM-TT.");
        }

        $year  = (int) $month->format('Y');
        $start = new \DateTimeImmutable(sprintf('%04d-%s-%s', $year, $m[1], $m[2]));

        return $start->format('Y-m') > $month->format('Y-m') ? $start->modify('-1 year') : $start;
    }
}
