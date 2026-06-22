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
                {{-- Anlegen nur Owner/Admin (E1/ADR 0004) — Backend: save() Gate asset-manager.manage. --}}
                @can('asset-manager.manage')
                    <button wire:click="newLine"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-white bg-violet-600 rounded-lg hover:bg-violet-700 transition-all">
                        @svg('heroicon-o-plus', 'w-3.5 h-3.5')
                        Neue Position
                    </button>
                @endcan
            </x-slot>
        </x-asset-manager-page-actionbar>
    </x-slot>

    {{-- LINKS: Filter --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Filter" icon="heroicon-o-funnel" width="w-72" :defaultOpen="true">
            <div class="p-4 space-y-4 bg-[var(--ui-muted-5)]">
                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Suche</h3>
                    <div class="px-3 pb-3">
                        <input type="text" wire:model.live.debounce.300ms="search" placeholder="Bezeichnung…"
                               class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40 focus:outline-none focus:ring-2 focus:ring-violet-500/30" />
                    </div>
                </section>

                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Kostenart</h3>
                    <div class="px-3 pb-3">
                        <select wire:model.live="filterType" class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40">
                            <option value="">Alle</option>
                            @foreach($costTypes as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
                        </select>
                    </div>
                </section>

                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Kostenstelle</h3>
                    <div class="px-3 pb-3">
                        <select wire:model.live="filterCenter" class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40">
                            <option value="">Alle</option>
                            @foreach($costCenters as $c)<option value="{{ $c->id }}">{{ $c->code }}</option>@endforeach
                        </select>
                    </div>
                </section>

                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Kreditor</h3>
                    <div class="px-3 pb-3">
                        <select wire:model.live="filterVendor" class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40">
                            <option value="">Alle</option>
                            @foreach($vendors as $v)<option value="{{ $v->id }}">{{ $v->name }}</option>@endforeach
                        </select>
                    </div>
                </section>

                <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm overflow-hidden">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] px-3 pt-3 pb-1.5">Status</h3>
                    <div class="px-3 pb-3">
                        <select wire:model.live="filterActive" class="w-full px-2 py-1.5 text-[11px] rounded-md bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40">
                            <option value="">Alle</option>
                            <option value="1">Aktiv</option>
                            <option value="0">Inaktiv</option>
                        </select>
                    </div>
                </section>

                @if($search || $filterType || $filterCenter || $filterVendor || $filterActive !== '')
                    <button wire:click="resetFilters"
                            class="w-full px-3 py-2 text-[11px] font-medium text-red-500 bg-red-500/5 border border-red-500/20 rounded-lg hover:bg-red-500/10">
                        @svg('heroicon-o-x-circle', 'w-3.5 h-3.5 inline -mt-0.5 mr-1')
                        Filter zurücksetzen
                    </button>
                @endif
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- RECHTS: Anlegen / Bearbeiten --}}
    <x-slot name="activity">
        <x-ui-page-sidebar :title="$editId ? 'Position bearbeiten' : 'Neue Position'" icon="heroicon-o-pencil-square"
                           width="w-80" :defaultOpen="false" storeKey="activityOpen" side="right">
            <div class="p-4 space-y-3 bg-[var(--ui-muted-5)]">
                {{-- Editor (Anlegen/Bearbeiten/Speichern/Löschen) nur Owner/Admin (ADR 0004). --}}
                @can('asset-manager.manage')
                @if($showEditor)
                    <div class="flex items-center justify-between pb-2 border-b border-[var(--ui-border)]/30">
                        <span class="text-[10px] uppercase tracking-wider text-[var(--ui-muted)]">{{ $editId ? 'Bearbeiten' : 'Neu anlegen' }}</span>
                        <button wire:click="cancelEdit" class="text-[10px] text-[var(--ui-muted)] hover:text-red-500">
                            @svg('heroicon-o-x-mark', 'w-3 h-3 inline -mt-0.5')
                            Schließen
                        </button>
                    </div>

                    <section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm p-3 space-y-3">
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">Kostenart *</label>
                            <select wire:model="fCostType" class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                                <option value="">– wählen –</option>
                                @foreach($costTypes as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
                            </select>
                            @error('fCostType')<span class="text-[10px] text-red-500">{{ $message }}</span>@enderror
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">Bezeichnung *</label>
                            <input type="text" wire:model="fLabel" class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                            @error('fLabel')<span class="text-[10px] text-red-500">{{ $message }}</span>@enderror
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">Kostenstelle (Code)</label>
                            <input list="cc-list" wire:model="fCostCenter" class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                            <datalist id="cc-list">@foreach($costCenters as $c)<option value="{{ $c->code }}">@endforeach</datalist>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">Kreditor</label>
                            <select wire:model="fVendor" class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                                <option value="">– Standard –</option>
                                @foreach($vendors as $v)<option value="{{ $v->id }}">{{ $v->name }}</option>@endforeach
                            </select>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Betrag (€) *</label>
                                <input type="number" step="0.01" wire:model="fAmount" class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                                @error('fAmount')<span class="text-[10px] text-red-500">{{ $message }}</span>@enderror
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Frequenz</label>
                                <select wire:model="fFrequency" class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                                    <option value="monthly">Monatlich</option>
                                    <option value="quarterly">Quartal</option>
                                    <option value="yearly">Jährlich</option>
                                    <option value="once">Einmalig</option>
                                </select>
                            </div>
                        </div>
                        <label class="flex items-center gap-2 text-sm text-gray-600">
                            <input type="checkbox" wire:model="fActive" class="rounded"> Aktiv
                        </label>

                        <div class="flex items-center gap-2 pt-3 mt-1 border-t border-[var(--ui-border)]/30">
                            <button wire:click="save" class="flex-1 px-3 py-2 text-xs font-medium text-white bg-violet-600 rounded-lg hover:bg-violet-700">
                                {{ $editId ? 'Speichern' : 'Anlegen' }}
                            </button>
                            <button wire:click="cancelEdit" class="px-3 py-2 text-xs font-medium text-gray-500 bg-black/[0.04] rounded-lg hover:bg-black/[0.07]">Abbrechen</button>
                            @if($editId)
                                <button wire:click="delete({{ $editId }})" wire:confirm="Position löschen?"
                                        class="px-3 py-2 text-xs font-medium text-red-500 bg-red-500/5 border border-red-500/20 rounded-lg hover:bg-red-500/10" title="Löschen">
                                    @svg('heroicon-o-trash', 'w-3.5 h-3.5')
                                </button>
                            @endif
                        </div>
                    </section>
                @else
                    <div class="flex flex-col items-center justify-center py-10 text-center">
                        @svg('heroicon-o-cursor-arrow-rays', 'w-8 h-8 text-gray-300 mb-3')
                        <p class="text-[11px] text-[var(--ui-muted)]">Eine Zeile anklicken zum Bearbeiten — oder oben „Neue Position“.</p>
                    </div>
                @endif
                @endcan
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- Öffnet das rechte Panel bei „Neu“ oder Zeilenklick. --}}
    <div x-data x-on:open-activity.window="$store.ui && $store.ui.mSet('activity', 'open', true)"></div>

    {{-- HAUPT --}}
    <div class="flex-1 flex flex-col min-h-0 min-w-0">
        <div class="flex-1 overflow-y-auto p-6 space-y-4">

            @if($flash)
                <div class="px-4 py-2 text-xs font-medium text-emerald-700 bg-emerald-500/10 border border-emerald-500/20 rounded-lg">{{ $flash }}</div>
            @endif

            <div class="flex items-center gap-2">
                @svg('heroicon-o-banknotes', 'w-5 h-5 text-[var(--ui-secondary)]')
                <h2 class="text-lg font-semibold text-[var(--ui-secondary)] m-0">Kostenpositionen</h2>
                <span class="flex-1"></span>
                <span class="text-sm text-[var(--ui-secondary)]">
                    Summe (gefiltert): <strong class="text-violet-700 tabular-nums">{{ number_format($monthlySum, 2, ',', '.') }} € / Monat</strong>
                    @if($oneTimeSum > 0)
                        <span class="ml-2 text-[var(--ui-muted)]">·</span>
                        <span class="ml-2">Einmalkosten: <strong class="text-amber-700 tabular-nums">{{ number_format($oneTimeSum, 2, ',', '.') }} €</strong></span>
                        <span class="text-[10px] text-[var(--ui-muted)]">(nicht in Monatssumme)</span>
                    @endif
                </span>
            </div>

            <div class="rounded-xl bg-white/60 border border-black/5 shadow-sm overflow-hidden">
                @if($lines->isEmpty())
                    <div class="p-8 text-center text-sm text-gray-400">Keine Kostenpositionen gefunden.</div>
                @else
                    <table class="w-full text-sm">
                        <thead>
                            @php
                                $sortIcon = fn($f) => $sortField === $f
                                    ? '<svg class="w-3 h-3 inline -mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="' . ($sortDirection === 'asc' ? 'M4.5 15.75l7.5-7.5 7.5 7.5' : 'M19.5 8.25l-7.5 7.5-7.5-7.5') . '" /></svg>'
                                    : '';
                            @endphp
                            <tr class="border-b border-black/5 text-xs uppercase tracking-wider text-gray-400">
                                <th class="text-left px-4 py-3"><button wire:click="sortBy('label')" class="inline-flex items-center gap-1 uppercase hover:text-gray-600">Bezeichnung {!! $sortIcon('label') !!}</button></th>
                                <th class="text-left px-4 py-3"><button wire:click="sortBy('cost_type')" class="inline-flex items-center gap-1 uppercase hover:text-gray-600">Kostenart {!! $sortIcon('cost_type') !!}</button></th>
                                <th class="text-left px-4 py-3"><button wire:click="sortBy('cost_center')" class="inline-flex items-center gap-1 uppercase hover:text-gray-600">KSt {!! $sortIcon('cost_center') !!}</button></th>
                                <th class="text-left px-4 py-3"><button wire:click="sortBy('vendor')" class="inline-flex items-center gap-1 uppercase hover:text-gray-600">Kreditor {!! $sortIcon('vendor') !!}</button></th>
                                <th class="text-right px-4 py-3"><button wire:click="sortBy('amount')" class="inline-flex items-center gap-1 uppercase hover:text-gray-600">Betrag {!! $sortIcon('amount') !!}</button></th>
                                <th class="text-left px-4 py-3"><button wire:click="sortBy('frequency')" class="inline-flex items-center gap-1 uppercase hover:text-gray-600">Frequenz {!! $sortIcon('frequency') !!}</button></th>
                                <th class="text-right px-4 py-3"><button wire:click="sortBy('monthly_amount')" class="inline-flex items-center gap-1 uppercase hover:text-gray-600">€/Monat {!! $sortIcon('monthly_amount') !!}</button></th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-black/[0.03]">
                            @foreach($lines as $line)
                                <tr wire:key="cl-{{ $line->id }}" wire:click="edit({{ $line->id }})"
                                    class="cursor-pointer hover:bg-black/[0.02] {{ $editId === $line->id ? 'bg-violet-500/10' : '' }} {{ $line->active ? '' : 'opacity-50' }}">
                                    <td class="px-4 py-2.5 font-medium text-gray-800">{{ $line->label }}</td>
                                    <td class="px-4 py-2.5 text-xs text-gray-500">{{ $line->costType?->name }}</td>
                                    <td class="px-4 py-2.5 text-xs text-gray-500">{{ $line->costCenter?->code ?? '—' }}</td>
                                    <td class="px-4 py-2.5 text-xs text-gray-500">{{ $line->vendor?->name ?? '—' }}</td>
                                    <td class="px-4 py-2.5 text-right tabular-nums">{{ number_format((float)$line->amount, 2, ',', '.') }} €</td>
                                    <td class="px-4 py-2.5 text-xs text-gray-500">{{ ['monthly'=>'mtl.','quarterly'=>'qrtl.','yearly'=>'jähr.','once'=>'einm.'][$line->frequency] ?? $line->frequency }}</td>
                                    <td class="px-4 py-2.5 text-right font-semibold tabular-nums text-violet-700">
                                        @if($line->frequency === 'once')
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-medium bg-amber-500/10 text-amber-700 border border-amber-500/20" title="Einmalbetrag — fließt nicht in die monatliche Aufteilung">einmalig</span>
                                        @else
                                            {{ number_format((float)$line->monthly_amount, 2, ',', '.') }} €
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5 text-right whitespace-nowrap">
                                        @can('asset-manager.manage')
                                            <button wire:click.stop="toggleActive({{ $line->id }})" class="text-xs text-gray-400 hover:text-amber-600" title="Aktiv/Inaktiv">@svg('heroicon-o-power', 'w-4 h-4 inline')</button>
                                        @endcan
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            <div>{{ $lines->links() }}</div>
        </div>
    </div>
</x-ui-page>
