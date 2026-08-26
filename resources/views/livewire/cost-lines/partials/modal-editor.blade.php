{{-- Anlegen/Bearbeiten einer Kostenposition. Lag bis S8b in einer zugeklappten rechten Sidebar —
     ein Formular gehört in den Vordergrund, nicht hinter einen zweiten Klick (ADR 0012). --}}
@php
    // Für den Bezugsebenen-Hinweis: die Kostenart entscheidet, ob ein Träger fachlich passt.
    $chosenType  = $fCostType ? $costTypes->firstWhere('id', (int) $fCostType) : null;
    $isPerPerson = $chosenType?->allocation_level === \Platform\AssetManager\Models\AssetCostType::LEVEL_PERSON;
@endphp

<x-ui-modal model="showEditor" size="lg">
    <x-slot name="header">{{ $editId ? 'Position bearbeiten' : 'Neue Position' }}</x-slot>

    <div class="space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Kostenart *</label>
                <x-asset-manager-select size="sm" wire:model.live="fCostType">
                    <option value="">– wählen –</option>
                    @foreach($costTypes as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
                </x-asset-manager-select>
                @error('fCostType') <p class="text-[10px] text-[var(--am-error)] mt-0.5">{{ $message }}</p> @enderror
                @if($chosenType)
                    <p class="text-[10px] text-[var(--am-text-muted)] mt-0.5">Bezugsebene: {{ $chosenType->levelLabel() }}</p>
                @endif
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Kostenstelle (Code)</label>
                <x-asset-manager-input size="sm" list="cc-list" wire:model="fCostCenter" />
                <datalist id="cc-list">@foreach($costCenters as $c)<option value="{{ $c->code }}">@endforeach</datalist>
            </div>
        </div>

        <div>
            <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Bezeichnung *</label>
            <x-asset-manager-input size="sm" type="text" wire:model="fLabel" />
            @error('fLabel') <p class="text-[10px] text-[var(--am-error)] mt-0.5">{{ $message }}</p> @enderror
        </div>

        {{-- Asset-Träger: Suchfeld statt Select (es können hunderte sein). Der Hinweis unten ist
             absichtlich KEIN Validierungsfehler — 21 der 575 Bestandszeilen verletzen die Regel,
             ein hartes Verbot machte die Seite für ihre eigene Datenlage unbenutzbar. --}}
        <div>
            <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Asset-Träger</label>

            @if($this->selectedHolder)
                <div class="flex items-center gap-2">
                    <span class="inline-flex items-center gap-1.5 px-2 py-1 rounded-lg bg-[var(--am-bg)] border border-[color:var(--am-border)] text-xs text-[var(--am-text)]">
                        {{ $this->selectedHolder->name }}
                        @unless($this->selectedHolder->is_active)
                            <x-asset-manager-badge color="gray" size="xs" :pill="false">inaktiv</x-asset-manager-badge>
                        @endunless
                    </span>
                    <button type="button" wire:click="clearHolder"
                            class="text-xs text-[var(--am-text-secondary)] hover:text-[var(--am-error)]">entfernen</button>
                </div>
            @else
                <x-asset-manager-input size="sm" type="text" wire:model.live.debounce.300ms="fHolderSearch"
                                       placeholder="Name oder UPN suchen…" />
                @if($fHolderSearch !== '')
                    <div class="mt-1 max-h-40 overflow-y-auto rounded-lg border border-[color:var(--am-border)] divide-y divide-[color:var(--am-border)]">
                        @forelse($this->holderOptions as $holder)
                            <button type="button" wire:click="chooseHolder({{ $holder->id }})"
                                    class="w-full text-left px-2 py-1.5 text-xs text-[var(--am-text)] hover:bg-[var(--am-bg)]">
                                {{ $holder->name }}
                                @unless($holder->is_active)
                                    <span class="text-[var(--am-text-muted)]">· inaktiv</span>
                                @endunless
                            </button>
                        @empty
                            <p class="px-2 py-1.5 text-xs text-[var(--am-text-muted)] m-0">Keine Treffer.</p>
                        @endforelse
                    </div>
                @endif
            @endif

            @error('fAssignee') <p class="text-[10px] text-[var(--am-error)] mt-0.5">{{ $message }}</p> @enderror

            @if($chosenType && $isPerPerson && ! $fAssignee)
                <p class="flex items-start gap-1 text-[10px] text-amber-700 mt-1 m-0">
                    @svg('heroicon-o-exclamation-triangle', 'w-3 h-3 flex-shrink-0 mt-px')
                    Diese Kostenart fällt je Person an — ohne Träger erscheint der Betrag in der
                    Auswertung je Person nicht.
                </p>
            @elseif($chosenType && ! $isPerPerson && $fAssignee)
                <p class="flex items-start gap-1 text-[10px] text-amber-700 mt-1 m-0">
                    @svg('heroicon-o-exclamation-triangle', 'w-3 h-3 flex-shrink-0 mt-px')
                    Diese Kostenart wird nur einer Kostenstelle zugeschlüsselt — die Zuordnung an eine
                    Person lässt die Kosten dort auftauchen, wo sie nicht hingehören.
                </p>
            @endif
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Kreditor</label>
                <x-asset-manager-select size="sm" wire:model="fVendor">
                    <option value="">– Standard –</option>
                    @foreach($vendors as $v)<option value="{{ $v->id }}">{{ $v->name }}</option>@endforeach
                </x-asset-manager-select>
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Betrag *</label>
                <x-asset-manager-input size="sm" type="number" step="0.01" wire:model="fAmount" />
                @error('fAmount') <p class="text-[10px] text-[var(--am-error)] mt-0.5">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Frequenz</label>
                <x-asset-manager-select size="sm" wire:model="fFrequency">
                    <option value="monthly">Monatlich</option>
                    <option value="quarterly">Quartal</option>
                    <option value="yearly">Jährlich</option>
                    <option value="once">Einmalig</option>
                </x-asset-manager-select>
            </div>
        </div>

        {{-- Währung + FX-Kurs: bis zum Redesign schrieb save() hart 'EUR' und nie einen Kurs —
             die USD-Posten (ChatGPT) waren über die Oberfläche gar nicht erfassbar, obwohl
             ADR 0002 genau das vorsieht. Kurs ist für Nicht-EUR Pflicht (sonst stille 1:1-Wertung). --}}
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Währung</label>
                <x-asset-manager-input size="sm" type="text" maxlength="3" wire:model.live="fCurrency" />
                @error('fCurrency') <p class="text-[10px] text-[var(--am-error)] mt-0.5">{{ $message }}</p> @enderror
            </div>
            @if(strtoupper(trim($fCurrency)) !== 'EUR')
                <div class="sm:col-span-2">
                    <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">FX-Kurs → EUR *</label>
                    <x-asset-manager-input size="sm" type="number" step="0.000001" wire:model="fFxRate" />
                    @error('fFxRate') <p class="text-[10px] text-[var(--am-error)] mt-0.5">{{ $message }}</p> @enderror
                    <p class="text-[10px] text-[var(--am-text-muted)] mt-0.5">
                        Der Kurs wird eingefroren (ADR 0002) — kein Live-Bezug, keine automatische Aktualisierung.
                    </p>
                </div>
            @endif
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Gültig ab</label>
                <x-asset-manager-input size="sm" type="date" wire:model="fValidFrom" />
                @error('fValidFrom') <p class="text-[10px] text-[var(--am-error)] mt-0.5">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Gültig bis</label>
                <x-asset-manager-input size="sm" type="date" wire:model="fValidTo" />
                @error('fValidTo') <p class="text-[10px] text-[var(--am-error)] mt-0.5">{{ $message }}</p> @enderror
                <p class="text-[10px] text-[var(--am-text-muted)] mt-0.5">Leer = unbefristet.</p>
            </div>
        </div>

        <label class="flex items-center gap-2 text-sm text-[var(--am-text-secondary)]">
            <input type="checkbox" wire:model="fActive" class="rounded"> Aktiv
        </label>
    </div>

    <x-slot name="footer">
        @if($editId)
            <x-asset-manager-button variant="danger" size="sm" wire:click="delete({{ $editId }})" wire:confirm="Position löschen?" class="mr-auto">
                @svg('heroicon-o-trash', 'w-3.5 h-3.5')
                Löschen
            </x-asset-manager-button>
        @endif
        <x-asset-manager-button variant="secondary" size="sm" wire:click="cancelEdit">Abbrechen</x-asset-manager-button>
        <x-asset-manager-button variant="primary" size="sm" wire:click="save" wire:loading.attr="disabled">
            @svg('heroicon-o-check', 'w-3.5 h-3.5')
            {{ $editId ? 'Speichern' : 'Anlegen' }}
        </x-asset-manager-button>
    </x-slot>
</x-ui-modal>
