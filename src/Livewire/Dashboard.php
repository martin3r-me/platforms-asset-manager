<?php

/**
 * Dashboard Livewire Component
 * 
 * Hauptübersicht des Moduls.
 * 
 * WICHTIG FÜR LLMs:
 * - Jedes Modul sollte ein Dashboard haben
 * - Dashboard zeigt Übersicht/Statistiken
 * - Verwendet platform::layouts.app Layout
 * - Kann comms-Event dispatch'en (für Kommunikation)
 * 
 * ANPASSUNGEN:
 * - Füge Datenqueries hinzu
 * - Passe View an deine Bedürfnisse an
 * - Füge Statistiken hinzu
 */

namespace Platform\AssetManager\Livewire;

use Livewire\Component;
use Illuminate\Support\Facades\Auth;
use Platform\AssetManager\Models\AssetConnectorConfig;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetDeviceSyncLog;

class Dashboard extends Component
{
    /**
     * Dispatch comms-Event (optional)
     * 
     * Wird nach dem Rendern aufgerufen.
     * Kann für Kommunikation/Notifications verwendet werden.
     */
    public function rendered()
    {
        $this->dispatch('comms', [
            'model' => null,
            'modelId' => null,
            'subject' => 'Asset Manager Dashboard',
            'description' => 'Übersicht des Template-Moduls',
            'url' => route('asset-manager.dashboard'),
            'source' => 'asset-manager.dashboard',
            'recipients' => [],
            'meta' => [
                'view_type' => 'dashboard',
            ],
        ]);
    }

    /**
     * Render-Methode
     * 
     * Lädt Daten und gibt die View zurück.
     * 
     * PATTERN:
     * 1. User/Team holen
     * 2. Daten laden (Models, Statistiken, etc.)
     * 3. View mit Daten zurückgeben
     */
    public function render()
    {
        $user = Auth::user();
        $team = $user->currentTeam;

        /**
         * BEISPIEL: Daten laden
         * 
         * $entities = YourModel::where('team_id', $team->id)
         *     ->orderBy('name')
         *     ->get();
         * 
         * $stats = [
         *     'total' => $entities->count(),
         *     'active' => $entities->where('is_active', true)->count(),
         * ];
         */

        $team   = $user->currentTeam;
        $config = AssetConnectorConfig::where('team_id', $team->id)->first();

        $stats = [
            'total'        => 0,
            'compliant'    => 0,
            'noncompliant' => 0,
            'unknown'      => 0,
        ];

        $recentDevices = collect();
        $lastLog       = null;

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
        }

        return view('asset-manager::livewire.dashboard', [
            'config'        => $config,
            'stats'         => $stats,
            'recentDevices' => $recentDevices,
            'lastLog'       => $lastLog,
        ])->layout('platform::layouts.app');
    }
}
