{{-- Kreditor bearbeiten/anlegen. Vars: $form, $selectedId, $creating --}}
<section class="rounded-xl bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm p-3 space-y-3">
    <div>
        <label class="block text-xs text-[var(--am-text-muted)] mb-1">Name</label>
        <x-asset-manager-input size="sm" type="text" wire:model="form.name" placeholder="z.B. Vodafone" />
        @error('form.name')<span class="text-[10px] text-red-600">{{ $message }}</span>@enderror
    </div>
    <div>
        <label class="block text-xs text-[var(--am-text-muted)] mb-1">Kreditor-Nr.</label>
        <x-asset-manager-input size="sm" type="text" wire:model="form.creditor_no" placeholder="optional" />
    </div>

    @include('asset-manager::livewire.master-data.partials.form-actions', [
        'id'      => $selectedId,
        'confirm' => 'Kreditor wirklich löschen? Zuordnungen an Positionen/Kostenarten werden entfernt.',
    ])
</section>
