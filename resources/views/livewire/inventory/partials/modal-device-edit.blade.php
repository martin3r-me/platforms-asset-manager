{{-- Gerät: Lifecycle & Beschaffung (Phase 4). Intune-Stammdaten bleiben read-only; das hier wird
     manuell gepflegt. Status-Wechsel schreibt ein Audit-Event (ADR 0007). --}}
<x-ui-modal model="showDeviceEdit" size="lg">
    <x-slot name="header">Lifecycle &amp; Beschaffung</x-slot>

    <div class="space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Lifecycle-Status</label>
                <x-asset-manager-select size="sm" wire:model="glStatus">
                    <option value="">– kein Status –</option>
                    @foreach(\Platform\AssetManager\Models\AssetDevice::LIFECYCLE_LABELS as $val => $label)
                        <option value="{{ $val }}">{{ $label }}</option>
                    @endforeach
                </x-asset-manager-select>
                @error('glStatus') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Standort</label>
                <x-asset-manager-input size="sm" type="text" wire:model="glLocation" />
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Garantie bis</label>
                <x-asset-manager-input size="sm" type="date" wire:model="glWarranty" />
                @error('glWarranty') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Leasing bis</label>
                <x-asset-manager-input size="sm" type="date" wire:model="glLease" />
                @error('glLease') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Kreditor</label>
                <x-asset-manager-select size="sm" wire:model="glVendor">
                    <option value="">– keiner –</option>
                    @foreach($vendors as $v)
                        <option value="{{ $v->id }}">{{ $v->name }}</option>
                    @endforeach
                </x-asset-manager-select>
                @error('glVendor') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Bestellnr.</label>
                <x-asset-manager-input size="sm" type="text" wire:model="glOrderNo" />
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Bestelldatum</label>
                <x-asset-manager-input size="sm" type="date" wire:model="glOrderDate" />
                @error('glOrderDate') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
            </div>
        </div>
    </div>

    <x-slot name="footer">
        <x-asset-manager-button variant="ghost" size="sm" x-on:click="modalShow = false">Abbrechen</x-asset-manager-button>
        <x-asset-manager-button variant="primary" size="sm" wire:click="saveDeviceEdit" wire:loading.attr="disabled">
            @svg('heroicon-o-check', 'w-3.5 h-3.5') Speichern
        </x-asset-manager-button>
    </x-slot>
</x-ui-modal>
