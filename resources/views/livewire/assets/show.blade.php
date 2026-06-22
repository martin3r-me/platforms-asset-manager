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
            <form wire:submit="save" class="p-4 space-y-4 bg-[var(--ui-muted-5)]">

                @if($item->source === 'intune')
                    <div class="rounded-lg bg-violet-500/10 border border-violet-500/20 p-2 text-[11px] text-violet-700 dark:text-violet-400">
                        @svg('heroicon-o-cloud', 'w-3.5 h-3.5 inline -mt-0.5 mr-1')
                        Intune-synced — Gerätedaten read-only, Zuweisung und Kosten editierbar.
                    </div>
                @endif

                {{-- Kategorie & Status --}}
                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Klassifikation</h3>
                    <div class="px-3 pb-3 space-y-2">
                        <div>
                            <label class="block text-[10px] text-[var(--ui-muted)] mb-1">Kategorie</label>
                            <select wire:model="categoryId" @if($item->source === 'intune') disabled @endif class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40 disabled:opacity-60">
                                @foreach($categories as $cat)
                                    <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] text-[var(--ui-muted)] mb-1">Status</label>
                            <select wire:model="status" class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40">
                                <option value="in_stock">Lager</option>
                                <option value="assigned">Zugewiesen</option>
                                <option value="retired">Ausgemustert</option>
                                <option value="lost">Verloren</option>
                            </select>
                        </div>
                    </div>
                </section>

                {{-- Hardware --}}
                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Gerät</h3>
                    <div class="px-3 pb-3 space-y-2">
                        <div>
                            <label class="block text-[10px] text-[var(--ui-muted)] mb-1">Name</label>
                            <input type="text" wire:model="name" @if($item->source === 'intune') disabled @endif class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40 disabled:opacity-60" />
                        </div>
                        <div>
                            <label class="block text-[10px] text-[var(--ui-muted)] mb-1">Hersteller</label>
                            <input type="text" wire:model="manufacturer" @if($item->source === 'intune') disabled @endif class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40 disabled:opacity-60" />
                        </div>
                        <div>
                            <label class="block text-[10px] text-[var(--ui-muted)] mb-1">Modell</label>
                            <input type="text" wire:model="model" @if($item->source === 'intune') disabled @endif class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40 disabled:opacity-60" />
                        </div>
                        <div>
                            <label class="block text-[10px] text-[var(--ui-muted)] mb-1">Seriennummer</label>
                            <input type="text" wire:model="serialNumber" @if($item->source === 'intune') disabled @endif class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40 disabled:opacity-60" />
                        </div>
                    </div>
                </section>

                {{-- Zuweisung --}}
                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Zuweisung</h3>
                    <div class="px-3 pb-3">
                        <select wire:model="assigneeId" class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40">
                            <option value="">– Niemand (Lager) –</option>
                            @foreach($employees as $emp)
                                <option value="{{ $emp->id }}">{{ $emp->name }}</option>
                            @endforeach
                        </select>
                        @if($item->assignee)
                            <a href="{{ route('asset-manager.employees.show', $item->assignee) }}" wire:navigate class="block mt-2 text-[10px] text-violet-500 hover:underline">
                                → Mitarbeiter-Profil ansehen
                            </a>
                        @endif
                    </div>
                </section>

                {{-- Kosten --}}
                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Kosten</h3>
                    <div class="px-3 pb-3 space-y-2">
                        <div>
                            <label class="block text-[10px] text-[var(--ui-muted)] mb-1">Kaufdatum</label>
                            <input type="date" wire:model="purchaseDate" class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40" />
                        </div>
                        <div>
                            <label class="block text-[10px] text-[var(--ui-muted)] mb-1">Kaufpreis (€)</label>
                            <input type="number" step="0.01" min="0" wire:model="purchasePrice" class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40" />
                        </div>
                        <div>
                            <label class="block text-[10px] text-[var(--ui-muted)] mb-1">AfA (Monate)</label>
                            <input type="number" min="1" max="240" wire:model="depreciationMonths" class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40" />
                        </div>
                        @if($item->monthlyCost() > 0)
                            <div class="text-[11px] text-[var(--ui-muted)] pt-1 border-t border-[var(--ui-border)]/30 flex justify-between">
                                <span>Monatlich</span>
                                <span class="font-semibold text-[var(--ui-secondary)]">{{ number_format($item->monthlyCost(), 2, ',', '.') }} €</span>
                            </div>
                        @endif
                    </div>
                </section>

                {{-- Notes --}}
                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Notizen</h3>
                    <div class="px-3 pb-3">
                        <textarea wire:model="notes" rows="3" class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40 resize-none"></textarea>
                    </div>
                </section>

                <div class="space-y-2 pt-2">
                    <button type="submit" class="w-full inline-flex items-center justify-center gap-2 px-3 py-2 text-xs font-medium text-white bg-gradient-to-br from-violet-500 to-indigo-600 rounded-lg hover:from-violet-600 hover:to-indigo-700 transition-all shadow-sm">
                        @svg('heroicon-o-check', 'w-3.5 h-3.5')
                        Speichern
                    </button>
                    @if($saved)
                        <div class="text-[10px] text-emerald-600 text-center">Gespeichert.</div>
                    @endif
                    @can('delete', $item)
                        <button type="button" wire:click="delete" wire:confirm="Asset wirklich löschen?" class="w-full inline-flex items-center justify-center gap-2 px-3 py-2 text-xs font-medium text-red-500 bg-red-500/5 border border-red-500/20 rounded-lg hover:bg-red-500/10 transition-colors">
                            @svg('heroicon-o-trash', 'w-3.5 h-3.5')
                            Löschen
                        </button>
                    @endcan
                </div>
            </form>
            @endcan
        </x-ui-page-sidebar>
    </x-slot>

    {{-- RECHTS: Zuweisungs-Historie --}}
    <x-slot name="activity">
        <x-ui-page-sidebar title="Historie" icon="heroicon-o-clock" width="w-80" :defaultOpen="false" storeKey="activityOpen" side="right">
            <div class="p-4 space-y-3 bg-[var(--ui-muted-5)]">
                <div class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-1">Zuweisungs-Historie</div>
                @forelse($activities as $a)
                    <div class="p-3 rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm">
                        <div class="flex items-start justify-between gap-2 mb-1.5">
                            <a href="{{ $a->employee ? route('asset-manager.employees.show', $a->employee) : '#' }}" @if($a->employee) wire:navigate @endif
                                class="text-[12px] font-medium text-[var(--ui-secondary)] hover:text-violet-600">
                                {{ $a->employee?->name ?? '— gelöscht —' }}
                            </a>
                            @if($a->isOpen())
                                <span class="px-1.5 py-0.5 text-[9px] rounded-full bg-emerald-500/10 text-emerald-600">aktuell</span>
                            @endif
                        </div>
                        <div class="text-[10px] text-[var(--ui-muted)]">
                            {{ $a->assigned_at->format('d.m.Y') }}
                            @if($a->returned_at) — {{ $a->returned_at->format('d.m.Y') }} @endif
                        </div>
                    </div>
                @empty
                    <div class="p-3 text-center text-[11px] text-[var(--ui-muted)]">Noch keine Historie.</div>
                @endforelse
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- HAUPT-CONTENT --}}
    <div class="flex-1 flex flex-col min-h-0 min-w-0">
        <div class="flex-1 overflow-y-auto p-6">
            <div class="max-w-3xl mx-auto space-y-5">
                <div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-5">
                    <div class="flex items-start gap-4">
                        <div class="flex-shrink-0 w-12 h-12 rounded-xl bg-gradient-to-br from-violet-500/10 to-indigo-500/10 flex items-center justify-center">
                            @if($item->category?->icon) @svg($item->category->icon, 'w-6 h-6 text-violet-500') @else @svg('heroicon-o-cube', 'w-6 h-6 text-violet-500') @endif
                        </div>
                        <div class="flex-1 min-w-0">
                            <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100 truncate">{{ $item->name }}</h1>
                            @if($item->manufacturer || $item->model)
                                <p class="text-sm text-gray-500 dark:text-gray-400">{{ trim($item->manufacturer . ' ' . $item->model) }}</p>
                            @endif
                        </div>
                        @php $c = $item->statusBadgeColor() @endphp
                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-medium bg-{{ $c }}-500/10 text-{{ $c }}-600 dark:text-{{ $c }}-400 flex-shrink-0">
                            <span class="w-2 h-2 rounded-full bg-{{ $c }}-500"></span>
                            {{ $item->statusLabel() }}
                        </span>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div class="rounded-lg bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm p-4">
                        <div class="text-[10px] uppercase tracking-wider text-gray-400 mb-1">Kategorie</div>
                        <div class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $item->category?->name ?? '—' }}</div>
                    </div>
                    <div class="rounded-lg bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm p-4">
                        <div class="text-[10px] uppercase tracking-wider text-gray-400 mb-1">Zugewiesen an</div>
                        <div class="text-sm font-medium text-gray-700 dark:text-gray-300 truncate">{{ $item->assignee?->name ?? '—' }}</div>
                    </div>
                    <div class="rounded-lg bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm p-4">
                        <div class="text-[10px] uppercase tracking-wider text-gray-400 mb-1">Monatliche Kosten</div>
                        <div class="text-sm font-medium text-gray-700 dark:text-gray-300">
                            @if($item->monthlyCost() > 0)
                                {{ number_format($item->monthlyCost(), 2, ',', '.') }} €
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </div>
                    </div>
                </div>

                @if($item->notes)
                    <div class="rounded-xl bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm p-5">
                        <div class="text-xs font-medium uppercase tracking-wider text-gray-400 mb-2">Notizen</div>
                        <div class="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-wrap">{{ $item->notes }}</div>
                    </div>
                @endif
            </div>
        </div>

        {{-- BOTTOM PANEL --}}
        @if($item->raw_data)
            <div class="shrink-0 border-t border-[color:var(--ui-border)] bg-[var(--ui-muted-5)]" x-data="{ open: false }">
                <button type="button" @click="open = !open" class="w-full p-2 text-center flex items-center justify-center gap-2 hover:bg-[var(--ui-muted-10)] text-[11px] uppercase tracking-wider text-[var(--ui-muted)]">
                    <span class="font-semibold">Rohdaten</span>
                    @svg('heroicon-o-chevron-double-down', 'w-3 h-3', ['x-show' => '!open'])
                    @svg('heroicon-o-chevron-double-up', 'w-3 h-3', ['x-show' => 'open', 'style' => 'display:none'])
                </button>
                <div x-show="open" x-cloak class="border-t border-[color:var(--ui-border)] p-4 max-h-64 overflow-y-auto bg-white dark:bg-black/20">
                    <pre class="text-[10px] text-gray-500 font-mono whitespace-pre-wrap break-all">{{ json_encode($item->raw_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                </div>
            </div>
        @endif
    </div>
</x-ui-page>
