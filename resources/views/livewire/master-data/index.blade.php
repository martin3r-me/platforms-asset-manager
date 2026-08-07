@php
    // „Gesellschaften“ ist kein eigener Bereich mehr — sie sind die obersten Knoten des
    // Kostenstellen-Baums (docs/adr/0016).
    $areas = [
        'cost-centers' => ['label' => 'Kostenstellen',  'icon' => 'heroicon-o-clipboard-document-list', 'singular' => 'Kostenstelle'],
        'cost-types'   => ['label' => 'Kostenarten',    'icon' => 'heroicon-o-tag',                     'singular' => 'Kostenart'],
        'vendors'      => ['label' => 'Kreditoren',     'icon' => 'heroicon-o-building-storefront',      'singular' => 'Kreditor'],
    ];
    $current = $areas[$active] ?? $areas['cost-centers'];

    // Computed-Properties hier (Komponenten-Scope) auflösen, damit sie auch in den
    // <x-slot>-Inhalten verfügbar sind ($this-> ist im Slot-Scope nicht verlässlich).
    $counts           = $this->counts;
    $parentOptions    = $this->parentOptions;
    $rootOptions      = $this->rootOptions;
    $vendorsForSelect = $this->vendorsForSelect;
@endphp

<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-asset-manager-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Stammdaten', 'icon' => 'rectangle-stack'],
            ['label' => $current['label']],
        ]">
            <x-slot name="actions">
                {{-- Anlegen nur Owner/Admin (E1/ADR 0004) — Backend: save() Gate asset-manager.manage. --}}
                @can('asset-manager.manage')
                    <x-asset-manager-button variant="primary" size="sm" wire:click="startCreate">
                        @svg('heroicon-o-plus', 'w-3.5 h-3.5')
                        Neu
                    </x-asset-manager-button>
                @endcan
            </x-slot>
        </x-asset-manager-page-actionbar>
    </x-slot>

    {{-- LINKS: Bereichs-Navigation + Filter --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Bereiche" icon="heroicon-o-rectangle-stack" width="w-72" :defaultOpen="true">
            <div class="p-4 space-y-4 bg-[var(--am-bg)]">
                @include('asset-manager::livewire.master-data.partials.nav', ['areas' => $areas, 'counts' => $counts])
                @include('asset-manager::livewire.master-data.partials.filters', ['areas' => $areas, 'roots' => $rootOptions])
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- Editor als Modal (S8b): Bearbeiten/Anlegen nur Owner/Admin (ADR 0004) — Backend: save()/delete() Gate. --}}
    @can('asset-manager.manage')
        @include('asset-manager::livewire.master-data.partials.modal-editor')
    @endcan

    {{-- HAUPT: read-only Liste des aktiven Bereichs --}}
    <div class="flex-1 flex flex-col min-h-0 min-w-0" wire:key="area-{{ $active }}">
        <div class="flex-1 overflow-y-auto p-6 space-y-4">

            <div class="flex items-center gap-2">
                @svg($current['icon'], 'w-5 h-5 text-[var(--am-text-secondary)]')
                <h2 class="text-lg font-semibold text-[var(--am-text)] m-0">{{ $current['label'] }}</h2>
                <span class="text-xs text-[var(--am-text-secondary)] bg-[var(--am-bg)] rounded-full px-2 py-0.5">{{ $counts[$active] ?? 0 }}</span>
                <span class="flex-1"></span>
                @can('asset-manager.manage')
                    <x-asset-manager-button variant="primary" size="sm" wire:click="startCreate">
                        @svg('heroicon-o-plus', 'w-3.5 h-3.5')
                        {{ $current['singular'] }}
                    </x-asset-manager-button>
                @endcan
            </div>

            @if($flash)
                <div class="px-4 py-2 text-xs font-medium text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-lg">{{ $flash }}</div>
            @endif

            <div class="rounded-xl bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm overflow-hidden">
                @switch($active)
                    @case('cost-centers')
                        @include('asset-manager::livewire.master-data.partials.table-cost-centers', ['rows' => $this->costCenters])
                        @break
                    @case('cost-types')
                        @include('asset-manager::livewire.master-data.partials.table-cost-types', ['rows' => $this->costTypes, 'manualOrder' => $this->manualOrder, 'total' => $counts['cost-types']])
                        @break
                    @case('vendors')
                        @include('asset-manager::livewire.master-data.partials.table-vendors', ['rows' => $this->vendors])
                        @break
                @endswitch
            </div>
        </div>
    </div>
</x-ui-page>
