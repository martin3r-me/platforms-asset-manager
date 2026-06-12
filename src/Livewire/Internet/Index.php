<?php

namespace Platform\AssetManager\Livewire\Internet;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\AssetManager\Models\AssetCategory;
use Platform\AssetManager\Models\AssetCostLine;
use Platform\AssetManager\Models\AssetItem;

class Index extends Component
{
    protected function teamId(): int
    {
        return Auth::user()->currentTeam->id;
    }

    public function render()
    {
        $teamId = $this->teamId();
        $catId  = AssetCategory::where('key', 'internet')->value('id');

        $items = $catId
            ? AssetItem::where('team_id', $teamId)->where('category_id', $catId)->orderBy('name')->get()
            : collect();

        $costByItem = AssetCostLine::active()->where('team_id', $teamId)
            ->whereIn('asset_item_id', $items->pluck('id'))
            ->get(['asset_item_id', 'monthly_amount'])
            ->groupBy('asset_item_id')
            ->map(fn($g) => (float) $g->sum('monthly_amount'));

        return view('asset-manager::livewire.internet.index', [
            'items'        => $items,
            'costByItem'   => $costByItem,
            'totalMonthly' => round($costByItem->sum(), 2),
        ])->layout('platform::layouts.app');
    }
}
