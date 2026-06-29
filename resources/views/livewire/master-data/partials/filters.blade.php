{{-- Suche + bereichsspezifische Filter. Vars: $areas, $companies, $active, $search, $filterCompany, $onlyActive, $filterSource --}}
<x-asset-manager-filter-section title="Suche">
    <x-asset-manager-input size="sm" type="text" wire:model.live.debounce.300ms="search" placeholder="Name suchen..." class="w-full" />
</x-asset-manager-filter-section>

@switch($active)
    @case('cost-centers')
        <x-asset-manager-filter-section title="Gesellschaft">
            <div class="space-y-2">
                <x-asset-manager-select size="sm" wire:model.live="filterCompany" class="w-full">
                    <option value="">Alle</option>
                    @foreach($companies as $co)<option value="{{ $co->id }}">{{ $co->name }}</option>@endforeach
                </x-asset-manager-select>
                <label class="flex items-center gap-2 text-[11px] text-[var(--am-text-secondary)]">
                    <input type="checkbox" wire:model.live="onlyActive" class="rounded"> Nur aktive
                </label>
            </div>
        </x-asset-manager-filter-section>
        @break

    @case('cost-types')
        <x-asset-manager-filter-section title="Quelle">
            <x-asset-manager-select size="sm" wire:model.live="filterSource" class="w-full">
                <option value="">Alle</option>
                <option value="cost_line">Kostenposition</option>
                <option value="hardware_afa">Hardware-AfA</option>
                <option value="ms_license">MS-Lizenz (Graph)</option>
                <option value="asset_device">Geräte-Kosten</option>
            </x-asset-manager-select>
        </x-asset-manager-filter-section>
        @break
@endswitch

@if($search || $filterCompany || $onlyActive || $filterSource)
    <x-asset-manager-button variant="danger" size="sm"
            wire:click="$set('search', ''); $set('filterCompany', null); $set('onlyActive', false); $set('filterSource', '')"
            class="w-full">
        @svg('heroicon-o-x-circle', 'w-3.5 h-3.5 inline -mt-0.5 mr-1')
        Filter zurücksetzen
    </x-asset-manager-button>
@endif
