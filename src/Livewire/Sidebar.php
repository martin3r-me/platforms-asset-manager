<?php

namespace Platform\AssetManager\Livewire;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\AssetManager\Services\ControllingContext;

class Sidebar extends Component
{
    public function render()
    {
        // Controlling-Schicht (Auswertungen, Stammdaten, Kosten-Import) ist per Team abschaltbar (ADR 0008).
        $controllingEnabled = false;
        $team = Auth::user()?->currentTeam;
        if ($team) {
            $controllingEnabled = app(ControllingContext::class)->enabledFor($team->id);
        }

        return view('asset-manager::livewire.sidebar', [
            'controllingEnabled' => $controllingEnabled,
        ]);
    }
}
