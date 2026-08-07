{{-- Kostenstellen-Baum (Einrückung über depth). Vars: $rows, $selectedId --}}
<div class="overflow-x-auto">
<table class="w-full text-sm">
    <thead>
        <tr class="border-b border-[color:var(--am-border)] bg-[var(--am-bg)] text-xs uppercase tracking-wider text-[var(--am-text-muted)] font-semibold">
            <th class="text-left px-4 py-3">Code</th>
            <th class="text-left px-4 py-3">Bezeichnung</th>
            <th class="text-right px-4 py-3">Träger</th>
            <th class="px-4 py-3"></th>
        </tr>
    </thead>
    <tbody class="divide-y divide-[color:var(--am-border)]">
        @forelse($rows as $cc)
            @php
                // Einrückung als Inline-Style: Tailwind kann dynamische Klassen (pl-{{ $n }}) nicht
                // erzeugen, und die Tiefe ist unbegrenzt — feste Klassen pro Ebene wären eine Falle.
                $indent = min((int) $cc->depth, \Platform\AssetManager\Models\AssetCostCenter::MAX_DEPTH) * 18;
                $isRoot = (int) $cc->depth === 0;
            @endphp
            <tr wire:key="cc-{{ $cc->id }}" wire:click="selectRow({{ $cc->id }})"
                class="cursor-pointer hover:bg-[var(--am-bg)] {{ $selectedId === $cc->id ? 'bg-[var(--am-accent-surface)] shadow-[inset_3px_0_0_var(--am-accent)]' : '' }} {{ $cc->is_active ? '' : 'opacity-50' }}">
                <td class="px-4 py-2.5 font-mono {{ $isRoot ? 'font-semibold text-[var(--am-text)]' : 'font-medium text-[var(--am-text-secondary)]' }}">
                    <span class="inline-flex items-center" style="padding-left: {{ $indent }}px">
                        @unless($isRoot)
                            <span class="text-[var(--am-text-muted)] mr-1.5 select-none">└</span>
                        @endunless
                        {{ $cc->code }}
                    </span>
                </td>
                <td class="px-4 py-2.5 {{ $isRoot ? 'text-[var(--am-text)] font-medium' : 'text-[var(--am-text-secondary)]' }}">{{ $cc->name ?? '—' }}</td>
                <td class="px-4 py-2.5 text-right text-xs text-[var(--am-text-secondary)]">{{ $cc->holders_count ?? 0 }}</td>
                <td class="px-4 py-2.5 text-right whitespace-nowrap">
                    @unless($cc->is_active)<x-asset-manager-badge color="amber" size="xs" class="mr-2">inaktiv</x-asset-manager-badge>@endunless
                    @can('asset-manager.manage')
                        @unless($isRoot)
                            <button wire:click.stop="moveUnder({{ $cc->id }}, null)"
                                    title="Zum obersten Knoten machen"
                                    class="text-xs text-[var(--am-text-muted)] hover:text-[var(--am-accent)] mr-1">@svg('heroicon-o-arrow-up-on-square', 'w-4 h-4 inline')</button>
                        @endunless
                        <button wire:click.stop="delete({{ $cc->id }})"
                                wire:confirm="Kostenstelle {{ $cc->code }} wirklich löschen?{{ $cc->holders_count ? ' '.$cc->holders_count.' Asset-Träger verlieren die Zuordnung.' : '' }} Untergeordnete Kostenstellen werden zu obersten Knoten. Kostenpositionen behalten ihren Betrag, verlieren aber die Kostenstelle."
                                class="text-xs text-[var(--am-text-muted)] hover:text-red-600">@svg('heroicon-o-trash', 'w-4 h-4 inline')</button>
                    @endcan
                </td>
            </tr>
        @empty
            <tr><td colspan="4" class="px-4 py-8 text-center text-xs text-[var(--am-text-muted)]">Keine Kostenstellen gefunden — „Neu“ klicken oder Filter zurücksetzen.</td></tr>
        @endforelse
    </tbody>
</table>
</div>
