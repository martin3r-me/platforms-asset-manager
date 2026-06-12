<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Kostenstellen', 'icon' => 'clipboard-document-list'],
        ]" />
    </x-slot>

    <div class="flex-1 flex flex-col min-h-0 min-w-0">
        <div class="flex-1 overflow-y-auto p-6 space-y-4 max-w-4xl">

            @if($flash)
                <div class="px-4 py-2 text-xs font-medium text-emerald-700 bg-emerald-500/10 border border-emerald-500/20 rounded-lg">{{ $flash }}</div>
            @endif

            {{-- Anlage --}}
            <div class="flex items-end gap-2 rounded-xl bg-white border border-black/5 shadow-sm p-4">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Neue Kostenstelle (Code)</label>
                    <input type="text" wire:model="newCode" placeholder="z.B. 2599" class="px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white w-40">
                    @error('newCode')<span class="text-[10px] text-red-500">{{ $message }}</span>@enderror
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Gesellschaft</label>
                    <select wire:model="newCompany" class="px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                        <option value="">– keine –</option>
                        @foreach($companies as $co)<option value="{{ $co->id }}">{{ $co->name }}</option>@endforeach
                    </select>
                </div>
                <button wire:click="create" class="px-3 py-1.5 text-xs font-medium text-white bg-violet-600 rounded-lg hover:bg-violet-700">Anlegen</button>
            </div>

            {{-- Liste gruppiert nach Gesellschaft --}}
            @foreach($companies->push(null) as $company)
                @php $rows = $centers[$company?->id] ?? collect(); @endphp
                @if($rows->isNotEmpty())
                    <div class="rounded-xl bg-white/60 border border-black/5 shadow-sm overflow-hidden">
                        <div class="px-4 py-2 bg-[var(--ui-muted-10)] text-xs font-semibold text-[var(--ui-secondary)]">
                            {{ $company?->name ?? 'Ohne Gesellschaft' }}
                        </div>
                        <table class="w-full text-sm">
                            <tbody class="divide-y divide-black/[0.03]">
                                @foreach($rows as $cc)
                                    <tr class="hover:bg-black/[0.02] {{ $cc->is_active ? '' : 'opacity-50' }}">
                                        @if($editId === $cc->id)
                                            <td class="px-4 py-2 font-mono">{{ $cc->code }}</td>
                                            <td class="px-4 py-2" colspan="2">
                                                <div class="flex items-center gap-2">
                                                    <input type="text" wire:model="eName" placeholder="Bezeichnung" class="px-2 py-1 text-xs rounded border border-[var(--ui-border)] bg-white flex-1">
                                                    <select wire:model="eCompany" class="px-2 py-1 text-xs rounded border border-[var(--ui-border)] bg-white">
                                                        <option value="">– keine –</option>
                                                        @foreach($companies->filter() as $co)<option value="{{ $co->id }}">{{ $co->name }}</option>@endforeach
                                                    </select>
                                                    <label class="flex items-center gap-1 text-xs"><input type="checkbox" wire:model="eActive"> aktiv</label>
                                                </div>
                                            </td>
                                            <td class="px-4 py-2 text-right whitespace-nowrap">
                                                <button wire:click="saveEdit" class="text-xs text-violet-600">Speichern</button>
                                                <button wire:click="$set('editId', null)" class="text-xs text-gray-400 ml-2">Abbr.</button>
                                            </td>
                                        @else
                                            <td class="px-4 py-2 font-mono font-medium text-gray-800">{{ $cc->code }}</td>
                                            <td class="px-4 py-2 text-gray-600">{{ $cc->name ?? '—' }}</td>
                                            <td class="px-4 py-2 text-xs text-gray-400">{{ $cc->employees_count }} MA</td>
                                            <td class="px-4 py-2 text-right">
                                                <button wire:click="edit({{ $cc->id }})" class="text-xs text-gray-400 hover:text-violet-600">@svg('heroicon-o-pencil-square', 'w-4 h-4 inline')</button>
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endforeach

        </div>
    </div>
</x-ui-page>
