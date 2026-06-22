{{-- Read-only Liste Kostenarten + Sortierung + Drag&Drop. Vars: $rows, $selectedId, $sortField, $sortDir, $manualOrder, $total --}}
@php $sourceLabels = ['cost_line' => 'Kostenposition', 'hardware_afa' => 'Hardware-AfA', 'ms_license' => 'MS-Lizenz (Graph)', 'asset_device' => 'Geräte-Kosten']; @endphp
<table class="w-full text-sm">
    <thead>
        <tr class="border-b border-black/5 text-xs uppercase tracking-wider text-gray-400">
            <th class="px-2 py-3 text-center w-12">
                <button wire:click="sortBy('sort_order')" title="Manuelle Reihenfolge" class="hover:text-violet-600 {{ $manualOrder ? 'text-violet-500' : '' }}">↕</button>
            </th>
            <th class="text-left px-2 py-3">
                <button wire:click="sortBy('name')" class="inline-flex items-center gap-1 hover:text-violet-600">Kostenart @if($sortField==='name')<span class="text-violet-500">{{ $sortDir==='asc' ? '▲' : '▼' }}</span>@endif</button>
            </th>
            <th class="text-left px-2 py-3">Kreditor</th>
            <th class="text-left px-2 py-3">System</th>
            <th class="text-left px-2 py-3">
                <button wire:click="sortBy('frequency_default')" class="inline-flex items-center gap-1 hover:text-violet-600">Frequenz @if($sortField==='frequency_default')<span class="text-violet-500">{{ $sortDir==='asc' ? '▲' : '▼' }}</span>@endif</button>
            </th>
            <th class="text-left px-2 py-3">
                <button wire:click="sortBy('aggregation_source')" class="inline-flex items-center gap-1 hover:text-violet-600">Quelle @if($sortField==='aggregation_source')<span class="text-violet-500">{{ $sortDir==='asc' ? '▲' : '▼' }}</span>@endif</button>
            </th>
            <th class="text-right px-2 py-3">
                <button wire:click="sortBy('cost_lines_count')" class="inline-flex items-center gap-1 hover:text-violet-600">Pos. @if($sortField==='cost_lines_count')<span class="text-violet-500">{{ $sortDir==='asc' ? '▲' : '▼' }}</span>@endif</button>
            </th>
            <th class="px-2 py-3"></th>
        </tr>
    </thead>
    <tbody class="divide-y divide-black/[0.03]" @if($manualOrder) wire:sortable="reorder" @endif>
        @foreach($rows as $t)
            <tr wire:key="ct-{{ $t->id }}" wire:click="selectRow({{ $t->id }})"
                class="cursor-pointer hover:bg-black/[0.02] {{ $selectedId === $t->id ? 'bg-violet-500/10' : '' }}"
                @if($manualOrder) wire:sortable.item="{{ $t->id }}" @endif>
                <td class="px-2 py-2.5 text-center whitespace-nowrap">
                    @if($manualOrder)
                        @can('asset-manager.manage')
                            <button wire:sortable.handle type="button" x-on:click.stop title="Ziehen zum Sortieren" class="text-gray-300 hover:text-gray-500 cursor-grab active:cursor-grabbing">@svg('heroicon-o-bars-3', 'w-4 h-4 inline')</button>
                        @else
                            <span class="text-gray-200">–</span>
                        @endcan
                    @else
                        <span class="text-gray-200">–</span>
                    @endif
                </td>
                <td class="px-2 py-2.5 font-medium text-gray-800">{{ $t->name }} <span class="text-[10px] text-gray-300 font-mono">{{ $t->key }}</span></td>
                <td class="px-2 py-2.5 text-xs text-gray-500">{{ $t->vendorDefault?->name ?? '—' }}</td>
                <td class="px-2 py-2.5 text-xs text-gray-500">{{ $t->system_default ?? '—' }}</td>
                <td class="px-2 py-2.5 text-xs text-gray-500">{{ ['monthly'=>'mtl.','quarterly'=>'qrtl.','yearly'=>'jähr.','once'=>'einm.'][$t->frequency_default] ?? $t->frequency_default }}</td>
                <td class="px-2 py-2.5">
                    <span class="inline-block px-2 py-0.5 text-[10px] rounded-full {{ $t->aggregation_source === 'cost_line' ? 'bg-violet-500/10 text-violet-600' : 'bg-gray-500/10 text-gray-500' }}">{{ $sourceLabels[$t->aggregation_source] ?? $t->aggregation_source }}</span>
                </td>
                <td class="px-2 py-2.5 text-right text-xs text-gray-400">{{ $t->cost_lines_count }}</td>
                <td class="px-2 py-2.5 text-right whitespace-nowrap">
                    @can('asset-manager.manage')
                        <button wire:click.stop="delete({{ $t->id }})"
                                @if($t->cost_lines_count == 0) wire:confirm="Kostenart {{ $t->name }} wirklich löschen?" @endif
                                class="text-xs text-gray-400 hover:text-red-600">@svg('heroicon-o-trash', 'w-4 h-4 inline')</button>
                    @endcan
                </td>
            </tr>
        @endforeach
        @if($rows->isEmpty())
            <tr>
                <td colspan="8" class="px-4 py-10 text-center">
                    @if(($total ?? 0) === 0)
                        <p class="text-sm text-gray-400 mb-3">Noch keine Kostenarten angelegt. Lege oben eine eigene an — oder lade generische Standard-Kostenarten als Starthilfe.</p>
                        @can('asset-manager.manage')
                            <button wire:click="seedDefaults" class="px-3 py-1.5 text-xs font-medium text-white bg-violet-600 rounded-lg hover:bg-violet-700">Standard-Kostenarten laden</button>
                        @endcan
                    @else
                        <p class="text-sm text-gray-400">Keine Kostenarten für diesen Filter — Filter zurücksetzen.</p>
                    @endif
                </td>
            </tr>
        @endif
    </tbody>
</table>
