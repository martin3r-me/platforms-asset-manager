{{-- Read-only Liste Kostenarten + Sortierung + Drag&Drop. Vars: $rows, $selectedId, $sortField, $sortDir, $manualOrder, $total --}}
@php $sourceLabels = ['cost_line' => 'Kostenposition', 'hardware_afa' => 'Hardware-AfA', 'ms_license' => 'MS-Lizenz (Graph)', 'asset_device' => 'Geräte-Kosten']; @endphp
<table class="w-full text-sm">
    <thead>
        <tr class="border-b border-[color:var(--ui-muted)] bg-[color:var(--ui-muted-10)] text-xs uppercase tracking-wider text-[color:var(--ui-body-color)] font-semibold">
            <th class="px-2 py-3 text-center w-12">
                <button wire:click="sortBy('sort_order')" title="Manuelle Reihenfolge" class="hover:text-[color:var(--ui-primary)] {{ $manualOrder ? 'text-[color:var(--ui-primary)]' : '' }}">↕</button>
            </th>
            <th class="text-left px-2 py-3">
                <button wire:click="sortBy('name')" class="inline-flex items-center gap-1 hover:text-[color:var(--ui-primary)]">Kostenart @if($sortField==='name')<span class="text-[color:var(--ui-primary)]">{{ $sortDir==='asc' ? '▲' : '▼' }}</span>@endif</button>
            </th>
            <th class="text-left px-2 py-3">Kreditor</th>
            <th class="text-left px-2 py-3">System</th>
            <th class="text-left px-2 py-3">
                <button wire:click="sortBy('frequency_default')" class="inline-flex items-center gap-1 hover:text-[color:var(--ui-primary)]">Frequenz @if($sortField==='frequency_default')<span class="text-[color:var(--ui-primary)]">{{ $sortDir==='asc' ? '▲' : '▼' }}</span>@endif</button>
            </th>
            <th class="text-left px-2 py-3">
                <button wire:click="sortBy('aggregation_source')" class="inline-flex items-center gap-1 hover:text-[color:var(--ui-primary)]">Quelle @if($sortField==='aggregation_source')<span class="text-[color:var(--ui-primary)]">{{ $sortDir==='asc' ? '▲' : '▼' }}</span>@endif</button>
            </th>
            <th class="text-right px-2 py-3">
                <button wire:click="sortBy('cost_lines_count')" class="inline-flex items-center gap-1 hover:text-[color:var(--ui-primary)]">Pos. @if($sortField==='cost_lines_count')<span class="text-[color:var(--ui-primary)]">{{ $sortDir==='asc' ? '▲' : '▼' }}</span>@endif</button>
            </th>
            <th class="px-2 py-3"></th>
        </tr>
    </thead>
    <tbody class="divide-y divide-[color:var(--ui-muted)]" @if($manualOrder) wire:sortable="reorder" @endif>
        @foreach($rows as $t)
            <tr wire:key="ct-{{ $t->id }}" wire:click="selectRow({{ $t->id }})"
                class="cursor-pointer hover:bg-[color:var(--ui-muted-10)] {{ $selectedId === $t->id ? 'bg-[color:var(--ui-primary-10)] shadow-[inset_3px_0_0_rgb(var(--ui-primary-rgb))]' : '' }}"
                @if($manualOrder) wire:sortable.item="{{ $t->id }}" @endif>
                <td class="px-2 py-2.5 text-center whitespace-nowrap">
                    @if($manualOrder)
                        @can('asset-manager.manage')
                            <button wire:sortable.handle type="button" x-on:click.stop title="Ziehen zum Sortieren" class="text-[color:var(--ui-muted)] hover:text-[color:var(--ui-secondary)] cursor-grab active:cursor-grabbing">@svg('heroicon-o-bars-3', 'w-4 h-4 inline')</button>
                        @else
                            <span class="text-[color:var(--ui-muted)]">–</span>
                        @endcan
                    @else
                        <span class="text-[color:var(--ui-muted)]">–</span>
                    @endif
                </td>
                <td class="px-2 py-2.5 font-medium text-gray-800">{{ $t->name }} <span class="text-[10px] text-[color:var(--ui-muted)] font-mono">{{ $t->key }}</span></td>
                <td class="px-2 py-2.5 text-xs text-[color:var(--ui-secondary)]">{{ $t->vendorDefault?->name ?? '—' }}</td>
                <td class="px-2 py-2.5 text-xs text-[color:var(--ui-secondary)]">{{ $t->system_default ?? '—' }}</td>
                <td class="px-2 py-2.5 text-xs text-[color:var(--ui-secondary)]">{{ ['monthly'=>'mtl.','quarterly'=>'qrtl.','yearly'=>'jähr.','once'=>'einm.'][$t->frequency_default] ?? $t->frequency_default }}</td>
                <td class="px-2 py-2.5">
                    @if($t->aggregation_source === 'cost_line')
                        <x-asset-manager-badge color="violet" size="xs">{{ $sourceLabels[$t->aggregation_source] ?? $t->aggregation_source }}</x-asset-manager-badge>
                    @else
                        <x-asset-manager-badge color="gray" size="xs">{{ $sourceLabels[$t->aggregation_source] ?? $t->aggregation_source }}</x-asset-manager-badge>
                    @endif
                </td>
                <td class="px-2 py-2.5 text-right text-xs text-[color:var(--ui-secondary)]">{{ $t->cost_lines_count }}</td>
                <td class="px-2 py-2.5 text-right whitespace-nowrap">
                    @can('asset-manager.manage')
                        <button wire:click.stop="delete({{ $t->id }})"
                                @if($t->cost_lines_count == 0) wire:confirm="Kostenart {{ $t->name }} wirklich löschen?" @endif
                                class="text-xs text-[color:var(--ui-secondary)] hover:text-red-600">@svg('heroicon-o-trash', 'w-4 h-4 inline')</button>
                    @endcan
                </td>
            </tr>
        @endforeach
        @if($rows->isEmpty())
            <tr>
                <td colspan="8" class="px-4 py-10 text-center">
                    @if(($total ?? 0) === 0)
                        <p class="text-sm text-[color:var(--ui-secondary)] mb-3">Noch keine Kostenarten angelegt. Lege oben eine eigene an — oder lade generische Standard-Kostenarten als Starthilfe.</p>
                        @can('asset-manager.manage')
                            <x-ui-button variant="primary" size="sm" rounded="lg" wire:click="seedDefaults">Standard-Kostenarten laden</x-ui-button>
                        @endcan
                    @else
                        <p class="text-sm text-[color:var(--ui-secondary)]">Keine Kostenarten für diesen Filter — Filter zurücksetzen.</p>
                    @endif
                </td>
            </tr>
        @endif
    </tbody>
</table>
