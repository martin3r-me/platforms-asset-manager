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

    public function mount(): void
    {
        $this->controllingEnabled = app(ControllingContext::class)->enabledFor($this->teamId());
        $this->loadHolderRules();
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
        ])->layout('platform::layouts.app');
    }
}
