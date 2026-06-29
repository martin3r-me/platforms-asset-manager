{{-- Anlage-Modal: legt ein MANUELLES Asset an (Geräte kommen aus Intune, E7). --}}
<x-ui-modal model="showCreate" size="lg">
    <x-slot name="header">Asset anlegen</x-slot>

    <div class="space-y-4">
        <p class="text-[11px] text-[color:var(--ui-secondary)] flex items-center gap-1.5">
            @svg('heroicon-o-information-circle', 'w-3.5 h-3.5 flex-shrink-0')
            Intune-Geräte werden automatisch synchronisiert — hier legst du ein <strong>manuelles</strong> Asset an.
        </p>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)] mb-1">Kategorie *</label>
                <select wire:model.live="cCategoryId" class="w-full px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10">
                    <option value="">– wählen –</option>
                    @foreach($categories as $cat)
                        <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                    @endforeach
                </select>
                @error('cCategoryId') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)] mb-1">Status *</label>
                <select wire:model="cStatus" class="w-full px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10">
                    <option value="in_stock">Lager</option>
                    <option value="assigned">Zugeordnet</option>
                    <option value="retired">Ausgemustert</option>
                    <option value="lost">Verloren</option>
                </select>
                <p class="text-[10px] text-[color:var(--ui-secondary)] mt-0.5">Bei direkter Zuordnung wird „Zugeordnet" automatisch gesetzt.</p>
            </div>
        </div>

        <div>
            <label class="block text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)] mb-1">Name *</label>
            <input type="text" wire:model="cName" placeholder="z. B. Logitech MX Keys"
                   class="w-full px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10" />
            @error('cName') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)] mb-1">Hersteller</label>
                <input type="text" wire:model="cManufacturer" class="w-full px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10" />
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)] mb-1">Modell</label>
                <input type="text" wire:model="cModel" class="w-full px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10" />
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)] mb-1">Seriennummer</label>
                <input type="text" wire:model="cSerialNumber" class="w-full px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10" />
            </div>
        </div>

        <div>
            <label class="block text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)] mb-1">Direkt zuordnen an</label>
            <select wire:model="cAssigneeId" class="w-full px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10">
                <option value="">– Niemand (Lager) –</option>
                @foreach($employees as $emp)
                    <option value="{{ $emp->id }}">{{ $emp->name }}</option>
                @endforeach
            </select>
            @error('cAssigneeId') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
        </div>

        <div class="rounded-lg border border-black/10 dark:border-white/10 p-3 space-y-3">
            <div class="text-[10px] font-semibold uppercase tracking-wider text-[color:var(--ui-secondary)]">Abschreibung (optional)</div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)] mb-1">Kaufdatum</label>
                    <input type="date" wire:model="cPurchaseDate" class="w-full px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10" />
                </div>
                <div>
                    <label class="block text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)] mb-1">Kaufpreis (€)</label>
                    <input type="number" step="0.01" min="0" wire:model="cPurchasePrice" class="w-full px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10" />
                    @error('cPurchasePrice') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)] mb-1">AfA (Monate)</label>
                    <input type="number" min="1" max="240" wire:model="cDepreciationMonths" class="w-full px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10" />
                    @error('cDepreciationMonths') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <div>
            <label class="block text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)] mb-1">Notizen</label>
            <textarea wire:model="cNotes" rows="2" class="w-full px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10 resize-none"></textarea>
        </div>
    </div>

    <x-slot name="footer">
        <x-ui-button variant="secondary-outline" size="sm" x-on:click="modalShow = false">Abbrechen</x-ui-button>
        <x-ui-button variant="primary" size="sm" wire:click="createItem" wire:loading.attr="disabled">
            @svg('heroicon-o-check', 'w-3.5 h-3.5')
            Asset anlegen
        </x-ui-button>
    </x-slot>
</x-ui-modal>
