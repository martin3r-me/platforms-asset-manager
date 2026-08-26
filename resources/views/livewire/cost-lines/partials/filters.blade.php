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

    <x-asset-manager-filter-section title="Asset-Träger">
        <x-asset-manager-select size="sm" wire:model.live="filterHolder">
            <option value="">Alle</option>
            <option value="with">Mit Träger</option>
            <option value="without">Ohne Träger</option>
        </x-asset-manager-select>
    </x-asset-manager-filter-section>

    <x-asset-manager-filter-section title="Frequenz">
        <x-asset-manager-select size="sm" wire:model.live="filterFreq">
            <option value="">Alle</option>
            <option value="monthly">Monatlich</option>
            <option value="quarterly">Quartal</option>
            <option value="yearly">Jährlich</option>
            <option value="once">Einmalig</option>
        </x-asset-manager-select>
    </x-asset-manager-filter-section>

    <x-asset-manager-filter-section title="Gültigkeit">
        <x-asset-manager-select size="sm" wire:model.live="filterValidity">
            <option value="">Alle</option>
            <option value="current">Aktuell gültig</option>
            <option value="future">Erst zukünftig</option>
            <option value="expired">Abgelaufen</option>
            <option value="unlimited">Unbefristet</option>
        </x-asset-manager-select>
    </x-asset-manager-filter-section>

    <x-asset-manager-filter-section title="Herkunft">
        <x-asset-manager-select size="sm" wire:model.live="filterSource">
            <option value="">Alle</option>
            <option value="manual">Manuell erfasst</option>
            <option value="excel_import">Excel-Import</option>
            <option value="graph">Graph-Sync</option>
        </x-asset-manager-select>
    </x-asset-manager-filter-section>

    <x-asset-manager-filter-section title="Status">
        <x-asset-manager-select size="sm" wire:model.live="filterActive">
            <option value="">Alle</option>
            <option value="1">Aktiv</option>
            <option value="0">Inaktiv</option>
        </x-asset-manager-select>
    </x-asset-manager-filter-section>

    <x-asset-manager-filter-section title="Prüfung">
        <label class="flex items-start gap-2 text-xs text-[var(--am-text-secondary)] cursor-pointer">
            <input type="checkbox" value="1" wire:model.live="filterFlagged"
                   class="mt-0.5 rounded border-[color:var(--am-border-strong)] text-[var(--am-accent)]" />
            <span>Nur auffällige Positionen</span>
        </label>
    </x-asset-manager-filter-section>

    {{-- Bezugsgröße angleichen: die Kostenaufteilung zählt nur aktive, heute gültige Zeilen
         (CostAggregationService::normalizedLines). Ohne dieses Preset zeigt die Seite eine andere
         Summe als /costs, ohne dass man es merkt. --}}
    <x-asset-manager-filter-section title="Abgleich">
        @if($isReconciled)
            <p class="flex items-start gap-1.5 text-[11px] text-emerald-700 m-0">
                @svg('heroicon-o-check-circle', 'w-3.5 h-3.5 flex-shrink-0 mt-px')
                Basis entspricht der Kostenaufteilung.
            </p>
        @else
            <x-asset-manager-button variant="secondary" size="sm" class="w-full" wire:click="applyReconcilePreset">
                @svg('heroicon-o-scale', 'w-3.5 h-3.5')
                Basis wie Kostenaufteilung
            </x-asset-manager-button>
        @endif
    </x-asset-manager-filter-section>

    @if($hasFilters)
        <x-asset-manager-button variant="ghost" size="sm" class="w-full" wire:click="resetFilters">
            @svg('heroicon-o-x-circle', 'w-3.5 h-3.5')
            Filter zurücksetzen
        </x-asset-manager-button>
    @endif
</div>
