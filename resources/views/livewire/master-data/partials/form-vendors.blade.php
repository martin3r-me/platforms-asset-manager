{{-- Kreditor bearbeiten/anlegen. Vars: $form, $selectedId, $creating --}}
<section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm p-3 space-y-3">
    <div>
        <label class="block text-xs text-gray-500 mb-1">Name</label>
        <input type="text" wire:model="form.name" placeholder="z.B. Vodafone"
               class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
        @error('form.name')<span class="text-[10px] text-red-500">{{ $message }}</span>@enderror
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Kreditor-Nr.</label>
        <input type="text" wire:model="form.creditor_no" placeholder="optional"
               class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
    </div>

    @include('asset-manager::livewire.master-data.partials.form-actions', [
        'id'      => $selectedId,
        'confirm' => 'Kreditor wirklich löschen? Zuordnungen an Positionen/Kostenarten werden entfernt.',
    ])
</section>
