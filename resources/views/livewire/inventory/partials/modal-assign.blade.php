{{-- Zuordnen — manuelles Asset (E2/E7): Mitarbeiter + Gültig ab/bis, keine Signatur-Toggles. --}}
<x-ui-modal model="showAssign" size="md">
    <x-slot name="header">Mitarbeiter zuordnen</x-slot>

    <div class="space-y-4">
        <div>
            <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Mitarbeiter</label>
            <x-asset-manager-select size="md" wire:model="aAssigneeId">
                <option value="">– Niemand (zurück ins Lager) –</option>
                @foreach($employees as $emp)
                    <option value="{{ $emp->id }}">{{ $emp->name }}</option>
                @endforeach
            </x-asset-manager-select>
            @error('aAssigneeId') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Gültig ab</label>
                <x-asset-manager-input size="md" type="date" wire:model="aValidFrom" />
                @error('aValidFrom') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Gültig bis (optional)</label>
                <x-asset-manager-input size="md" type="date" wire:model="aValidUntil" />
                @error('aValidUntil') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
            </div>
        </div>

        <p class="text-[11px] text-[var(--am-text-secondary)]">Die bisherige offene Zuordnung wird automatisch beendet und in der Historie festgehalten.</p>
    </div>

    <x-slot name="footer">
        <x-asset-manager-button variant="ghost" size="sm" x-on:click="modalShow = false">Abbrechen</x-asset-manager-button>
        <x-asset-manager-button variant="primary" size="sm" wire:click="saveAssign" wire:loading.attr="disabled">
            @svg('heroicon-o-check', 'w-3.5 h-3.5') Zuordnen
        </x-asset-manager-button>
    </x-slot>
</x-ui-modal>
