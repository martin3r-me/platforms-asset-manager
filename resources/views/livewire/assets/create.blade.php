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
                <h1 class="text-xl font-semibold text-[var(--am-text)]">Neues Asset anlegen</h1>
                <p class="mt-1 text-sm text-[var(--am-text-secondary)]">
                    Erfasse manuell Hardware wie Maus, Tastatur, Headset, Monitor o.ä.
                </p>
            </div>

            <form wire:submit="save">
                <x-asset-manager-panel title="Asset-Daten">
                    <div class="space-y-4">

                        <div>
                            <label class="block text-sm font-medium text-[var(--am-text-secondary)] mb-1.5">Kategorie <span class="text-red-500">*</span></label>
                            <x-asset-manager-select size="md" wire:model.live="categoryId">
                                <option value="">– Bitte wählen –</option>
                                @foreach($categories as $cat)
                                    <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                                @endforeach
                            </x-asset-manager-select>
                            @error('categoryId') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-[var(--am-text-secondary)] mb-1.5">Name <span class="text-red-500">*</span></label>
                            <x-asset-manager-input size="md" type="text" wire:model="name" placeholder="z.B. Logitech MX Master 3S" />
                            @error('name') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-[var(--am-text-secondary)] mb-1.5">Hersteller</label>
                                <x-asset-manager-input size="md" type="text" wire:model="manufacturer" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-[var(--am-text-secondary)] mb-1.5">Modell</label>
                                <x-asset-manager-input size="md" type="text" wire:model="model" />
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-[var(--am-text-secondary)] mb-1.5">Seriennummer</label>
                            <x-asset-manager-input size="md" type="text" wire:model="serialNumber" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-[var(--am-text-secondary)] mb-1.5">Zugewiesen an</label>
                            <x-asset-manager-select size="md" wire:model="assigneeId">
                                <option value="">– Niemand (Lager) –</option>
                                @foreach($employees as $emp)
                                    <option value="{{ $emp->id }}">{{ $emp->name }}</option>
                                @endforeach
                            </x-asset-manager-select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-[var(--am-text-secondary)] mb-1.5">Status</label>
                            <x-asset-manager-select size="md" wire:model="status">
                                <option value="in_stock">Lager</option>
                                <option value="assigned">Zugewiesen</option>
                                <option value="retired">Ausgemustert</option>
                                <option value="lost">Verloren</option>
                            </x-asset-manager-select>
                        </div>

                        <div class="rounded-lg bg-[var(--am-bg)] border border-[color:var(--am-border)] p-3 space-y-3">
                            <h3 class="text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">Kosten (optional)</h3>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                <div>
                                    <label class="block text-xs text-[var(--am-text-secondary)] mb-1">Kaufdatum</label>
                                    <x-asset-manager-input size="sm" type="date" wire:model="purchaseDate" />
                                </div>
                                <div>
                                    <label class="block text-xs text-[var(--am-text-secondary)] mb-1">Kaufpreis (€)</label>
                                    <x-asset-manager-input size="sm" type="number" step="0.01" min="0" wire:model="purchasePrice" />
                                </div>
                                <div>
                                    <label class="block text-xs text-[var(--am-text-secondary)] mb-1">AfA (Monate)</label>
                                    <x-asset-manager-input size="sm" type="number" min="1" max="240" wire:model="depreciationMonths" />
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-[var(--am-text-secondary)] mb-1.5">Notizen</label>
                            <x-asset-manager-textarea rows="3" wire:model="notes" />
                        </div>

                        <div class="flex items-center gap-3 pt-2 border-t border-[color:var(--am-border)]">
                            {{-- Submit nur Owner/Admin (Backend: mount()+save() Gate create, ADR 0004). --}}
                            @can('asset-manager.manage')
                                <x-asset-manager-button variant="primary" size="md" type="submit">
                                    @svg('heroicon-o-check', 'w-4 h-4')
                                    Anlegen
                                </x-asset-manager-button>
                            @endcan
                            <x-asset-manager-button variant="ghost" size="sm" href="{{ route('asset-manager.assets.index') }}" wire:navigate>Abbrechen</x-asset-manager-button>
                        </div>

                    </div>
                </x-asset-manager-panel>
            </form>
        </div>
    </x-ui-page-container>
</x-ui-page>
