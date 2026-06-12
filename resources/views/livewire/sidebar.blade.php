<div>
    <div x-show="!collapsed" class="p-3 text-sm italic text-[var(--ui-secondary)] uppercase border-b border-[var(--ui-border)] mb-2">
        Asset Manager
    </div>

    <x-ui-sidebar-list label="Übersicht">
        <x-ui-sidebar-item :href="route('asset-manager.dashboard')">
            @svg('heroicon-o-home', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Dashboard</span>
        </x-ui-sidebar-item>
    </x-ui-sidebar-list>

    <x-ui-sidebar-list label="Assets">
        <x-ui-sidebar-item :href="route('asset-manager.assets.index')">
            @svg('heroicon-o-cube-transparent', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Alle Assets</span>
        </x-ui-sidebar-item>
        <x-ui-sidebar-item :href="route('asset-manager.devices.index')">
            @svg('heroicon-o-computer-desktop', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Intune-Geräte</span>
        </x-ui-sidebar-item>
        <x-ui-sidebar-item :href="route('asset-manager.licenses.index')">
            @svg('heroicon-o-key', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Lizenzen</span>
        </x-ui-sidebar-item>
    </x-ui-sidebar-list>

    <x-ui-sidebar-list label="Mitarbeiter">
        <x-ui-sidebar-item :href="route('asset-manager.employees.index')">
            @svg('heroicon-o-users', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Alle Mitarbeiter</span>
        </x-ui-sidebar-item>
    </x-ui-sidebar-list>

    <x-ui-sidebar-list label="Auswertungen">
        <x-ui-sidebar-item :href="route('asset-manager.costs')">
            @svg('heroicon-o-banknotes', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Kosten</span>
        </x-ui-sidebar-item>
    </x-ui-sidebar-list>

    <x-ui-sidebar-list label="Einstellungen">
        <x-ui-sidebar-item :href="route('asset-manager.setup')">
            @svg('heroicon-o-wrench-screwdriver', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Connector</span>
        </x-ui-sidebar-item>
    </x-ui-sidebar-list>

    {{-- Collapsed: Icons-only --}}
    <div x-show="collapsed" class="px-2 py-2 border-b border-[var(--ui-border)]">
        <div class="flex flex-col gap-2">
            <a href="{{ route('asset-manager.dashboard') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]">
                @svg('heroicon-o-home', 'w-5 h-5')
            </a>
            <a href="{{ route('asset-manager.assets.index') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]">
                @svg('heroicon-o-cube-transparent', 'w-5 h-5')
            </a>
            <a href="{{ route('asset-manager.devices.index') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]">
                @svg('heroicon-o-computer-desktop', 'w-5 h-5')
            </a>
            <a href="{{ route('asset-manager.licenses.index') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]">
                @svg('heroicon-o-key', 'w-5 h-5')
            </a>
            <a href="{{ route('asset-manager.employees.index') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]">
                @svg('heroicon-o-users', 'w-5 h-5')
            </a>
            <a href="{{ route('asset-manager.costs') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]">
                @svg('heroicon-o-banknotes', 'w-5 h-5')
            </a>
            <a href="{{ route('asset-manager.setup') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]">
                @svg('heroicon-o-wrench-screwdriver', 'w-5 h-5')
            </a>
        </div>
    </div>
</div>
