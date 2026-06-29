{{-- Read-only Liste Kostenstellen (flach, mit Gesellschaft-Spalte). Vars: $rows, $selectedId --}}
<div class="overflow-x-auto">
<table class="w-full text-sm">
    <thead>
        <tr class="border-b border-[color:var(--am-border)] bg-[var(--am-bg)] text-xs uppercase tracking-wider text-[var(--am-text-muted)] font-semibold">
            <th class="text-left px-4 py-3">Code</th>
            <th class="text-left px-4 py-3">Bezeichnung</th>
            <th class="text-left px-4 py-3">Gesellschaft</th>
            <th class="text-right px-4 py-3">MA</th>
            <th class="px-4 py-3"></th>
        </tr>
    </thead>
    <tbody class="divide-y divide-[color:var(--am-border)]">
        @forelse($rows as $cc)
            <tr wire:key="cc-{{ $cc->id }}" wire:click="selectRow({{ $cc->id }})"
                class="cursor-pointer hover:bg-[var(--am-bg)] {{ $selectedId === $cc->id ? 'bg-[var(--am-accent-surface)] shadow-[inset_3px_0_0_var(--am-accent)]' : '' }} {{ $cc->is_active ? '' : 'opacity-50' }}">
                <td class="px-4 py-2.5 font-mono font-medium text-[var(--am-text)]">{{ $cc->code }}</td>
                <td class="px-4 py-2.5 text-[var(--am-text-secondary)]">{{ $cc->name ?? '—' }}</td>
                <td class="px-4 py-2.5 text-xs text-[var(--am-text-secondary)]">{{ $cc->company?->name ?? '— ohne —' }}</td>
                <td class="px-4 py-2.5 text-right text-xs text-[var(--am-text-secondary)]">{{ $cc->employees_count }}</td>
                <td class="px-4 py-2.5 text-right whitespace-nowrap">
                    @unless($cc->is_active)<x-asset-manager-badge color="amber" size="xs" class="mr-2">inaktiv</x-asset-manager-badge>@endunless
                    @can('asset-manager.manage')
                        <button wire:click.stop="delete({{ $cc->id }})"
                                wire:confirm="Kostenstelle {{ $cc->code }} wirklich löschen?{{ $cc->employees_count ? ' '.$cc->employees_count.' Mitarbeiter verlieren die Zuordnung.' : '' }} Kostenpositionen behalten ihren Betrag, verlieren aber die Kostenstelle."
                                class="text-xs text-[var(--am-text-muted)] hover:text-red-600">@svg('heroicon-o-trash', 'w-4 h-4 inline')</button>
                    @endcan
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="px-4 py-8 text-center text-xs text-[var(--am-text-muted)]">Keine Kostenstellen gefunden — „Neu“ klicken oder Filter zurücksetzen.</td></tr>
        @endforelse
    </tbody>
</table>
</div>
