{{--
    Abrechnungs-Ausnahme eines einzelnen Trägers (ADR 0024).

    Die Kachel mit „Menge vorher → nachher" ist der Grund, warum das ein eigenes Modal ist und kein
    Häkchen in der Profilmaske: die Entscheidung verändert eine Kundenrechnung. Der Grund ist
    Pflichtfeld — nicht aus Formalismus, sondern weil er in Vorschau und Snapshot jedes künftigen
    Rechnungslaufs als Ausschlussgrund erscheint und dort die Frage „warum 151 statt 152?"
    beantworten muss.
--}}
<x-ui-modal model="showBillingExclusion" size="md">
    <x-slot name="header">Weiterberechnung</x-slot>

    <div class="space-y-4">
        @if($holder->isBillingExcluded())
            <div class="rounded-lg border border-[color:var(--am-border-strong)] bg-[var(--am-bg)] px-3 py-2.5 text-xs text-[var(--am-text-secondary)]">
                Ausgenommen seit <strong class="text-[var(--am-text)]">{{ $holder->billing_excluded_at->format('d.m.Y') }}</strong>
                @if($holder->billingExcludedBy)
                    von <strong class="text-[var(--am-text)]">{{ $holder->billingExcludedBy->name }}</strong>
                @endif
                — dieser Träger steht auf keiner Rechnung.
            </div>
        @else
            <p class="text-sm text-[var(--am-text-secondary)] m-0">
                Dieser Träger folgt der Zählregel des Abrechnungsprofils. Eine Ausnahme nimmt ihn
                dauerhaft heraus, bis sie hier wieder aufgehoben wird — der Sync fasst sie nicht an.
            </p>
        @endif

        <div>
            <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">
                Grund der Ausnahme <span class="text-red-700">*</span>
            </label>
            <x-asset-manager-input size="sm" type="text" wire:model="billingReason"
                                   placeholder="z. B. „Geschäftsführung, laut Vertrag nicht berechnet“" />
            @error('billingReason') <p class="text-[10px] text-red-700 mt-0.5">{{ $message }}</p> @enderror
            <p class="text-[10px] text-[var(--am-text-muted)] mt-1 leading-tight">
                Steht später als Ausschlussgrund in jeder Rechnungsvorschau. Ohne ihn ist die Ausnahme
                in einem halben Jahr nicht mehr von einem Datenfehler zu unterscheiden.
            </p>
        </div>

        {{-- Wirkung je aktivem Abrechnungsprofil — gerechnet mit derselben Regel wie der Lauf. --}}
        @if($billingImpact)
            @foreach($billingImpact as $row)
                <div class="rounded-lg border border-[color:var(--am-border-strong)] bg-[var(--am-bg)] px-4 py-3">
                    <div class="text-[10px] uppercase tracking-wider text-[var(--am-text-muted)]">{{ $row['profile_name'] }}</div>
                    <div class="flex flex-wrap items-baseline gap-3 mt-1">
                        <span class="text-sm text-[var(--am-text-secondary)]">Abgerechnete Menge</span>
                        <span class="text-lg font-semibold tabular-nums text-[var(--am-text)]">
                            {{ $row['quantity_before'] }} → {{ $row['quantity_after'] }}
                        </span>
                        @if($row['delta_quantity'] === 0)
                            <span class="text-xs text-[var(--am-text-secondary)]">unverändert</span>
                        @elseif($row['delta_net_cents'] !== null)
                            <span class="text-sm font-semibold tabular-nums {{ $row['delta_net_cents'] < 0 ? 'text-red-700' : 'text-emerald-700' }}">
                                {{ $row['delta_net_cents'] < 0 ? '−' : '+' }}{{ number_format(abs($row['delta_net_cents']) / 100, 2, ',', '.') }}
                                {{ $row['currency'] }} netto / Monat
                            </span>
                        @endif
                    </div>
                    @if($row['delta_quantity'] === 0)
                        <p class="text-xs text-[var(--am-text-secondary)] mt-2 mb-0">
                            Die Zählregel hält diesen Träger ohnehin heraus (kein Personenkonto, keine
                            Lizenz, ausgeschlossene Domain …). Die Ausnahme wäre eine Notiz, keine Ersparnis.
                        </p>
                    @endif
                </div>
            @endforeach
        @endif
    </div>

    <x-slot name="footer">
        <x-asset-manager-button variant="secondary" size="sm" wire:click="$set('showBillingExclusion', false)">
            Abbrechen
        </x-asset-manager-button>

        @if($holder->isBillingExcluded())
            {{-- Zwei Wege aus einer bestehenden Ausnahme: den Grund korrigieren („Elternzeit" →
                 „ausgeschieden") oder sie ganz aufheben. Beides ist eine bewusste Handlung. --}}
            <x-asset-manager-button variant="secondary" size="sm" wire:click="saveBillingExclusion(true)" wire:loading.attr="disabled">
                @svg('heroicon-o-pencil-square', 'w-3.5 h-3.5')
                Grund speichern
            </x-asset-manager-button>
            <x-asset-manager-button variant="primary" size="sm" wire:click="saveBillingExclusion(false)" wire:loading.attr="disabled">
                @svg('heroicon-o-arrow-uturn-left', 'w-3.5 h-3.5')
                Ausnahme aufheben
            </x-asset-manager-button>
        @else
            <x-asset-manager-button variant="primary" size="sm" wire:click="saveBillingExclusion(true)" wire:loading.attr="disabled">
                @svg('heroicon-o-check', 'w-3.5 h-3.5')
                Ausnehmen
            </x-asset-manager-button>
        @endif
    </x-slot>
</x-ui-modal>
