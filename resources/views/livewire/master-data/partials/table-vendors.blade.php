{{-- Read-only Liste Kreditoren. Vars: $rows, $selectedId --}}
<table class="w-full text-sm">
    <tbody class="divide-y divide-[color:var(--am-border)]">
        @forelse($rows as $v)
            <tr wire:key="ve-{{ $v->id }}" wire:click="selectRow({{ $v->id }})"
                class="cursor-pointer hover:bg-[var(--am-bg)] {{ $selectedId === $v->id ? 'bg-[var(--am-accent-surface)] shadow-[inset_3px_0_0_var(--am-accent)]' : '' }}">
                <td class="px-4 py-2.5 font-medium text-[var(--am-text)]">{{ $v->name }}@if($v->creditor_no)<span class="text-xs text-[var(--am-text-muted)] ml-2">#{{ $v->creditor_no }}</span>@endif</td>
                <td class="px-4 py-2.5 text-xs text-[var(--am-text-secondary)]">{{ $v->cost_lines_count }} Pos.</td>
                <td class="px-4 py-2.5 text-right whitespace-nowrap">
                    @can('asset-manager.manage')
                        <button wire:click.stop="delete({{ $v->id }})"
                                wire:confirm="Kreditor {{ $v->name }} wirklich löschen?{{ $v->cost_lines_count ? ' '.$v->cost_lines_count.' Positionen verlieren den Kreditor.' : '' }}"
                                class="text-xs text-[var(--am-text-muted)] hover:text-red-600">@svg('heroicon-o-trash', 'w-4 h-4 inline')</button>
                    @endcan
                </td>
            </tr>
        @empty
            <tr><td class="px-4 py-8 text-center text-xs text-[var(--am-text-muted)]">Noch keine Kreditoren — „Neu“ klicken zum Anlegen.</td></tr>
        @endforelse
    </tbody>
</table>
