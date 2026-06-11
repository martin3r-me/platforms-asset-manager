<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Geräte', 'icon' => 'computer-desktop'],
        ]">
            <x-slot name="actions">
                <a href="{{ route('asset-manager.setup') }}" wire:navigate
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-gray-600 dark:text-gray-400 bg-black/[0.04] dark:bg-white/[0.06] rounded-lg hover:bg-black/[0.07] transition-all">
                    @svg('heroicon-o-wrench-screwdriver', 'w-3.5 h-3.5')
                    Connector
                </a>
            </x-slot>
        </x-ui-page-actionbar>
    </x-slot>

    <x-ui-page-container>
        <div class="space-y-5">

            {{-- Kein Connector konfiguriert --}}
            @if(!$config || !$config->isConfigured())
                <div class="flex flex-col items-center justify-center py-16 text-center">
                    <div class="w-12 h-12 rounded-xl bg-amber-500/10 flex items-center justify-center mb-4">
                        @svg('heroicon-o-wrench-screwdriver', 'w-6 h-6 text-amber-500')
                    </div>
                    <h3 class="text-sm font-medium text-gray-900 dark:text-gray-100 mb-1">Connector nicht konfiguriert</h3>
                    <p class="text-xs text-gray-400 mb-4 max-w-xs">Trage die Azure App-Registration Credentials ein, um Intune-Gerätedaten zu synchronisieren.</p>
                    <a href="{{ route('asset-manager.setup') }}" wire:navigate
                       class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-gradient-to-br from-violet-500 to-indigo-600 rounded-lg hover:from-violet-600 hover:to-indigo-700 transition-all shadow-sm">
                        @svg('heroicon-o-arrow-right', 'w-4 h-4')
                        Connector einrichten
                    </a>
                </div>
            @else

            {{-- Stat-Karten --}}
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-4">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-violet-500/50 to-transparent"></div>
                    <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $stats['total'] }}</div>
                    <div class="text-xs text-gray-400 mt-0.5">Geräte gesamt</div>
                </div>
                <div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-4">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-emerald-500/50 to-transparent"></div>
                    <div class="text-2xl font-semibold text-emerald-600 dark:text-emerald-400">{{ $stats['compliant'] }}</div>
                    <div class="text-xs text-gray-400 mt-0.5">Konform</div>
                </div>
                <div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-4">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-red-500/50 to-transparent"></div>
                    <div class="text-2xl font-semibold text-red-600 dark:text-red-400">{{ $stats['noncompliant'] }}</div>
                    <div class="text-xs text-gray-400 mt-0.5">Nicht konform</div>
                </div>
                <div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-4">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-gray-400/50 to-transparent"></div>
                    <div class="text-2xl font-semibold text-gray-500 dark:text-gray-400">{{ $stats['unknown'] }}</div>
                    <div class="text-xs text-gray-400 mt-0.5">Unbekannt</div>
                </div>
            </div>

            {{-- Filter + Suche --}}
            <div class="flex flex-wrap items-center gap-3">
                <div class="relative flex-1 min-w-48">
                    <div class="absolute inset-y-0 left-3 flex items-center pointer-events-none">
                        @svg('heroicon-o-magnifying-glass', 'w-4 h-4 text-gray-400')
                    </div>
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Gerät, Nutzer oder Seriennummer suchen..."
                        class="w-full pl-9 pr-3 py-2 text-sm rounded-lg bg-black/[0.03] dark:bg-white/[0.04] border border-black/10 dark:border-white/10 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-violet-500/30 transition-all"
                    />
                </div>

                <select wire:model.live="filterCompliance"
                    class="px-3 py-2 text-sm rounded-lg bg-black/[0.03] dark:bg-white/[0.04] border border-black/10 dark:border-white/10 text-gray-700 dark:text-gray-300 focus:outline-none focus:ring-2 focus:ring-violet-500/30 transition-all">
                    <option value="">Alle Status</option>
                    <option value="compliant">Konform</option>
                    <option value="noncompliant">Nicht konform</option>
                    <option value="inGracePeriod">Karenzzeit</option>
                    <option value="unknown">Unbekannt</option>
                    <option value="error">Fehler</option>
                </select>

                @if($osList->count() > 0)
                    <select wire:model.live="filterOs"
                        class="px-3 py-2 text-sm rounded-lg bg-black/[0.03] dark:bg-white/[0.04] border border-black/10 dark:border-white/10 text-gray-700 dark:text-gray-300 focus:outline-none focus:ring-2 focus:ring-violet-500/30 transition-all">
                        <option value="">Alle Betriebssysteme</option>
                        @foreach($osList as $os)
                            <option value="{{ $os }}">{{ $os }}</option>
                        @endforeach
                    </select>
                @endif

                <select wire:model.live="perPage"
                    class="px-3 py-2 text-sm rounded-lg bg-black/[0.03] dark:bg-white/[0.04] border border-black/10 dark:border-white/10 text-gray-700 dark:text-gray-300 focus:outline-none focus:ring-2 focus:ring-violet-500/30 transition-all">
                    <option value="15">15 pro Seite</option>
                    <option value="25">25 pro Seite</option>
                    <option value="50">50 pro Seite</option>
                </select>
            </div>

            {{-- Tabelle --}}
            <div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm">
                <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-violet-500/30 to-transparent"></div>

                @if($devices->isEmpty())
                    <div class="flex flex-col items-center justify-center py-16 text-center">
                        @svg('heroicon-o-computer-desktop', 'w-10 h-10 text-gray-300 dark:text-gray-600 mb-3')
                        <p class="text-sm text-gray-400">
                            @if($search || $filterCompliance || $filterOs)
                                Keine Geräte gefunden für diese Filtereinstellungen.
                            @else
                                Noch keine Geräte synchronisiert.
                            @endif
                        </p>
                    </div>
                @else
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-black/5 dark:border-white/5">
                                <th class="text-left px-5 py-3 text-xs font-medium uppercase tracking-wider text-gray-400">
                                    <button wire:click="sortBy('device_name')" class="flex items-center gap-1 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                                        Gerät
                                        @if($sortField === 'device_name') @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-3 h-3') @endif
                                    </button>
                                </th>
                                <th class="text-left px-5 py-3 text-xs font-medium uppercase tracking-wider text-gray-400">Nutzer</th>
                                <th class="text-left px-5 py-3 text-xs font-medium uppercase tracking-wider text-gray-400">Betriebssystem</th>
                                <th class="text-left px-5 py-3 text-xs font-medium uppercase tracking-wider text-gray-400">
                                    <button wire:click="sortBy('compliance_state')" class="flex items-center gap-1 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                                        Status
                                        @if($sortField === 'compliance_state') @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-3 h-3') @endif
                                    </button>
                                </th>
                                <th class="text-left px-5 py-3 text-xs font-medium uppercase tracking-wider text-gray-400">
                                    <button wire:click="sortBy('last_check_in_at')" class="flex items-center gap-1 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                                        Letztes Check-In
                                        @if($sortField === 'last_check_in_at') @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-3 h-3') @endif
                                    </button>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-black/[0.03] dark:divide-white/[0.04]">
                            @foreach($devices as $device)
                                <tr class="hover:bg-black/[0.02] dark:hover:bg-white/[0.02] transition-colors group">
                                    <td class="px-5 py-3">
                                        <a href="{{ route('asset-manager.devices.show', $device) }}" wire:navigate
                                           class="font-medium text-gray-900 dark:text-gray-100 hover:text-violet-600 dark:hover:text-violet-400 transition-colors">
                                            {{ $device->device_name ?? '—' }}
                                        </a>
                                        @if($device->model)
                                            <div class="text-xs text-gray-400">{{ $device->manufacturer }} {{ $device->model }}</div>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3">
                                        <div class="text-gray-700 dark:text-gray-300">{{ $device->user_display_name ?? '—' }}</div>
                                        @if($device->user_principal_name)
                                            <div class="text-xs text-gray-400 truncate max-w-[180px]">{{ $device->user_principal_name }}</div>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3">
                                        <div class="text-gray-700 dark:text-gray-300">{{ $device->operating_system ?? '—' }}</div>
                                        @if($device->os_version)
                                            <div class="text-xs text-gray-400">{{ $device->os_version }}</div>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3">
                                        @php $color = $device->complianceBadgeColor() @endphp
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium
                                            bg-{{ $color }}-500/10 text-{{ $color }}-600 dark:text-{{ $color }}-400">
                                            <span class="w-1.5 h-1.5 rounded-full bg-{{ $color }}-500"></span>
                                            {{ $device->complianceLabel() }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-3 text-sm text-gray-500 dark:text-gray-400">
                                        {{ $device->last_check_in_at ? $device->last_check_in_at->diffForHumans() : '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    @if($devices->hasPages())
                        <div class="px-5 py-3 border-t border-black/5 dark:border-white/5">
                            {{ $devices->links() }}
                        </div>
                    @endif
                @endif
            </div>

            @endif {{-- Ende: Connector konfiguriert --}}

        </div>
    </x-ui-page-container>
</x-ui-page>
