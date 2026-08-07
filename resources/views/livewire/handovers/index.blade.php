<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-asset-manager-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Geräteausgaben', 'icon' => 'clipboard-document-check'],
        ]">
            <x-slot name="actions">
                {{-- Anlegen nur Owner/Admin (ADR 0004) — Backend: save() Gate asset-manager.manage. --}}
                @can('asset-manager.manage')
                    <x-asset-manager-button variant="primary" size="md" wire:click="newHandover">
                        @svg('heroicon-o-plus', 'w-3.5 h-3.5')
                        Neue Ausgabe
                    </x-asset-manager-button>
                @endcan
            </x-slot>
        </x-asset-manager-page-actionbar>
    </x-slot>

    {{-- LINKS: Filter --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Filter" icon="heroicon-o-funnel" width="w-72" :defaultOpen="true">
            <div class="p-4 space-y-4 bg-[var(--am-bg)]">
                <x-asset-manager-filter-section title="Suche">
                    <x-asset-manager-input size="sm" type="text" wire:model.live.debounce.300ms="search"
                                           placeholder="Empfänger, Gerät, Seriennr.…" />
                </x-asset-manager-filter-section>

                <x-asset-manager-filter-section title="Status">
                    <x-asset-manager-select size="sm" wire:model.live="filterStatus">
                        <option value="">Alle</option>
                        <option value="open">Ausgegeben</option>
                        <option value="partially_returned">Teilweise zurück</option>
                        <option value="returned">Vollständig zurück</option>
                    </x-asset-manager-select>
                </x-asset-manager-filter-section>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- Editor als Modal (S8b): stand vorher in einer zugeklappten rechten Sidebar. --}}
    @can('asset-manager.manage')
        @include('asset-manager::livewire.handovers.partials.modal-editor')
    @endcan

    {{-- HAUPT --}}
    <div class="flex-1 flex flex-col min-h-0 min-w-0">
        <div class="flex-1 overflow-y-auto p-6 space-y-4">

            @if($flash)
                <div class="px-4 py-2 text-xs font-medium text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-lg">{{ $flash }}</div>
            @endif

            <div class="flex items-center gap-2">
                @svg('heroicon-o-clipboard-document-check', 'w-5 h-5 text-[var(--am-text-secondary)]')
                <h2 class="text-lg font-semibold text-[var(--am-text)] m-0">Geräteausgaben</h2>
            </div>

            <div class="rounded-xl bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm overflow-hidden">
                @if($handovers->isEmpty())
                    <div class="p-8 text-center text-sm text-[var(--am-text-secondary)]">Noch keine Ausgaben erfasst.</div>
                @else
                    <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-[var(--am-bg)]">
                                <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">Empfänger</th>
                                <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">Geräte</th>
                                <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">Datum</th>
                                <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">Unterschrift</th>
                                <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">Status</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[color:var(--am-border)]">
                            @foreach($handovers as $h)
                                <tr wire:key="ho-{{ $h->id }}" wire:click="edit({{ $h->id }})"
                                    class="cursor-pointer transition-colors hover:bg-[var(--am-bg)]">
                                    <td class="px-4 py-2.5 font-medium text-[var(--am-text)]">{{ $h->holder?->display_name ?: ($h->holder?->user_principal_name ?? '—') }}</td>
                                    <td class="px-4 py-2.5 text-xs text-[var(--am-text-secondary)]">
                                        @php $names = $h->lines->map(fn($l) => $l->deviceName()); @endphp
                                        {{ $names->take(2)->implode(', ') }}@if($names->count() > 2) +{{ $names->count() - 2 }}@endif
                                        <span class="text-[var(--am-text-muted)]">({{ $h->lines->count() }})</span>
                                    </td>
                                    <td class="px-4 py-2.5 text-xs text-[var(--am-text-secondary)]">{{ $h->issued_at?->format('d.m.Y') ?? '—' }}</td>
                                    <td class="px-4 py-2.5">
                                        @if($h->isSigned())
                                            <x-asset-manager-badge color="emerald" size="xs" :pill="false" icon="heroicon-o-check-badge">unterschrieben</x-asset-manager-badge>
                                        @else
                                            <x-asset-manager-badge color="amber" size="xs" :pill="false">nicht unterschrieben</x-asset-manager-badge>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5">
                                        <x-asset-manager-badge :color="$h->statusBadgeColor()" size="xs" :pill="false">{{ $h->statusLabel() }}</x-asset-manager-badge>
                                    </td>
                                    <td class="px-4 py-2.5 text-right whitespace-nowrap">
                                        <a href="{{ route('asset-manager.handovers.pdf', $h->id) }}" target="_blank" wire:click.stop
                                           class="text-[var(--am-text-secondary)] hover:text-[var(--am-accent)]" title="Protokoll-PDF">@svg('heroicon-o-document-arrow-down', 'w-4 h-4 inline')</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    </div>
                @endif
            </div>

            <div>{{ $handovers->links() }}</div>
        </div>
    </div>
</x-ui-page>
