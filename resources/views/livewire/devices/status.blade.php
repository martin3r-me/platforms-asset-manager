<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-asset-manager-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Geräte', 'href' => route('asset-manager.devices.index'), 'icon' => 'computer-desktop'],
            ['label' => 'Status', 'icon' => 'wrench-screwdriver'],
        ]">
            <x-slot name="actions">
                @include('asset-manager::livewire.partials.tenant-selector')
            </x-slot>
        </x-asset-manager-page-actionbar>
    </x-slot>

    <x-ui-page-container padding="p-6" spacing="space-y-5">
        @php
            // Reihenfolge + Beschriftung/Farbe je Status (Farben spiegeln AssetDevice::lifecycleBadgeColor()).
            $statusMeta = [
                'all'     => ['Alle',                 'violet'],
                'in_use'  => ['In Betrieb',           'emerald'],
                'spare'   => ['Reserve / Lager',      'indigo'],
                'repair'  => ['In Reparatur',         'amber'],
                'defect'  => ['Defekt / Kaputt',      'orange'],
                'retired' => ['Ausgemustert',         'gray'],
                'lost'    => ['Verloren / Gestohlen', 'red'],
                'none'    => ['Ohne Status',          'gray'],
            ];
        @endphp

        {{-- Stat-Kacheln je Lifecycle-Status (klickbar = Filter) --}}
        <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-3">
            @foreach($statusMeta as $key => [$label, $color])
                @php $active = ($key === 'all' && $status === '') || $status === $key; @endphp
                <button type="button" wire:click="setStatus('{{ $key === 'all' ? '' : $key }}')"
                    class="text-left rounded-xl border bg-white px-4 py-3 transition-all hover:shadow-sm {{ $active ? 'border-violet-400 ring-2 ring-violet-500/30' : 'border-[var(--ui-border)]/40' }}">
                    <div class="text-2xl font-semibold tabular-nums text-{{ $color }}-700">{{ $counts[$key] ?? 0 }}</div>
                    <div class="mt-0.5 text-[11px] font-medium text-[var(--ui-secondary)]">{{ $label }}</div>
                </button>
            @endforeach
        </div>

        {{-- Geräteliste (nach aktivem Status-Filter) --}}
        <x-ui-panel>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wider border-b border-[color:var(--ui-muted)]">
                            <th class="px-4 py-2.5 bg-[color:var(--ui-muted-10)] text-[color:var(--ui-body-color)] font-semibold">Gerät</th>
                            <th class="px-4 py-2.5 bg-[color:var(--ui-muted-10)] text-[color:var(--ui-body-color)] font-semibold">Nutzer</th>
                            <th class="px-4 py-2.5 bg-[color:var(--ui-muted-10)] text-[color:var(--ui-body-color)] font-semibold">Status</th>
                            <th class="px-4 py-2.5 bg-[color:var(--ui-muted-10)] text-[color:var(--ui-body-color)] font-semibold">Letzter Check-in</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--ui-border)]/30">
                        @forelse($devices as $device)
                            <tr class="hover:bg-[color:var(--ui-muted-10)] transition-colors">
                                <td class="px-4 py-2.5">
                                    <a href="{{ route('asset-manager.devices.show', $device) }}" wire:navigate
                                       class="font-medium text-[color:var(--ui-primary)] hover:text-violet-700">
                                        {{ $device->device_name ?: '—' }}
                                    </a>
                                    @if($device->manufacturer || $device->model)
                                        <div class="text-[11px] text-[color:var(--ui-secondary)]">{{ trim(($device->manufacturer ?? '') . ' ' . ($device->model ?? '')) }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-[var(--ui-secondary)]">
                                    {{ $device->user_display_name ?: ($device->user_principal_name ?: '—') }}
                                </td>
                                <td class="px-4 py-2.5">
                                    <x-asset-manager-badge :color="$device->lifecycleBadgeColor()" dot size="sm">{{ $device->lifecycleLabel() }}</x-asset-manager-badge>
                                </td>
                                <td class="px-4 py-2.5 text-[var(--ui-secondary)] tabular-nums">
                                    {{ $device->last_check_in_at?->format('d.m.Y H:i') ?? '—' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-10 text-center text-sm text-[color:var(--ui-secondary)]">
                                    Keine Geräte in diesem Status.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($devices->hasPages())
                <div class="px-4 py-3 border-t border-[color:var(--ui-muted)]">
                    {{ $devices->links() }}
                </div>
            @endif
        </x-ui-panel>
    </x-ui-page-container>
</x-ui-page>
