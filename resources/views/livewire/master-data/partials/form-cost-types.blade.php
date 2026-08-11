{{-- Kostenart bearbeiten/anlegen. Vars: $form, $selectedId, $creating, $vendors --}}
<section class="rounded-xl bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm p-3 space-y-3">
    <div>
        <label class="block text-xs text-[var(--am-text-muted)] mb-1">Name</label>
        <x-asset-manager-input size="sm" type="text" wire:model="form.name" placeholder="z.B. Microsoft 365" class="w-full" />
        @error('form.name')<span class="text-[10px] text-red-600">{{ $message }}</span>@enderror
    </div>
    <div>
        <label class="block text-xs text-[var(--am-text-muted)] mb-1">Kreditor (Standard)</label>
        <x-asset-manager-select size="sm" wire:model="form.vendor_default_id" class="w-full">
            <option value="">– keiner –</option>
            @foreach($vendors as $v)<option value="{{ $v->id }}">{{ $v->name }}</option>@endforeach
        </x-asset-manager-select>
    </div>
    <div class="grid grid-cols-2 gap-2">
        <div>
            <label class="block text-xs text-[var(--am-text-muted)] mb-1">Frequenz</label>
            <x-asset-manager-select size="sm" wire:model="form.frequency_default" class="w-full">
                <option value="monthly">mtl.</option>
                <option value="quarterly">qrtl.</option>
                <option value="yearly">jähr.</option>
                <option value="once">einm.</option>
            </x-asset-manager-select>
            @error('form.frequency_default')<span class="text-[10px] text-red-600">{{ $message }}</span>@enderror
        </div>
        <div>
            <label class="block text-xs text-[var(--am-text-muted)] mb-1">System</label>
            <x-asset-manager-select size="sm" wire:model="form.system_default" class="w-full">
                <option value="">–</option>
                <option value="HGK">HGK</option>
                <option value="Moss">Moss</option>
            </x-asset-manager-select>
        </div>
    </div>
    <div>
        <label class="block text-xs text-[var(--am-text-muted)] mb-1">Quelle</label>
        <x-asset-manager-select size="sm" wire:model="form.aggregation_source" class="w-full">
            <option value="cost_line">Kostenposition</option>
            <option value="hardware_afa">Hardware-AfA</option>
            <option value="ms_license">MS-Lizenz (Graph)</option>
            <option value="asset_device">Geräte-Kosten</option>
        </x-asset-manager-select>
        @error('form.aggregation_source')<span class="text-[10px] text-red-600">{{ $message }}</span>@enderror
    </div>
    {{-- Bezugsebene statt des früheren Häkchens „pro Asset-Träger": sie wird in der
         Plausibilitätsprüfung ausgewertet, ist also keine Beschriftung mehr. --}}
    <div>
        <label class="block text-xs text-[var(--am-text-muted)] mb-1">Bezugsebene</label>
        <x-asset-manager-select size="sm" wire:model="form.allocation_level">
            <option value="cost_center">Kostenstelle — gehört keiner Person (Internet, Drucker, BPEvent, Team-Abos)</option>
            <option value="person">Person — gehört einem Asset-Träger (Mobilfunk, Lap+Dock, Lizenz je Seat)</option>
        </x-asset-manager-select>
        @error('form.allocation_level')<span class="text-[10px] text-red-600">{{ $message }}</span>@enderror
        <p class="text-[10px] text-[var(--am-text-muted)] mt-1">
            Bestimmt, welche Zuordnung eine Kostenposition dieser Art braucht. Die Plausibilitätsprüfung
            im Kosten-Dashboard meldet Positionen, die nicht dazu passen.
        </p>
    </div>

    @include('asset-manager::livewire.master-data.partials.form-actions', [
        'id'      => $selectedId,
        'confirm' => 'Kostenart wirklich löschen?',
    ])
</section>
