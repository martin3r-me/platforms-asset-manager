{{-- Gerät: Kosten-Override (Phase 4). Leasing-Rate hat Vorrang vor Kauf/AfA; leer/0 → Modell-Default greift. --}}
<x-ui-modal model="showDeviceCost" size="lg">
    <x-slot name="header">Geräte-Kosten bearbeiten</x-slot>

    <div class="space-y-4">
        <p class="text-[11px] text-[color:var(--ui-secondary)]">Leasing-Rate hat Vorrang vor Kauf + AfA. Leer lassen → der Modell-Standard greift.</p>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)] mb-1">Leasing / Monat (€)</label>
                <input type="number" step="0.01" min="0" wire:model="gcMonthly" class="w-full px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10" />
                @error('gcMonthly') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)] mb-1">Kaufdatum</label>
                <input type="date" wire:model="gcPurchaseDate" class="w-full px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10" />
                @error('gcPurchaseDate') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)] mb-1">Kaufpreis (€)</label>
                <input type="number" step="0.01" min="0" wire:model="gcPurchase" class="w-full px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10" />
                @error('gcPurchase') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)] mb-1">AfA (Monate)</label>
                <input type="number" min="1" wire:model="gcDep" class="w-full px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10" />
                @error('gcDep') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)] mb-1">Kostenart</label>
                <select wire:model="gcCostType" class="w-full px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10">
                    <option value="">– Modell-Standard –</option>
                    @foreach($costTypes as $ct)
                        <option value="{{ $ct->id }}">{{ $ct->name }}</option>
                    @endforeach
                </select>
                @error('gcCostType') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)] mb-1">Kostenstelle</label>
                <select wire:model="gcCostCenter" class="w-full px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10">
                    <option value="">– keine –</option>
                    @foreach($costCenters as $cc)
                        <option value="{{ $cc->id }}">{{ $cc->code }}{{ $cc->name ? ' – ' . $cc->name : '' }}</option>
                    @endforeach
                </select>
                @error('gcCostCenter') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
            </div>
        </div>
    </div>

    <x-slot name="footer">
        <x-ui-button variant="secondary-outline" size="sm" x-on:click="modalShow = false">Abbrechen</x-ui-button>
        <x-ui-button variant="primary" size="sm" wire:click="saveDeviceCost" wire:loading.attr="disabled">
            @svg('heroicon-o-check', 'w-3.5 h-3.5') Speichern
        </x-ui-button>
    </x-slot>
</x-ui-modal>
