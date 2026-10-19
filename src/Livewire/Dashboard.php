<?php

namespace Platform\AssetManager\Livewire;

use Livewire\Component;
use Platform\AssetManager\Concerns\ReportsAcrossTenants;
use Illuminate\Support\Facades\Auth;
use Platform\AssetManager\Models\AssetConnectorConfig;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetDeviceSyncLog;
use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Models\AssetItem;
use Platform\AssetManager\Models\AssetLicenseSku;
use Platform\AssetManager\Models\AssetLicenseSyncLog;

/**
 * Modul-Dashboard. Eine **Auswertungs-Sicht**: befolgt die „Alle Tenants"-Präferenz
 * ({@see ReportsAcrossTenants}), zeigt sonst den aktiven Tenant.
 *
 * Vor docs/adr/0016 war das Dashboard immer team-weit. Jetzt ist der Default die engere Sicht und
 * „alle Kunden" ein ausdrücklicher Umschalter-Wert — die Kacheln zeigen also standardmäßig den
 * Kunden, in dem man gerade arbeitet.
 */
class Dashboard extends Component
{
    use ReportsAcrossTenants;

    public function render()
    {
        return $this->inReportScope(fn () => $this->buildView());
    }

    private function buildView()
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
            'expired'      => 0,
            'issues'       => 0,
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
                // Genau das, worauf die Kachel verlinkt (#955) — Fehler/Konflikt zählen getrennt (Preset „issues").
                'unknown'      => AssetDevice::where('team_id', $team->id)->where('compliance_state', 'unknown')->count(),
                'issues'       => AssetDevice::where('team_id', $team->id)->whereIn('compliance_state', ['error', 'conflict'])->count(),
                'inactive'     => AssetDevice::where('team_id', $team->id)
                                    ->where(function ($q) {
                                        $q->whereNull('last_check_in_at')
                                          ->orWhere('last_check_in_at', '<', now()->subDays(\Platform\AssetManager\Livewire\Devices\Index::INACTIVE_DAYS));
                                    })->count(),
                'no_user'      => AssetDevice::where('team_id', $team->id)
                                    ->where(function ($q) { $q->whereNull('user_principal_name')->orWhere('user_principal_name', ''); })
                                    ->count(),
                // „Läuft ab" = ab heute bis +90 Tage; schon Abgelaufenes zählt getrennt (#955). Vorher hatte
                // die Kachel keine Untergrenze, und seit Jahren abgelaufene Geräte zählten dauerhaft mit.
                'expiring'     => AssetDevice::where('team_id', $team->id)
                                    ->where(function ($q) {
                                        $from = now()->startOfDay();
                                        $to   = now()->addDays(AssetDevice::EXPIRY_SOON_DAYS);
                                        $q->whereBetween('warranty_until', [$from, $to])
                                          ->orWhereBetween('lease_until', [$from, $to]);
                                    })->count(),
                'expired'      => AssetDevice::where('team_id', $team->id)
                                    ->where(function ($q) {
                                        $today = now()->startOfDay();
                                        $q->where('warranty_until', '<', $today)
                                          ->orWhere('lease_until', '<', $today);
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


            $unusedLicenses = AssetLicenseSku::where('team_id', $team->id)
                ->where('available_units', '>', 0)
                ->count();

            $lastLicenseSync = AssetLicenseSyncLog::where('team_id', $team->id)
                ->orderBy('started_at', 'desc')
                ->first();
        }

        $assetCounts = [
            'items'     => AssetItem::where('team_id', $team->id)->count(),
            'holders' => AssetHolder::where('team_id', $team->id)->where('is_active', true)->count(),
        ];

        // Kosten aus derselben Rechnung wie Kostenaufteilung und Lizenzseite (#955): Lizenzen über das
        // Preisbuch (Vertragszeile vor Handpreis — rechnungsgedeckte Lizenzen zählten hier vorher 0 €),
        // Hardware inklusive Gerätekosten (Leasing/Override), die vorher ganz fehlten. Nur bei aktivem
        // Controlling — sonst wird die Kosten-Engine gar nicht erst angeworfen (ADR 0008).
        $controllingEnabled = app(\Platform\AssetManager\Services\ControllingContext::class)->enabledFor($team->id);
        $hardwareCost = 0.0;
        if ($controllingEnabled) {
            $totals       = app(\Platform\AssetManager\Services\CostAggregationService::class)->totalMonthly($team->id);
            $licenseCost  = (float) $totals['licenses'];
            $hardwareCost = (float) $totals['hardware'];
        }

        return view('asset-manager::livewire.dashboard', [
            'controllingEnabled' => $controllingEnabled,
            // Fristen & Warnungen (#973): eine Liste statt fünf Seiten.
            'alerts'          => app(\Platform\AssetManager\Services\AlertService::class)->collect($team->id, $controllingEnabled),
            // Für den Hinweis „Zahlen über alle Kunden" in der Ansicht.
            'showsAllTenants' => $this->showsAllTenants(),
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
