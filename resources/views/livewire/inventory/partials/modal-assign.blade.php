{{-- Zuordnen — manuelles Asset (E2/E7): Mitarbeiter + Gültig ab/bis, keine Signatur-Toggles. --}}
<x-ui-modal model="showAssign" size="md">
    <x-slot name="header">Mitarbeiter zuordnen</x-slot>

    <div class="space-y-4">
        <div>
            <label class="block text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)] mb-1">Mitarbeiter</label>
            <select wire:model="aAssigneeId" class="w-full px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10">
                <option value="">– Niemand (zurück ins Lager) –</option>
                @foreach($employees as $emp)
                    <option value="{{ $emp->id }}">{{ $emp->name }}</option>
                @endforeach
            </select>
            @error('aAssigneeId') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)] mb-1">Gültig ab</label>
                <input type="date" wire:model="aValidFrom" class="w-full px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10" />
                @error('aValidFrom') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)] mb-1">Gültig bis (optional)</label>
                <input type="date" wire:model="aValidUntil" class="w-full px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10" />
                @error('aValidUntil') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
            </div>
        </div>

        <p class="text-[11px] text-[color:var(--ui-secondary)]">Die bisherige offene Zuordnung wird automatisch beendet und in der Historie festgehalten.</p>
    </div>

    <x-slot name="footer">
        <x-ui-button variant="secondary-outline" size="sm" x-on:click="modalShow = false">Abbrechen</x-ui-button>
        <x-ui-button variant="primary" size="sm" wire:click="saveAssign" wire:loading.attr="disabled">
            @svg('heroicon-o-check', 'w-3.5 h-3.5') Zuordnen
        </x-ui-button>
    </x-slot>
</x-ui-modal>
