<div>
    <div x-show="!collapsed" class="p-3 text-sm italic text-[var(--ui-secondary)] uppercase border-b border-[var(--ui-border)] mb-2">
        Asset Manager
    </div>

    <x-ui-sidebar-list label="Übersicht">
        <x-ui-sidebar-item :href="route('asset-manager.dashboard')" :active="request()->routeIs('asset-manager.dashboard')">
            @svg('heroicon-o-home', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Dashboard</span>
        </x-ui-sidebar-item>
    </x-ui-sidebar-list>

    <x-ui-sidebar-list label="Assets">
        <x-ui-sidebar-item :href="route('asset-manager.assets.index')" :active="request()->routeIs('asset-manager.assets.*')">
            @svg('heroicon-o-cube-transparent', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Alle Assets</span>
        </x-ui-sidebar-item>
        <x-ui-sidebar-item :href="route('asset-manager.devices.index')" :active="request()->routeIs('asset-manager.devices.*')">
            @svg('heroicon-o-computer-desktop', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Intune-Geräte</span>
        </x-ui-sidebar-item>
        <x-ui-sidebar-item :href="route('asset-manager.licenses.index')" :active="request()->routeIs('asset-manager.licenses.*')">
            @svg('heroicon-o-key', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Lizenzen</span>
        </x-ui-sidebar-item>
        <x-ui-sidebar-item :href="route('asset-manager.printers.index')" :active="request()->routeIs('asset-manager.printers.*')">
            @svg('heroicon-o-printer', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Drucker</span>
        </x-ui-sidebar-item>
        <x-ui-sidebar-item :href="route('asset-manager.internet.index')" :active="request()->routeIs('asset-manager.internet.*')">
            @svg('heroicon-o-wifi', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Internet</span>
        </x-ui-sidebar-item>
    </x-ui-sidebar-list>

    <x-ui-sidebar-list label="Sicherheit">
        <x-ui-sidebar-item :href="route('asset-manager.compliance.index')" :active="request()->routeIs('asset-manager.compliance.*')">
            @svg('heroicon-o-shield-check', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Compliance-Cockpit</span>
        </x-ui-sidebar-item>
    </x-ui-sidebar-list>

    <x-ui-sidebar-list label="Mitarbeiter">
        <x-ui-sidebar-item :href="route('asset-manager.employees.index')" :active="request()->routeIs('asset-manager.employees.*')">
            @svg('heroicon-o-users', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Alle Mitarbeiter</span>
        </x-ui-sidebar-item>
    </x-ui-sidebar-list>

    <x-ui-sidebar-list label="Auswertungen">
        <x-ui-sidebar-item :href="route('asset-manager.costs.allocation')" :active="request()->routeIs('asset-manager.costs.allocation')">
            @svg('heroicon-o-table-cells', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Kostenaufteilung</span>
        </x-ui-sidebar-item>
        <x-ui-sidebar-item :href="route('asset-manager.cost-lines.index')" :active="request()->routeIs('asset-manager.cost-lines.*')">
            @svg('heroicon-o-list-bullet', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Kostenpositionen</span>
        </x-ui-sidebar-item>
        <x-ui-sidebar-item :href="route('asset-manager.costs')" :active="request()->routeIs('asset-manager.costs')">
            @svg('heroicon-o-banknotes', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Kosten (pro MA)</span>
        </x-ui-sidebar-item>
    </x-ui-sidebar-list>

    <x-ui-sidebar-list label="Einstellungen">
        <x-ui-sidebar-item :href="route('asset-manager.master-data.index')" :active="request()->routeIs('asset-manager.master-data.index')">
            @svg('heroicon-o-rectangle-stack', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Stammdaten</span>
        </x-ui-sidebar-item>
        <x-ui-sidebar-item :href="route('asset-manager.device-models.index')" :active="request()->routeIs('asset-manager.device-models.*')">
            @svg('heroicon-o-cpu-chip', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Geräte-Modelle</span>
        </x-ui-sidebar-item>
        <x-ui-sidebar-item :href="route('asset-manager.costs.import')" :active="request()->routeIs('asset-manager.costs.import')">
            @svg('heroicon-o-arrow-up-tray', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Kosten-Import</span>
        </x-ui-sidebar-item>
        <x-ui-sidebar-item :href="route('asset-manager.costs.import-log')" :active="request()->routeIs('asset-manager.costs.import-log')">
            @svg('heroicon-o-document-magnifying-glass', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Import-Log</span>
        </x-ui-sidebar-item>
        <x-ui-sidebar-item :href="route('asset-manager.setup')" :active="request()->routeIs('asset-manager.setup')">
            @svg('heroicon-o-wrench-screwdriver', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Connector</span>
        </x-ui-sidebar-item>
    </x-ui-sidebar-list>

    {{-- Collapsed: Icons-only --}}
    <div x-show="collapsed" class="px-2 py-2 border-b border-[var(--ui-border)]">
        <div class="flex flex-col gap-2">
            <a href="{{ route('asset-manager.dashboard') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md {{ request()->routeIs('asset-manager.dashboard') ? 'bg-[var(--ui-primary-10)] text-[var(--ui-primary)]' : 'text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]' }}">
                @svg('heroicon-o-home', 'w-5 h-5')
            </a>
            <a href="{{ route('asset-manager.assets.index') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md {{ request()->routeIs('asset-manager.assets.*') ? 'bg-[var(--ui-primary-10)] text-[var(--ui-primary)]' : 'text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]' }}">
                @svg('heroicon-o-cube-transparent', 'w-5 h-5')
            </a>
            <a href="{{ route('asset-manager.devices.index') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md {{ request()->routeIs('asset-manager.devices.*') ? 'bg-[var(--ui-primary-10)] text-[var(--ui-primary)]' : 'text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]' }}">
                @svg('heroicon-o-computer-desktop', 'w-5 h-5')
            </a>
            <a href="{{ route('asset-manager.licenses.index') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md {{ request()->routeIs('asset-manager.licenses.*') ? 'bg-[var(--ui-primary-10)] text-[var(--ui-primary)]' : 'text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]' }}">
                @svg('heroicon-o-key', 'w-5 h-5')
            </a>
            <a href="{{ route('asset-manager.printers.index') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md {{ request()->routeIs('asset-manager.printers.*') ? 'bg-[var(--ui-primary-10)] text-[var(--ui-primary)]' : 'text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]' }}">
                @svg('heroicon-o-printer', 'w-5 h-5')
            </a>
            <a href="{{ route('asset-manager.internet.index') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md {{ request()->routeIs('asset-manager.internet.*') ? 'bg-[var(--ui-primary-10)] text-[var(--ui-primary)]' : 'text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]' }}">
                @svg('heroicon-o-wifi', 'w-5 h-5')
            </a>
            <a href="{{ route('asset-manager.compliance.index') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md {{ request()->routeIs('asset-manager.compliance.*') ? 'bg-[var(--ui-primary-10)] text-[var(--ui-primary)]' : 'text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]' }}">
                @svg('heroicon-o-shield-check', 'w-5 h-5')
            </a>
            <a href="{{ route('asset-manager.employees.index') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md {{ request()->routeIs('asset-manager.employees.*') ? 'bg-[var(--ui-primary-10)] text-[var(--ui-primary)]' : 'text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]' }}">
                @svg('heroicon-o-users', 'w-5 h-5')
            </a>
            <a href="{{ route('asset-manager.costs.allocation') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md {{ request()->routeIs('asset-manager.costs.allocation') ? 'bg-[var(--ui-primary-10)] text-[var(--ui-primary)]' : 'text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]' }}">
                @svg('heroicon-o-table-cells', 'w-5 h-5')
            </a>
            <a href="{{ route('asset-manager.cost-lines.index') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md {{ request()->routeIs('asset-manager.cost-lines.*') ? 'bg-[var(--ui-primary-10)] text-[var(--ui-primary)]' : 'text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]' }}">
                @svg('heroicon-o-list-bullet', 'w-5 h-5')
            </a>
            <a href="{{ route('asset-manager.costs') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md {{ request()->routeIs('asset-manager.costs') ? 'bg-[var(--ui-primary-10)] text-[var(--ui-primary)]' : 'text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]' }}">
                @svg('heroicon-o-banknotes', 'w-5 h-5')
            </a>
            <a href="{{ route('asset-manager.master-data.index') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md {{ request()->routeIs('asset-manager.master-data.index') ? 'bg-[var(--ui-primary-10)] text-[var(--ui-primary)]' : 'text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]' }}">
                @svg('heroicon-o-rectangle-stack', 'w-5 h-5')
            </a>
            <a href="{{ route('asset-manager.device-models.index') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md {{ request()->routeIs('asset-manager.device-models.*') ? 'bg-[var(--ui-primary-10)] text-[var(--ui-primary)]' : 'text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]' }}">
                @svg('heroicon-o-cpu-chip', 'w-5 h-5')
            </a>
            <a href="{{ route('asset-manager.costs.import') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md {{ request()->routeIs('asset-manager.costs.import') ? 'bg-[var(--ui-primary-10)] text-[var(--ui-primary)]' : 'text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]' }}">
                @svg('heroicon-o-arrow-up-tray', 'w-5 h-5')
            </a>
            <a href="{{ route('asset-manager.costs.import-log') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md {{ request()->routeIs('asset-manager.costs.import-log') ? 'bg-[var(--ui-primary-10)] text-[var(--ui-primary)]' : 'text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]' }}">
                @svg('heroicon-o-document-magnifying-glass', 'w-5 h-5')
            </a>
            <a href="{{ route('asset-manager.setup') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md {{ request()->routeIs('asset-manager.setup') ? 'bg-[var(--ui-primary-10)] text-[var(--ui-primary)]' : 'text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]' }}">
                @svg('heroicon-o-wrench-screwdriver', 'w-5 h-5')
            </a>
        </div>
    </div>
</div>
