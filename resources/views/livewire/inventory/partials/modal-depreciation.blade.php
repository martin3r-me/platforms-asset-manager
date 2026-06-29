{{-- Abschreibung bearbeiten — manuelles Asset. --}}
<x-ui-modal model="showDepreciation" size="md">
    <x-slot name="header">Abschreibung bearbeiten</x-slot>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div>
            <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Kaufdatum</label>
            <x-asset-manager-input size="sm" type="date" wire:model="dPurchaseDate" />
            @error('dPurchaseDate') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Kaufpreis (€)</label>
            <x-asset-manager-input size="sm" type="number" step="0.01" min="0" wire:model="dPurchasePrice" />
            @error('dPurchasePrice') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">AfA (Monate)</label>
            <x-asset-manager-input size="sm" type="number" min="1" max="240" wire:model="dDepreciationMonths" />
            @error('dDepreciationMonths') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
        </div>
    </div>

    <x-slot name="footer">
        <x-asset-manager-button variant="ghost" size="sm" x-on:click="modalShow = false">Abbrechen</x-asset-manager-button>
        <x-asset-manager-button variant="primary" size="sm" wire:click="saveDepreciation" wire:loading.attr="disabled">
            @svg('heroicon-o-check', 'w-3.5 h-3.5') Speichern
        </x-asset-manager-button>
    </x-slot>
</x-ui-modal>
