<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-asset-manager-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Weiterberechnung', 'icon' => 'document-currency-euro'],
        ]">
            <x-slot name="actions">
                @if($canManage)
                    <x-asset-manager-button variant="primary" size="sm" wire:click="startCreate">
                        @svg('heroicon-o-plus', 'w-3.5 h-3.5')
                        Neues Profil
                    </x-asset-manager-button>
                @endif
            </x-slot>
        </x-asset-manager-page-actionbar>
    </x-slot>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Abrechnungsprofile" icon="heroicon-o-users" width="w-72" :defaultOpen="true">
            <div class="p-3 space-y-1 bg-[var(--am-bg)]">
                @forelse($profiles as $profile)
                    <button type="button" wire:key="prof-{{ $profile->id }}" wire:click="selectProfile({{ $profile->id }})"
                            class="w-full text-left rounded-lg px-3 py-2 transition-colors border {{ $selected && $selected->id === $profile->id
                                ? 'bg-[var(--am-surface)] border-[color:var(--am-accent)]'
                                : 'bg-transparent border-transparent hover:bg-[var(--am-surface)]' }}">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-medium text-[var(--am-text)] truncate">{{ $profile->name }}</span>
                            @unless($profile->active)
                                <x-asset-manager-badge color="gray" size="xs">inaktiv</x-asset-manager-badge>
                            @endunless
                        </div>
                        <div class="text-[10px] text-[var(--am-text-muted)] mt-0.5 truncate">
                            {{ $profile->easybill_customer_name ?: 'kein easybill-Kunde' }}
                        </div>
                    </button>
                @empty
                    <p class="px-3 py-4 text-xs text-[var(--am-text-secondary)]">
                        Noch kein Profil angelegt.
                    </p>
                @endforelse
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <div class="flex-1 flex flex-col min-h-0 min-w-0">
        <div class="flex-1 overflow-y-auto p-6 space-y-4">

            @if($flash)
                <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {{ $flash }}
                </div>
            @endif

            @if($error)
                <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                    {{ $error }}
                </div>
            @endif

            @unless($gatewayAvailable)
                {{-- Kein Rechnungssystem: die Vorschau bleibt trotzdem nutzbar. Sie ist der eigentliche
                     Prüfschritt, und sie funktioniert ohne jede Anbindung. --}}
                <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    <strong>Kein Rechnungssystem angebunden.</strong>
                    {{ $gatewayReason }}
                    Vorschau und Zählung funktionieren weiterhin — nur das Anlegen des Entwurfs ist nicht möglich.
                </div>
            @endunless

            @if(! $selected)
                <x-asset-manager-panel>
                    <div class="p-8 text-center text-sm text-[var(--am-text-secondary)]">
                        Kein Abrechnungsprofil ausgewählt.
                        @if($canManage)
                            Lege eines an, um daraus Rechnungsentwürfe zu erzeugen.
                        @endif
                    </div>
                </x-asset-manager-panel>
            @else
                {{-- ---- Der Lauf ------------------------------------------------------------- --}}
                <x-asset-manager-panel :title="$selected->name" :subtitle="$selected->basisLabel()">
                    <x-slot name="actions">
                        @if($canManage)
                            <x-asset-manager-button variant="secondary" size="sm" wire:click="startEdit({{ $selected->id }})">
                                @svg('heroicon-o-pencil-square', 'w-3.5 h-3.5')
                                Bearbeiten
                            </x-asset-manager-button>
                        @endif
                    </x-slot>

                    <div class="space-y-4">
                        <div class="flex flex-wrap items-end gap-3">
                            <label class="block">
                                <span class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Abrechnungsmonat</span>
                                <x-asset-manager-select size="sm" wire:model.live="period" class="w-56">
                                    @foreach($periodOptions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </x-asset-manager-select>
                            </label>

                            <div class="flex-1"></div>

                            <x-asset-manager-button
                                variant="primary"
                                size="md"
                                wire:click="createDraft"
                                wire:loading.attr="disabled"
                                :disabled="! $canManage || ! $gatewayAvailable || $preview['problems'] !== []">
                                @svg('heroicon-o-document-plus', 'w-4 h-4')
                                Entwurf in easybill anlegen
                            </x-asset-manager-button>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <x-asset-manager-stat-card
                                label="Abzurechnende Träger"
                                :value="number_format($preview['quantity'], 0, ',', '.')"
                                :sub="$preview['skipped'] ? count($preview['skipped']) . ' nicht gezählt' : 'keine Ausschlüsse'"
                                icon="heroicon-o-users"
                                accent="navy" />

                            <x-asset-manager-stat-card
                                label="Stückpreis netto"
                                :value="$preview['unit_price_cents'] === null ? '—' : number_format($preview['unit_price_cents'] / 100, 2, ',', '.') . ' €'"
                                :sub="$preview['price_source'] === 'commerce' ? 'aus ' . $selected->commerce_sku : ($preview['price_source'] ? 'Rückfallpreis' : 'nicht ermittelbar')"
                                icon="heroicon-o-tag"
                                accent="sky" />

                            <x-asset-manager-stat-card
                                label="Rechnungsbetrag netto"
                                :value="number_format($preview['total_net_cents'] / 100, 2, ',', '.') . ' €'"
                                :sub="$preview['period_label']"
                                icon="heroicon-o-banknotes"
                                accent="emerald"
                                valueClass="text-[var(--am-accent)]" />
                        </div>

                        @if($preview['price_note'])
                            <p class="text-xs text-amber-700">{{ $preview['price_note'] }}</p>
                        @endif

                        @if($preview['problems'] !== [])
                            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3">
                                <p class="text-xs font-semibold uppercase tracking-wider text-amber-900 mb-1">
                                    Der Lauf ist nicht möglich
                                </p>
                                <ul class="list-disc list-inside space-y-0.5 text-sm text-amber-900">
                                    @foreach($preview['problems'] as $problem)
                                        <li>{{ $problem }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        {{-- Nicht gezählte Träger. Eingeklappt, aber vorhanden: die Zahl ist der einzige
                             Hinweis darauf, dass eine Rechnung zu niedrig ausfallen könnte. --}}
                        @if($preview['skipped'] !== [])
                            <div class="rounded-lg border border-[color:var(--am-border)]">
                                <button type="button" wire:click="$toggle('showSkipped')"
                                        class="w-full flex items-center gap-2 px-4 py-2.5 text-left text-sm text-[var(--am-text-secondary)] hover:bg-[var(--am-bg)] transition-colors">
                                    @svg('heroicon-o-chevron-right', 'w-3.5 h-3.5 transition-transform ' . ($showSkipped ? 'rotate-90' : ''))
                                    <span><strong class="text-[var(--am-text)]">{{ count($preview['skipped']) }}</strong> Asset-Träger werden nicht abgerechnet</span>
                                </button>

                                @if($showSkipped)
                                    <div class="border-t border-[color:var(--am-border)] max-h-72 overflow-y-auto">
                                        <table class="w-full text-sm">
                                            <tbody class="divide-y divide-[color:var(--am-border)]">
                                                @foreach($preview['skipped'] as $row)
                                                    <tr wire:key="skip-{{ $loop->index }}">
                                                        <td class="px-4 py-1.5 font-mono text-xs text-[var(--am-text-secondary)]">{{ $row['upn'] }}</td>
                                                        <td class="px-4 py-1.5 text-xs text-[var(--am-text-muted)] text-right">{{ $row['reason'] }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>
                </x-asset-manager-panel>

                {{-- ---- Bisherige Läufe ------------------------------------------------------- --}}
                <x-asset-manager-panel title="Bisherige Läufe" bodyClass="p-0">
                    @if($recentRuns->isEmpty())
                        <div class="p-8 text-center text-sm text-[var(--am-text-secondary)]">
                            Für dieses Profil wurde noch keine Rechnung erzeugt.
                        </div>
                    @else
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-[color:var(--am-border)] text-xs uppercase tracking-wider text-[var(--am-text-muted)] font-semibold">
                                        <th class="text-left px-4 py-3 bg-[var(--am-bg)]">Monat</th>
                                        <th class="text-left px-4 py-3 bg-[var(--am-bg)]">Erzeugt</th>
                                        <th class="text-right px-4 py-3 bg-[var(--am-bg)]">Menge</th>
                                        <th class="text-right px-4 py-3 bg-[var(--am-bg)]">Stückpreis</th>
                                        <th class="text-right px-4 py-3 bg-[var(--am-bg)]">Netto</th>
                                        <th class="text-left px-4 py-3 bg-[var(--am-bg)]">Beleg</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-[color:var(--am-border)]">
                                    @foreach($recentRuns as $run)
                                        <tr wire:key="run-{{ $run->id }}" class="transition-colors hover:bg-[var(--am-bg)]">
                                            <td class="px-4 py-2.5 text-[var(--am-text)]">{{ $run->period }}</td>
                                            <td class="px-4 py-2.5 text-xs text-[var(--am-text-secondary)]">{{ $run->created_at?->format('d.m.Y H:i') }}</td>
                                            <td class="px-4 py-2.5 text-right tabular-nums">{{ number_format($run->quantity, 0, ',', '.') }}</td>
                                            <td class="px-4 py-2.5 text-right tabular-nums text-[var(--am-text-secondary)]">{{ number_format($run->unitPrice(), 2, ',', '.') }} €</td>
                                            <td class="px-4 py-2.5 text-right font-semibold tabular-nums text-[var(--am-accent)]">{{ number_format($run->totalNet(), 2, ',', '.') }} €</td>
                                            <td class="px-4 py-2.5">
                                                @if($run->easybill_document_id)
                                                    <a href="https://app.easybill.de/documents/{{ $run->easybill_document_id }}" target="_blank" rel="noopener"
                                                       class="text-xs text-[var(--am-accent)] hover:underline">
                                                        Entwurf #{{ $run->easybill_document_id }}
                                                    </a>
                                                @else
                                                    <span class="text-xs text-red-700" title="{{ $run->error }}">fehlgeschlagen</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </x-asset-manager-panel>
            @endif
        </div>
    </div>

    {{-- ---- Profil-Formular (Modal muss innerhalb von x-ui-page liegen) ---------------------- --}}
    <x-ui-modal model="showForm" size="lg">
        <x-slot name="header">{{ $editingId ? 'Abrechnungsprofil bearbeiten' : 'Neues Abrechnungsprofil' }}</x-slot>

        <div class="space-y-4">
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Name *</label>
                <x-asset-manager-input size="sm" type="text" wire:model="fName" placeholder="z. B. Broich" />
                @error('fName') <p class="text-[10px] text-[var(--am-danger)] mt-0.5">{{ $message }}</p> @enderror
            </div>

            <div class="rounded-lg border border-[color:var(--am-border)] p-3 space-y-3">
                <span class="text-[10px] font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">Empfänger in easybill</span>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Kundennummer oder Kunden-ID</label>
                        <x-asset-manager-input size="sm" type="text" wire:model="fCustomerId" placeholder="z. B. 6010200" />
                        @error('fCustomerId') <p class="text-[10px] text-[var(--am-danger)] mt-0.5">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Bezeichnung</label>
                        <x-asset-manager-input size="sm" type="text" wire:model="fCustomerName" placeholder="wird beim Speichern gefüllt" />
                    </div>
                </div>
                <p class="text-[10px] text-[var(--am-text-muted)]">
                    Beides geht: die Kundennummer vom easybill-Kundenblatt (6010200) oder die interne Kunden-ID.
                    Beim Speichern wird der Kunde nachgeschlagen, die Bezeichnung übernommen — und ein Tippfehler
                    fällt sofort auf statt erst beim Rechnungslauf.
                </p>
            </div>

            <div class="rounded-lg border border-[color:var(--am-border)] p-3 space-y-3">
                <span class="text-[10px] font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">Preis</span>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Artikelnummer (Commerce)</label>
                        <x-asset-manager-input size="sm" type="text" wire:model="fSku" placeholder="z. B. IT-5001" />
                        @error('fSku') <p class="text-[10px] text-[var(--am-danger)] mt-0.5">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Rückfallpreis (€ netto)</label>
                        <x-asset-manager-input size="sm" type="number" step="0.01" min="0" wire:model="fFallbackPrice" placeholder="80.00" />
                        @error('fFallbackPrice') <p class="text-[10px] text-[var(--am-danger)] mt-0.5">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Steuersatz (%)</label>
                        <x-asset-manager-input size="sm" type="number" step="1" min="0" max="100" wire:model="fVat" placeholder="19" />
                        @error('fVat') <p class="text-[10px] text-[var(--am-danger)] mt-0.5">{{ $message }}</p> @enderror
                    </div>
                </div>
                <p class="text-[10px] text-[var(--am-text-muted)]">
                    Der Artikelpreis hat Vorrang. Der Rückfallpreis greift, wenn der Artikel nicht auffindbar ist —
                    ohne ihn stünde der Lauf dann still. Leerer Steuersatz heißt: die Einstellung des easybill-Kunden gilt.
                </p>
            </div>

            <div class="rounded-lg border border-[color:var(--am-border)] p-3 space-y-3">
                <span class="text-[10px] font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">Wer wird gezählt</span>
                <div>
                    <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Zählregel</label>
                    <x-asset-manager-select size="sm" wire:model="fBasis">
                        @foreach(\Platform\AssetManager\Models\AssetBillingProfile::BASIS_LABELS as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-asset-manager-select>
                    @error('fBasis') <p class="text-[10px] text-[var(--am-danger)] mt-0.5">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-[10px] uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Ausgeschlossene Domains</label>
                    <x-asset-manager-input size="sm" type="text" wire:model="fExcludeDomains" placeholder="bhgdigital.de" />
                    <p class="text-[10px] text-[var(--am-text-muted)] mt-1">
                        Kommagetrennt. Typisch die eigene Domain — sonst stehen die eigenen Mitarbeiter auf der Kundenrechnung.
                    </p>
                </div>
                <label class="inline-flex items-center gap-2 text-xs text-[var(--am-text-secondary)]">
                    <input type="checkbox" wire:model="fActive" class="rounded"> Profil aktiv
                </label>
            </div>
        </div>

        <x-slot name="footer">
            <x-asset-manager-button variant="secondary" size="sm" wire:click="cancelForm">Abbrechen</x-asset-manager-button>
            <x-asset-manager-button variant="primary" size="sm" wire:click="saveProfile" wire:loading.attr="disabled">
                @svg('heroicon-o-check', 'w-3.5 h-3.5')
                {{ $editingId ? 'Speichern' : 'Anlegen' }}
            </x-asset-manager-button>
        </x-slot>
    </x-ui-modal>
</x-ui-page>
