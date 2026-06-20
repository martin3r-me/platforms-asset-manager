<?php

namespace Platform\AssetManager\Livewire\Costs;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;
use Platform\AssetManager\Concerns\ResolvesCurrentTeam;
use Platform\AssetManager\Models\AssetCostLine;

/**
 * Import-Log: zeigt zeilengenau, WAS aus dem Excel WOHIN importiert wurde.
 * Quelle = jede Cost-Line mit source='excel_import'; Herkunft (Sheet + Excel-Zeile) aus raw_data.
 */
class ImportLog extends Component
{
    use ResolvesCurrentTeam;
    use WithPagination;

    public string $filterSheet = '';
    public string $filterBatch = '';
    public int    $perPage     = 50;

    protected $queryString = [
        'filterSheet' => ['except' => ''],
        'filterBatch' => ['except' => ''],
    ];

    public function updatingFilterSheet(): void { $this->resetPage(); }
    public function updatingFilterBatch(): void { $this->resetPage(); }

    public function render()
    {
        $teamId = $this->teamId();

        $base = AssetCostLine::where('team_id', $teamId)->where('source', 'excel_import');

        // Filter-Optionen aus allen Import-Zeilen
        $meta    = (clone $base)->get(['import_batch_id', 'raw_data']);
        $sheets  = $meta->map(fn ($l) => $l->raw_data['sheet'] ?? null)->filter()->unique()->sort()->values();
        $batches = $meta->pluck('import_batch_id')->filter()->unique()->sort()->values();

        $query = (clone $base)->with(['costType', 'costCenter', 'assignee', 'assetItem', 'vendor']);
        if ($this->filterSheet !== '') $query->where('raw_data->sheet', $this->filterSheet);
        if ($this->filterBatch !== '') $query->where('import_batch_id', $this->filterBatch);

        $shownSum = round((float) (clone $query)->sum('monthly_amount'), 2);
        $lines    = $query->orderByDesc('created_at')->orderBy('id')->paginate($this->perPage);

        return view('asset-manager::livewire.costs.import-log', [
            'lines'    => $lines,
            'sheets'   => $sheets,
            'batches'  => $batches,
            'total'    => (clone $base)->count(),
            'shownSum' => $shownSum,
        ])->layout('platform::layouts.app');
    }
}
