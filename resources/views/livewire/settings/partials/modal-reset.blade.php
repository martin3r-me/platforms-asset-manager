{{-- Team-Reset-Bestätigung — Type-to-confirm (Teamname). Nur Owner/Admin (Panel + Gate). --}}
<x-ui-modal model="showReset" size="sm">
    <x-slot name="header">Modul zurücksetzen</x-slot>

    <div class="space-y-4">
        <div class="flex items-start gap-3">
            <div class="flex-shrink-0 w-9 h-9 rounded-lg bg-red-50 border border-red-200 flex items-center justify-center">
                @svg('heroicon-o-exclamation-triangle', 'w-5 h-5 text-red-600')
            </div>
            <div class="min-w-0">
                <p class="text-sm font-medium text-[var(--am-text)]">Wirklich alles löschen?</p>
                <p class="text-xs text-[var(--am-text-secondary)] mt-1">
                    Alle Einträge dieses Teams (Inventar, Geräte, Mitarbeiter, Zuordnungen, Ausgaben,
                    Kostenzeilen, Lizenzen, Verlauf und Stammdaten) werden dauerhaft gelöscht. Die
                    Intune-Anbindung und die Controlling-Einstellung bleiben erhalten. Diese Aktion kann
                    <strong>nicht</strong> rückgängig gemacht werden.
                </p>
            </div>
        </div>

        <div>
            <label for="reset-phrase" class="block text-xs text-[var(--am-text-secondary)] mb-1.5">
                Zum Bestätigen den Teamnamen eintippen:
                <span class="font-semibold text-[var(--am-text)] select-all">{{ $teamName }}</span>
            </label>
            <x-asset-manager-input
                id="reset-phrase"
                type="text"
                wire:model.live.debounce.250ms="resetPhrase"
                autocomplete="off"
                autocapitalize="off"
                spellcheck="false"
                placeholder="{{ $teamName }}"
            />
        </div>
    </div>

    <x-slot name="footer">
        <x-asset-manager-button variant="secondary" size="sm" x-on:click="modalShow = false">Abbrechen</x-asset-manager-button>
        <x-asset-manager-button
            variant="danger"
            size="sm"
            wire:click="confirmReset"
            wire:loading.attr="disabled"
            :disabled="trim($resetPhrase) !== $teamName"
        >
            @svg('heroicon-o-trash', 'w-3.5 h-3.5') Dauerhaft löschen
        </x-asset-manager-button>
    </x-slot>
</x-ui-modal>
