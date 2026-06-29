{{-- Gerät: Kosten-Override (Phase 4). Leasing-Rate hat Vorrang vor Kauf/AfA; leer/0 → Modell-Default greift. --}}
<x-ui-modal model="showDeviceCost" size="lg">
    <x-slot name="header">Geräte-Kosten bearbeiten</x-slot>

    <div class="space-y-4">
        <p class="text-[11px] text-[var(--am-text-secondary)]">Leasing-Rate hat Vorrang vor Kauf + AfA. Leer lassen → der Modell-Standard greift.</p>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Leasing / Monat (€)</label>
                <x-asset-manager-input size="sm" type="number" step="0.01" min="0" wire:model="gcMonthly" />
                @error('gcMonthly') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Kaufdatum</label>
                <x-asset-manager-input size="sm" type="date" wire:model="gcPurchaseDate" />
                @error('gcPurchaseDate') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Kaufpreis (€)</label>
                <x-asset-manager-input size="sm" type="number" step="0.01" min="0" wire:model="gcPurchase" />
                @error('gcPurchase') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">AfA (Monate)</label>
                <x-asset-manager-input size="sm" type="number" min="1" wire:model="gcDep" />
                @error('gcDep') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Kostenart</label>
                <x-asset-manager-select size="sm" wire:model="gcCostType">
                    <option value="">– Modell-Standard –</option>
                    @foreach($costTypes as $ct)
                        <option value="{{ $ct->id }}">{{ $ct->name }}</option>
                    @endforeach
                </x-asset-manager-select>
                @error('gcCostType') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Kostenstelle</label>
                <x-asset-manager-select size="sm" wire:model="gcCostCenter">
                    <option value="">– keine –</option>
                    @foreach($costCenters as $cc)
                        <option value="{{ $cc->id }}">{{ $cc->code }}{{ $cc->name ? ' – ' . $cc->name : '' }}</option>
                    @endforeach
                </x-asset-manager-select>
                @error('gcCostCenter') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
            </div>
        </div>
    </div>

    <x-slot name="footer">
        <x-asset-manager-button variant="ghost" size="sm" x-on:click="modalShow = false">Abbrechen</x-asset-manager-button>
        <x-asset-manager-button variant="primary" size="sm" wire:click="saveDeviceCost" wire:loading.attr="disabled">
            @svg('heroicon-o-check', 'w-3.5 h-3.5') Speichern
        </x-asset-manager-button>
    </x-slot>
</x-ui-modal>
