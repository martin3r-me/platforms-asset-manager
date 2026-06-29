{{-- Gerät: Lifecycle & Beschaffung (Phase 4). Intune-Stammdaten bleiben read-only; das hier wird
     manuell gepflegt. Status-Wechsel schreibt ein Audit-Event (ADR 0007). --}}
<x-ui-modal model="showDeviceEdit" size="lg">
    <x-slot name="header">Lifecycle &amp; Beschaffung</x-slot>

    <div class="space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)] mb-1">Lifecycle-Status</label>
                <select wire:model="glStatus" class="w-full px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10">
                    <option value="">– kein Status –</option>
                    @foreach(\Platform\AssetManager\Models\AssetDevice::LIFECYCLE_LABELS as $val => $label)
                        <option value="{{ $val }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('glStatus') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)] mb-1">Standort</label>
                <input type="text" wire:model="glLocation" class="w-full px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10" />
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)] mb-1">Garantie bis</label>
                <input type="date" wire:model="glWarranty" class="w-full px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10" />
                @error('glWarranty') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)] mb-1">Leasing bis</label>
                <input type="date" wire:model="glLease" class="w-full px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10" />
                @error('glLease') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)] mb-1">Kreditor</label>
                <select wire:model="glVendor" class="w-full px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10">
                    <option value="">– keiner –</option>
                    @foreach($vendors as $v)
                        <option value="{{ $v->id }}">{{ $v->name }}</option>
                    @endforeach
                </select>
                @error('glVendor') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)] mb-1">Bestellnr.</label>
                <input type="text" wire:model="glOrderNo" class="w-full px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10" />
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)] mb-1">Bestelldatum</label>
                <input type="date" wire:model="glOrderDate" class="w-full px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10" />
                @error('glOrderDate') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
            </div>
        </div>
    </div>

    <x-slot name="footer">
        <x-ui-button variant="secondary-outline" size="sm" x-on:click="modalShow = false">Abbrechen</x-ui-button>
        <x-ui-button variant="primary" size="sm" wire:click="saveDeviceEdit" wire:loading.attr="disabled">
            @svg('heroicon-o-check', 'w-3.5 h-3.5') Speichern
        </x-ui-button>
    </x-slot>
</x-ui-modal>
