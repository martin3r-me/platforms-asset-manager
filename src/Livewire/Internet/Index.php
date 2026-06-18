<?php

namespace Platform\AssetManager\Livewire\Internet;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\AssetManager\Concerns\ScopesToTenant;
use Platform\AssetManager\Models\AssetCategory;
use Platform\AssetManager\Models\AssetCostLine;
use Platform\AssetManager\Models\AssetItem;

class Index extends Component
{
    use ScopesToTenant;

    public string $search = '';
    public string $filterProvider = '';
    public ?int   $selectedId = null;

    protected function teamId(): int
    {
        return Auth::user()->currentTeam->id;
    }

    public function selectItem(int $id): void
    {
        $this->selectedId = $id;
        $this->dispatch('open-activity');
    }

    public function clearSelection(): void
    {
        $this->selectedId = null;
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'filterProvider']);
    }

    public function render()
    {
        $teamId = $this->teamId();
        $catId  = AssetCategory::where('key', 'internet')->value('id');

        $all = $catId
            ? AssetItem::where('team_id', $teamId)->forTenant($this->selectedTenantId)->where('category_id', $catId)->orderBy('name')->get()
            : collect();

        // Cost-Lines je Anschluss (mit Kostenstelle) → Aufschlüsselung, Summe, KSt-Code.
        $linesByItem = AssetCostLine::active()->validOn(now())->where('team_id', $teamId)
            ->whereIn('asset_item_id', $all->pluck('id'))
            ->with('costCenter')
            ->get()
            ->groupBy('asset_item_id');

        $costByItem = $linesByItem->map(fn ($g) => (float) $g->sum('monthly_amount'));

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

        $selectedItem  = $this->selectedId ? AssetItem::where('team_id', $teamId)->forTenant($this->selectedTenantId)->find($this->selectedId) : null;
        $selectedLines = $selectedItem ? ($linesByItem[$selectedItem->id] ?? collect()) : collect();

        return view('asset-manager::livewire.internet.index', [
            'items'         => $items,
            'costByItem'    => $costByItem,
            'totalMonthly'  => round($items->sum(fn ($i) => $costByItem[$i->id] ?? 0), 2),
            'providers'     => $providers,
            'selectedItem'  => $selectedItem,
            'selectedLines' => $selectedLines,
        ])->layout('platform::layouts.app');
    }
}
