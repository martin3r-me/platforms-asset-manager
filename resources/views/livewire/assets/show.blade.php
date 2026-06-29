<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Assets', 'href' => route('asset-manager.assets.index'), 'icon' => 'cube-transparent'],
            ['label' => $item->name],
        ]" />
    </x-slot>

    {{-- LINKS: Eigenschaften (editierbar) --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Eigenschaften" icon="heroicon-o-adjustments-horizontal" width="w-72" :defaultOpen="true">
            {{-- Bearbeiten nur Owner/Admin (E1/ADR 0004) — Backend: save()/delete() Gate. Member sehen
                 die Werte read-only im Haupt-Content. --}}
            @can('asset-manager.manage')
            <form wire:submit="save" class="p-4 space-y-4 bg-[var(--am-bg)]">

                @if($item->source === 'intune')
                    <div class="rounded-lg bg-violet-50 border border-violet-200 p-2 text-[11px] text-violet-700">
                        @svg('heroicon-o-cloud', 'w-3.5 h-3.5 inline -mt-0.5 mr-1')
                        Intune-synced — Gerätedaten read-only, Zuweisung und Kosten editierbar.
                    </div>
                @endif

                {{-- Kategorie & Status --}}
                <x-asset-manager-filter-section title="Klassifikation">
                    <div class="space-y-2">
                        <div>
                            <label class="block text-[10px] text-[var(--am-text-muted)] mb-1">Kategorie</label>
                            <x-asset-manager-select size="sm" wire:model="categoryId" :disabled="$item->source === 'intune'">
                                @foreach($categories as $cat)
                                    <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                                @endforeach
                            </x-asset-manager-select>
                        </div>
                        <div>
                            <label class="block text-[10px] text-[var(--am-text-muted)] mb-1">Status</label>
                            <x-asset-manager-select size="sm" wire:model="status">
                                <option value="in_stock">Lager</option>
                                <option value="assigned">Zugewiesen</option>
                                <option value="retired">Ausgemustert</option>
                                <option value="lost">Verloren</option>
                            </x-asset-manager-select>
                        </div>
                    </div>
                </x-asset-manager-filter-section>

                {{-- Hardware --}}
                <x-asset-manager-filter-section title="Gerät">
                    <div class="space-y-2">
                        <div>
                            <label class="block text-[10px] text-[var(--am-text-muted)] mb-1">Name</label>
                            <x-asset-manager-input size="sm" type="text" wire:model="name" :disabled="$item->source === 'intune'" />
                        </div>
                        <div>
                            <label class="block text-[10px] text-[var(--am-text-muted)] mb-1">Hersteller</label>
                            <x-asset-manager-input size="sm" type="text" wire:model="manufacturer" :disabled="$item->source === 'intune'" />
                        </div>
                        <div>
                            <label class="block text-[10px] text-[var(--am-text-muted)] mb-1">Modell</label>
                            <x-asset-manager-input size="sm" type="text" wire:model="model" :disabled="$item->source === 'intune'" />
                        </div>
                        <div>
                            <label class="block text-[10px] text-[var(--am-text-muted)] mb-1">Seriennummer</label>
                            <x-asset-manager-input size="sm" type="text" wire:model="serialNumber" :disabled="$item->source === 'intune'" />
                        </div>
                    </div>
                </x-asset-manager-filter-section>

                {{-- Zuweisung --}}
                <x-asset-manager-filter-section title="Zuweisung">
                    <x-asset-manager-select size="sm" wire:model="assigneeId">
                        <option value="">– Niemand (Lager) –</option>
                        @foreach($employees as $emp)
                            <option value="{{ $emp->id }}">{{ $emp->name }}</option>
                        @endforeach
                    </x-asset-manager-select>
                    @if($item->assignee)
                        <a href="{{ route('asset-manager.employees.show', $item->assignee) }}" wire:navigate class="block mt-2 text-[10px] text-[var(--am-accent)] hover:underline">
                            → Mitarbeiter-Profil ansehen
                        </a>
                    @endif
                </x-asset-manager-filter-section>

                {{-- Kosten --}}
                <x-asset-manager-filter-section title="Kosten">
                    <div class="space-y-2">
                        <div>
                            <label class="block text-[10px] text-[var(--am-text-muted)] mb-1">Kaufdatum</label>
                            <x-asset-manager-input size="sm" type="date" wire:model="purchaseDate" />
                        </div>
                        <div>
                            <label class="block text-[10px] text-[var(--am-text-muted)] mb-1">Kaufpreis (€)</label>
                            <x-asset-manager-input size="sm" type="number" step="0.01" min="0" wire:model="purchasePrice" />
                        </div>
                        <div>
                            <label class="block text-[10px] text-[var(--am-text-muted)] mb-1">AfA (Monate)</label>
                            <x-asset-manager-input size="sm" type="number" min="1" max="240" wire:model="depreciationMonths" />
                        </div>
                        @if($item->monthlyCost() > 0)
                            <div class="text-[11px] text-[var(--am-text-secondary)] pt-1 border-t border-[color:var(--am-border)] flex justify-between">
                                <span>Monatlich</span>
                                <span class="font-semibold text-[var(--am-text)]">{{ number_format($item->monthlyCost(), 2, ',', '.') }} €</span>
                            </div>
                        @endif
                    </div>
                </x-asset-manager-filter-section>

                {{-- Notes --}}
                <x-asset-manager-filter-section title="Notizen">
                    <x-asset-manager-textarea wire:model="notes" rows="3" />
                </x-asset-manager-filter-section>

                <div class="space-y-2 pt-2">
                    <x-asset-manager-button type="submit" variant="primary" size="md" class="w-full">
                        @svg('heroicon-o-check', 'w-3.5 h-3.5')
                        Speichern
                    </x-asset-manager-button>
                    @if($saved)
                        <div class="text-[10px] text-emerald-700 text-center">Gespeichert.</div>
                    @endif
                    @can('delete', $item)
                        <x-asset-manager-button type="button" variant="danger" size="md" class="w-full"
                                     wire:click="delete" wire:confirm="Asset wirklich löschen?">
                            @svg('heroicon-o-trash', 'w-3.5 h-3.5')
                            Löschen
                        </x-asset-manager-button>
                    @endcan
                </div>
            </form>
            @endcan
        </x-ui-page-sidebar>
    </x-slot>

    {{-- RECHTS: Zuweisungs-Historie --}}
    <x-slot name="activity">
        <x-ui-page-sidebar title="Historie" icon="heroicon-o-clock" width="w-80" :defaultOpen="false" storeKey="activityOpen" side="right">
            <div class="p-4 space-y-3 bg-[var(--am-bg)]">
                <div class="text-[10px] font-semibold uppercase tracking-wider text-[var(--am-text-muted)] px-1">Zuweisungs-Historie</div>
                @forelse($activities as $a)
                    <div class="p-3 rounded-lg bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm">
                        <div class="flex items-start justify-between gap-2 mb-1.5">
                            <a href="{{ $a->employee ? route('asset-manager.employees.show', $a->employee) : '#' }}" @if($a->employee) wire:navigate @endif
                                class="text-[12px] font-medium text-[var(--am-text-secondary)] hover:text-[var(--am-accent)]">
                                {{ $a->employee?->name ?? '— gelöscht —' }}
                            </a>
                            @if($a->isOpen())
                                <x-asset-manager-badge color="emerald" size="xs">aktuell</x-asset-manager-badge>
                            @endif
                        </div>
                        <div class="text-[10px] text-[var(--am-text-muted)]">
                            {{ $a->assigned_at->format('d.m.Y') }}
                            @if($a->returned_at) — {{ $a->returned_at->format('d.m.Y') }} @endif
                        </div>
                    </div>
                @empty
                    <div class="p-3 text-center text-[11px] text-[var(--am-text-muted)]">Noch keine Historie.</div>
                @endforelse
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- HAUPT-CONTENT --}}
    <div class="flex-1 flex flex-col min-h-0 min-w-0">
        <div class="flex-1 overflow-y-auto p-6">
            <div class="max-w-3xl mx-auto space-y-5">
                <div class="rounded-xl bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm p-5">
                    <div class="flex items-start gap-4">
                        <div class="flex-shrink-0 w-12 h-12 rounded-xl bg-[var(--am-accent-surface)] flex items-center justify-center">
                            @if($item->category?->icon) @svg($item->category->icon, 'w-6 h-6 text-[var(--am-accent)]') @else @svg('heroicon-o-cube', 'w-6 h-6 text-[var(--am-accent)]') @endif
                        </div>
                        <div class="flex-1 min-w-0">
                            <h1 class="text-lg font-semibold text-[var(--am-text)] truncate">{{ $item->name }}</h1>
                            @if($item->manufacturer || $item->model)
                                <p class="text-sm text-[var(--am-text-secondary)]">{{ trim($item->manufacturer . ' ' . $item->model) }}</p>
                            @endif
                        </div>
                        <span class="flex-shrink-0">
                            <x-asset-manager-badge :color="$item->statusBadgeColor()" dot size="md">{{ $item->statusLabel() }}</x-asset-manager-badge>
                        </span>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div class="rounded-lg bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm p-4">
                        <div class="text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Kategorie</div>
                        <div class="text-sm font-medium text-[var(--am-text-secondary)]">{{ $item->category?->name ?? '—' }}</div>
                    </div>
                    <div class="rounded-lg bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm p-4">
                        <div class="text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Zugewiesen an</div>
                        <div class="text-sm font-medium text-[var(--am-text-secondary)] truncate">{{ $item->assignee?->name ?? '—' }}</div>
                    </div>
                    <div class="rounded-lg bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm p-4">
                        <div class="text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Monatliche Kosten</div>
                        <div class="text-sm font-medium text-[var(--am-text-secondary)]">
                            @if($item->monthlyCost() > 0)
                                {{ number_format($item->monthlyCost(), 2, ',', '.') }} €
                            @else
                                <span class="text-[var(--am-text-muted)]">—</span>
                            @endif
                        </div>
                    </div>
                </div>

                @if($item->notes)
                    <div class="rounded-xl bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm p-5">
                        <div class="text-xs font-medium uppercase tracking-wider text-[var(--am-text-muted)] mb-2">Notizen</div>
                        <div class="text-sm text-[var(--am-text-secondary)] whitespace-pre-wrap">{{ $item->notes }}</div>
                    </div>
                @endif
            </div>
        </div>

        {{-- BOTTOM PANEL --}}
        @if($item->raw_data)
            <div class="shrink-0 border-t border-[color:var(--am-border)] bg-[var(--am-bg)]" x-data="{ open: false }">
                <button type="button" @click="open = !open" class="w-full p-2 text-center flex items-center justify-center gap-2 hover:bg-[var(--am-bg)] text-[11px] uppercase tracking-wider text-[var(--am-text-muted)]">
                    <span class="font-semibold">Rohdaten</span>
                    @svg('heroicon-o-chevron-double-down', 'w-3 h-3', ['x-show' => '!open'])
                    @svg('heroicon-o-chevron-double-up', 'w-3 h-3', ['x-show' => 'open', 'style' => 'display:none'])
                </button>
                <div x-show="open" x-cloak class="border-t border-[color:var(--am-border)] p-4 max-h-64 overflow-y-auto bg-[var(--am-surface)]">
                    <pre class="text-[10px] text-[var(--am-text-secondary)] font-mono whitespace-pre-wrap break-all">{{ json_encode($item->raw_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                </div>
            </div>
        @endif
    </div>
</x-ui-page>
