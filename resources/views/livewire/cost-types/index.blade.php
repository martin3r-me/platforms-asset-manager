<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Kostenarten', 'icon' => 'tag'],
        ]" />
    </x-slot>

    <div class="flex-1 flex flex-col min-h-0 min-w-0">
        <div class="flex-1 overflow-y-auto p-6 space-y-4">

            @if($flash)
                <div class="px-4 py-2 text-xs font-medium text-emerald-700 bg-emerald-500/10 border border-emerald-500/20 rounded-lg">{{ $flash }}</div>
            @endif

            @php $sourceLabels = ['cost_line' => 'Kostenposition', 'hardware_afa' => 'Hardware-AfA', 'ms_license' => 'MS-Lizenz (Graph)']; @endphp

            <div class="rounded-xl bg-white/60 border border-black/5 shadow-sm overflow-hidden">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-black/5 text-xs uppercase tracking-wider text-gray-400">
                            <th class="text-left px-4 py-3">Kostenart</th>
                            <th class="text-left px-4 py-3">Kreditor (Standard)</th>
                            <th class="text-left px-4 py-3">System</th>
                            <th class="text-left px-4 py-3">Frequenz</th>
                            <th class="text-left px-4 py-3">Quelle</th>
                            <th class="text-right px-4 py-3">Pos.</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-black/[0.03]">
                        @foreach($types as $t)
                            <tr class="hover:bg-black/[0.02]">
                                @if($editId === $t->id)
                                    <td class="px-4 py-2"><input type="text" wire:model="eName" class="px-2 py-1 text-sm rounded border border-[var(--ui-border)] bg-white w-full"></td>
                                    <td class="px-4 py-2">
                                        <select wire:model="eVendor" class="px-2 py-1 text-xs rounded border border-[var(--ui-border)] bg-white">
                                            <option value="">– keiner –</option>
                                            @foreach($vendors as $v)<option value="{{ $v->id }}">{{ $v->name }}</option>@endforeach
                                        </select>
                                    </td>
                                    <td class="px-4 py-2">
                                        <select wire:model="eSystem" class="px-2 py-1 text-xs rounded border border-[var(--ui-border)] bg-white">
                                            <option value="">–</option><option value="HGK">HGK</option><option value="Moss">Moss</option>
                                        </select>
                                    </td>
                                    <td class="px-4 py-2">
                                        <select wire:model="eFrequency" class="px-2 py-1 text-xs rounded border border-[var(--ui-border)] bg-white">
                                            <option value="monthly">mtl.</option><option value="quarterly">qrtl.</option><option value="yearly">jähr.</option><option value="once">einm.</option>
                                        </select>
                                    </td>
                                    <td class="px-4 py-2 text-xs text-gray-400">{{ $sourceLabels[$t->aggregation_source] ?? $t->aggregation_source }}</td>
                                    <td class="px-4 py-2 text-right">
                                        <label class="text-[10px] text-gray-500"><input type="checkbox" wire:model="ePerEmployee"> /MA</label>
                                    </td>
                                    <td class="px-4 py-2 text-right whitespace-nowrap">
                                        <button wire:click="saveEdit" class="text-xs text-violet-600">Speichern</button>
                                        <button wire:click="$set('editId', null)" class="text-xs text-gray-400 ml-1">Abbr.</button>
                                    </td>
                                @else
                                    <td class="px-4 py-2.5 font-medium text-gray-800">{{ $t->name }} <span class="text-[10px] text-gray-300 font-mono">{{ $t->key }}</span></td>
                                    <td class="px-4 py-2.5 text-xs text-gray-500">{{ $t->vendorDefault?->name ?? '—' }}</td>
                                    <td class="px-4 py-2.5 text-xs text-gray-500">{{ $t->system_default ?? '—' }}</td>
                                    <td class="px-4 py-2.5 text-xs text-gray-500">{{ ['monthly'=>'mtl.','quarterly'=>'qrtl.','yearly'=>'jähr.','once'=>'einm.'][$t->frequency_default] ?? $t->frequency_default }}</td>
                                    <td class="px-4 py-2.5">
                                        <span class="inline-block px-2 py-0.5 text-[10px] rounded-full {{ $t->aggregation_source === 'cost_line' ? 'bg-violet-500/10 text-violet-600' : 'bg-gray-500/10 text-gray-500' }}">{{ $sourceLabels[$t->aggregation_source] ?? $t->aggregation_source }}</span>
                                    </td>
                                    <td class="px-4 py-2.5 text-right text-xs text-gray-400">{{ $t->cost_lines_count }}</td>
                                    <td class="px-4 py-2.5 text-right">
                                        <button wire:click="edit({{ $t->id }})" class="text-xs text-gray-400 hover:text-violet-600">@svg('heroicon-o-pencil-square', 'w-4 h-4 inline')</button>
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
