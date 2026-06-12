<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Assets', 'href' => route('asset-manager.assets.index'), 'icon' => 'cube-transparent'],
            ['label' => 'Neu'],
        ]" />
    </x-slot>

    <x-ui-page-container>
        <div class="max-w-2xl mx-auto space-y-6">
            <div>
                <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">Neues Asset anlegen</h1>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Erfasse manuell Hardware wie Maus, Tastatur, Headset, Monitor o.ä.
                </p>
            </div>

            <form wire:submit="save" class="rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm p-5 space-y-4">

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Kategorie <span class="text-red-400">*</span></label>
                    <select wire:model.live="categoryId" class="w-full px-3 py-2 text-sm rounded-lg bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10 focus:outline-none focus:ring-2 focus:ring-violet-500/30">
                        <option value="">– Bitte wählen –</option>
                        @foreach($categories as $cat)
                            <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                        @endforeach
                    </select>
                    @error('categoryId') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Name <span class="text-red-400">*</span></label>
                    <input type="text" wire:model="name" placeholder="z.B. Logitech MX Master 3S"
                        class="w-full px-3 py-2 text-sm rounded-lg bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10 focus:outline-none focus:ring-2 focus:ring-violet-500/30" />
                    @error('name') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Hersteller</label>
                        <input type="text" wire:model="manufacturer"
                            class="w-full px-3 py-2 text-sm rounded-lg bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10 focus:outline-none focus:ring-2 focus:ring-violet-500/30" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Modell</label>
                        <input type="text" wire:model="model"
                            class="w-full px-3 py-2 text-sm rounded-lg bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10 focus:outline-none focus:ring-2 focus:ring-violet-500/30" />
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Seriennummer</label>
                    <input type="text" wire:model="serialNumber"
                        class="w-full px-3 py-2 text-sm rounded-lg bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10 focus:outline-none focus:ring-2 focus:ring-violet-500/30" />
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Zugewiesen an</label>
                    <select wire:model="assigneeId" class="w-full px-3 py-2 text-sm rounded-lg bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10 focus:outline-none focus:ring-2 focus:ring-violet-500/30">
                        <option value="">– Niemand (Lager) –</option>
                        @foreach($employees as $emp)
                            <option value="{{ $emp->id }}">{{ $emp->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Status</label>
                    <select wire:model="status" class="w-full px-3 py-2 text-sm rounded-lg bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10 focus:outline-none focus:ring-2 focus:ring-violet-500/30">
                        <option value="in_stock">Lager</option>
                        <option value="assigned">Zugewiesen</option>
                        <option value="retired">Ausgemustert</option>
                        <option value="lost">Verloren</option>
                    </select>
                </div>

                <div class="rounded-lg bg-black/[0.02] dark:bg-white/[0.03] border border-[var(--ui-border)]/30 p-3 space-y-3">
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-[var(--ui-muted)]">Kosten (optional)</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                            <label class="block text-xs text-gray-600 mb-1">Kaufdatum</label>
                            <input type="date" wire:model="purchaseDate"
                                class="w-full px-2 py-1.5 text-xs rounded-md bg-white border border-black/10 focus:outline-none focus:ring-2 focus:ring-violet-500/30" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-600 mb-1">Kaufpreis (€)</label>
                            <input type="number" step="0.01" min="0" wire:model="purchasePrice"
                                class="w-full px-2 py-1.5 text-xs rounded-md bg-white border border-black/10 focus:outline-none focus:ring-2 focus:ring-violet-500/30" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-600 mb-1">AfA (Monate)</label>
                            <input type="number" min="1" max="240" wire:model="depreciationMonths"
                                class="w-full px-2 py-1.5 text-xs rounded-md bg-white border border-black/10 focus:outline-none focus:ring-2 focus:ring-violet-500/30" />
                        </div>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Notizen</label>
                    <textarea wire:model="notes" rows="3"
                        class="w-full px-3 py-2 text-sm rounded-lg bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10 focus:outline-none focus:ring-2 focus:ring-violet-500/30"></textarea>
                </div>

                <div class="flex items-center gap-3 pt-2 border-t border-[var(--ui-border)]/30">
                    <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-gradient-to-br from-violet-500 to-indigo-600 rounded-lg hover:from-violet-600 hover:to-indigo-700 transition-all shadow-sm">
                        @svg('heroicon-o-check', 'w-4 h-4')
                        Anlegen
                    </button>
                    <a href="{{ route('asset-manager.assets.index') }}" wire:navigate class="text-sm text-gray-500 hover:text-gray-700">Abbrechen</a>
                </div>
            </form>
        </div>
    </x-ui-page-container>
</x-ui-page>
