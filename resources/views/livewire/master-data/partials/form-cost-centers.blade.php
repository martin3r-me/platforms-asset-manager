{{-- Kostenstelle bearbeiten/anlegen. Vars: $form, $selectedId, $creating, $companies --}}
<section class="rounded-xl bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm p-3 space-y-3">
    <div>
        <label class="block text-xs text-[var(--am-text-muted)] mb-1">Code</label>
        @if($creating)
            <x-asset-manager-input size="sm" type="text" wire:model="form.code" placeholder="z.B. 2599" class="font-mono" />
            @error('form.code')<span class="text-[10px] text-red-600">{{ $message }}</span>@enderror
        @else
            <div class="px-2 py-1.5 text-xs font-mono rounded-lg bg-[var(--am-bg)] border border-[color:var(--am-border)] text-[var(--am-text-secondary)]">{{ $form['code'] ?? '' }}</div>
        @endif
    </div>
    <div>
        <label class="block text-xs text-[var(--am-text-muted)] mb-1">Bezeichnung</label>
        <x-asset-manager-input size="sm" type="text" wire:model="form.name" placeholder="optional" />
    </div>
    <div>
        <label class="block text-xs text-[var(--am-text-muted)] mb-1">Gesellschaft</label>
        <x-asset-manager-select size="sm" wire:model="form.company_id">
            <option value="">– keine –</option>
            @foreach($companies as $co)<option value="{{ $co->id }}">{{ $co->name }}</option>@endforeach
        </x-asset-manager-select>
    </div>
    <label class="flex items-center gap-2 text-sm text-[var(--am-text-secondary)]">
        <input type="checkbox" wire:model="form.is_active" class="rounded"> aktiv
    </label>

    @include('asset-manager::livewire.master-data.partials.form-actions', [
        'id'      => $selectedId,
        'confirm' => 'Kostenstelle wirklich löschen? Zuordnungen an Mitarbeitern/Positionen werden entfernt.',
    ])
</section>
