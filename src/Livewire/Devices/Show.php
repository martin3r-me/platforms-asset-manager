<?php

namespace Platform\AssetManager\Livewire\Devices;

use Livewire\Component;
use Illuminate\Support\Facades\Auth;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetDeviceSyncLog;

class Show extends Component
{
    public AssetDevice $device;
    public bool $showRawData = false;

    public function mount(AssetDevice $device): void
    {
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
        $activities = AssetDeviceSyncLog::where('team_id', $this->device->team_id)
            ->orderByDesc('started_at')
            ->limit(10)
            ->get();

        return view('asset-manager::livewire.devices.show', [
            'device'     => $this->device,
            'activities' => $activities,
        ])->layout('platform::layouts.app');
    }
}
