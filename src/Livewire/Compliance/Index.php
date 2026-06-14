<?php

namespace Platform\AssetManager\Livewire\Compliance;

use Livewire\Component;
use Illuminate\Support\Facades\Auth;
use Platform\AssetManager\Livewire\Devices\Index as DevicesIndex;
use Platform\AssetManager\Models\AssetConnectorConfig;
use Platform\AssetManager\Models\AssetDevice;

/**
 * Read-only Sicherheits-/Compliance-Cockpit: aggregiert Compliance-Quote, Verschlüsselungs-Abdeckung,
 * inaktive/zuordnungslose Geräte und OS-Versionen. Die Handlungslisten verlinken in die gefilterte
 * Geräteliste (Presets) — keine eigene Tabelle, kein Drill-Duplikat.
 */
class Index extends Component
{
    public function render()
    {
        $teamId = Auth::user()->currentTeam->id;
        $scope  = fn () => AssetDevice::where('team_id', $teamId);

        $total = $scope()->count();

        $compliant       = $scope()->where('compliance_state', 'compliant')->count();
        $noncompliant    = $scope()->where('compliance_state', 'noncompliant')->count();
        $complianceQuote = $total > 0 ? (int) round($compliant / $total * 100) : 0;

        $encrypted   = $scope()->where('is_encrypted', true)->count();
        $unencrypted = $scope()->where('is_encrypted', false)->count();
        $encUnknown  = max(0, $total - $encrypted - $unencrypted);
        $encQuote    = $total > 0 ? (int) round($encrypted / $total * 100) : 0;

        $stale = $scope()->where(function ($q) {
            $q->whereNull('last_check_in_at')
              ->orWhere('last_check_in_at', '<', now()->subDays(DevicesIndex::INACTIVE_DAYS));
        })->count();

        $noUser = $scope()->where(function ($q) {
            $q->whereNull('user_principal_name')->orWhere('user_principal_name', '');
        })->count();

        $expiring = $scope()->where(function ($q) {
            $t = now()->addDays(AssetDevice::EXPIRY_SOON_DAYS);
            $q->where(function ($w) use ($t) { $w->whereNotNull('warranty_until')->where('warranty_until', '<=', $t); })
              ->orWhere(function ($w) use ($t) { $w->whereNotNull('lease_until')->where('lease_until', '<=', $t); });
        })->count();

        $complianceBreakdown = $scope()
            ->selectRaw('compliance_state, count(*) as count')
            ->groupBy('compliance_state')
            ->orderByDesc('count')
            ->get();

        $osBreakdown = $scope()
            ->selectRaw("COALESCE(operating_system, 'Unbekannt') as os, COALESCE(os_version, '—') as version, count(*) as count")
            ->groupBy('os', 'version')
            ->orderByDesc('count')
            ->limit(12)
            ->get();

        $config = AssetConnectorConfig::where('team_id', $teamId)->first();

        return view('asset-manager::livewire.compliance.index', [
            'total'               => $total,
            'compliant'           => $compliant,
            'noncompliant'        => $noncompliant,
            'complianceQuote'     => $complianceQuote,
            'encrypted'           => $encrypted,
            'unencrypted'         => $unencrypted,
            'encUnknown'          => $encUnknown,
            'encQuote'            => $encQuote,
            'stale'               => $stale,
            'noUser'              => $noUser,
            'expiring'            => $expiring,
            'complianceBreakdown' => $complianceBreakdown,
            'osBreakdown'         => $osBreakdown,
            'config'              => $config,
        ])->layout('platform::layouts.app');
    }
}
