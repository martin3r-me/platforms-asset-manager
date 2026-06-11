<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Geräte', 'href' => route('asset-manager.devices.index'), 'icon' => 'computer-desktop'],
            ['label' => $device->device_name ?? 'Gerät'],
        ]" />
    </x-slot>

    <x-ui-page-container>
        <div class="max-w-3xl mx-auto space-y-5">

            {{-- Header --}}
            <div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-5">
                <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-violet-500/40 to-transparent"></div>
                <div class="flex items-start gap-4">
                    <div class="flex-shrink-0 w-12 h-12 rounded-xl bg-gradient-to-br from-violet-500/10 to-indigo-500/10 flex items-center justify-center">
                        @svg('heroicon-o-computer-desktop', 'w-6 h-6 text-violet-500')
                    </div>
                    <div class="flex-1 min-w-0">
                        <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100 truncate">
                            {{ $device->device_name ?? 'Unbekanntes Gerät' }}
                        </h1>
                        @if($device->user_display_name)
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $device->user_display_name }}</p>
                        @endif
                    </div>
                    @php $color = $device->complianceBadgeColor() @endphp
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-medium
                        bg-{{ $color }}-500/10 text-{{ $color }}-600 dark:text-{{ $color }}-400 flex-shrink-0">
                        <span class="w-2 h-2 rounded-full bg-{{ $color }}-500"></span>
                        {{ $device->complianceLabel() }}
                    </span>
                </div>
            </div>

            {{-- Details --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

                {{-- Gerätedaten --}}
                <div class="rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm overflow-hidden">
                    <div class="px-4 py-3 border-b border-black/5 dark:border-white/5">
                        <h2 class="text-xs font-medium uppercase tracking-wider text-gray-400">Gerätedaten</h2>
                    </div>
                    <dl class="divide-y divide-black/[0.03] dark:divide-white/[0.04]">
                        @foreach([
                            ['Hersteller', $device->manufacturer],
                            ['Modell', $device->model],
                            ['Typ', $device->device_type],
                            ['Seriennummer', $device->serial_number],
                        ] as [$label, $value])
                            <div class="flex justify-between px-4 py-2.5">
                                <dt class="text-xs text-gray-400">{{ $label }}</dt>
                                <dd class="text-xs font-medium text-gray-700 dark:text-gray-300 text-right max-w-[60%] truncate">{{ $value ?? '—' }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>

                {{-- Betriebssystem --}}
                <div class="rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm overflow-hidden">
                    <div class="px-4 py-3 border-b border-black/5 dark:border-white/5">
                        <h2 class="text-xs font-medium uppercase tracking-wider text-gray-400">Betriebssystem</h2>
                    </div>
                    <dl class="divide-y divide-black/[0.03] dark:divide-white/[0.04]">
                        @foreach([
                            ['System', $device->operating_system],
                            ['Version', $device->os_version],
                            ['Management', $device->management_state],
                        ] as [$label, $value])
                            <div class="flex justify-between px-4 py-2.5">
                                <dt class="text-xs text-gray-400">{{ $label }}</dt>
                                <dd class="text-xs font-medium text-gray-700 dark:text-gray-300 text-right max-w-[60%] truncate">{{ $value ?? '—' }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>

                {{-- Nutzerzuweisung --}}
                <div class="rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm overflow-hidden">
                    <div class="px-4 py-3 border-b border-black/5 dark:border-white/5">
                        <h2 class="text-xs font-medium uppercase tracking-wider text-gray-400">Nutzerzuweisung</h2>
                    </div>
                    <dl class="divide-y divide-black/[0.03] dark:divide-white/[0.04]">
                        @foreach([
                            ['Name', $device->user_display_name],
                            ['E-Mail', $device->user_principal_name],
                        ] as [$label, $value])
                            <div class="flex justify-between px-4 py-2.5">
                                <dt class="text-xs text-gray-400">{{ $label }}</dt>
                                <dd class="text-xs font-medium text-gray-700 dark:text-gray-300 text-right max-w-[60%] truncate">{{ $value ?? '—' }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>

                {{-- Zeitstempel --}}
                <div class="rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm overflow-hidden">
                    <div class="px-4 py-3 border-b border-black/5 dark:border-white/5">
                        <h2 class="text-xs font-medium uppercase tracking-wider text-gray-400">Zeitstempel</h2>
                    </div>
                    <dl class="divide-y divide-black/[0.03] dark:divide-white/[0.04]">
                        @foreach([
                            ['Enrollt am', $device->enrolled_at?->format('d.m.Y H:i')],
                            ['Letztes Check-In', $device->last_check_in_at ? $device->last_check_in_at->diffForHumans() . ' (' . $device->last_check_in_at->format('d.m.Y') . ')' : null],
                            ['Intune ID', $device->intune_id],
                        ] as [$label, $value])
                            <div class="flex justify-between px-4 py-2.5">
                                <dt class="text-xs text-gray-400">{{ $label }}</dt>
                                <dd class="text-xs font-medium text-gray-700 dark:text-gray-300 text-right max-w-[60%] truncate">{{ $value ?? '—' }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>

            </div>

            {{-- Raw Data --}}
            @if($device->raw_data)
                <div class="rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm overflow-hidden">
                    <button wire:click="toggleRawData" class="w-full flex items-center justify-between px-5 py-3 text-left hover:bg-black/[0.02] dark:hover:bg-white/[0.02] transition-colors">
                        <span class="text-xs font-medium text-gray-400">Rohdaten (Graph API)</span>
                        @svg($showRawData ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-4 h-4 text-gray-400')
                    </button>
                    @if($showRawData)
                        <div class="border-t border-black/5 dark:border-white/5 p-4">
                            <pre class="text-xs text-gray-500 dark:text-gray-400 overflow-auto max-h-96 font-mono whitespace-pre-wrap break-all">{{ json_encode($device->raw_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                        </div>
                    @endif
                </div>
            @endif

        </div>
    </x-ui-page-container>
</x-ui-page>
