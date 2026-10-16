<?php

namespace Platform\AssetManager\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Platform\AssetManager\Concerns\ResolvesCurrentTeam;
use Platform\AssetManager\Services\AssetResetService;
use Platform\AssetManager\Models\AssetTeamSetting;
use Platform\AssetManager\Models\AssetTenant;
use Platform\AssetManager\Services\ControllingContext;
use Platform\AssetManager\Services\HolderClassifier;
use Platform\AssetManager\Services\TenantContext;

/**
 * Modul-Einstellungen je Team (ADR 0008). Controlling-Schalter + Gefahrenzone (Team-Reset).
 * Lesen für alle Team-Mitglieder; Umschalten/Reset erfordert Owner/Admin (asset-manager.manage).
 */
class Index extends Component
{
    use ResolvesCurrentTeam;

    public bool $controllingEnabled = false;

    /** Gefahrenzone: Sichtbarkeit des Reset-Bestätigungs-Modals. */
    public bool $showReset = false;

    /** Type-to-confirm: der Nutzer muss hier den Teamnamen exakt eintippen. */
    public string $resetPhrase = '';

    /**
     * Klassifizierungs-Regeln für den Träger-Typ, je Tenant (ADR 0017) — als JSON-Text im Editor.
     *
     * Bewusst Rohtext statt Formular-Zeilen: die Regelliste ist kurz, wird selten angefasst, und ein
     * Zeilen-Editor wäre für den Nutzen deutlich mehr UI. Beim Speichern wird validiert und
     * normalisiert zurückgeschrieben, damit ein Tippfehler sofort sichtbar wird.
     */
    public string $holderRulesJson = '';

    public ?string $holderRulesError = null;

    /**
     * Ziele der Lizenz-Verteilungs-Kaskade für Mengen ohne Träger (ADR 0019).
     *
     * `licenseOverflowCostCenterId` nimmt Monats-Seats auf, die über den Bedarf an Funktionskonten
     * hinausgehen; `licensePoolCostCenterId` bezahlten, aber unbesetzten Leerstand. Beide sind
     * konfigurierbar, weil „IT-Gemeinkosten" und „Lizenzpool IT" Kostenstellennamen *eines* Kunden
     * sind — im Code wären sie eine Kundenspezifik im Schema.
     */
    public ?int $licenseOverflowCostCenterId = null;

    public ?int $licensePoolCostCenterId = null;

    /** Sammelstelle der Telefonie (#931): Anlagenanschluss, Verbindungen, Plätze ohne Person. */
    public ?int $telephonyCostCenterId = null;

    public ?string $licenseCostCenterFlash = null;

    // ---- DATEV-Stapelkopf (Discussion #26) — je Tenant, weil Kanzlei und Mandant Kundendaten sind.

    public string $datevConsultant = '';

    public string $datevClient = '';

    /** Anzeigeform „TT.MM." — gespeichert als MM-DD. */
    public string $datevFiscalYearStart = '';

    public string $datevAccountLength = '';

    public string $datevInitials = '';

    public ?int $datevSourceCostCenterId = null;

    /** Erster umzubuchender Leistungsmonat (`Y-m`) — davor verteilt die Buchhaltung selbst. */
    public string $datevStartMonth = '';

    public ?string $datevFlash = null;

    public function mount(): void
    {
        $this->controllingEnabled = app(ControllingContext::class)->enabledFor($this->teamId());
        $this->loadHolderRules();
        $this->loadLicenseCostCenters();
        $this->loadDatevHeader();
    }

    protected function loadDatevHeader(): void
    {
        $settings = AssetTeamSetting::where('team_id', $this->teamId())->first();

        $this->datevConsultant         = (string) $settings?->datev_consultant;
        $this->datevClient             = (string) $settings?->datev_client;
        $this->datevAccountLength      = $settings?->datev_account_length !== null ? (string) $settings->datev_account_length : '';
        $this->datevInitials           = (string) $settings?->datev_initials;
        $this->datevSourceCostCenterId = $settings?->datev_source_cost_center_id;
        $this->datevStartMonth         = (string) $settings?->datev_start_month?->format('Y-m');

        $monthDay = (string) $settings?->datev_fiscal_year_start;
        $this->datevFiscalYearStart = preg_match('/^(\d{2})-(\d{2})$/', $monthDay, $m) ? "{$m[2]}.{$m[1]}." : '';
    }

