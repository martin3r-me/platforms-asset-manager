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
                        @foreach($costCenters as $c)<option value="{{ $c->id }}">{{ $c->code }}</option>@endforeach
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

                @if($search || $filterType || $filterCenter || $filterVendor || $filterActive !== '')
                    <x-asset-manager-button variant="ghost" size="sm" class="w-full" wire:click="resetFilters">
                        @svg('heroicon-o-x-circle', 'w-3.5 h-3.5')
                        Filter zurücksetzen
                    </x-asset-manager-button>
                @endif
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- RECHTS: Anlegen / Bearbeiten --}}
    <x-slot name="activity">
        <x-ui-page-sidebar :title="$editId ? 'Position bearbeiten' : 'Neue Position'" icon="heroicon-o-pencil-square"
                           width="w-80" :defaultOpen="false" storeKey="activityOpen" side="right">
            <div class="p-4 space-y-3 bg-[var(--am-bg)]">
                {{-- Editor (Anlegen/Bearbeiten/Speichern/Löschen) nur Owner/Admin (ADR 0004). --}}
                @can('asset-manager.manage')
                @if($showEditor)
                    <div class="flex items-center justify-between pb-2 border-b border-[color:var(--am-border)]">
                        <span class="text-[10px] uppercase tracking-wider text-[var(--am-text-muted)]">{{ $editId ? 'Bearbeiten' : 'Neu anlegen' }}</span>
                        <button wire:click="cancelEdit" class="text-[10px] text-[var(--am-text-secondary)] hover:text-red-600">
                            @svg('heroicon-o-x-mark', 'w-3 h-3 inline -mt-0.5')
                            Schließen
                        </button>
                    </div>

                    <section class="rounded-xl bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm p-3 space-y-3">
                        <div>
                            <label class="block text-xs text-[var(--am-text-secondary)] mb-1">Kostenart *</label>
                            <x-asset-manager-select size="md" wire:model="fCostType">
                                <option value="">– wählen –</option>
                                @foreach($costTypes as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
                            </x-asset-manager-select>
                            @error('fCostType')<span class="text-[10px] text-red-700">{{ $message }}</span>@enderror
                        </div>
                        <div>
                            <label class="block text-xs text-[var(--am-text-secondary)] mb-1">Bezeichnung *</label>
                            <x-asset-manager-input size="md" type="text" wire:model="fLabel" />
                            @error('fLabel')<span class="text-[10px] text-red-700">{{ $message }}</span>@enderror
                        </div>
                        <div>
                            <label class="block text-xs text-[var(--am-text-secondary)] mb-1">Kostenstelle (Code)</label>
                            <x-asset-manager-input size="md" list="cc-list" wire:model="fCostCenter" />
                            <datalist id="cc-list">@foreach($costCenters as $c)<option value="{{ $c->code }}">@endforeach</datalist>
                        </div>
                        <div>
                            <label class="block text-xs text-[var(--am-text-secondary)] mb-1">Kreditor</label>
                            <x-asset-manager-select size="md" wire:model="fVendor">
                                <option value="">– Standard –</option>
                                @foreach($vendors as $v)<option value="{{ $v->id }}">{{ $v->name }}</option>@endforeach
                            </x-asset-manager-select>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-xs text-[var(--am-text-secondary)] mb-1">Betrag (€) *</label>
                                <x-asset-manager-input size="md" type="number" step="0.01" wire:model="fAmount" />
                                @error('fAmount')<span class="text-[10px] text-red-700">{{ $message }}</span>@enderror
                            </div>
                            <div>
                                <label class="block text-xs text-[var(--am-text-secondary)] mb-1">Frequenz</label>
                                <x-asset-manager-select size="md" wire:model="fFrequency">
                                    <option value="monthly">Monatlich</option>
                                    <option value="quarterly">Quartal</option>
                                    <option value="yearly">Jährlich</option>
                                    <option value="once">Einmalig</option>
                                </x-asset-manager-select>
                            </div>
                        </div>
                        <label class="flex items-center gap-2 text-sm text-[var(--am-text-secondary)]">
                            <input type="checkbox" wire:model="fActive" class="rounded"> Aktiv
                        </label>

                        <div class="flex items-center gap-2 pt-3 mt-1 border-t border-[color:var(--am-border)]">
                            <x-asset-manager-button variant="primary" size="sm" class="flex-1" wire:click="save">
                                {{ $editId ? 'Speichern' : 'Anlegen' }}
                            </x-asset-manager-button>
                            <x-asset-manager-button variant="ghost" size="sm" wire:click="cancelEdit">Abbrechen</x-asset-manager-button>
                            @if($editId)
                                <x-asset-manager-button variant="danger" size="sm" wire:click="delete({{ $editId }})" wire:confirm="Position löschen?" title="Löschen">
                                    @svg('heroicon-o-trash', 'w-3.5 h-3.5')
                                </x-asset-manager-button>
                            @endif
                        </div>
                    </section>
                @else
                    <div class="flex flex-col items-center justify-center py-10 text-center">
                        @svg('heroicon-o-cursor-arrow-rays', 'w-8 h-8 text-[var(--am-text-muted)] mb-3')
                        <p class="text-[11px] text-[var(--am-text-secondary)]">Eine Zeile anklicken zum Bearbeiten — oder oben „Neue Position“.</p>
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
                <div class="px-4 py-2 text-xs font-medium text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-lg">{{ $flash }}</div>
            @endif

            <div class="flex items-center gap-2">
                @svg('heroicon-o-banknotes', 'w-5 h-5 text-[var(--am-text-secondary)]')
                <h2 class="text-lg font-semibold text-[var(--am-text)] m-0">Kostenpositionen</h2>
                <span class="flex-1"></span>
                <span class="text-sm text-[var(--am-text-secondary)]">
                    Summe (gefiltert): <strong class="text-[var(--am-accent)] tabular-nums">{{ number_format($monthlySum, 2, ',', '.') }} € / Monat</strong>
                    @if($oneTimeSum > 0)
                        <span class="ml-2 text-[var(--am-text-secondary)]">·</span>
                        <span class="ml-2">Einmalkosten: <strong class="text-amber-700 tabular-nums">{{ number_format($oneTimeSum, 2, ',', '.') }} €</strong></span>
                        <span class="text-[10px] text-[var(--am-text-muted)]">(nicht in Monatssumme)</span>
                    @endif
                </span>
            </div>

            <div class="rounded-xl bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm overflow-hidden">
                @if($lines->isEmpty())
                    <div class="p-8 text-center text-sm text-[var(--am-text-secondary)]">Keine Kostenpositionen gefunden.</div>
                @else
                    <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            @php
                                $sortIcon = fn($f) => $sortField === $f
                                    ? '<svg class="w-3 h-3 inline -mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="' . ($sortDirection === 'asc' ? 'M4.5 15.75l7.5-7.5 7.5 7.5' : 'M19.5 8.25l-7.5 7.5-7.5-7.5') . '" /></svg>'
                                    : '';
                            @endphp
                            <tr class="border-b border-[color:var(--am-border)] text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">
                                <th class="text-left px-4 py-3 bg-[var(--am-bg)]"><button wire:click="sortBy('label')" class="inline-flex items-center gap-1 uppercase hover:text-[var(--am-text-secondary)]">Bezeichnung {!! $sortIcon('label') !!}</button></th>
                                <th class="text-left px-4 py-3 bg-[var(--am-bg)]"><button wire:click="sortBy('cost_type')" class="inline-flex items-center gap-1 uppercase hover:text-[var(--am-text-secondary)]">Kostenart {!! $sortIcon('cost_type') !!}</button></th>
                                <th class="text-left px-4 py-3 bg-[var(--am-bg)]"><button wire:click="sortBy('cost_center')" class="inline-flex items-center gap-1 uppercase hover:text-[var(--am-text-secondary)]">KSt {!! $sortIcon('cost_center') !!}</button></th>
                                <th class="text-left px-4 py-3 bg-[var(--am-bg)]"><button wire:click="sortBy('vendor')" class="inline-flex items-center gap-1 uppercase hover:text-[var(--am-text-secondary)]">Kreditor {!! $sortIcon('vendor') !!}</button></th>
                                <th class="text-right px-4 py-3 bg-[var(--am-bg)]"><button wire:click="sortBy('amount')" class="inline-flex items-center gap-1 uppercase hover:text-[var(--am-text-secondary)]">Betrag {!! $sortIcon('amount') !!}</button></th>
                                <th class="text-left px-4 py-3 bg-[var(--am-bg)]"><button wire:click="sortBy('frequency')" class="inline-flex items-center gap-1 uppercase hover:text-[var(--am-text-secondary)]">Frequenz {!! $sortIcon('frequency') !!}</button></th>
                                <th class="text-right px-4 py-3 bg-[var(--am-bg)]"><button wire:click="sortBy('monthly_amount')" class="inline-flex items-center gap-1 uppercase hover:text-[var(--am-text-secondary)]">€/Monat {!! $sortIcon('monthly_amount') !!}</button></th>
                                <th class="px-4 py-3 bg-[var(--am-bg)]"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[color:var(--am-border)]">
                            @foreach($lines as $line)
                                <tr wire:key="cl-{{ $line->id }}" wire:click="edit({{ $line->id }})"
                                    class="cursor-pointer hover:bg-[var(--am-bg)] {{ $editId === $line->id ? 'bg-[var(--am-accent-surface)] shadow-[inset_3px_0_0_var(--am-accent)]' : '' }} {{ $line->active ? '' : 'opacity-50' }}">
                                    <td class="px-4 py-2.5 font-medium text-[var(--am-text)]">{{ $line->label }}</td>
                                    <td class="px-4 py-2.5 text-xs text-[var(--am-text-secondary)]">{{ $line->costType?->name }}</td>
                                    <td class="px-4 py-2.5 text-xs text-[var(--am-text-secondary)]">{{ $line->costCenter?->code ?? '—' }}</td>
                                    <td class="px-4 py-2.5 text-xs text-[var(--am-text-secondary)]">{{ $line->vendor?->name ?? '—' }}</td>
                                    <td class="px-4 py-2.5 text-right tabular-nums text-[var(--am-text)]">{{ number_format((float)$line->amount, 2, ',', '.') }} €</td>
                                    <td class="px-4 py-2.5 text-xs text-[var(--am-text-secondary)]">{{ ['monthly'=>'mtl.','quarterly'=>'qrtl.','yearly'=>'jähr.','once'=>'einm.'][$line->frequency] ?? $line->frequency }}</td>
                                    <td class="px-4 py-2.5 text-right font-semibold tabular-nums text-[var(--am-accent)]">
                                        @if($line->frequency === 'once')
                                            <x-asset-manager-badge color="amber" size="xs" :pill="false" title="Einmalbetrag — fließt nicht in die monatliche Aufteilung">einmalig</x-asset-manager-badge>
                                        @else
                                            {{ number_format((float)$line->monthly_amount, 2, ',', '.') }} €
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5 text-right whitespace-nowrap">
                                        @can('asset-manager.manage')
                                            <button wire:click.stop="toggleActive({{ $line->id }})" class="text-xs text-[var(--am-text-secondary)] hover:text-amber-600" title="Aktiv/Inaktiv">@svg('heroicon-o-power', 'w-4 h-4 inline')</button>
                                        @endcan
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    </div>
                @endif
            </div>

            <div>{{ $lines->links() }}</div>
        </div>
    </div>
</x-ui-page>
