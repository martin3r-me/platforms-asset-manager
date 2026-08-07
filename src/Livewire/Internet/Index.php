<?php

namespace Platform\AssetManager\Livewire\Internet;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\AssetManager\Concerns\ResolvesCurrentTeam;
use Platform\AssetManager\Models\AssetCategory;
use Platform\AssetManager\Models\AssetCostLine;
use Platform\AssetManager\Models\AssetItem;

class Index extends Component
{
    use ResolvesCurrentTeam;

    public string $search = '';
    public string $filterProvider = '';

    public function resetFilters(): void
    {
        $this->reset(['search', 'filterProvider']);
    }

    public function render()
    {
        $teamId = $this->teamId();
        $catId  = AssetCategory::where('key', 'internet')->value('id');

        $all = $catId
            ? AssetItem::where('team_id', $teamId)->where('category_id', $catId)->orderBy('name')->get()
            : collect();

        // Cost-Lines je Anschluss (mit Kostenstelle) → Aufschlüsselung, Summe, KSt-Code.
        $linesByItem = AssetCostLine::active()->validOn(now())->where('team_id', $teamId)
            ->whereIn('asset_item_id', $all->pluck('id'))
            ->with('costCenter')
            ->get()
            ->groupBy('asset_item_id');

        $costByItem = $linesByItem->map(fn ($g) => (float) $g->sum('monthly_amount'));
        // Die Kostenstelle hängt an der Cost-Line, nicht am Asset — als Spalte in der Liste ausgewiesen,
        // seit das rechte Vorschau-Panel entfallen ist (S8b).
        $kstByItem  = $linesByItem->map(fn ($g) => $g->map(fn ($l) => $l->costCenter?->code)->filter()->unique()->implode(', '));

        // Distinct Anbieter für das Filter-Dropdown.
        $providers = $all->map(fn ($i) => $i->raw_data['anbieter'] ?? null)
            ->filter()->unique()->sort()->values();

        // Such- + Anbieter-Filter in PHP (raw_data ist JSON, kleine Liste).
        $items = $all
            ->when($this->search !== '', fn ($c) => $c->filter(function ($i) {
                $s  = mb_strtolower($this->search);
                $rd = $i->raw_data ?? [];
                return str_contains(mb_strtolower((string) $i->name), $s)
                    || str_contains(mb_strtolower((string) ($rd['standort'] ?? '')), $s)
                    || str_contains(mb_strtolower((string) ($rd['anbieter'] ?? '')), $s);
            }))
            ->when($this->filterProvider !== '', fn ($c) => $c->filter(
                fn ($i) => ($i->raw_data['anbieter'] ?? null) === $this->filterProvider
            ))
            ->values();

        return view('asset-manager::livewire.internet.index', [
            'items'        => $items,
            'costByItem'   => $costByItem,
            'kstByItem'    => $kstByItem,
            'totalMonthly' => round($items->sum(fn ($i) => $costByItem[$i->id] ?? 0), 2),
            'providers'    => $providers,
        ])->layout('platform::layouts.app');
    }
}
