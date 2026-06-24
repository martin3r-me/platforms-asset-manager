{{-- Read-only Liste Kreditoren. Vars: $rows, $selectedId --}}
<table class="w-full text-sm">
    <tbody class="divide-y divide-[color:var(--ui-muted)]">
        @forelse($rows as $v)
            <tr wire:key="ve-{{ $v->id }}" wire:click="selectRow({{ $v->id }})"
                class="cursor-pointer hover:bg-[color:var(--ui-muted-10)] {{ $selectedId === $v->id ? 'bg-[color:var(--ui-primary-10)] shadow-[inset_3px_0_0_rgb(var(--ui-primary-rgb))]' : '' }}">
                <td class="px-4 py-2.5 font-medium text-gray-800">{{ $v->name }}@if($v->creditor_no)<span class="text-xs text-[color:var(--ui-secondary)] ml-2">#{{ $v->creditor_no }}</span>@endif</td>
                <td class="px-4 py-2.5 text-xs text-[color:var(--ui-secondary)]">{{ $v->cost_lines_count }} Pos.</td>
                <td class="px-4 py-2.5 text-right whitespace-nowrap">
                    @can('asset-manager.manage')
                        <button wire:click.stop="delete({{ $v->id }})"
                                wire:confirm="Kreditor {{ $v->name }} wirklich löschen?{{ $v->cost_lines_count ? ' '.$v->cost_lines_count.' Positionen verlieren den Kreditor.' : '' }}"
                                class="text-xs text-[color:var(--ui-secondary)] hover:text-red-600">@svg('heroicon-o-trash', 'w-4 h-4 inline')</button>
                    @endcan
                </td>
            </tr>
        @empty
            <tr><td class="px-4 py-8 text-center text-xs text-[color:var(--ui-secondary)]">Noch keine Kreditoren — „Neu“ klicken zum Anlegen.</td></tr>
        @endforelse
    </tbody>
</table>
