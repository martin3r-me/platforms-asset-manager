<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Stammdaten', 'icon' => 'rectangle-stack'],
        ]" />
    </x-slot>

    <div class="flex-1 flex flex-col min-h-0 min-w-0">
        <div class="flex-1 overflow-y-auto p-6 max-w-5xl">

            <x-ui-page-header
                title="Stammdaten"
                subtitle="Gesellschaften, Kostenstellen, Kostenarten und Kreditoren an einem Ort pflegen." />

            {{-- Alle vier Bereiche gleichzeitig sichtbar, zwei fachliche Gruppen, klassenbasiert verschachtelt. --}}
            <div class="space-y-8">

                <div class="space-y-4">
                    <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-[var(--ui-muted)]">
                        @svg('heroicon-o-building-office-2', 'w-4 h-4')
                        <span>Organisation</span>
                        <span class="flex-1 h-px bg-[var(--ui-border)]"></span>
                    </div>

                    <section id="gesellschaften" class="scroll-mt-6">
                        @livewire(\Platform\AssetManager\Livewire\Companies\Index::class)
                    </section>

                    <section id="kostenstellen" class="scroll-mt-6">
                        @livewire(\Platform\AssetManager\Livewire\CostCenters\Index::class)
                    </section>
                </div>

                <div class="space-y-4">
                    <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-[var(--ui-muted)]">
                        @svg('heroicon-o-banknotes', 'w-4 h-4')
                        <span>Kostenstruktur</span>
                        <span class="flex-1 h-px bg-[var(--ui-border)]"></span>
                    </div>

                    <section id="kostenarten" class="scroll-mt-6">
                        @livewire(\Platform\AssetManager\Livewire\CostTypes\Index::class)
                    </section>

                    <section id="kreditoren" class="scroll-mt-6">
                        @livewire(\Platform\AssetManager\Livewire\Vendors\Index::class)
                    </section>
                </div>

            </div>
        </div>
    </div>
</x-ui-page>
