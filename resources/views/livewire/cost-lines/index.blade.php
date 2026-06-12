<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Kostenpositionen', 'icon' => 'banknotes'],
        ]">
            <x-slot name="actions">
                <button wire:click="newLine"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-white bg-violet-600 rounded-lg hover:bg-violet-700 transition-all">
                    @svg('heroicon-o-plus', 'w-3.5 h-3.5')
                    Neue Position
                </button>
            </x-slot>
        </x-ui-page-actionbar>
    </x-slot>

    <div class="flex-1 flex flex-col min-h-0 min-w-0">
        <div class="flex-1 overflow-y-auto p-6 space-y-4">

            @if($flash)
                <div class="px-4 py-2 text-xs font-medium text-emerald-700 bg-emerald-500/10 border border-emerald-500/20 rounded-lg">{{ $flash }}</div>
            @endif

            {{-- Filter --}}
            <div class="flex flex-wrap items-center gap-2">
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Suche Bezeichnung…"
                       class="px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white min-w-[200px]">
                <select wire:model.live="filterType" class="px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                    <option value="">Alle Kostenarten</option>
                    @foreach($costTypes as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
                </select>
                <select wire:model.live="filterCenter" class="px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                    <option value="">Alle Kostenstellen</option>
                    @foreach($costCenters as $c)<option value="{{ $c->id }}">{{ $c->code }}</option>@endforeach
                </select>
                <select wire:model.live="filterVendor" class="px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                    <option value="">Alle Kreditoren</option>
                    @foreach($vendors as $v)<option value="{{ $v->id }}">{{ $v->name }}</option>@endforeach
                </select>
                <select wire:model.live="filterActive" class="px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                    <option value="">Alle</option>
                    <option value="1">Aktiv</option>
                    <option value="0">Inaktiv</option>
                </select>
                <span class="ml-auto text-sm text-[var(--ui-secondary)]">
                    Summe (gefiltert): <strong class="text-violet-700 tabular-nums">{{ number_format($monthlySum, 2, ',', '.') }} € / Monat</strong>
                </span>
            </div>

            {{-- Editor --}}
            @if($showEditor)
                <div class="rounded-xl bg-white border border-violet-500/30 shadow-sm p-5 space-y-4">
                    <h3 class="text-sm font-semibold text-[var(--ui-secondary)]">{{ $editId ? 'Position bearbeiten' : 'Neue Kostenposition' }}</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">Kostenart *</label>
                            <select wire:model="fCostType" class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                                <option value="">– wählen –</option>
                                @foreach($costTypes as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
                            </select>
                            @error('fCostType')<span class="text-[10px] text-red-500">{{ $message }}</span>@enderror
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
                        <div class="md:col-span-2">
                            <label class="block text-xs text-gray-500 mb-1">Bezeichnung *</label>
                            <input type="text" wire:model="fLabel" class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                            @error('fLabel')<span class="text-[10px] text-red-500">{{ $message }}</span>@enderror
                        </div>
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
                        <label class="flex items-center gap-2 text-sm text-gray-600 mt-5">
                            <input type="checkbox" wire:model="fActive" class="rounded"> Aktiv
                        </label>
                    </div>
                    <div class="flex items-center gap-2">
                        <button wire:click="save" class="px-3 py-1.5 text-xs font-medium text-white bg-violet-600 rounded-lg hover:bg-violet-700">Speichern</button>
                        <button wire:click="$set('showEditor', false)" class="px-3 py-1.5 text-xs font-medium text-gray-600 bg-black/[0.04] rounded-lg hover:bg-black/[0.07]">Abbrechen</button>
                    </div>
                </div>
            @endif

            {{-- Tabelle --}}
            <div class="rounded-xl bg-white/60 dark:bg-white/5 border border-black/5 dark:border-white/10 shadow-sm overflow-hidden">
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
                                <tr class="hover:bg-black/[0.02] {{ $line->active ? '' : 'opacity-50' }}">
                                    <td class="px-4 py-2.5 font-medium text-gray-800">{{ $line->label }}</td>
                                    <td class="px-4 py-2.5 text-xs text-gray-500">{{ $line->costType?->name }}</td>
                                    <td class="px-4 py-2.5 text-xs text-gray-500">{{ $line->costCenter?->code ?? '—' }}</td>
                                    <td class="px-4 py-2.5 text-xs text-gray-500">{{ $line->vendor?->name ?? '—' }}</td>
                                    <td class="px-4 py-2.5 text-right tabular-nums">{{ number_format((float)$line->amount, 2, ',', '.') }} €</td>
                                    <td class="px-4 py-2.5 text-xs text-gray-500">{{ ['monthly'=>'mtl.','quarterly'=>'qrtl.','yearly'=>'jähr.','once'=>'einm.'][$line->frequency] ?? $line->frequency }}</td>
                                    <td class="px-4 py-2.5 text-right font-semibold tabular-nums text-violet-700">{{ number_format((float)$line->monthly_amount, 2, ',', '.') }} €</td>
                                    <td class="px-4 py-2.5 text-right whitespace-nowrap">
                                        <button wire:click="toggleActive({{ $line->id }})" class="text-xs text-gray-400 hover:text-amber-600" title="Aktiv/Inaktiv">@svg('heroicon-o-power', 'w-4 h-4 inline')</button>
                                        <button wire:click="edit({{ $line->id }})" class="text-xs text-gray-400 hover:text-violet-600 ml-1" title="Bearbeiten">@svg('heroicon-o-pencil-square', 'w-4 h-4 inline')</button>
                                        <button wire:click="delete({{ $line->id }})" wire:confirm="Position löschen?" class="text-xs text-gray-400 hover:text-red-600 ml-1" title="Löschen">@svg('heroicon-o-trash', 'w-4 h-4 inline')</button>
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
