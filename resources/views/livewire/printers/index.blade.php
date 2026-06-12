<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Drucker', 'icon' => 'printer'],
        ]" />
    </x-slot>

    <div class="flex-1 flex flex-col min-h-0 min-w-0">
        <div class="flex-1 overflow-y-auto p-6 space-y-4">

            <div class="flex items-center gap-2">
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Suche Modell / Seriennr. / Standort…"
                       class="px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white min-w-[260px]">
                <span class="ml-auto text-sm text-[var(--ui-secondary)]">Gesamt: <strong class="text-violet-700 tabular-nums">{{ number_format($totalMonthly, 2, ',', '.') }} € / Monat</strong></span>
            </div>

            <div class="rounded-xl bg-white/60 border border-black/5 shadow-sm overflow-hidden">
                @if($items->isEmpty())
                    <div class="p-8 text-center text-sm text-gray-400">Keine Drucker erfasst. Importiere die Excel oder lege sie als Asset (Kategorie „Drucker") an.</div>
                @else
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-black/5 text-xs uppercase tracking-wider text-gray-400">
                                <th class="text-left px-4 py-3">Modell / Standort</th>
                                <th class="text-left px-4 py-3">Seriennr.</th>
                                <th class="text-left px-4 py-3">KSt</th>
                                <th class="text-right px-4 py-3">€/Monat</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-black/[0.03]">
                            @foreach($items as $item)
                                <tr class="hover:bg-black/[0.02]">
                                    <td class="px-4 py-2.5">
                                        <a href="{{ route('asset-manager.assets.show', $item) }}" wire:navigate class="font-medium text-gray-800 hover:text-violet-600">{{ $item->name }}</a>
                                    </td>
                                    <td class="px-4 py-2.5 text-xs text-gray-500 font-mono">{{ $item->serial_number ?? '—' }}</td>
                                    <td class="px-4 py-2.5 text-xs text-gray-500">{{ $item->raw_data['kostenstelle'] ?? '—' }}</td>
                                    <td class="px-4 py-2.5 text-right font-semibold tabular-nums text-violet-700">{{ number_format($costByItem[$item->id] ?? 0, 2, ',', '.') }} €</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>
</x-ui-page>