    /**
     * DATEV-Stapelkopf speichern. Geprüft wird hier, was der Writer später ohnehin verlangt — so
     * fällt ein Tippfehler beim Speichern auf und nicht erst beim ersten Stapel.
     */
    public function saveDatevHeader(): void
    {
        abort_unless(Gate::allows('asset-manager.manage'), 403);

        $this->datevFlash = null;
        $this->datevConsultant = trim($this->datevConsultant);
        $this->datevClient     = trim($this->datevClient);
        $this->datevInitials   = trim($this->datevInitials);

        $this->validate([
            'datevConsultant'      => ['nullable', 'regex:/^\d{1,7}$/'],
            'datevClient'          => ['nullable', 'regex:/^\d{1,5}$/'],
            'datevFiscalYearStart' => ['nullable', 'regex:/^\s*\d{1,2}\.\d{1,2}\.?\s*$/'],
            'datevAccountLength'   => ['nullable', 'integer', 'between:4,8'],
            'datevInitials'        => ['nullable', 'max:2', 'not_regex:/[;"]/'],
            'datevStartMonth'      => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ], [
            'datevConsultant.regex'      => 'Berater: nur Ziffern, höchstens 7.',
            'datevClient.regex'          => 'Mandant: nur Ziffern, höchstens 5.',
            'datevFiscalYearStart.regex' => 'Beginn Wirtschaftsjahr als TT.MM., z. B. 01.01.',
            'datevAccountLength.between' => 'Sachkontenlänge 4 bis 8.',
            'datevInitials.max'          => 'Diktatkürzel: höchstens 2 Zeichen.',
            'datevStartMonth.regex'      => 'Umbuchung ab: Monat als JJJJ-MM.',
        ]);

        $monthDay = null;
        if (trim($this->datevFiscalYearStart) !== '') {
            [$day, $month] = array_map('intval', explode('.', trim($this->datevFiscalYearStart)));
            if (! checkdate($month, $day, 2001)) {
                $this->addError('datevFiscalYearStart', 'Beginn Wirtschaftsjahr ist kein gültiges Datum.');

                return;
            }
            $monthDay = sprintf('%02d-%02d', $month, $day);
            $this->datevFiscalYearStart = sprintf('%02d.%02d.', $day, $month);
        }

        $source = $this->datevSourceCostCenterId !== null ? (int) $this->datevSourceCostCenterId : null;
        if ($source !== null && ! \Platform\AssetManager\Models\AssetCostCenter::where('team_id', $this->teamId())->whereKey($source)->exists()) {
            $source = null;
        }
        $this->datevSourceCostCenterId = $source;

        AssetTeamSetting::updateOrCreate(
            ['team_id' => $this->teamId(), 'tenant_id' => TenantContext::scopeTenantId()],
            [
                'datev_consultant'            => $this->datevConsultant ?: null,
                'datev_client'                => $this->datevClient ?: null,
                'datev_fiscal_year_start'     => $monthDay,
                'datev_account_length'        => $this->datevAccountLength !== '' ? (int) $this->datevAccountLength : null,
                'datev_initials'              => $this->datevInitials ?: null,
                'datev_source_cost_center_id' => $source,
                'datev_start_month'           => $this->datevStartMonth !== '' ? $this->datevStartMonth . '-01' : null,
            ],
        );

        $this->datevFlash = 'Gespeichert.';
    }

    protected function loadLicenseCostCenters(): void
    {
        $settings = AssetTeamSetting::where('team_id', $this->teamId())->first();

        $this->licenseOverflowCostCenterId = $settings?->license_overflow_cost_center_id;
        $this->licensePoolCostCenterId     = $settings?->license_pool_cost_center_id;
        $this->telephonyCostCenterId       = $settings?->telephony_cost_center_id;
    }

    /**
     * Sammel-Kostenstellen speichern. Eine Änderung wirkt sofort auf die Kostenaufteilung, ohne dass
     * neu verteilt werden muss: die Mengen stehen an den Vertragszeilen, nur ihr Ziel ändert sich.
     */
    public function saveLicenseCostCenters(): void
    {
        abort_unless(Gate::allows('asset-manager.manage'), 403);

        $valid = \Platform\AssetManager\Models\AssetCostCenter::where('team_id', $this->teamId())
            ->pluck('id')
            ->all();

        foreach (['licenseOverflowCostCenterId', 'licensePoolCostCenterId', 'telephonyCostCenterId'] as $field) {
            $value = $this->{$field} !== null ? (int) $this->{$field} : null;

            // Fremde oder gelöschte Kostenstelle nicht übernehmen — sonst zeigte die Einstellung auf
            // eine Zeile, die in diesem Tenant nicht existiert, und die Beträge verschwänden.
            $this->{$field} = ($value !== null && in_array($value, $valid, true)) ? $value : null;
        }

        AssetTeamSetting::updateOrCreate(
            ['team_id' => $this->teamId(), 'tenant_id' => TenantContext::scopeTenantId()],
            [
                'license_overflow_cost_center_id' => $this->licenseOverflowCostCenterId,
                'license_pool_cost_center_id'     => $this->licensePoolCostCenterId,
                'telephony_cost_center_id'        => $this->telephonyCostCenterId,
            ],
        );

        \Platform\AssetManager\Support\LicensePriceBook::flush();

        $this->licenseCostCenterFlash = 'Gespeichert.';
    }

