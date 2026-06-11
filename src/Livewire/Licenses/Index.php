<?php

namespace Platform\AssetManager\Livewire\Licenses;

use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Platform\AssetManager\Jobs\SyncLicensesJob;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetLicenseSku;
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

        $skus = $query
            ->orderBy($this->sortField, $this->sortDirection)
            ->paginate(25);

        $allSkus          = AssetLicenseSku::where('team_id', $team->id)->get();
        $totalMonthlyCost = $allSkus->sum(fn($s) => $s->monthlyCost());
        $unusedLicenses   = $allSkus->where('available_units', '>', 0)->count();

        $lastLog  = AssetLicenseSyncLog::where('team_id', $team->id)
            ->orderBy('started_at', 'desc')
            ->first();

        $config   = AssetConnectorConfig::where('team_id', $team->id)->first();
        $canSync  = Gate::allows('manageConnector', AssetDevice::class);

        return view('asset-manager::livewire.licenses.index', [
            'skus'             => $skus,
            'totalMonthlyCost' => $totalMonthlyCost,
            'unusedLicenses'   => $unusedLicenses,
            'lastLog'          => $lastLog,
            'canSync'          => $canSync,
            'config'           => $config,
        ])->layout('platform::layouts.app');
    }
}
