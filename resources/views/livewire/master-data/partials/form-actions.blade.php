{{-- Speichern/Abbrechen/Löschen. Vars: $id (selectedId|null), $confirm, $creating --}}
<div class="flex items-center gap-2 pt-3 mt-1 border-t border-[var(--ui-border)]/30">
    <button wire:click="save" class="flex-1 px-3 py-2 text-xs font-medium text-white bg-violet-600 rounded-lg hover:bg-violet-700">
        {{ $creating ? 'Anlegen' : 'Speichern' }}
    </button>
    <button wire:click="cancelEdit" class="px-3 py-2 text-xs font-medium text-gray-500 bg-black/[0.04] rounded-lg hover:bg-black/[0.07]">
        Abbrechen
    </button>
    @if($id)
        <button wire:click="delete({{ $id }})" wire:confirm="{{ $confirm }}"
                class="px-3 py-2 text-xs font-medium text-red-500 bg-red-500/5 border border-red-500/20 rounded-lg hover:bg-red-500/10"
                title="Löschen">
            @svg('heroicon-o-trash', 'w-3.5 h-3.5')
        </button>
    @endif
</div>
