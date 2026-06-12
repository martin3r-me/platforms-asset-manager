<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Kreditoren', 'icon' => 'building-storefront'],
        ]" />
    </x-slot>

    <div class="flex-1 flex flex-col min-h-0 min-w-0">
        <div class="flex-1 overflow-y-auto p-6 space-y-4 max-w-2xl">

            @if($flash)
                <div class="px-4 py-2 text-xs font-medium text-emerald-700 bg-emerald-500/10 border border-emerald-500/20 rounded-lg">{{ $flash }}</div>
            @endif

            <div class="flex items-end gap-2 rounded-xl bg-white border border-black/5 shadow-sm p-4">
                <div class="flex-1">
                    <label class="block text-xs text-gray-500 mb-1">Neuer Kreditor</label>
                    <input type="text" wire:model="newName" class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                    @error('newName')<span class="text-[10px] text-red-500">{{ $message }}</span>@enderror
                </div>
                <button wire:click="create" class="px-3 py-1.5 text-xs font-medium text-white bg-violet-600 rounded-lg hover:bg-violet-700">Anlegen</button>
            </div>

            <div class="rounded-xl bg-white/60 border border-black/5 shadow-sm overflow-hidden">
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-black/[0.03]">
                        @foreach($vendors as $v)
                            <tr class="hover:bg-black/[0.02]">
                                @if($editId === $v->id)
                                    <td class="px-4 py-2" colspan="2">
                                        <div class="flex items-center gap-2">
                                            <input type="text" wire:model="eName" class="px-2 py-1 text-sm rounded border border-[var(--ui-border)] bg-white flex-1">
                                            <input type="text" wire:model="eCreditor" placeholder="Kreditor-Nr." class="px-2 py-1 text-sm rounded border border-[var(--ui-border)] bg-white w-32">
                                        </div>
                                    </td>
                                    <td class="px-4 py-2 text-right whitespace-nowrap">
                                        <button wire:click="saveEdit" class="text-xs text-violet-600">Speichern</button>
                                        <button wire:click="$set('editId', null)" class="text-xs text-gray-400 ml-2">Abbr.</button>
                                    </td>
                                @else
                                    <td class="px-4 py-2.5 font-medium text-gray-800">{{ $v->name }}@if($v->creditor_no)<span class="text-xs text-gray-400 ml-2">#{{ $v->creditor_no }}</span>@endif</td>
                                    <td class="px-4 py-2.5 text-xs text-gray-400">{{ $v->cost_lines_count }} Pos.</td>
                                    <td class="px-4 py-2.5 text-right">
                                        <button wire:click="edit({{ $v->id }})" class="text-xs text-gray-400 hover:text-violet-600">@svg('heroicon-o-pencil-square', 'w-4 h-4 inline')</button>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-ui-page>
