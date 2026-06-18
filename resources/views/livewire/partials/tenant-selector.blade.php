{{--
    Tenant-Selektor (M3). Nur sichtbar bei ≥2 Tenants; bei genau einem filtern die Views still auf
    diesen. Per @include im actions-Slot der Inventar-Actionbars. selectedTenantId + tenantOptions()
    + showTenantSelector() liefert das Livewire-Trait Concerns\ScopesToTenant.
--}}
@if($this->showTenantSelector())
    <label class="inline-flex items-center gap-1.5">
        <span class="inline-flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)]">
            @svg('heroicon-o-building-office-2', 'w-3.5 h-3.5')
            Tenant
        </span>
        <select wire:model.live="selectedTenantId"
            class="px-2 py-1.5 text-xs font-medium rounded-lg bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40 text-[var(--ui-secondary)] focus:outline-none focus:ring-2 focus:ring-violet-500/30">
            @foreach($this->tenantOptions() as $tenant)
                <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
            @endforeach
        </select>
    </label>
@endif
