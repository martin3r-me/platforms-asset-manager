@php
    $areas = [
        'companies'    => ['label' => 'Gesellschaften', 'icon' => 'heroicon-o-building-office-2',      'singular' => 'Gesellschaft'],
        'cost-centers' => ['label' => 'Kostenstellen',  'icon' => 'heroicon-o-clipboard-document-list', 'singular' => 'Kostenstelle'],
        'cost-types'   => ['label' => 'Kostenarten',    'icon' => 'heroicon-o-tag',                     'singular' => 'Kostenart'],
        'vendors'      => ['label' => 'Kreditoren',     'icon' => 'heroicon-o-building-storefront',      'singular' => 'Kreditor'],
    ];
    $current = $areas[$active] ?? $areas['companies'];

    // Computed-Properties hier (Komponenten-Scope) auflösen, damit sie auch in den
    // <x-slot>-Inhalten verfügbar sind ($this-> ist im Slot-Scope nicht verlässlich).
    $counts             = $this->counts;
    $companiesForSelect = $this->companiesForSelect;
    $vendorsForSelect   = $this->vendorsForSelect;
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
                <button wire:click="startCreate"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-white bg-violet-600 rounded-lg hover:bg-violet-700 transition-all">
                    @svg('heroicon-o-plus', 'w-3.5 h-3.5')
                    Neu
                </button>
            </x-slot>
        </x-asset-manager-page-actionbar>
    </x-slot>

    {{-- LINKS: Bereichs-Navigation + Filter --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Bereiche" icon="heroicon-o-rectangle-stack" width="w-72" :defaultOpen="true">
            <div class="p-4 space-y-4 bg-[var(--ui-muted-5)]">
                @include('asset-manager::livewire.master-data.partials.nav', ['areas' => $areas, 'counts' => $counts])
                @include('asset-manager::livewire.master-data.partials.filters', ['areas' => $areas, 'companies' => $companiesForSelect])
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- RECHTS: Bearbeiten / Anlegen --}}
    <x-slot name="activity">
        <x-ui-page-sidebar
            :title="($creating ? 'Neu: ' : '') . $current['singular']"
            icon="heroicon-o-pencil-square" width="w-80" :defaultOpen="false" storeKey="activityOpen" side="right">
            <div class="p-4 space-y-3 bg-[var(--ui-muted-5)]">
                @if($creating || $selectedId)
                    <div class="flex items-center justify-between pb-2 border-b border-[var(--ui-border)]/30">
                        <span class="text-[10px] uppercase tracking-wider text-[var(--ui-muted)]">
                            {{ $creating ? 'Neu anlegen' : 'Bearbeiten' }}
                        </span>
                        <button wire:click="cancelEdit" class="text-[10px] text-[var(--ui-muted)] hover:text-red-500">
                            @svg('heroicon-o-x-mark', 'w-3 h-3 inline -mt-0.5')
                            Schließen
                        </button>
                    </div>

                    @switch($active)
                        @case('companies')
                            @include('asset-manager::livewire.master-data.partials.form-companies')
                            @break
                        @case('cost-centers')
                            @include('asset-manager::livewire.master-data.partials.form-cost-centers', ['companies' => $companiesForSelect])
                            @break
                        @case('cost-types')
                            @include('asset-manager::livewire.master-data.partials.form-cost-types', ['vendors' => $vendorsForSelect])
                            @break
                        @case('vendors')
                            @include('asset-manager::livewire.master-data.partials.form-vendors')
                            @break
                    @endswitch
                @else
                    <div class="flex flex-col items-center justify-center py-10 text-center">
                        @svg('heroicon-o-cursor-arrow-rays', 'w-8 h-8 text-gray-300 mb-3')
                        <p class="text-[11px] text-[var(--ui-muted)]">Eine Zeile anklicken zum Bearbeiten — oder oben „Neu“ für einen neuen Eintrag.</p>
                    </div>
                @endif
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- Öffnet das rechte Panel, sobald eine Zeile gewählt oder „Neu“ geklickt wird. --}}
    <div x-data x-on:open-activity.window="$store.ui && $store.ui.mSet('activity', 'open', true)"></div>

    {{-- HAUPT: read-only Liste des aktiven Bereichs --}}
    <div class="flex-1 flex flex-col min-h-0 min-w-0" wire:key="area-{{ $active }}">
        <div class="flex-1 overflow-y-auto p-6 space-y-4">

            <div class="flex items-center gap-2">
                @svg($current['icon'], 'w-5 h-5 text-[var(--ui-secondary)]')
                <h2 class="text-lg font-semibold text-[var(--ui-secondary)] m-0">{{ $current['label'] }}</h2>
                <span class="text-xs text-[var(--ui-secondary)] bg-[var(--ui-muted-10)] rounded-full px-2 py-0.5">{{ $counts[$active] ?? 0 }}</span>
                <span class="flex-1"></span>
                <button wire:click="startCreate"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-white bg-violet-600 rounded-lg hover:bg-violet-700">
                    @svg('heroicon-o-plus', 'w-3.5 h-3.5')
                    {{ $current['singular'] }}
                </button>
            </div>

            @if($flash)
                <div class="px-4 py-2 text-xs font-medium text-emerald-700 bg-emerald-500/10 border border-emerald-500/20 rounded-lg">{{ $flash }}</div>
            @endif

            <div class="rounded-xl bg-white/70 backdrop-blur-sm border border-white/40 shadow-[0_1px_3px_rgba(0,0,0,0.04),0_1px_2px_rgba(0,0,0,0.03)] overflow-hidden">
                @switch($active)
                    @case('companies')
                        @include('asset-manager::livewire.master-data.partials.table-companies', ['rows' => $this->companies])
                        @break
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
