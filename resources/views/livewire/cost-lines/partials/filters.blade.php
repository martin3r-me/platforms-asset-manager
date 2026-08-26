{{--
    Filter der Kostenpositionen-Liste. Lag bis zum Redesign inline in index.blade.php.

    Kostenstellen kommen als flache, baum-sortierte Liste (AssetCostCenter::treeFor) mit
    `tree_label` — vorher stand hier nur `$c->code`, und weil `name` bei den numerischen Knoten
    NULL ist, las man "gf-gl, 1000, 1900, verwaltung, 1500 …" ohne jede Zugehörigkeit.
--}}
<div class="p-4 space-y-4 bg-[var(--am-bg)]">
    <x-asset-manager-filter-section title="Suche">
        <x-asset-manager-input size="sm" type="text" wire:model.live.debounce.300ms="search" placeholder="Bezeichnung…" />
    </x-asset-manager-filter-section>

    <x-asset-manager-filter-section title="Kostenart">
        <x-asset-manager-select size="sm" wire:model.live="filterType">
            <option value="">Alle</option>
            @foreach($costTypes as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
        </x-asset-manager-select>
    </x-asset-manager-filter-section>

    <x-asset-manager-filter-section title="Kostenstelle">
        <x-asset-manager-select size="sm" wire:model.live="filterCenter">
            <option value="">Alle</option>
            @foreach($costCenters as $c)<option value="{{ $c->id }}">{{ $c->tree_label }}</option>@endforeach
        </x-asset-manager-select>
    </x-asset-manager-filter-section>

    <x-asset-manager-filter-section title="Kreditor">
        <x-asset-manager-select size="sm" wire:model.live="filterVendor">
            <option value="">Alle</option>
            @foreach($vendors as $v)<option value="{{ $v->id }}">{{ $v->name }}</option>@endforeach
        </x-asset-manager-select>
    </x-asset-manager-filter-section>

    <x-asset-manager-filter-section title="Status">
        <x-asset-manager-select size="sm" wire:model.live="filterActive">
            <option value="">Alle</option>
            <option value="1">Aktiv</option>
            <option value="0">Inaktiv</option>
        </x-asset-manager-select>
    </x-asset-manager-filter-section>

    @if($hasFilters)
        <x-asset-manager-button variant="ghost" size="sm" class="w-full" wire:click="resetFilters">
            @svg('heroicon-o-x-circle', 'w-3.5 h-3.5')
            Filter zurücksetzen
        </x-asset-manager-button>
    @endif
</div>
