{{-- Read-only Liste Gesellschaften. Vars: $rows, $selectedId --}}
<table class="w-full text-sm">
    <tbody class="divide-y divide-[color:var(--ui-muted)]">
        @forelse($rows as $co)
            <tr wire:key="co-{{ $co->id }}" wire:click="selectRow({{ $co->id }})"
                class="cursor-pointer hover:bg-[color:var(--ui-muted-10)] {{ $selectedId === $co->id ? 'bg-[color:var(--ui-primary-10)] shadow-[inset_3px_0_0_rgb(var(--ui-primary-rgb))]' : '' }}">
                <td class="px-4 py-2.5 font-medium text-gray-800">{{ $co->name }}</td>
                <td class="px-4 py-2.5 text-xs text-[color:var(--ui-secondary)]">{{ $co->cost_centers_count }} Kostenstellen</td>
                <td class="px-4 py-2.5 text-right whitespace-nowrap">
                    @can('asset-manager.manage')
                        <button wire:click.stop="delete({{ $co->id }})"
                                wire:confirm="Gesellschaft {{ $co->name }} wirklich löschen?{{ $co->cost_centers_count ? ' '.$co->cost_centers_count.' Kostenstellen verlieren die Zuordnung.' : '' }}"
                                class="text-xs text-[color:var(--ui-secondary)] hover:text-red-600">@svg('heroicon-o-trash', 'w-4 h-4 inline')</button>
                    @endcan
                </td>
            </tr>
        @empty
            <tr><td class="px-4 py-8 text-center text-xs text-[color:var(--ui-secondary)]">Noch keine Gesellschaften — „Neu“ klicken zum Anlegen.</td></tr>
        @endforelse
    </tbody>
</table>
