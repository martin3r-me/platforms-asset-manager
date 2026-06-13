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
        <div class="flex-1 overflow-y-auto p-6 space-y-8 max-w-5xl">

            {{-- Alle vier Stammdaten-Bereiche gleichzeitig sichtbar, in logischer Reihenfolge:
                 erst Organisation (Gesellschaften → Kostenstellen), dann Kostenstruktur (Kostenarten → Kreditoren).
                 Die vier bestehenden Komponenten werden klassenbasiert verschachtelt (wie in platforms-planner),
                 dadurch unabhängig vom Livewire-Alias. --}}

            <section id="gesellschaften" class="scroll-mt-6">
                @livewire(\Platform\AssetManager\Livewire\Companies\Index::class)
            </section>

            <section id="kostenstellen" class="scroll-mt-6">
                @livewire(\Platform\AssetManager\Livewire\CostCenters\Index::class)
            </section>

            <section id="kostenarten" class="scroll-mt-6">
                @livewire(\Platform\AssetManager\Livewire\CostTypes\Index::class)
            </section>

            <section id="kreditoren" class="scroll-mt-6">
                @livewire(\Platform\AssetManager\Livewire\Vendors\Index::class)
            </section>

        </div>
    </div>
</x-ui-page>
