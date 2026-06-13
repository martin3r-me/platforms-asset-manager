{{-- Read-only Liste Kostenstellen (flach, mit Gesellschaft-Spalte). Vars: $rows, $selectedId --}}
<table class="w-full text-sm">
    <thead>
        <tr class="border-b border-black/5 text-xs uppercase tracking-wider text-gray-400">
            <th class="text-left px-4 py-3">Code</th>
            <th class="text-left px-4 py-3">Bezeichnung</th>
            <th class="text-left px-4 py-3">Gesellschaft</th>
            <th class="text-right px-4 py-3">MA</th>
            <th class="px-4 py-3"></th>
        </tr>
    </thead>
    <tbody class="divide-y divide-black/[0.03]">
        @forelse($rows as $cc)
            <tr wire:key="cc-{{ $cc->id }}" wire:click="selectRow({{ $cc->id }})"
                class="cursor-pointer hover:bg-black/[0.02] {{ $selectedId === $cc->id ? 'bg-violet-500/10' : '' }} {{ $cc->is_active ? '' : 'opacity-50' }}">
                <td class="px-4 py-2.5 font-mono font-medium text-gray-800">{{ $cc->code }}</td>
                <td class="px-4 py-2.5 text-gray-600">{{ $cc->name ?? '—' }}</td>
                <td class="px-4 py-2.5 text-xs text-gray-500">{{ $cc->company?->name ?? '— ohne —' }}</td>
                <td class="px-4 py-2.5 text-right text-xs text-gray-400">{{ $cc->employees_count }}</td>
                <td class="px-4 py-2.5 text-right whitespace-nowrap">
                    @unless($cc->is_active)<span class="text-[10px] text-amber-600 mr-2">inaktiv</span>@endunless
                    <button wire:click.stop="delete({{ $cc->id }})"
                            wire:confirm="Kostenstelle {{ $cc->code }} wirklich löschen?{{ $cc->employees_count ? ' '.$cc->employees_count.' Mitarbeiter verlieren die Zuordnung.' : '' }} Kostenpositionen behalten ihren Betrag, verlieren aber die Kostenstelle."
                            class="text-xs text-gray-400 hover:text-red-600">@svg('heroicon-o-trash', 'w-4 h-4 inline')</button>
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="px-4 py-8 text-center text-xs text-gray-400">Keine Kostenstellen gefunden — „Neu“ klicken oder Filter zurücksetzen.</td></tr>
        @endforelse
    </tbody>
</table>
