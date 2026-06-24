{{-- Read-only Liste Kostenstellen (flach, mit Gesellschaft-Spalte). Vars: $rows, $selectedId --}}
<table class="w-full text-sm">
    <thead>
        <tr class="border-b border-[color:var(--ui-muted)] bg-[color:var(--ui-muted-10)] text-xs uppercase tracking-wider text-[color:var(--ui-body-color)] font-semibold">
            <th class="text-left px-4 py-3">Code</th>
            <th class="text-left px-4 py-3">Bezeichnung</th>
            <th class="text-left px-4 py-3">Gesellschaft</th>
            <th class="text-right px-4 py-3">MA</th>
            <th class="px-4 py-3"></th>
        </tr>
    </thead>
    <tbody class="divide-y divide-[color:var(--ui-muted)]">
        @forelse($rows as $cc)
            <tr wire:key="cc-{{ $cc->id }}" wire:click="selectRow({{ $cc->id }})"
                class="cursor-pointer hover:bg-[color:var(--ui-muted-10)] {{ $selectedId === $cc->id ? 'bg-[color:var(--ui-primary-10)] shadow-[inset_3px_0_0_rgb(var(--ui-primary-rgb))]' : '' }} {{ $cc->is_active ? '' : 'opacity-50' }}">
                <td class="px-4 py-2.5 font-mono font-medium text-gray-800">{{ $cc->code }}</td>
                <td class="px-4 py-2.5 text-gray-600">{{ $cc->name ?? '—' }}</td>
                <td class="px-4 py-2.5 text-xs text-[color:var(--ui-secondary)]">{{ $cc->company?->name ?? '— ohne —' }}</td>
                <td class="px-4 py-2.5 text-right text-xs text-[color:var(--ui-secondary)]">{{ $cc->employees_count }}</td>
                <td class="px-4 py-2.5 text-right whitespace-nowrap">
                    @unless($cc->is_active)<x-asset-manager-badge color="amber" size="xs" class="mr-2">inaktiv</x-asset-manager-badge>@endunless
                    @can('asset-manager.manage')
                        <button wire:click.stop="delete({{ $cc->id }})"
                                wire:confirm="Kostenstelle {{ $cc->code }} wirklich löschen?{{ $cc->employees_count ? ' '.$cc->employees_count.' Mitarbeiter verlieren die Zuordnung.' : '' }} Kostenpositionen behalten ihren Betrag, verlieren aber die Kostenstelle."
                                class="text-xs text-[color:var(--ui-secondary)] hover:text-red-600">@svg('heroicon-o-trash', 'w-4 h-4 inline')</button>
                    @endcan
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="px-4 py-8 text-center text-xs text-[color:var(--ui-secondary)]">Keine Kostenstellen gefunden — „Neu“ klicken oder Filter zurücksetzen.</td></tr>
        @endforelse
    </tbody>
</table>
