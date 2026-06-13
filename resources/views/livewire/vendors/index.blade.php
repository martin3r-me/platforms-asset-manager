<div class="rounded-xl bg-white/70 backdrop-blur-sm border border-white/40 shadow-[0_1px_3px_rgba(0,0,0,0.04),0_1px_2px_rgba(0,0,0,0.03)] overflow-hidden">
    <header class="flex items-center gap-2 px-4 py-3 border-b border-[var(--ui-border)]">
        @svg('heroicon-o-building-storefront', 'w-4 h-4 text-[var(--ui-secondary)]')
        <h3 class="text-base font-semibold text-[var(--ui-secondary)] m-0">Kreditoren</h3>
        <span class="text-xs text-[var(--ui-secondary)] bg-[var(--ui-muted-10)] rounded-full px-2 py-0.5">{{ $vendors->count() }}</span>
    </header>

    <div class="p-4 space-y-3">
        @if($flash)
            <div class="px-4 py-2 text-xs font-medium text-emerald-700 bg-emerald-500/10 border border-emerald-500/20 rounded-lg">{{ $flash }}</div>
        @endif

        {{-- Anlage --}}
        <div class="flex items-end gap-2 max-w-xl">
            <div class="flex-1">
                <label class="block text-xs text-gray-500 mb-1">Neuer Kreditor</label>
                <input type="text" wire:model="newName" class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                @error('newName')<span class="text-[10px] text-red-500">{{ $message }}</span>@enderror
            </div>
            <button wire:click="create" class="px-3 py-1.5 text-xs font-medium text-white bg-violet-600 rounded-lg hover:bg-violet-700">Anlegen</button>
        </div>

        {{-- Liste --}}
        <table class="w-full text-sm border-t border-[var(--ui-border)]">
            <tbody class="divide-y divide-black/[0.03]">
                @foreach($vendors as $v)
                    <tr class="hover:bg-black/[0.02]">
                        @if($editId === $v->id)
                            <td class="px-2 py-2" colspan="2">
                                <div class="flex items-center gap-2">
                                    <input type="text" wire:model="eName" class="px-2 py-1 text-sm rounded border border-[var(--ui-border)] bg-white flex-1">
                                    <input type="text" wire:model="eCreditor" placeholder="Kreditor-Nr." class="px-2 py-1 text-sm rounded border border-[var(--ui-border)] bg-white w-32">
                                </div>
                            </td>
                            <td class="px-2 py-2 text-right whitespace-nowrap">
                                <button wire:click="saveEdit" class="text-xs text-violet-600">Speichern</button>
                                <button wire:click="$set('editId', null)" class="text-xs text-gray-400 ml-2">Abbr.</button>
                            </td>
                        @else
                            <td class="px-2 py-2.5 font-medium text-gray-800">{{ $v->name }}@if($v->creditor_no)<span class="text-xs text-gray-400 ml-2">#{{ $v->creditor_no }}</span>@endif</td>
                            <td class="px-2 py-2.5 text-xs text-gray-400">{{ $v->cost_lines_count }} Pos.</td>
                            <td class="px-2 py-2.5 text-right whitespace-nowrap">
                                <button wire:click="edit({{ $v->id }})" class="text-xs text-gray-400 hover:text-violet-600">@svg('heroicon-o-pencil-square', 'w-4 h-4 inline')</button>
                                <button wire:click="delete({{ $v->id }})"
                                        wire:confirm="Kreditor {{ $v->name }} wirklich löschen?{{ $v->cost_lines_count ? ' '.$v->cost_lines_count.' Positionen verlieren den Kreditor.' : '' }}"
                                        class="text-xs text-gray-400 hover:text-red-600 ml-2">@svg('heroicon-o-trash', 'w-4 h-4 inline')</button>
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
