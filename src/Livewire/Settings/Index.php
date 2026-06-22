<?php

namespace Platform\AssetManager\Livewire\Settings;

use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Platform\AssetManager\Concerns\ResolvesCurrentTeam;
use Platform\AssetManager\Services\ControllingContext;

/**
 * Modul-Einstellungen je Team (ADR 0008). Aktuell: Controlling-Schalter.
 * Lesen für alle Team-Mitglieder; Umschalten erfordert Owner/Admin (asset-manager.manage).
 */
class Index extends Component
{
    use ResolvesCurrentTeam;

    public bool $controllingEnabled = false;

    public function mount(): void
    {
        $this->controllingEnabled = app(ControllingContext::class)->enabledFor($this->teamId());
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
        ])->layout('platform::layouts.app');
    }
}
