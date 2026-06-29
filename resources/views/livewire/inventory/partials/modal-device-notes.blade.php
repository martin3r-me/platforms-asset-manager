{{-- Gerät: operative Freitext-Notiz (Phase 4). --}}
<x-ui-modal model="showDeviceNotes" size="md">
    <x-slot name="header">Notizen bearbeiten</x-slot>

    <div>
        <label class="block text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)] mb-1">Notizen</label>
        <textarea wire:model="gnNotes" rows="6" class="w-full px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10 resize-none"></textarea>
        @error('gnNotes') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
    </div>

    <x-slot name="footer">
        <x-ui-button variant="secondary-outline" size="sm" x-on:click="modalShow = false">Abbrechen</x-ui-button>
        <x-ui-button variant="primary" size="sm" wire:click="saveDeviceNotes" wire:loading.attr="disabled">
            @svg('heroicon-o-check', 'w-3.5 h-3.5') Speichern
        </x-ui-button>
    </x-slot>
</x-ui-modal>
