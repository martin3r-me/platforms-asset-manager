{{-- Bearbeiten (Details) — manuelles Asset. Bei source=intune Hardware-Felder read-only (E?/Assets/Show). --}}
<x-ui-modal model="showEdit" size="lg">
    <x-slot name="header">Asset bearbeiten</x-slot>

    @if($item)
        @php $isIntune = $item->source === 'intune'; @endphp
        <div class="space-y-4">
            @if($isIntune)
                <p class="text-[11px] text-[var(--am-text-secondary)] flex items-center gap-1.5">
                    @svg('heroicon-o-cloud', 'w-3.5 h-3.5 text-[var(--am-accent)]') Intune-synced — Gerätedaten read-only, nur Status editierbar.
                </p>
            @endif

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Kategorie *</label>
                    <x-asset-manager-select size="sm" wire:model="eCategoryId" :disabled="$isIntune">
                        @foreach($categories as $cat)
                            <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                        @endforeach
                    </x-asset-manager-select>
                    @error('eCategoryId') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Status *</label>
                    <x-asset-manager-select size="sm" wire:model="eStatus">
                        <option value="in_stock">Lager</option>
                        <option value="assigned">Zugeordnet</option>
                        <option value="retired">Ausgemustert</option>
                        <option value="lost">Verloren</option>
                    </x-asset-manager-select>
                    @error('eStatus') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Name *</label>
                <x-asset-manager-input size="sm" type="text" wire:model="eName" :disabled="$isIntune" />
                @error('eName') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Hersteller</label>
                    <x-asset-manager-input size="sm" type="text" wire:model="eManufacturer" :disabled="$isIntune" />
                </div>
                <div>
                    <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Modell</label>
                    <x-asset-manager-input size="sm" type="text" wire:model="eModel" :disabled="$isIntune" />
                </div>
                <div>
                    <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Seriennummer</label>
                    <x-asset-manager-input size="sm" type="text" wire:model="eSerial" :disabled="$isIntune" />
                </div>
            </div>
        </div>
    @endif

    <x-slot name="footer">
        <x-asset-manager-button variant="secondary" size="sm" x-on:click="modalShow = false">Abbrechen</x-asset-manager-button>
        <x-asset-manager-button variant="primary" size="sm" wire:click="saveEdit" wire:loading.attr="disabled">
            @svg('heroicon-o-check', 'w-3.5 h-3.5') Speichern
        </x-asset-manager-button>
    </x-slot>
</x-ui-modal>
