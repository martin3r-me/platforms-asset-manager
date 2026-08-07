{{-- Anlegen/Bearbeiten einer Kostenposition. Lag bis S8b in einer zugeklappten rechten Sidebar —
     ein Formular gehört in den Vordergrund, nicht hinter einen zweiten Klick (ADR 0012). --}}
<x-ui-modal model="showEditor" size="lg">
    <x-slot name="header">{{ $editId ? 'Position bearbeiten' : 'Neue Position' }}</x-slot>

    <div class="space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Kostenart *</label>
                <x-asset-manager-select size="sm" wire:model="fCostType">
                    <option value="">– wählen –</option>
                    @foreach($costTypes as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
                </x-asset-manager-select>
                @error('fCostType') <p class="text-[10px] text-[var(--am-danger)] mt-0.5">{{ $message }}</p> @enderror
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
            @error('fLabel') <p class="text-[10px] text-[var(--am-danger)] mt-0.5">{{ $message }}</p> @enderror
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
                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Betrag (€) *</label>
                <x-asset-manager-input size="sm" type="number" step="0.01" wire:model="fAmount" />
                @error('fAmount') <p class="text-[10px] text-[var(--am-danger)] mt-0.5">{{ $message }}</p> @enderror
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
