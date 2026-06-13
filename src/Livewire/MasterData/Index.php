<?php

namespace Platform\AssetManager\Livewire\MasterData;

use Livewire\Component;

/**
 * Stammdaten-Sammelseite: zeigt die vier kostenaufteilungs-relevanten Stammdaten
 * (Gesellschaften, Kostenstellen, Kostenarten, Kreditoren) gleichzeitig untereinander
 * auf EINER Seite. Trägt selbst keine Fachlogik — die vier bestehenden Komponenten
 * werden im View als verschachtelte <livewire:.../>-Kinder gestapelt.
 */
class Index extends Component
{
    public function render()
    {
        return view('asset-manager::livewire.master-data.index')
            ->layout('platform::layouts.app');
    }
}
