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
    public string $filterNiederlassung = '';
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
        $this->reset(['search', 'filterNiederlassung']);
    }

    public function render()
    {
        $teamId = $this->teamId();
        $catId  = AssetCategory::where('key', 'drucker')->value('id');

        $all = $catId
            ? AssetItem::where('team_id', $teamId)->where('category_id', $catId)->orderBy('name')->get()
            : collect();

        // Cost-Lines je Drucker (mit Kostenstelle) → Aufschlüsselung, Summe, KSt-Code.
        // Die KSt liegt an der Cost-Line, NICHT am Asset (raw_data hat keine kostenstelle).
        $linesByItem = AssetCostLine::active()->validOn(now())->where('team_id', $teamId)
            ->whereIn('asset_item_id', $all->pluck('id'))
            ->with('costCenter')
            ->get()
            ->groupBy('asset_item_id');

        $costByItem = $linesByItem->map(fn ($g) => (float) $g->sum('monthly_amount'));
        $kstByItem  = $linesByItem->map(fn ($g) => $g->map(fn ($l) => $l->costCenter?->code)->filter()->unique()->implode(', '));

        // Distinct Niederlassungen für das Filter-Dropdown (aus der gesamten Kategorie).
        $niederlassungen = $all->map(fn ($i) => $i->raw_data['niederlassung'] ?? null)
            ->filter()->unique()->sort()->values();

        // Such- + Niederlassungs-Filter in PHP (raw_data ist JSON, kleine Liste).
        $items = $all
            ->when($this->search !== '', fn ($c) => $c->filter(function ($i) {
                $s = mb_strtolower($this->search);
                return str_contains(mb_strtolower((string) $i->name), $s)
                    || str_contains(mb_strtolower((string) $i->model), $s)
                    || str_contains(mb_strtolower((string) $i->serial_number), $s);
            }))
            ->when($this->filterNiederlassung !== '', fn ($c) => $c->filter(
                fn ($i) => ($i->raw_data['niederlassung'] ?? null) === $this->filterNiederlassung
            ))
            ->values();

        $selectedItem  = $this->selectedId ? AssetItem::where('team_id', $teamId)->find($this->selectedId) : null;
        $selectedLines = $selectedItem ? ($linesByItem[$selectedItem->id] ?? collect()) : collect();

        return view('asset-manager::livewire.printers.index', [
            'items'           => $items,
            'costByItem'      => $costByItem,
            'kstByItem'       => $kstByItem,
            'totalMonthly'    => round($items->sum(fn ($i) => $costByItem[$i->id] ?? 0), 2),
            'niederlassungen' => $niederlassungen,
            'selectedItem'    => $selectedItem,
            'selectedLines'   => $selectedLines,
        ])->layout('platform::layouts.app');
    }
}
