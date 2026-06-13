<?php

namespace Platform\AssetManager\Livewire\Licenses;

use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Platform\AssetManager\Jobs\SyncLicensesJob;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetLicenseSku;
use Platform\AssetManager\Models\AssetUserLicense;
use Platform\AssetManager\Models\AssetLicenseSyncLog;
use Platform\AssetManager\Models\AssetConnectorConfig;

class Index extends Component
{
    use WithPagination;

    public string  $search        = '';
    public string  $sortField     = 'display_name';
    public string  $sortDirection = 'asc';
    public ?string $syncResult    = null;

    // Inline-Preis-Bearbeitung
    public array $editingPrices = [];

    // Master-Detail: in der linken Sidebar aufgeklappte Lizenz
    public ?int $selectedSkuId = null;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField     = $field;
            $this->sortDirection = 'asc';
        }
    }

    public function syncNow(): void
    {
        Gate::authorize('manageConnector', AssetDevice::class);

        $team = Auth::user()->currentTeam;

        SyncLicensesJob::dispatch($team->id);

        $this->syncResult = 'Lizenz-Sync gestartet — Daten werden im Hintergrund synchronisiert.';
    }

    public function updatePrice(int $skuId, ?string $price): void
    {
        Gate::authorize('manageConnector', AssetDevice::class);

        $team = Auth::user()->currentTeam;

        AssetLicenseSku::where('team_id', $team->id)
            ->where('id', $skuId)
            ->update(['unit_price' => $price !== null && $price !== '' ? (float) str_replace(',', '.', $price) : null]);

        unset($this->editingPrices[$skuId]);
    }

    public function selectSku(int $skuId): void
    {
        $team = Auth::user()->currentTeam;

        $this->selectedSkuId = AssetLicenseSku::where('team_id', $team->id)
            ->whereKey($skuId)
            ->exists()
            ? $skuId
            : null;
    }

    public function clearSku(): void
    {
        $this->selectedSkuId = null;
    }

    public function render()
    {
        $team  = Auth::user()->currentTeam;
        $query = AssetLicenseSku::where('team_id', $team->id);

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('display_name', 'like', '%' . $this->search . '%')
                  ->orWhere('sku_part_number', 'like', '%' . $this->search . '%');
            });
        }

        $dir = $this->sortDirection === 'desc' ? 'desc' : 'asc';

        if ($this->sortField === 'monthly_cost') {
            $query->orderByRaw('COALESCE(unit_price, 0) * consumed_units ' . $dir);
        } else {
            $field = in_array($this->sortField, ['display_name', 'consumed_units'], true)
                ? $this->sortField
                : 'display_name';
            $query->orderBy($field, $dir);
        }

        $skus = $query->paginate(25);

        $allSkus          = AssetLicenseSku::where('team_id', $team->id)->get();
        $totalMonthlyCost = $allSkus->sum(fn($s) => $s->monthlyCost());
        $unusedLicenses   = $allSkus->where('available_units', '>', 0)->count();

        $lastLog  = AssetLicenseSyncLog::where('team_id', $team->id)
            ->orderBy('started_at', 'desc')
            ->first();

        $config   = AssetConnectorConfig::where('team_id', $team->id)->first();
        $canSync  = Gate::allows('manageConnector', AssetDevice::class);

        // Aufgeklappte Lizenz + ihre Nutzer-Zuweisungen (für die linke Sidebar)
        $selectedSku = null;
        $assignments = collect();

        if ($this->selectedSkuId) {
            $selectedSku = AssetLicenseSku::where('team_id', $team->id)
                ->find($this->selectedSkuId);

            if ($selectedSku) {
                $assignments = AssetUserLicense::where('team_id', $team->id)
                    ->where('sku_id', $selectedSku->sku_id)
                    ->orderBy('display_name')
                    ->limit(200)
                    ->get();
            } else {
                $this->selectedSkuId = null;
            }
        }

        return view('asset-manager::livewire.licenses.index', [
            'skus'             => $skus,
            'totalMonthlyCost' => $totalMonthlyCost,
            'unusedLicenses'   => $unusedLicenses,
            'lastLog'          => $lastLog,
            'canSync'          => $canSync,
            'config'           => $config,
            'selectedSku'      => $selectedSku,
            'assignments'      => $assignments,
        ])->layout('platform::layouts.app');
    }
}
