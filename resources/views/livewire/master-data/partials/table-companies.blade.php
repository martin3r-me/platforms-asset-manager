{{-- Read-only Liste Gesellschaften. Vars: $rows, $selectedId --}}
<table class="w-full text-sm">
    <tbody class="divide-y divide-black/[0.03]">
        @forelse($rows as $co)
            <tr wire:key="co-{{ $co->id }}" wire:click="selectRow({{ $co->id }})"
                class="cursor-pointer hover:bg-black/[0.02] {{ $selectedId === $co->id ? 'bg-violet-500/10' : '' }}">
                <td class="px-4 py-2.5 font-medium text-gray-800">{{ $co->name }}</td>
                <td class="px-4 py-2.5 text-xs text-gray-400">{{ $co->cost_centers_count }} Kostenstellen</td>
                <td class="px-4 py-2.5 text-right whitespace-nowrap">
                    <button wire:click.stop="delete({{ $co->id }})"
                            wire:confirm="Gesellschaft {{ $co->name }} wirklich löschen?{{ $co->cost_centers_count ? ' '.$co->cost_centers_count.' Kostenstellen verlieren die Zuordnung.' : '' }}"
                            class="text-xs text-gray-400 hover:text-red-600">@svg('heroicon-o-trash', 'w-4 h-4 inline')</button>
                </td>
            </tr>
        @empty
            <tr><td class="px-4 py-8 text-center text-xs text-gray-400">Noch keine Gesellschaften — „Neu“ klicken zum Anlegen.</td></tr>
        @endforelse
    </tbody>
</table>
