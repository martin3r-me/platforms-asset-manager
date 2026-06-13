<div class="rounded-xl bg-white/70 backdrop-blur-sm border border-white/40 shadow-[0_1px_3px_rgba(0,0,0,0.04),0_1px_2px_rgba(0,0,0,0.03)] overflow-hidden">
    <header class="flex items-center gap-2 px-4 py-3 border-b border-[var(--ui-border)]">
        @svg('heroicon-o-tag', 'w-4 h-4 text-[var(--ui-secondary)]')
        <h3 class="text-base font-semibold text-[var(--ui-secondary)] m-0">Kostenarten</h3>
        <span class="text-xs text-[var(--ui-secondary)] bg-[var(--ui-muted-10)] rounded-full px-2 py-0.5">{{ $types->count() }}</span>
    </header>

    <div class="p-4 space-y-3">
        @if($flash)
            <div class="px-4 py-2 text-xs font-medium text-emerald-700 bg-emerald-500/10 border border-emerald-500/20 rounded-lg">{{ $flash }}</div>
        @endif

        @php $sourceLabels = ['cost_line' => 'Kostenposition', 'hardware_afa' => 'Hardware-AfA', 'ms_license' => 'MS-Lizenz (Graph)', 'asset_device' => 'Geräte-Kosten']; @endphp

        {{-- Anlage --}}
        <div class="flex items-end gap-2 flex-wrap">
            <div class="flex-1 min-w-[200px]">
                <label class="block text-xs text-gray-500 mb-1">Neue Kostenart</label>
                <input type="text" wire:model="newName" placeholder="z.B. Microsoft 365 Business Premium" class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                @error('newName')<span class="text-[10px] text-red-500">{{ $message }}</span>@enderror
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Frequenz</label>
                <select wire:model="newFrequency" class="px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                    <option value="monthly">mtl.</option><option value="quarterly">qrtl.</option><option value="yearly">jähr.</option><option value="once">einm.</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Quelle</label>
                <select wire:model="newAggSource" class="px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                    <option value="cost_line">Kostenposition</option>
                    <option value="hardware_afa">Hardware-AfA</option>
                    <option value="ms_license">MS-Lizenz (Graph)</option>
                    <option value="asset_device">Geräte-Kosten</option>
                </select>
            </div>
            <button wire:click="create" class="px-3 py-1.5 text-xs font-medium text-white bg-violet-600 rounded-lg hover:bg-violet-700">Anlegen</button>
        </div>

        {{-- Tabelle --}}
        <table class="w-full text-sm border-t border-[var(--ui-border)]">
            <thead>
                <tr class="border-b border-black/5 text-xs uppercase tracking-wider text-gray-400">
                    <th class="px-2 py-3 text-center w-12">
                        <button wire:click="sortBy('sort_order')" title="Manuelle Reihenfolge" class="hover:text-violet-600 {{ $manualOrder ? 'text-violet-500' : '' }}">↕</button>
                    </th>
                    <th class="text-left px-2 py-3">
                        <button wire:click="sortBy('name')" class="inline-flex items-center gap-1 hover:text-violet-600">Kostenart @if($sortField==='name')<span class="text-violet-500">{{ $sortDir==='asc' ? '▲' : '▼' }}</span>@endif</button>
                    </th>
                    <th class="text-left px-2 py-3">Kreditor (Standard)</th>
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
                @foreach($types as $t)
                    <tr class="hover:bg-black/[0.02]" wire:key="ct-{{ $t->id }}" @if($manualOrder) wire:sortable.item="{{ $t->id }}" @endif>
                        @if($editId === $t->id)
                            <td class="px-2 py-2"></td>
                            <td class="px-2 py-2"><input type="text" wire:model="eName" class="px-2 py-1 text-sm rounded border border-[var(--ui-border)] bg-white w-full"></td>
                            <td class="px-2 py-2">
                                <select wire:model="eVendor" class="px-2 py-1 text-xs rounded border border-[var(--ui-border)] bg-white">
                                    <option value="">– keiner –</option>
                                    @foreach($vendors as $v)<option value="{{ $v->id }}">{{ $v->name }}</option>@endforeach
                                </select>
                            </td>
                            <td class="px-2 py-2">
                                <select wire:model="eSystem" class="px-2 py-1 text-xs rounded border border-[var(--ui-border)] bg-white">
                                    <option value="">–</option><option value="HGK">HGK</option><option value="Moss">Moss</option>
                                </select>
                            </td>
                            <td class="px-2 py-2">
                                <select wire:model="eFrequency" class="px-2 py-1 text-xs rounded border border-[var(--ui-border)] bg-white">
                                    <option value="monthly">mtl.</option><option value="quarterly">qrtl.</option><option value="yearly">jähr.</option><option value="once">einm.</option>
                                </select>
                            </td>
                            <td class="px-2 py-2">
                                <select wire:model="eAggSource" class="px-2 py-1 text-xs rounded border border-[var(--ui-border)] bg-white">
                                    <option value="cost_line">Kostenposition</option>
                                    <option value="hardware_afa">Hardware-AfA</option>
                                    <option value="ms_license">MS-Lizenz (Graph)</option>
                                    <option value="asset_device">Geräte-Kosten</option>
                                </select>
                            </td>
                            <td class="px-2 py-2 text-right">
                                <label class="text-[10px] text-gray-500"><input type="checkbox" wire:model="ePerEmployee"> /MA</label>
                            </td>
                            <td class="px-2 py-2 text-right whitespace-nowrap">
                                <button wire:click="saveEdit" class="text-xs text-violet-600">Speichern</button>
                                <button wire:click="$set('editId', null)" class="text-xs text-gray-400 ml-1">Abbr.</button>
                            </td>
                        @else
                            <td class="px-2 py-2.5 text-center whitespace-nowrap">
                                @if($manualOrder)
                                    <button wire:sortable.handle type="button" title="Ziehen zum Sortieren" class="text-gray-300 hover:text-gray-500 cursor-grab active:cursor-grabbing">@svg('heroicon-o-bars-3', 'w-4 h-4 inline')</button>
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
                                <button wire:click="edit({{ $t->id }})" class="text-xs text-gray-400 hover:text-violet-600">@svg('heroicon-o-pencil-square', 'w-4 h-4 inline')</button>
                                <button wire:click="delete({{ $t->id }})"
                                        @if($t->cost_lines_count == 0) wire:confirm="Kostenart {{ $t->name }} wirklich löschen?" @endif
                                        class="text-xs text-gray-400 hover:text-red-600 ml-2">@svg('heroicon-o-trash', 'w-4 h-4 inline')</button>
                            </td>
                        @endif
                    </tr>
                @endforeach
                @if($types->isEmpty())
                    <tr>
                        <td colspan="8" class="px-4 py-10 text-center">
                            <p class="text-sm text-gray-400 mb-3">Noch keine Kostenarten angelegt. Lege oben eine eigene an — oder lade generische Standard-Kostenarten als Starthilfe.</p>
                            <button wire:click="seedDefaults" class="px-3 py-1.5 text-xs font-medium text-white bg-violet-600 rounded-lg hover:bg-violet-700">Standard-Kostenarten laden</button>
                        </td>
                    </tr>
                @endif
            </tbody>
        </table>
    </div>
</div>
