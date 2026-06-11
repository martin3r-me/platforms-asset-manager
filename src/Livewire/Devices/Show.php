<?php

namespace Platform\AssetManager\Livewire\Devices;

use Livewire\Component;
use Illuminate\Support\Facades\Auth;
use Platform\AssetManager\Models\AssetDevice;

class Show extends Component
{
    public AssetDevice $device;

    public bool $showRawData = false;

    public function mount(AssetDevice $device): void
    {
        // Sicherstellen, dass das Gerät zum aktuellen Team gehört
        abort_unless(
            $device->team_id === Auth::user()->currentTeam->id,
            403
        );

        $this->device = $device;
    }

    public function toggleRawData(): void
    {
        $this->showRawData = !$this->showRawData;
    }

    public function render()
    {
        return view('asset-manager::livewire.devices.show', [
            'device' => $this->device,
        ])->layout('platform::layouts.app');
    }
}
