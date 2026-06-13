<div class="rounded-xl bg-white/70 backdrop-blur-sm border border-white/40 shadow-[0_1px_3px_rgba(0,0,0,0.04),0_1px_2px_rgba(0,0,0,0.03)] overflow-hidden">
    <header class="flex items-center gap-2 px-4 py-3 border-b border-[var(--ui-border)]">
        @svg('heroicon-o-building-office-2', 'w-4 h-4 text-[var(--ui-secondary)]')
        <h3 class="text-base font-semibold text-[var(--ui-secondary)] m-0">Gesellschaften</h3>
        <span class="text-xs text-[var(--ui-secondary)] bg-[var(--ui-muted-10)] rounded-full px-2 py-0.5">{{ $companies->count() }}</span>
    </header>

    <div class="p-4 space-y-3">
        @if($flash)
            <div class="px-4 py-2 text-xs font-medium text-emerald-700 bg-emerald-500/10 border border-emerald-500/20 rounded-lg">{{ $flash }}</div>
        @endif

        {{-- Anlage --}}
        <div class="flex items-end gap-2">
            <div class="flex-1">
                <label class="block text-xs text-gray-500 mb-1">Neue Gesellschaft</label>
                <input type="text" wire:model="newName" placeholder="z.B. BROICH - RHEIN RUHR" class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                @error('newName')<span class="text-[10px] text-red-500">{{ $message }}</span>@enderror
            </div>
            <button wire:click="create" class="px-3 py-1.5 text-xs font-medium text-white bg-violet-600 rounded-lg hover:bg-violet-700">Anlegen</button>
        </div>

        {{-- Liste --}}
        <table class="w-full text-sm border-t border-[var(--ui-border)]">
            <tbody class="divide-y divide-black/[0.03]">
                @forelse($companies as $co)
                    <tr class="hover:bg-black/[0.02]">
                        @if($editId === $co->id)
                            <td class="px-2 py-2" colspan="2">
                                <div class="flex items-center gap-2">
                                    <input type="text" wire:model="eName" placeholder="Name" class="flex-1 px-2 py-1 text-sm rounded border border-[var(--ui-border)] bg-white">
                                    <label class="text-[10px] text-gray-400">Sortierung</label>
                                    <input type="number" wire:model="eSort" class="w-20 px-2 py-1 text-xs rounded border border-[var(--ui-border)] bg-white">
                                </div>
                                @error('eName')<span class="text-[10px] text-red-500">{{ $message }}</span>@enderror
                            </td>
                            <td class="px-2 py-2 text-right whitespace-nowrap">
                                <button wire:click="saveEdit" class="text-xs text-violet-600">Speichern</button>
                                <button wire:click="$set('editId', null)" class="text-xs text-gray-400 ml-2">Abbr.</button>
                            </td>
                        @else
                            <td class="px-2 py-2.5 font-medium text-gray-800">{{ $co->name }}</td>
                            <td class="px-2 py-2.5 text-xs text-gray-400">{{ $co->cost_centers_count }} Kostenstellen</td>
                            <td class="px-2 py-2.5 text-right whitespace-nowrap">
                                <button wire:click="edit({{ $co->id }})" class="text-xs text-gray-400 hover:text-violet-600">@svg('heroicon-o-pencil-square', 'w-4 h-4 inline')</button>
                                <button wire:click="delete({{ $co->id }})"
                                        wire:confirm="Gesellschaft {{ $co->name }} wirklich löschen?{{ $co->cost_centers_count ? ' '.$co->cost_centers_count.' Kostenstellen verlieren die Zuordnung.' : '' }}"
                                        class="text-xs text-gray-400 hover:text-red-600 ml-2">@svg('heroicon-o-trash', 'w-4 h-4 inline')</button>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr><td class="px-2 py-6 text-center text-xs text-gray-400">Noch keine Gesellschaften angelegt.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
