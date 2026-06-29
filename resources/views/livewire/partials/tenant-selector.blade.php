{{--
    Tenant-Selektor (M3). Nur sichtbar bei ≥2 Tenants; bei genau einem filtern die Views still auf
    diesen. Per @include im actions-Slot der Inventar-Actionbars. selectedTenantId + tenantOptions()
    + showTenantSelector() liefert das Livewire-Trait Concerns\ScopesToTenant.
--}}
@if($this->showTenantSelector())
    <label class="inline-flex items-center gap-1.5">
        <span class="inline-flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">
            @svg('heroicon-o-building-office-2', 'w-3.5 h-3.5')
            Tenant
        </span>
        <x-asset-manager-select size="sm" wire:model.live="selectedTenantId">
            @foreach($this->tenantOptions() as $tenant)
                <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
            @endforeach
        </x-asset-manager-select>
    </label>
@endif
