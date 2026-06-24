{{-- Speichern/Abbrechen/Löschen. Vars: $id (selectedId|null), $confirm, $creating --}}
<div class="flex items-center gap-2 pt-3 mt-1 border-t border-[var(--ui-border)]/30">
    <x-ui-button variant="primary" size="md" rounded="lg" class="flex-1" wire:click="save">
        {{ $creating ? 'Anlegen' : 'Speichern' }}
    </x-ui-button>
    <x-ui-button variant="secondary-ghost" size="sm" rounded="lg" wire:click="cancelEdit">
        Abbrechen
    </x-ui-button>
    @if($id)
        <x-ui-button variant="danger-ghost" size="sm" rounded="lg" wire:click="delete({{ $id }})" wire:confirm="{{ $confirm }}" title="Löschen">
            @svg('heroicon-o-trash', 'w-3.5 h-3.5')
        </x-ui-button>
    @endif
</div>
