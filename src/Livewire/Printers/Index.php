<?php

namespace Platform\AssetManager\Livewire\Printers;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\AssetManager\Models\AssetCategory;
use Platform\AssetManager\Models\AssetCostLine;
use Platform\AssetManager\Models\AssetItem;

class Index extends Component
{
    public string $search = '';

    protected function teamId(): int
    {
        return Auth::user()->currentTeam->id;
    }

    public function render()
    {
        $teamId = $this->teamId();
        $catId  = AssetCategory::where('key', 'drucker')->value('id');

        $items = collect();
        if ($catId) {
            $q = AssetItem::where('team_id', $teamId)->where('category_id', $catId);
            if ($this->search) {
                $q->where(fn($x) => $x->where('name', 'like', "%{$this->search}%")
                    ->orWhere('model', 'like', "%{$this->search}%")
                    ->orWhere('serial_number', 'like', "%{$this->search}%"));
            }
            $items = $q->orderBy('name')->get();
        }

        // Kosten je Drucker (Wartung + Leasing) aus cost_lines
        $costByItem = AssetCostLine::active()->where('team_id', $teamId)
            ->whereIn('asset_item_id', $items->pluck('id'))
            ->get(['asset_item_id', 'monthly_amount'])
            ->groupBy('asset_item_id')
            ->map(fn($g) => (float) $g->sum('monthly_amount'));

        return view('asset-manager::livewire.printers.index', [
            'items'      => $items,
            'costByItem' => $costByItem,
            'totalMonthly' => round($costByItem->sum(), 2),
        ])->layout('platform::layouts.app');
    }
}
