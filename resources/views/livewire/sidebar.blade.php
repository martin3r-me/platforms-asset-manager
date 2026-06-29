<div>
    {{-- Modul-lokale Design-Token-Schicht (--am-*). Sidebar wird von Core auf JEDER Modul-Seite
         eingebettet → genau eine zuverlässige Injektionsstelle. Siehe components/theme.blade.php. --}}
    <x-asset-manager-theme />

    @php $controllingEnabled = $controllingEnabled ?? false; @endphp

    <div x-show="!collapsed" class="p-3 text-sm italic uppercase text-[var(--am-text-muted)] border-b border-[color:var(--am-border)] mb-2">
        Asset Manager
    </div>

    <x-ui-sidebar-list label="Übersicht">
        <x-asset-manager-nav-item :href="route('asset-manager.dashboard')" :active="request()->routeIs('asset-manager.dashboard')" icon="heroicon-o-home" label="Dashboard" />
    </x-ui-sidebar-list>

    <x-ui-sidebar-list label="Assets">
        {{-- „Inventar" ist der EINE vereinte Asset-Einstieg (manuelle Assets + Intune-Geräte, E1).
             „Alle Assets" (manuelle Teilmenge) entfällt hier — Inventar deckt sie ab (Filter „Nur manuelle
             Assets"); die Bulk-Anlage bleibt unter /assets per URL erreichbar. --}}
        <x-asset-manager-nav-item :href="route('asset-manager.inventory.index')" :active="request()->routeIs('asset-manager.inventory.*') || request()->routeIs('asset-manager.assets.*')" icon="heroicon-o-rectangle-group" label="Inventar" />
        <x-asset-manager-nav-item :href="route('asset-manager.devices.index')" :active="request()->routeIs('asset-manager.devices.*')" icon="heroicon-o-computer-desktop" label="Intune-Geräte" />
        <x-asset-manager-nav-item :href="route('asset-manager.licenses.index')" :active="request()->routeIs('asset-manager.licenses.*')" icon="heroicon-o-key" label="Lizenzen" />
        <x-asset-manager-nav-item :href="route('asset-manager.printers.index')" :active="request()->routeIs('asset-manager.printers.*')" icon="heroicon-o-printer" label="Drucker" />
        <x-asset-manager-nav-item :href="route('asset-manager.internet.index')" :active="request()->routeIs('asset-manager.internet.*')" icon="heroicon-o-wifi" label="Internet" />
    </x-ui-sidebar-list>

    <x-ui-sidebar-list label="Sicherheit">
        <x-asset-manager-nav-item :href="route('asset-manager.compliance.index')" :active="request()->routeIs('asset-manager.compliance.*')" icon="heroicon-o-shield-check" label="Compliance-Cockpit" />
    </x-ui-sidebar-list>

    <x-ui-sidebar-list label="Mitarbeiter">
        <x-asset-manager-nav-item :href="route('asset-manager.employees.index')" :active="request()->routeIs('asset-manager.employees.*')" icon="heroicon-o-users" label="Alle Mitarbeiter" />
    </x-ui-sidebar-list>

    {{-- Auswertungen = Controlling-Schicht (per Team abschaltbar, ADR 0008) --}}
    @if($controllingEnabled)
    <x-ui-sidebar-list label="Auswertungen">
        <x-asset-manager-nav-item :href="route('asset-manager.costs.allocation')" :active="request()->routeIs('asset-manager.costs.allocation')" icon="heroicon-o-table-cells" label="Kostenaufteilung" />
        <x-asset-manager-nav-item :href="route('asset-manager.cost-lines.index')" :active="request()->routeIs('asset-manager.cost-lines.*')" icon="heroicon-o-list-bullet" label="Kostenpositionen" />
        <x-asset-manager-nav-item :href="route('asset-manager.costs')" :active="request()->routeIs('asset-manager.costs')" icon="heroicon-o-banknotes" label="Kosten (pro MA)" />
        <x-asset-manager-nav-item :href="route('asset-manager.reports.device-models')" :active="request()->routeIs('asset-manager.reports.*')" icon="heroicon-o-chart-bar" label="Geräte nach Modell" />
    </x-ui-sidebar-list>
    @endif

    <x-ui-sidebar-list label="Einstellungen">
        {{-- Stammdaten = Controlling (ADR 0008) --}}
        @if($controllingEnabled)
        <x-asset-manager-nav-item :href="route('asset-manager.master-data.index')" :active="request()->routeIs('asset-manager.master-data.index')" icon="heroicon-o-rectangle-stack" label="Stammdaten" />
        @endif
        {{-- Geräte-Modelle bleibt IT-Kern (Hardware-Katalog), unabhängig vom Controlling --}}
        <x-asset-manager-nav-item :href="route('asset-manager.device-models.index')" :active="request()->routeIs('asset-manager.device-models.*')" icon="heroicon-o-cpu-chip" label="Geräte-Modelle" />
        {{-- Kosten-Import + Import-Log = Controlling (ADR 0008) --}}
        @if($controllingEnabled)
        <x-asset-manager-nav-item :href="route('asset-manager.costs.import')" :active="request()->routeIs('asset-manager.costs.import')" icon="heroicon-o-arrow-up-tray" label="Kosten-Import" />
        <x-asset-manager-nav-item :href="route('asset-manager.costs.import-log')" :active="request()->routeIs('asset-manager.costs.import-log')" icon="heroicon-o-document-magnifying-glass" label="Import-Log" />
        @endif
        <x-asset-manager-nav-item :href="route('asset-manager.setup')" :active="request()->routeIs('asset-manager.setup')" icon="heroicon-o-wrench-screwdriver" label="Connector" />
        <x-asset-manager-nav-item :href="route('asset-manager.settings')" :active="request()->routeIs('asset-manager.settings')" icon="heroicon-o-cog-6-tooth" label="Modul-Einstellungen" />
    </x-ui-sidebar-list>

    {{-- Collapsed: Icons-only --}}
    <div x-show="collapsed" class="px-2 py-2 border-b border-[color:var(--am-border)]">
        <div class="flex flex-col gap-2">
            <a href="{{ route('asset-manager.dashboard') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md {{ request()->routeIs('asset-manager.dashboard') ? 'bg-[var(--am-primary)] text-[var(--am-on-primary)]' : 'text-[var(--am-text-secondary)] hover:bg-[var(--am-bg)]' }}">
                @svg('heroicon-o-home', 'w-5 h-5')
            </a>
            <a href="{{ route('asset-manager.inventory.index') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md {{ request()->routeIs('asset-manager.inventory.*') || request()->routeIs('asset-manager.assets.*') ? 'bg-[var(--am-primary)] text-[var(--am-on-primary)]' : 'text-[var(--am-text-secondary)] hover:bg-[var(--am-bg)]' }}">
                @svg('heroicon-o-rectangle-group', 'w-5 h-5')
            </a>
            <a href="{{ route('asset-manager.devices.index') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md {{ request()->routeIs('asset-manager.devices.*') ? 'bg-[var(--am-primary)] text-[var(--am-on-primary)]' : 'text-[var(--am-text-secondary)] hover:bg-[var(--am-bg)]' }}">
                @svg('heroicon-o-computer-desktop', 'w-5 h-5')
            </a>
            <a href="{{ route('asset-manager.licenses.index') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md {{ request()->routeIs('asset-manager.licenses.*') ? 'bg-[var(--am-primary)] text-[var(--am-on-primary)]' : 'text-[var(--am-text-secondary)] hover:bg-[var(--am-bg)]' }}">
                @svg('heroicon-o-key', 'w-5 h-5')
            </a>
            <a href="{{ route('asset-manager.printers.index') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md {{ request()->routeIs('asset-manager.printers.*') ? 'bg-[var(--am-primary)] text-[var(--am-on-primary)]' : 'text-[var(--am-text-secondary)] hover:bg-[var(--am-bg)]' }}">
                @svg('heroicon-o-printer', 'w-5 h-5')
            </a>
            <a href="{{ route('asset-manager.internet.index') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md {{ request()->routeIs('asset-manager.internet.*') ? 'bg-[var(--am-primary)] text-[var(--am-on-primary)]' : 'text-[var(--am-text-secondary)] hover:bg-[var(--am-bg)]' }}">
                @svg('heroicon-o-wifi', 'w-5 h-5')
            </a>
            <a href="{{ route('asset-manager.compliance.index') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md {{ request()->routeIs('asset-manager.compliance.*') ? 'bg-[var(--am-primary)] text-[var(--am-on-primary)]' : 'text-[var(--am-text-secondary)] hover:bg-[var(--am-bg)]' }}">
                @svg('heroicon-o-shield-check', 'w-5 h-5')
            </a>
            <a href="{{ route('asset-manager.employees.index') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md {{ request()->routeIs('asset-manager.employees.*') ? 'bg-[var(--am-primary)] text-[var(--am-on-primary)]' : 'text-[var(--am-text-secondary)] hover:bg-[var(--am-bg)]' }}">
                @svg('heroicon-o-users', 'w-5 h-5')
            </a>
            @if($controllingEnabled)
            <a href="{{ route('asset-manager.costs.allocation') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md {{ request()->routeIs('asset-manager.costs.allocation') ? 'bg-[var(--am-primary)] text-[var(--am-on-primary)]' : 'text-[var(--am-text-secondary)] hover:bg-[var(--am-bg)]' }}">
                @svg('heroicon-o-table-cells', 'w-5 h-5')
            </a>
            <a href="{{ route('asset-manager.cost-lines.index') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md {{ request()->routeIs('asset-manager.cost-lines.*') ? 'bg-[var(--am-primary)] text-[var(--am-on-primary)]' : 'text-[var(--am-text-secondary)] hover:bg-[var(--am-bg)]' }}">
                @svg('heroicon-o-list-bullet', 'w-5 h-5')
            </a>
            <a href="{{ route('asset-manager.costs') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md {{ request()->routeIs('asset-manager.costs') ? 'bg-[var(--am-primary)] text-[var(--am-on-primary)]' : 'text-[var(--am-text-secondary)] hover:bg-[var(--am-bg)]' }}">
                @svg('heroicon-o-banknotes', 'w-5 h-5')
            </a>
            <a href="{{ route('asset-manager.reports.device-models') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md {{ request()->routeIs('asset-manager.reports.*') ? 'bg-[var(--am-primary)] text-[var(--am-on-primary)]' : 'text-[var(--am-text-secondary)] hover:bg-[var(--am-bg)]' }}">
                @svg('heroicon-o-chart-bar', 'w-5 h-5')
            </a>
            <a href="{{ route('asset-manager.master-data.index') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md {{ request()->routeIs('asset-manager.master-data.index') ? 'bg-[var(--am-primary)] text-[var(--am-on-primary)]' : 'text-[var(--am-text-secondary)] hover:bg-[var(--am-bg)]' }}">
                @svg('heroicon-o-rectangle-stack', 'w-5 h-5')
            </a>
            @endif
            <a href="{{ route('asset-manager.device-models.index') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md {{ request()->routeIs('asset-manager.device-models.*') ? 'bg-[var(--am-primary)] text-[var(--am-on-primary)]' : 'text-[var(--am-text-secondary)] hover:bg-[var(--am-bg)]' }}">
                @svg('heroicon-o-cpu-chip', 'w-5 h-5')
            </a>
            @if($controllingEnabled)
            <a href="{{ route('asset-manager.costs.import') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md {{ request()->routeIs('asset-manager.costs.import') ? 'bg-[var(--am-primary)] text-[var(--am-on-primary)]' : 'text-[var(--am-text-secondary)] hover:bg-[var(--am-bg)]' }}">
                @svg('heroicon-o-arrow-up-tray', 'w-5 h-5')
            </a>
            <a href="{{ route('asset-manager.costs.import-log') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md {{ request()->routeIs('asset-manager.costs.import-log') ? 'bg-[var(--am-primary)] text-[var(--am-on-primary)]' : 'text-[var(--am-text-secondary)] hover:bg-[var(--am-bg)]' }}">
                @svg('heroicon-o-document-magnifying-glass', 'w-5 h-5')
            </a>
            @endif
            <a href="{{ route('asset-manager.setup') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md {{ request()->routeIs('asset-manager.setup') ? 'bg-[var(--am-primary)] text-[var(--am-on-primary)]' : 'text-[var(--am-text-secondary)] hover:bg-[var(--am-bg)]' }}">
                @svg('heroicon-o-wrench-screwdriver', 'w-5 h-5')
            </a>
            <a href="{{ route('asset-manager.settings') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md {{ request()->routeIs('asset-manager.settings') ? 'bg-[var(--am-primary)] text-[var(--am-on-primary)]' : 'text-[var(--am-text-secondary)] hover:bg-[var(--am-bg)]' }}">
                @svg('heroicon-o-cog-6-tooth', 'w-5 h-5')
            </a>
        </div>
    </div>
</div>