    protected function loadHolderRules(): void
    {
        $rules = app(HolderClassifier::class)->rulesFor(TenantContext::scopeTenantId());

        $this->holderRulesJson = $rules === []
            ? ''
            : json_encode($rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Regeln des aktiven Tenants speichern.
     *
     * Ungültige Regeln werden von {@see HolderClassifier::sanitize()} verworfen, nicht abgelehnt —
     * und der Nutzer sieht anschließend die normalisierte Liste. So ist sofort erkennbar, WAS
     * übernommen wurde, statt dass eine unwirksame Regel gespeichert dasteht und nichts tut.
     */
    public function saveHolderRules(): void
    {
        Gate::authorize('asset-manager.manage');

        $this->holderRulesError = null;
        $raw = trim($this->holderRulesJson);

        if ($raw === '') {
            $rules = [];
        } else {
            $decoded = json_decode($raw, true);

            if (! is_array($decoded)) {
                $this->holderRulesError = 'Kein gültiges JSON-Array. Beispiel: '
                    . '[{"type":"admin","field":"upn","match":"prefix","value":"adm-"}]';

                return;
            }

            $rules = app(HolderClassifier::class)->sanitize($decoded);

            if ($rules === [] && $decoded !== []) {
                $this->holderRulesError = 'Keine der Regeln ist gültig. Erlaubt: type = function|admin|'
                    . 'service|external, field = upn|email|display_name, match = prefix|suffix|contains|equals, '
                    . 'value nicht leer.';

                return;
            }
        }

        $tenantId = TenantContext::resolveForWrite($this->teamId(), (int) Auth::id());

        AssetTeamSetting::withoutTenantScope()->updateOrCreate(
            ['team_id' => $this->teamId(), 'tenant_id' => $tenantId],
            ['holder_type_rules' => $rules === [] ? null : $rules],
        );

        HolderClassifier::forget();
        $this->loadHolderRules();

        $count = count($rules);
        session()->flash('status', $count === 0
            ? "Klassifizierungs-Regeln für {$this->activeTenantName()} entfernt — neue Träger gelten als Person."
            : "{$count} Klassifizierungs-Regel(n) für {$this->activeTenantName()} gespeichert. Wirkt auf den nächsten Sync; bestehende Träger über den Neu-Klassifizieren-Lauf.");
    }

    private function teamName(): string
    {
        return (string) Auth::user()->currentTeam->name;
    }

    public function openReset(): void
    {
        Gate::authorize('asset-manager.manage');

        $this->resetPhrase = '';
        $this->showReset = true;
    }

    public function confirmReset(AssetResetService $service): void
    {
        Gate::authorize('asset-manager.manage');

        // Harte serverseitige Absicherung — unabhängig vom UI-Disabled. Der eingetippte Teamname
        // muss exakt passen, sonst 403 (verhindert versehentliches/umgangenes Auslösen).
        abort_unless(trim($this->resetPhrase) === $this->teamName(), 403);

        $stats = $service->resetTeam($this->teamId());

        $this->showReset = false;
        $this->resetPhrase = '';

        $count = array_sum($stats);
        session()->flash('status', "Modul zurückgesetzt — {$count} Einträge dieses Teams wurden gelöscht. "
            . 'Intune-Anbindung und Controlling-Einstellung bleiben erhalten; der nächste Sync holt die Geräte zurück.');
    }

    public function toggleControlling(): void
    {
        Gate::authorize('asset-manager.manage');

        $new      = !$this->controllingEnabled;
        $tenantId = TenantContext::resolveForWrite($this->teamId(), (int) Auth::id());

        // Der Schalter gilt seit ADR 0016 je Tenant — ausdruecklich uebergeben, damit Anzeige und
        // Schreibziel garantiert derselbe Kundenkontext sind.
        app(ControllingContext::class)->setEnabled($this->teamId(), $new, $tenantId);
        $this->controllingEnabled = $new;

        $tenantName = $this->activeTenantName();

        session()->flash('status', $new
            ? "Controlling fuer {$tenantName} aktiviert — Auswertungen, Stammdaten und Kosten-Import sind nun sichtbar."
            : "Controlling fuer {$tenantName} deaktiviert — die Kosten-Schicht ist ausgeblendet. Vorhandene Daten bleiben erhalten.");
    }

    /** Name des aktiven Tenants — der Controlling-Schalter gilt nur fuer ihn (ADR 0016). */
    protected function activeTenantName(): string
    {
        $tenantId = TenantContext::scopeTenantId();

        if ($tenantId === null) {
            return $this->teamName();
        }

        return (string) (AssetTenant::query()->whereKey($tenantId)->value('name') ?? $this->teamName());
    }

    public function render()
    {
        return view('asset-manager::livewire.settings.index', [
            'canManage'  => Gate::allows('asset-manager.manage'),
            'teamName'   => $this->teamName(),
            // Damit auf der Seite steht, WELCHER Kundenkontext gerade geschaltet wird.
            'tenantName' => $this->activeTenantName(),
            'holderTypeExample' => json_encode([
                ['type' => 'admin', 'field' => 'upn', 'match' => 'prefix', 'value' => 'adm-'],
                ['type' => 'service', 'field' => 'upn', 'match' => 'contains', 'value' => 'svc'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'costCenters' => \Platform\AssetManager\Models\AssetCostCenter::where('team_id', $this->teamId())
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
        ])->layout('platform::layouts.app');
    }
}
