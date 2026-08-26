<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-asset-manager-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Kostenpositionen', 'icon' => 'banknotes'],
        ]">
            <x-slot name="actions">
                {{-- Gruppiert | Liste. Bewusst serverseitig ueber $view im $queryString statt
                     <x-asset-manager-tabs> (das braucht einen Alpine-Eltern-Scope): der Zustand
                     soll deep-linkbar sein. Muster: costs/allocation.blade.php --}}
                <div class="inline-flex rounded-lg bg-[var(--am-bg)] border border-[color:var(--am-border)] p-0.5">
                    <button type="button" wire:click="setView('grouped')"
                            class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors {{ $view === 'grouped' ? 'bg-[var(--am-primary)] text-[var(--am-on-primary)] shadow-sm' : 'text-[var(--am-text-secondary)] hover:text-[var(--am-text)]' }}">
                        Gruppiert
                    </button>
                    <button type="button" wire:click="setView('flat')"
                            class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors {{ $view === 'flat' ? 'bg-[var(--am-primary)] text-[var(--am-on-primary)] shadow-sm' : 'text-[var(--am-text-secondary)] hover:text-[var(--am-text)]' }}">
                        Liste
                    </button>
                </div>

                {{-- Querverweise: die Seite stand bisher ohne einen einzigen Absprung zu Pivot,
                     Import oder Stammdaten da, obwohl das die Nachbarschritte derselben Arbeit sind. --}}
                <x-asset-manager-button variant="ghost" size="sm" :href="route('asset-manager.costs.allocation')" wire:navigate>
                    @svg('heroicon-o-table-cells', 'w-3.5 h-3.5')
                    Kostenaufteilung
                </x-asset-manager-button>
                <x-asset-manager-button variant="ghost" size="sm" :href="route('asset-manager.costs.import')" wire:navigate>
                    @svg('heroicon-o-arrow-up-tray', 'w-3.5 h-3.5')
                    Import
                </x-asset-manager-button>
                <x-asset-manager-button variant="ghost" size="sm" :href="route('asset-manager.master-data.index', ['bereich' => 'cost-types'])" wire:navigate>
                    @svg('heroicon-o-tag', 'w-3.5 h-3.5')
                    Stammdaten
                </x-asset-manager-button>

                <x-asset-manager-button variant="secondary" size="sm" wire:click="exportCsv">
                    @svg('heroicon-o-arrow-down-tray', 'w-3.5 h-3.5')
                    CSV
                </x-asset-manager-button>

                {{-- Anlegen nur Owner/Admin (E1/ADR 0004) — Backend: save() Gate asset-manager.manage. --}}
                @can('asset-manager.manage')
                    <x-asset-manager-button variant="primary" size="sm" wire:click="newLine">
                        @svg('heroicon-o-plus', 'w-3.5 h-3.5')
                        Neue Position
                    </x-asset-manager-button>
                @endcan
            </x-slot>
        </x-asset-manager-page-actionbar>
    </x-slot>

    {{-- LINKS: Filter --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Filter" icon="heroicon-o-funnel" width="w-72" :defaultOpen="true">
            @include('asset-manager::livewire.cost-lines.partials.filters')
        </x-ui-page-sidebar>
    </x-slot>

    {{-- HAUPT --}}
    <div class="flex-1 flex flex-col min-h-0 min-w-0">
        <div class="flex-1 overflow-y-auto p-6 space-y-4">

            @if($flash)
                <div class="flex items-center gap-3 px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200">
                    @svg('heroicon-o-check-circle', 'w-4 h-4 text-emerald-500 flex-shrink-0')
                    <p class="text-sm text-emerald-700 m-0">{{ $flash }}</p>
                    <button type="button" wire:click="$set('flash', null)" class="ml-auto text-emerald-600 hover:text-emerald-800">&times;</button>
                </div>
            @endif

            @include('asset-manager::livewire.cost-lines.partials.kpis')

            {{-- Schlanke Meta-Zeile: Treffer + Gruppierungsachse bzw. Seitengröße. --}}
            <div class="flex items-center justify-between gap-2 px-1">
                <div class="text-xs text-[var(--am-text-secondary)]">
                    {{ number_format($totalFiltered, 0, ',', '.') }} Positionen
                    @if($view === 'grouped')
                        in {{ $groups->count() }} {{ $groups->count() === 1 ? 'Gruppe' : 'Gruppen' }}
                    @endif
                </div>

                <div class="flex items-center gap-1.5 text-xs text-[var(--am-text-secondary)]">
                    @if($view === 'grouped')
                        <span>Gruppieren nach</span>
                        <select wire:model.live="groupBy" class="px-2 py-1 text-xs rounded-md bg-[var(--am-surface)] border border-[color:var(--am-border-strong)] text-[var(--am-text)] cursor-pointer focus:outline-none focus:border-[color:var(--am-accent)] focus:shadow-[var(--am-focus)]">
                            @foreach($axes as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    @else
                        <span>Pro Seite</span>
                        <select wire:model.live="perPage" class="px-2 py-1 text-xs rounded-md bg-[var(--am-surface)] border border-[color:var(--am-border-strong)] text-[var(--am-text)] cursor-pointer focus:outline-none focus:border-[color:var(--am-accent)] focus:shadow-[var(--am-focus)]">
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                    @endif
                </div>
            </div>

            @if($view === 'grouped')
                @include('asset-manager::livewire.cost-lines.partials.table-grouped')
            @else
                @include('asset-manager::livewire.cost-lines.partials.table-flat')
            @endif
        </div>
    </div>

    {{-- Editor als Modal (S8b) — Modals gehören innerhalb <x-ui-page>. --}}
    @can('asset-manager.manage')
        @include('asset-manager::livewire.cost-lines.partials.modal-editor')
    @endcan
</x-ui-page>
