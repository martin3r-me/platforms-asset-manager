<?php

namespace Platform\AssetManager\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Platform\AssetManager\Concerns\ResolvesCurrentTeam;
use Platform\AssetManager\Services\AssetResetService;
use Platform\AssetManager\Services\ControllingContext;

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

    public function mount(): void
    {
        $this->controllingEnabled = app(ControllingContext::class)->enabledFor($this->teamId());
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

        $new = !$this->controllingEnabled;
        app(ControllingContext::class)->setEnabled($this->teamId(), $new);
        $this->controllingEnabled = $new;

        session()->flash('status', $new
            ? 'Controlling aktiviert — Auswertungen, Stammdaten und Kosten-Import sind nun sichtbar.'
            : 'Controlling deaktiviert — die Kosten-Schicht ist ausgeblendet. Vorhandene Daten bleiben erhalten.');
    }

    public function render()
    {
        return view('asset-manager::livewire.settings.index', [
            'canManage' => Gate::allows('asset-manager.manage'),
            'teamName'  => $this->teamName(),
        ])->layout('platform::layouts.app');
    }
}
