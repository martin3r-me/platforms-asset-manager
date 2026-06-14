<?php

namespace Platform\AssetManager\Livewire;

use Livewire\Component;
use Illuminate\Support\Facades\Auth;
use Platform\AssetManager\Models\AssetConnectorConfig;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetDeviceSyncLog;
use Platform\AssetManager\Models\AssetEmployee;
use Platform\AssetManager\Models\AssetItem;
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
            'inactive'     => 0,
            'no_user'      => 0,
            'expiring'     => 0,
        ];
        $complianceQuote = 0;
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
                'inactive'     => AssetDevice::where('team_id', $team->id)
                                    ->where(function ($q) {
                                        $q->whereNull('last_check_in_at')
                                          ->orWhere('last_check_in_at', '<', now()->subDays(\Platform\AssetManager\Livewire\Devices\Index::INACTIVE_DAYS));
                                    })->count(),
                'no_user'      => AssetDevice::where('team_id', $team->id)
                                    ->where(function ($q) { $q->whereNull('user_principal_name')->orWhere('user_principal_name', ''); })
                                    ->count(),
                'expiring'     => AssetDevice::where('team_id', $team->id)
                                    ->where(function ($q) {
                                        $t = now()->addDays(AssetDevice::EXPIRY_SOON_DAYS);
                                        $q->where(function ($w) use ($t) { $w->whereNotNull('warranty_until')->where('warranty_until', '<=', $t); })
                                          ->orWhere(function ($w) use ($t) { $w->whereNotNull('lease_until')->where('lease_until', '<=', $t); });
                                    })->count(),
            ];
            $complianceQuote = $stats['total'] > 0 ? (int) round($stats['compliant'] / $stats['total'] * 100) : 0;

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

        $assetCounts = [
            'items'     => AssetItem::where('team_id', $team->id)->count(),
            'employees' => AssetEmployee::where('team_id', $team->id)->where('is_active', true)->count(),
        ];

        $hardwareCost = AssetItem::where('team_id', $team->id)
            ->get()
            ->sum(fn($i) => $i->monthlyCost());

        return view('asset-manager::livewire.dashboard', [
            'config'          => $config,
            'stats'           => $stats,
            'complianceQuote' => $complianceQuote,
            'recentDevices'   => $recentDevices,
            'lastLog'         => $lastLog,
            'licenseCost'     => $licenseCost,
            'unusedLicenses'  => $unusedLicenses,
            'lastLicenseSync' => $lastLicenseSync,
            'assetCounts'     => $assetCounts,
            'hardwareCost'    => $hardwareCost,
            'totalMonthly'    => $licenseCost + $hardwareCost,
        ])->layout('platform::layouts.app');
    }
}
