<?php

namespace Platform\AssetManager\Livewire\Reports;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetDeviceModel;
use Platform\AssetManager\Support\DeviceCostResolver;

/**
 * Read-only Auswertung: Intune-Geräte gruppiert nach Hersteller + Modell.
 * Zeigt Stückzahl, zugewiesene Geräte und Σ Monatskosten je Modell und macht Modelle OHNE
 * hinterlegte Kosten sichtbar (die fallen sonst still aus der Kostenrechnung — analog „SKU ohne Preis").
 *
 * Monatskosten = rohe AfA/Leasing JE OBJEKT summiert (über Override bzw. Katalog-Default), NICHT die
 * doppelzählungsfreie, kostenstellen-zugeteilte Zahl des Kosten-Dashboards. Rührt CostAggregationService
 * nicht an — nur Lese-Logik (statische AssetDevice::computeMonthlyFrom).
 */
class DeviceModels extends Component
{
    public string $sortField     = 'count';   // count | monthly | name
    public string $sortDirection = 'desc';

    protected $queryString = [
        'sortField'     => ['except' => 'count'],
        'sortDirection' => ['except' => 'desc'],
    ];

    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField     = $field;
            $this->sortDirection = $field === 'name' ? 'asc' : 'desc';
        }
    }

    public function render()
    {
        $teamId = Auth::user()->currentTeam->id;

        // Katalog + tenant-eigene Modell-Kosten einmal laden → Preis-Flag + N+1-freie Auflösung.
        $resolver = DeviceCostResolver::for($teamId);

        $groups = [];
        foreach (AssetDevice::where('team_id', $teamId)->get() as $device) {
            $key = AssetDeviceModel::normalizeKey($device->manufacturer, $device->model);

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'manufacturer' => $device->manufacturer ?: '—',
                    'model'        => $device->model ?: '—',
                    'count'        => 0,
                    'assigned'     => 0,
                    'monthly'      => 0.0,
                    'hasCatalog'   => $resolver->modelFor($device) !== null,
                ];
            }

            $groups[$key]['count']++;
            if (filled($device->user_principal_name)) {
                $groups[$key]['assigned']++;
            }

            // Override am Gerät, sonst tenant-eigener Katalog-Default — beides im Resolver (N+1-frei).
            $groups[$key]['monthly'] += $resolver->monthlyCost($device);
        }

        $rows = collect($groups)->values()->sortBy(function ($g) {
            return match ($this->sortField) {
                'monthly' => $g['monthly'],
                'name'    => mb_strtolower($g['manufacturer'] . ' ' . $g['model']),
                default   => $g['count'],
            };
        }, SORT_REGULAR, $this->sortDirection === 'desc')->values();

        $summary = [
            'devices'     => (int) $rows->sum('count'),
            'models'      => $rows->count(),
            'withoutCost' => $rows->filter(fn ($g) => $g['monthly'] <= 0)->count(),
        ];

        return view('asset-manager::livewire.reports.device-models', [
            'rows'    => $rows,
            'summary' => $summary,
        ])->layout('platform::layouts.app');
    }
}
