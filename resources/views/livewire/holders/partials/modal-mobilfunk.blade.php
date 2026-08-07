{{-- Mobilfunk-Stammdaten am Asset-Träger bearbeiten (ADR 0014). Rufnummern kommen aus Entra, außer manuell übersteuert. --}}
<x-ui-modal model="showMobilfunk" size="lg">
    <x-slot name="header">Mobilfunk bearbeiten</x-slot>

    <div class="space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Mobilnummer</label>
                <x-asset-manager-input size="sm" type="text" wire:model="mobilePhone" />
                @error('mobilePhone') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Geschäftlich</label>
                <x-asset-manager-input size="sm" type="text" wire:model="businessPhone" />
                @error('businessPhone') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="rounded-lg bg-[var(--am-bg)] border border-[color:var(--am-border)] px-3 py-2">
            <label class="flex items-center gap-2 text-[11px] text-[var(--am-text-secondary)]">
                <input type="checkbox" wire:model="phoneFromEntra" class="rounded border-[color:var(--am-border)]" />
                Rufnummern automatisch aus Entra beziehen
            </label>
            <p class="text-[10px] text-[var(--am-text-muted)] mt-1 leading-tight">Deaktivieren, um die Nummern manuell zu pflegen — der Sync überschreibt sie dann nicht mehr.</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">SIM-Nummer</label>
                <x-asset-manager-input size="sm" type="text" wire:model="simNumber" />
                @error('simNumber') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Vertragsnummer</label>
                <x-asset-manager-input size="sm" type="text" wire:model="contractNumber" />
                @error('contractNumber') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
            </div>
        </div>

        <div>
            <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Datenvolumen</label>
            <x-asset-manager-input size="sm" type="text" wire:model="dataVolume" />
            @error('dataVolume') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
        </div>
    </div>

    <x-slot name="footer">
        <x-asset-manager-button variant="ghost" size="sm" x-on:click="modalShow = false">Abbrechen</x-asset-manager-button>
        <x-asset-manager-button variant="primary" size="sm" wire:click="saveMobilfunk" wire:loading.attr="disabled">
            @svg('heroicon-o-check', 'w-3.5 h-3.5') Speichern
        </x-asset-manager-button>
    </x-slot>
</x-ui-modal>
