{{-- Kostenart bearbeiten/anlegen. Vars: $form, $selectedId, $creating, $vendors --}}
<section class="rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm p-3 space-y-3">
    <div>
        <label class="block text-xs text-gray-500 mb-1">Name</label>
        <input type="text" wire:model="form.name" placeholder="z.B. Microsoft 365"
               class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
        @error('form.name')<span class="text-[10px] text-red-500">{{ $message }}</span>@enderror
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Kreditor (Standard)</label>
        <select wire:model="form.vendor_default_id" class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
            <option value="">– keiner –</option>
            @foreach($vendors as $v)<option value="{{ $v->id }}">{{ $v->name }}</option>@endforeach
        </select>
    </div>
    <div class="grid grid-cols-2 gap-2">
        <div>
            <label class="block text-xs text-gray-500 mb-1">Frequenz</label>
            <select wire:model="form.frequency_default" class="w-full px-2 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                <option value="monthly">mtl.</option>
                <option value="quarterly">qrtl.</option>
                <option value="yearly">jähr.</option>
                <option value="once">einm.</option>
            </select>
            @error('form.frequency_default')<span class="text-[10px] text-red-500">{{ $message }}</span>@enderror
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">System</label>
            <select wire:model="form.system_default" class="w-full px-2 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
                <option value="">–</option>
                <option value="HGK">HGK</option>
                <option value="Moss">Moss</option>
            </select>
        </div>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Quelle</label>
        <select wire:model="form.aggregation_source" class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--ui-border)] bg-white">
            <option value="cost_line">Kostenposition</option>
            <option value="hardware_afa">Hardware-AfA</option>
            <option value="ms_license">MS-Lizenz (Graph)</option>
            <option value="asset_device">Geräte-Kosten</option>
        </select>
        @error('form.aggregation_source')<span class="text-[10px] text-red-500">{{ $message }}</span>@enderror
    </div>
    <label class="flex items-center gap-2 text-sm text-[var(--ui-secondary)]">
        <input type="checkbox" wire:model="form.is_per_employee" class="rounded"> pro Mitarbeiter
    </label>

    @include('asset-manager::livewire.master-data.partials.form-actions', [
        'id'      => $selectedId,
        'confirm' => 'Kostenart wirklich löschen?',
    ])
</section>
