{{-- Gerät: operative Freitext-Notiz (Phase 4). --}}
<x-ui-modal model="showDeviceNotes" size="md">
    <x-slot name="header">Notizen bearbeiten</x-slot>

    <div>
        <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Notizen</label>
        <x-asset-manager-textarea wire:model="gnNotes" rows="6" class="resize-none" />
        @error('gnNotes') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
    </div>

    <x-slot name="footer">
        <x-asset-manager-button variant="ghost" size="sm" x-on:click="modalShow = false">Abbrechen</x-asset-manager-button>
        <x-asset-manager-button variant="primary" size="sm" wire:click="saveDeviceNotes" wire:loading.attr="disabled">
            @svg('heroicon-o-check', 'w-3.5 h-3.5') Speichern
        </x-asset-manager-button>
    </x-slot>
</x-ui-modal>
