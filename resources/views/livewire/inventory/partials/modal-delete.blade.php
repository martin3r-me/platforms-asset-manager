{{-- Löschen-Bestätigung — nur manuelle Assets (Intune per Policy blockiert). --}}
<x-ui-modal model="showDelete" size="sm">
    <x-slot name="header">Asset löschen</x-slot>

    <div class="space-y-3">
        <div class="flex items-start gap-3">
            <div class="flex-shrink-0 w-9 h-9 rounded-lg bg-red-500/10 flex items-center justify-center">
                @svg('heroicon-o-exclamation-triangle', 'w-5 h-5 text-red-600')
            </div>
            <div>
                <p class="text-sm font-medium text-gray-900 dark:text-gray-100">Wirklich löschen?</p>
                @if($item)
                    <p class="text-xs text-[color:var(--ui-secondary)] mt-1">„{{ $item->name }}" wird entfernt. Die Zuordnungs-Historie geht mit verloren.</p>
                @endif
            </div>
        </div>
    </div>

    <x-slot name="footer">
        <x-ui-button variant="secondary-outline" size="sm" x-on:click="modalShow = false">Abbrechen</x-ui-button>
        <x-ui-button variant="danger" size="sm" wire:click="deleteItem" wire:loading.attr="disabled">
            @svg('heroicon-o-trash', 'w-3.5 h-3.5') Löschen
        </x-ui-button>
    </x-slot>
</x-ui-modal>
