{{-- Bearbeiten (Details) — manuelles Asset. Bei source=intune Hardware-Felder read-only (E?/Assets/Show). --}}
<x-ui-modal model="showEdit" size="lg">
    <x-slot name="header">Asset bearbeiten</x-slot>

    @if($item)
        @php $isIntune = $item->source === 'intune'; @endphp
        <div class="space-y-4">
            @if($isIntune)
                <p class="text-[11px] text-violet-700 dark:text-violet-400 flex items-center gap-1.5">
                    @svg('heroicon-o-cloud', 'w-3.5 h-3.5') Intune-synced — Gerätedaten read-only, nur Status editierbar.
                </p>
            @endif

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)] mb-1">Kategorie *</label>
                    <select wire:model="eCategoryId" @disabled($isIntune) class="w-full px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10 disabled:opacity-60">
                        @foreach($categories as $cat)
                            <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                        @endforeach
                    </select>
                    @error('eCategoryId') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)] mb-1">Status *</label>
                    <select wire:model="eStatus" class="w-full px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10">
                        <option value="in_stock">Lager</option>
                        <option value="assigned">Zugeordnet</option>
                        <option value="retired">Ausgemustert</option>
                        <option value="lost">Verloren</option>
                    </select>
                    @error('eStatus') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)] mb-1">Name *</label>
                <input type="text" wire:model="eName" @disabled($isIntune) class="w-full px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10 disabled:opacity-60" />
                @error('eName') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)] mb-1">Hersteller</label>
                    <input type="text" wire:model="eManufacturer" @disabled($isIntune) class="w-full px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10 disabled:opacity-60" />
                </div>
                <div>
                    <label class="block text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)] mb-1">Modell</label>
                    <input type="text" wire:model="eModel" @disabled($isIntune) class="w-full px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10 disabled:opacity-60" />
                </div>
                <div>
                    <label class="block text-[10px] uppercase tracking-wider text-[color:var(--ui-secondary)] mb-1">Seriennummer</label>
                    <input type="text" wire:model="eSerial" @disabled($isIntune) class="w-full px-2 py-1.5 text-xs rounded-md bg-black/[0.02] dark:bg-white/[0.03] border border-black/10 dark:border-white/10 disabled:opacity-60" />
                </div>
            </div>
        </div>
    @endif

    <x-slot name="footer">
        <x-ui-button variant="secondary-outline" size="sm" x-on:click="modalShow = false">Abbrechen</x-ui-button>
        <x-ui-button variant="primary" size="sm" wire:click="saveEdit" wire:loading.attr="disabled">
            @svg('heroicon-o-check', 'w-3.5 h-3.5') Speichern
        </x-ui-button>
    </x-slot>
</x-ui-modal>
