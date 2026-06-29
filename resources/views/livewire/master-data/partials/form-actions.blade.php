{{-- Speichern/Abbrechen/Löschen. Vars: $id (selectedId|null), $confirm, $creating --}}
<div class="flex items-center gap-2 pt-3 mt-1 border-t border-[color:var(--am-border)]">
    <x-asset-manager-button variant="primary" size="md" class="flex-1" wire:click="save">
        {{ $creating ? 'Anlegen' : 'Speichern' }}
    </x-asset-manager-button>
    <x-asset-manager-button variant="ghost" size="sm" wire:click="cancelEdit">
        Abbrechen
    </x-asset-manager-button>
    @if($id)
        <x-asset-manager-button variant="danger" size="sm" wire:click="delete({{ $id }})" wire:confirm="{{ $confirm }}" title="Löschen">
            @svg('heroicon-o-trash', 'w-3.5 h-3.5')
        </x-asset-manager-button>
    @endif
</div>
