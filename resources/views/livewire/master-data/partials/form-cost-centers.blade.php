{{-- Kostenstelle bearbeiten/anlegen. Vars: $form, $selectedId, $creating, $parents --}}
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
        <label class="block text-xs text-[var(--am-text-muted)] mb-1">Übergeordnet</label>
        <x-asset-manager-select size="sm" wire:model="form.parent_id">
            <option value="">– oberster Knoten –</option>
            @foreach($parents as $node)<option value="{{ $node->id }}">{{ $node->tree_label }}</option>@endforeach
        </x-asset-manager-select>
        @error('form.parent_id')<span class="text-[10px] text-red-600">{{ $message }}</span>@enderror
        <p class="mt-1 text-[10px] text-[var(--am-text-muted)]">
            Oberste Knoten sind die Gesellschaften. Beliebige Tiefe möglich; die eigene Kostenstelle und
            ihre untergeordneten stehen nicht zur Auswahl (das wäre ein Zyklus).
        </p>
    </div>
    <label class="flex items-center gap-2 text-sm text-[var(--am-text-secondary)]">
        <input type="checkbox" wire:model="form.is_active" class="rounded"> aktiv
    </label>

    @include('asset-manager::livewire.master-data.partials.form-actions', [
        'id'      => $selectedId,
        'confirm' => 'Kostenstelle wirklich löschen? Untergeordnete werden zu obersten Knoten, Zuordnungen an Trägern/Positionen werden entfernt.',
    ])
</section>
