<?php

namespace Platform\AssetManager\Livewire;

use Livewire\Component;
use Illuminate\Support\Facades\Auth;
use Platform\AssetManager\Models\AssetConnectorConfig;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetDeviceSyncLog;
use Platform\AssetManager\Models\AssetLicenseSku;
use Platform\AssetManager\Models\AssetLicenseSyncLog;

class Dashboard extends Component
{
    public function render()
    {
        $user   = Auth::user();
        $team   = $user->currentTeam;
        $config = AssetConnectorConfig::where('team_id', $team->id)->first();

        $stats = [
            'total'        => 0,
            'compliant'    => 0,
            'noncompliant' => 0,
            'unknown'      => 0,
        ];
        $recentDevices   = collect();
        $lastLog         = null;
        $licenseCost     = 0.0;
        $unusedLicenses  = 0;
        $lastLicenseSync = null;

        if ($config && $config->isConfigured()) {
            $stats = [
                'total'        => AssetDevice::where('team_id', $team->id)->count(),
                'compliant'    => AssetDevice::where('team_id', $team->id)->where('compliance_state', 'compliant')->count(),
                'noncompliant' => AssetDevice::where('team_id', $team->id)->where('compliance_state', 'noncompliant')->count(),
                'unknown'      => AssetDevice::where('team_id', $team->id)->whereIn('compliance_state', ['unknown', 'error', 'conflict'])->count(),
            ];

            $recentDevices = AssetDevice::where('team_id', $team->id)
                ->orderBy('updated_at', 'desc')
                ->limit(5)
                ->get();

            $lastLog = AssetDeviceSyncLog::where('team_id', $team->id)
                ->orderBy('started_at', 'desc')
                ->first();

            $licenseCost = AssetLicenseSku::where('team_id', $team->id)
                ->get()
                ->sum(fn($s) => $s->monthlyCost());

            $unusedLicenses = AssetLicenseSku::where('team_id', $team->id)
                ->where('available_units', '>', 0)
                ->count();

            $lastLicenseSync = AssetLicenseSyncLog::where('team_id', $team->id)
                ->orderBy('started_at', 'desc')
                ->first();
        }

        return view('asset-manager::livewire.dashboard', [
            'config'          => $config,
            'stats'           => $stats,
            'recentDevices'   => $recentDevices,
            'lastLog'         => $lastLog,
            'licenseCost'     => $licenseCost,
            'unusedLicenses'  => $unusedLicenses,
            'lastLicenseSync' => $lastLicenseSync,
        ])->layout('platform::layouts.app');
    }
}
