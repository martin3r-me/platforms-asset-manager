{{-- Read-only Liste Gesellschaften. Vars: $rows, $selectedId --}}
<table class="w-full text-sm">
    <tbody class="divide-y divide-[color:var(--am-border)]">
        @forelse($rows as $co)
            <tr wire:key="co-{{ $co->id }}" wire:click="selectRow({{ $co->id }})"
                class="cursor-pointer hover:bg-[var(--am-bg)] {{ $selectedId === $co->id ? 'bg-[var(--am-accent-surface)] shadow-[inset_3px_0_0_var(--am-accent)]' : '' }}">
                <td class="px-4 py-2.5 font-medium text-[var(--am-text)]">{{ $co->name }}</td>
                <td class="px-4 py-2.5 text-xs text-[var(--am-text-secondary)]">{{ $co->cost_centers_count }} Kostenstellen</td>
                <td class="px-4 py-2.5 text-right whitespace-nowrap">
                    @can('asset-manager.manage')
                        <button wire:click.stop="delete({{ $co->id }})"
                                wire:confirm="Gesellschaft {{ $co->name }} wirklich löschen?{{ $co->cost_centers_count ? ' '.$co->cost_centers_count.' Kostenstellen verlieren die Zuordnung.' : '' }}"
                                class="text-xs text-[var(--am-text-secondary)] hover:text-red-600">@svg('heroicon-o-trash', 'w-4 h-4 inline')</button>
                    @endcan
                </td>
            </tr>
        @empty
            <tr><td class="px-4 py-8 text-center text-xs text-[var(--am-text-secondary)]">Noch keine Gesellschaften — „Neu“ klicken zum Anlegen.</td></tr>
        @endforelse
    </tbody>
</table>
