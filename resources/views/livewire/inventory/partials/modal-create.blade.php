{{-- Anlage-Modal: legt ein MANUELLES Asset an (Geräte kommen aus Intune, E7). --}}
<x-ui-modal model="showCreate" size="lg">
    <x-slot name="header">Asset anlegen</x-slot>

    <div class="space-y-4">
        <p class="text-[11px] text-[var(--am-text-secondary)] flex items-center gap-1.5">
            @svg('heroicon-o-information-circle', 'w-3.5 h-3.5 flex-shrink-0 text-[var(--am-text-muted)]')
            Intune-Geräte werden automatisch synchronisiert — hier legst du ein <strong>manuelles</strong> Asset an.
        </p>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Kategorie *</label>
                <x-asset-manager-select size="sm" wire:model.live="cCategoryId">
                    <option value="">– wählen –</option>
                    @foreach($categories as $cat)
                        <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                    @endforeach
                </x-asset-manager-select>
                @error('cCategoryId') <p class="text-[10px] text-[var(--am-danger)] mt-0.5">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Status *</label>
                <x-asset-manager-select size="sm" wire:model="cStatus">
                    <option value="in_stock">Lager</option>
                    <option value="assigned">Zugeordnet</option>
                    <option value="retired">Ausgemustert</option>
                    <option value="lost">Verloren</option>
                </x-asset-manager-select>
                <p class="text-[10px] text-[var(--am-text-secondary)] mt-0.5">Bei direkter Zuordnung wird „Zugeordnet" automatisch gesetzt.</p>
            </div>
        </div>

        <div>
            <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Name *</label>
            <x-asset-manager-input size="sm" type="text" wire:model="cName" placeholder="z. B. Logitech MX Keys" />
            @error('cName') <p class="text-[10px] text-[var(--am-danger)] mt-0.5">{{ $message }}</p> @enderror
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Hersteller</label>
                <x-asset-manager-input size="sm" type="text" wire:model="cManufacturer" />
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Modell</label>
                <x-asset-manager-input size="sm" type="text" wire:model="cModel" />
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Seriennummer</label>
                <x-asset-manager-input size="sm" type="text" wire:model="cSerialNumber" />
            </div>
        </div>

        <div>
            <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Direkt zuordnen an</label>
            <x-asset-manager-select size="sm" wire:model="cAssigneeId">
                <option value="">– Niemand (Lager) –</option>
                @foreach($employees as $emp)
                    <option value="{{ $emp->id }}">{{ $emp->name }}</option>
                @endforeach
            </x-asset-manager-select>
            @error('cAssigneeId') <p class="text-[10px] text-[var(--am-danger)] mt-0.5">{{ $message }}</p> @enderror
        </div>

        <div class="rounded-lg border border-[color:var(--am-border)] p-3 space-y-3">
            <div class="text-[10px] font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">Abschreibung (optional)</div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Kaufdatum</label>
                    <x-asset-manager-input size="sm" type="date" wire:model="cPurchaseDate" />
                </div>
                <div>
                    <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Kaufpreis (€)</label>
                    <x-asset-manager-input size="sm" type="number" step="0.01" min="0" wire:model="cPurchasePrice" />
                    @error('cPurchasePrice') <p class="text-[10px] text-[var(--am-danger)] mt-0.5">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">AfA (Monate)</label>
                    <x-asset-manager-input size="sm" type="number" min="1" max="240" wire:model="cDepreciationMonths" />
                    @error('cDepreciationMonths') <p class="text-[10px] text-[var(--am-danger)] mt-0.5">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <div>
            <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Notizen</label>
            <x-asset-manager-textarea wire:model="cNotes" rows="2" />
        </div>
    </div>

    <x-slot name="footer">
        <x-asset-manager-button variant="secondary" size="sm" x-on:click="modalShow = false">Abbrechen</x-asset-manager-button>
        <x-asset-manager-button variant="primary" size="sm" wire:click="createItem" wire:loading.attr="disabled">
            @svg('heroicon-o-check', 'w-3.5 h-3.5')
            Asset anlegen
        </x-asset-manager-button>
    </x-slot>
</x-ui-modal>
