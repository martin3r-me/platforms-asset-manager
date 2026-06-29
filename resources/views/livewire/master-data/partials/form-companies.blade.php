{{-- Gesellschaft bearbeiten/anlegen. Vars: $form, $selectedId, $creating --}}
<x-asset-manager-panel title="Gesellschaft">
    <div class="space-y-3">
        <div>
            <label class="block text-xs text-[var(--am-text-muted)] mb-1">Name</label>
            <x-asset-manager-input size="sm" type="text" wire:model="form.name" placeholder="z.B. Verwaltung" class="w-full" />
            @error('form.name')<span class="text-[10px] text-red-500">{{ $message }}</span>@enderror
        </div>
        <div>
            <label class="block text-xs text-[var(--am-text-muted)] mb-1">Sortierung</label>
            <x-asset-manager-input size="sm" type="number" wire:model="form.sort_order" placeholder="automatisch" class="w-full" />
        </div>

        @include('asset-manager::livewire.master-data.partials.form-actions', [
            'id'      => $selectedId,
            'confirm' => 'Gesellschaft wirklich löschen? Die Kostenstellen-Zuordnungen werden entfernt.',
        ])
    </div>
</x-asset-manager-panel>
