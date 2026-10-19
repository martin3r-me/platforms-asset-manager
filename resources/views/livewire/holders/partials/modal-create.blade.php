{{--
    Asset-Träger von Hand anlegen (#881) — für einen Tenant ohne Microsoft-Anbindung, für Externe und
    für Träger, die das Verzeichnis (noch) nicht kennt. Die Regeln (UPN-Normalisierung, Eindeutigkeit
    je Tenant, Klassifizierung) liegen im HolderService, den sich das Modal mit dem MCP-Tool teilt.
--}}
<x-ui-modal model="showCreate" size="lg">
    <x-slot name="header">Asset-Träger anlegen</x-slot>

    <div class="space-y-4">
        <div>
            <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">UPN / Anmeldename *</label>
            <x-asset-manager-input size="sm" type="text" wire:model="newUpn" placeholder="max.muster@firma.de" />
            @error('newUpn') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
            <p class="text-[10px] text-[var(--am-text-muted)] mt-1 leading-tight">
                Identitätsanker des Trägers. Findet ein späterer Verzeichnis-Abgleich dieselbe UPN, reichert er
                diesen Träger an, statt einen zweiten anzulegen — stillgelegt wird ein von Hand angelegter
                Träger vom Abgleich nie.
            </p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Anzeigename</label>
                <x-asset-manager-input size="sm" type="text" wire:model="newDisplayName" />
                @error('newDisplayName') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">E-Mail</label>
                <x-asset-manager-input size="sm" type="email" wire:model="newEmail" placeholder="leer = UPN" />
                @error('newEmail') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Träger-Typ</label>
                <x-asset-manager-select size="sm" wire:model="newHolderType">
                    <option value="">Automatisch (Erkennungsregeln)</option>
                    @foreach($holderTypes as $type)
                        <option value="{{ $type }}">{{ \Platform\AssetManager\Models\AssetHolder::holderTypeLabelFor($type) }}</option>
                    @endforeach
                </x-asset-manager-select>
                @error('newHolderType') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
                <p class="text-[10px] text-[var(--am-text-muted)] mt-1 leading-tight">Ein gewählter Typ wird festgehalten.</p>
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Kostenstelle</label>
                <x-asset-manager-select size="sm" wire:model="newCostCenterId">
                    <option value="">— keine —</option>
                    @foreach($costCenters as $cc)
                        <option value="{{ $cc->id }}">{{ $cc->name ? $cc->code . ' — ' . $cc->name : $cc->code }}</option>
                    @endforeach
                </x-asset-manager-select>
                @error('newCostCenterId') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Abteilung</label>
                <x-asset-manager-input size="sm" type="text" wire:model="newDepartment" />
                @error('newDepartment') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Position</label>
                <x-asset-manager-input size="sm" type="text" wire:model="newJobTitle" />
                @error('newJobTitle') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
            </div>
        </div>
    </div>

    <x-slot name="footer">
        <x-asset-manager-button variant="ghost" size="sm" x-on:click="modalShow = false">Abbrechen</x-asset-manager-button>
        <x-asset-manager-button variant="primary" size="sm" wire:click="createHolder" wire:loading.attr="disabled" wire:target="createHolder">
            @svg('heroicon-o-user-plus', 'w-3.5 h-3.5') Anlegen
        </x-asset-manager-button>
    </x-slot>
</x-ui-modal>
