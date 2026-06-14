<?php

namespace Platform\AssetManager\Livewire\Reports;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetDeviceModel;

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

        // Modell-Katalog einmal laden → Preis-Flag + N+1-freie Kostenauflösung (statt deviceModel() je Zeile).
        $catalog = [];
        foreach (AssetDeviceModel::where('team_id', $teamId)->get() as $m) {
            $catalog[AssetDeviceModel::normalizeKey($m->manufacturer, $m->model)] = $m;
        }

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
                    'hasCatalog'   => isset($catalog[$key]),
                ];
            }

            $groups[$key]['count']++;
            if (filled($device->user_principal_name)) {
                $groups[$key]['assigned']++;
            }

            // Override am Gerät, sonst Katalog-Default (gleiche Logik wie resolvedMonthlyCost, aber N+1-frei).
            $monthly = AssetDevice::computeMonthlyFrom(
                $device->monthly_cost, $device->purchase_price, $device->depreciation_months, $device->purchase_date
            );
            if ($monthly === null && isset($catalog[$key])) {
                $c = $catalog[$key];
                $monthly = AssetDevice::computeMonthlyFrom($c->monthly_cost, $c->purchase_price, $c->depreciation_months, null);
            }
            $groups[$key]['monthly'] += $monthly ?? 0.0;
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
