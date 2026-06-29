{{-- Abschreibung bearbeiten — manuelles Asset. --}}
<x-ui-modal model="showDepreciation" size="md">
    <x-slot name="header">Abschreibung bearbeiten</x-slot>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div>
            <label class="block text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)] mb-1">Kaufdatum</label>
            <input type="date" wire:model="dPurchaseDate" class="w-full px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10" />
            @error('dPurchaseDate') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)] mb-1">Kaufpreis (€)</label>
            <input type="number" step="0.01" min="0" wire:model="dPurchasePrice" class="w-full px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10" />
            @error('dPurchasePrice') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)] mb-1">AfA (Monate)</label>
            <input type="number" min="1" max="240" wire:model="dDepreciationMonths" class="w-full px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10" />
            @error('dDepreciationMonths') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
        </div>
    </div>

    <x-slot name="footer">
        <x-ui-button variant="secondary-outline" size="sm" x-on:click="modalShow = false">Abbrechen</x-ui-button>
        <x-ui-button variant="primary" size="sm" wire:click="saveDepreciation" wire:loading.attr="disabled">
            @svg('heroicon-o-check', 'w-3.5 h-3.5') Speichern
        </x-ui-button>
    </x-slot>
</x-ui-modal>
