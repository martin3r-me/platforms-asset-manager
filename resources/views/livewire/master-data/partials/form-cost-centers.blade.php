{{-- Kostenstelle bearbeiten/anlegen. Vars: $form, $selectedId, $creating, $companies --}}
<section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm p-3 space-y-3">
    <div>
        <label class="block text-xs text-gray-500 mb-1">Code</label>
        @if($creating)
            <input type="text" wire:model="form.code" placeholder="z.B. 2599"
                   class="w-full px-3 py-1.5 text-sm font-mono rounded-lg border border-[var(--ui-border)] bg-white">
            @error('form.code')<span class="text-[10px] text-red-500">{{ $message }}</span>@enderror
        @else
            <div class="px-3 py-1.5 text-sm font-mono rounded-lg bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40 text-gray-600">{{ $form['code'] ?? '' }}</div>
        @endif
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Bezeichnung</label>
        <input type="text" wire:model="form.name" placeholder="optional"
               class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Gesellschaft</label>
        <select wire:model="form.company_id" class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
            <option value="">– keine –</option>
            @foreach($companies as $co)<option value="{{ $co->id }}">{{ $co->name }}</option>@endforeach
        </select>
    </div>
    <label class="flex items-center gap-2 text-sm text-[var(--ui-secondary)]">
        <input type="checkbox" wire:model="form.is_active" class="rounded"> aktiv
    </label>

    @include('asset-manager::livewire.master-data.partials.form-actions', [
        'id'      => $selectedId,
        'confirm' => 'Kostenstelle wirklich löschen? Zuordnungen an Mitarbeitern/Positionen werden entfernt.',
    ])
</section>
