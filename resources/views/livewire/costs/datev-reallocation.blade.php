{{--
    DATEV-Umbuchung (Discussion #26, docs/adr/0027): Vorschau des Buchungsmonats oben, Protokoll der
    erzeugten Stapel unten. Beträge kommen in Cent aus dem Service und werden hier nur formatiert.
--}}
@php
    $euro = fn (int|float $cents) => number_format($cents / 100, 2, ',', '.') . ' €';
    $sourceLabels = [
        'vodafone_invoice'  => 'Mobilfunk',
        'vodafone_fixednet' => 'Festnetz',
        'license_invoice'   => 'Lizenzen',
    ];
    $monthLabel = \Platform\AssetManager\Support\DatevBookingText::month(new \DateTimeImmutable($month . '-01'));
@endphp

<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-asset-manager-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'DATEV-Umbuchung', 'icon' => 'arrows-right-left'],
        ]">
            <x-slot name="actions">
                @if($canManage)
                    <x-asset-manager-button variant="primary" size="sm" wire:click="createBatch" :disabled="! $canCreate"
                        wire:confirm="Stapel für {{ $monthLabel }} erzeugen? Die enthaltenen Rechnungen werden danach für weitere Umbuchungen gesperrt.">
                        @svg('heroicon-o-document-arrow-down', 'w-3.5 h-3.5')
                        Stapel erzeugen
                    </x-asset-manager-button>
                @endif
            </x-slot>
        </x-asset-manager-page-actionbar>
    </x-slot>

    <div class="flex-1 flex flex-col min-h-0 min-w-0">
        <div class="flex-1 overflow-y-auto p-6 space-y-4">

            @if($flash)
                <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ $flash }}</div>
            @endif

            @if($problems !== [])
                <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                    <ul class="m-0 pl-4 list-disc space-y-0.5">
                        @foreach($problems as $problem)
                            <li>{{ $problem }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Monat + Stapelkopf --}}
            <x-asset-manager-panel>
                <div class="flex flex-wrap items-center gap-4">
                    <div class="flex items-center gap-1">
                        <x-asset-manager-button variant="ghost" size="sm" wire:click="shiftMonth(-1)" title="Vormonat">
                            @svg('heroicon-o-chevron-left', 'w-4 h-4')
                        </x-asset-manager-button>
                        <x-asset-manager-input type="month" wire:model.live="month" class="w-40" />
                        <x-asset-manager-button variant="ghost" size="sm" wire:click="shiftMonth(1)" title="Folgemonat">
                            @svg('heroicon-o-chevron-right', 'w-4 h-4')
                        </x-asset-manager-button>
                    </div>

                    <div class="text-xs text-[var(--am-text-secondary)]">
                        Buchungsmonat = Leistungsmonat (Ende des Abrechnungszeitraums).
                        @if($openMonths->isNotEmpty())
                            Offen:
                            @foreach($openMonths as $openMonth => $count)
                                <button type="button" wire:click="$set('month', '{{ $openMonth }}')"
                                        class="underline decoration-dotted {{ $openMonth === $month ? 'font-semibold text-[var(--am-text)]' : '' }}">{{ \Platform\AssetManager\Support\DatevBookingText::month(new \DateTimeImmutable($openMonth . '-01')) }} ({{ $count }})</button>@if(! $loop->last), @endif
                            @endforeach
                        @else
                            Keine offenen Rechnungen.
                        @endif
                    </div>

                    <div class="ml-auto text-xs text-[var(--am-text-secondary)]">
                        @if($settings)
                            Mandant {{ $settings->datev_client ?: '—' }} · Berater {{ $settings->datev_consultant ?: '—' }} ·
                            Sammel-KST {{ $settings->datevSourceCostCenter?->code ?: '—' }} ·
                            Umbuchung ab {{ $settings->datev_start_month ? \Platform\AssetManager\Support\DatevBookingText::month($settings->datev_start_month) : '—' }}
                        @endif
                    </div>
                </div>

                @if($plan['before_start'])
                    <div class="mt-3 rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-800">
                        {{ $monthLabel }} liegt vor der Umstellung (Umbuchung ab
                        {{ \Platform\AssetManager\Support\DatevBookingText::month($settings->datev_start_month) }}).
                        Die Buchhaltung hat diese Rechnungen selbst je Kostenstelle verteilt — kein Stapel, sonst doppelt gebucht.
                    </div>
                @endif

                @if($missing !== [])
                    <div class="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                        Stapelkopf unvollständig: {{ implode(', ', $missing) }}.
                        <a href="{{ route('asset-manager.settings') }}" wire:navigate class="underline">In den Einstellungen nachtragen</a>
                        — die Vorschau rechnet trotzdem.
                    </div>
                @endif
            </x-asset-manager-panel>

            {{-- Rechnungen des Monats --}}
            <x-asset-manager-panel title="Rechnungen {{ $monthLabel }}" bodyClass="p-0">
                @if($canManage && $snapshots->contains(fn ($s) => ! $s->isBooked()))
                    <x-slot name="actions">
                        <button type="button" wire:click="resetMonth"
                                wire:confirm="Alle offenen Monatsstände für {{ $monthLabel }} zurücksetzen? Gebuchte bleiben. Neu entstehen sie mit dem nächsten scharfen Import der Rechnungen."
                                class="text-xs text-[var(--am-text-secondary)] underline decoration-dotted">
                            Offene zurücksetzen
                        </button>
                    </x-slot>
                @endif

                @if($snapshots->isEmpty())
                    <p class="px-4 py-3 text-xs text-[var(--am-text-secondary)] m-0">
                        Für diesen Monat ist keine Rechnung festgeschrieben. Der Monatsstand entsteht beim scharfen
                        Rechnungsimport (Kosten-Import).
                    </p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="text-[11px] uppercase tracking-wide text-[var(--am-text-muted)] border-b border-[color:var(--am-border)]">
                                <tr>
                                    <th class="px-4 py-2 text-left font-medium">Rechnung</th>
                                    <th class="px-4 py-2 text-left font-medium">Art</th>
                                    <th class="px-4 py-2 text-left font-medium">Konto</th>
                                    <th class="px-4 py-2 text-left font-medium">Rechnungsdatum</th>
                                    <th class="px-4 py-2 text-right font-medium">Netto</th>
                                    <th class="px-4 py-2 text-left font-medium">Status</th>
                                    <th class="px-4 py-2"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[color:var(--am-border)]">
                                @foreach($snapshots as $snapshot)
                                    <tr wire:key="snap-{{ $snapshot->id }}">
                                        <td class="px-4 py-2 font-medium text-[var(--am-text)]">{{ $snapshot->invoice }}</td>
                                        <td class="px-4 py-2 text-[var(--am-text-secondary)]">{{ $sourceLabels[$snapshot->source] ?? $snapshot->source }}</td>
                                        <td class="px-4 py-2 text-[var(--am-text-secondary)]">{{ $snapshot->account ?: '—' }}</td>
                                        <td class="px-4 py-2 text-[var(--am-text-secondary)]">{{ $snapshot->invoice_date?->format('d.m.Y') ?? $snapshot->period_label ?? '—' }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ number_format((float) $snapshot->total_net, 2, ',', '.') }} €</td>
                                        <td class="px-4 py-2">
                                            @if($snapshot->isBooked())
                                                <x-asset-manager-badge color="emerald" size="xs">gebucht · {{ $batchLabels[$snapshot->datev_batch_id] ?? 'Stapel #' . $snapshot->datev_batch_id }}</x-asset-manager-badge>
                                            @elseif(isset($predistributedIds[$snapshot->id]))
                                                <x-asset-manager-badge color="gray" size="xs"
                                                    title="Vor der Umstellung — von der Buchhaltung selbst je KST verteilt.">
                                                    vorverteilt
                                                </x-asset-manager-badge>
                                            @elseif(isset($directIds[$snapshot->id]))
                                                <x-asset-manager-badge color="sky" size="xs"
                                                    title="Die ganze Rechnung liegt auf einer Kostenstelle — die Buchhaltung bucht sie direkt dorthin, keine Umbuchung.">
                                                    direkt auf KST {{ $directIds[$snapshot->id] }}
                                                </x-asset-manager-badge>
                                            @else
                                                <x-asset-manager-badge color="amber" size="xs">offen</x-asset-manager-badge>
                                            @endif
                                        </td>
                                        <td class="px-4 py-2 text-right whitespace-nowrap space-x-3">
                                            @if($canManage && ! $snapshot->isBooked())
                                                @if($snapshot->source !== 'license_invoice')
                                                    <button type="button" wire:click="recapture({{ $snapshot->id }})"
                                                            class="text-xs text-[var(--am-text-secondary)] underline decoration-dotted"
                                                            title="Verteilung aus den heutigen Kostenpositionen übernehmen und den Buchungsmonat aus dem Leistungszeitraum neu bestimmen">
                                                        Neu festschreiben
                                                    </button>
                                                @endif
                                                <button type="button" wire:click="resetSnapshot({{ $snapshot->id }})"
                                                        wire:confirm="Monatsstand der Rechnung {{ $snapshot->invoice }} zurücksetzen? Er entsteht neu mit dem nächsten scharfen Import dieser Rechnung."
                                                        class="text-xs text-red-700 underline decoration-dotted">
                                                    Zurücksetzen
                                                </button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-asset-manager-panel>

            {{-- Vorschau der Umbuchung --}}
            <x-asset-manager-panel title="Umbuchung {{ $monthLabel }}" subtitle="Rechnung × Anteil laut Kostenaufteilung, centgenau — noch nichts geschrieben">
                <div class="space-y-3">
                    @if($plan['violations'] !== [])
                        <div class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800">
                            <strong>Blockiert</strong> — erst beheben, dann erzeugen:
                            <ul class="m-0 mt-1 pl-4 list-disc space-y-0.5">
                                @foreach($plan['violations'] as $violation)
                                    <li>{{ $violation }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if($plan['findings'] !== [])
                        <div class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                            <ul class="m-0 pl-4 list-disc space-y-0.5">
                                @foreach($plan['findings'] as $finding)
                                    <li>{{ $finding }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if($plan['summary'] === [])
                        <p class="text-xs text-[var(--am-text-secondary)] m-0">
                            Nichts umzubuchen: keine offenen Rechnungen mit DATEV-Sachkonto in diesem Monat.
                        </p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="text-[11px] uppercase tracking-wide text-[var(--am-text-muted)] border-b border-[color:var(--am-border)]">
                                    <tr>
                                        <th class="px-2 py-2 text-left font-medium">Sachkonto</th>
                                        <th class="px-2 py-2 text-left font-medium">Leistung</th>
                                        <th class="px-2 py-2 text-right font-medium">Betrag</th>
                                        <th class="px-2 py-2 text-right font-medium">Ziel-KST</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-[color:var(--am-border)]">
                                    @foreach($plan['summary'] as $block)
                                        <tr>
                                            <td class="px-2 py-2 tabular-nums">{{ $block['account'] }}</td>
                                            <td class="px-2 py-2">{{ $block['service'] }}</td>
                                            <td class="px-2 py-2 text-right tabular-nums">{{ $euro($block['amount_cents']) }}</td>
                                            <td class="px-2 py-2 text-right tabular-nums">{{ $block['lines'] }}</td>
                                        </tr>
                                    @endforeach
                                    <tr class="font-medium">
                                        <td class="px-2 py-2" colspan="2">Summe · {{ count($plan['bookings']) }} Buchungszeilen</td>
                                        <td class="px-2 py-2 text-right tabular-nums">{{ $euro(array_sum(array_map(fn ($b) => abs($b['amount_cents']), $plan['summary']))) }}</td>
                                        <td></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <button type="button" wire:click="$toggle('showBookings')" class="text-xs text-[var(--am-text-secondary)] inline-flex items-center gap-1">
                            @svg('heroicon-o-chevron-right', 'w-3 h-3 transition-transform ' . ($showBookings ? 'rotate-90' : ''))
                            Buchungszeilen {{ $showBookings ? 'ausblenden' : 'anzeigen' }}
                        </button>

                        @if($showBookings)
                            <div class="overflow-x-auto">
                                <table class="w-full text-xs">
                                    <thead class="text-[10px] uppercase tracking-wide text-[var(--am-text-muted)] border-b border-[color:var(--am-border)]">
                                        <tr>
                                            <th class="px-2 py-1.5 text-right font-medium">Umsatz</th>
                                            <th class="px-2 py-1.5 text-left font-medium">S/H</th>
                                            <th class="px-2 py-1.5 text-left font-medium">Konto</th>
                                            <th class="px-2 py-1.5 text-left font-medium">Gegenkonto</th>
                                            <th class="px-2 py-1.5 text-left font-medium">KOST1/2</th>
                                            <th class="px-2 py-1.5 text-left font-medium">Buchungstext</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-[color:var(--am-border)] font-mono">
                                        @foreach($plan['bookings'] as $booking)
                                            <tr class="{{ str_contains($booking['text'], '|diverse]') ? 'bg-[var(--am-bg)]' : '' }}">
                                                <td class="px-2 py-1 text-right tabular-nums">{{ number_format($booking['amount_cents'] / 100, 2, ',', '.') }}</td>
                                                <td class="px-2 py-1">{{ $booking['side'] }}</td>
                                                <td class="px-2 py-1">{{ $booking['account'] }}</td>
                                                <td class="px-2 py-1">{{ $booking['contra_account'] }}</td>
                                                <td class="px-2 py-1">{{ $booking['cost_center'] ?: '—' }}</td>
                                                <td class="px-2 py-1">{{ $booking['text'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    @endif
                </div>
            </x-asset-manager-panel>

            {{-- Protokoll --}}
            <x-asset-manager-panel title="Erzeugte Stapel" bodyClass="p-0">
                @if($batches->isEmpty())
                    <p class="px-4 py-3 text-xs text-[var(--am-text-secondary)] m-0">Noch kein Stapel erzeugt.</p>
                @else
                    <div class="divide-y divide-[color:var(--am-border)]">
                        @foreach($batches as $batch)
                            <div wire:key="batch-{{ $batch->id }}" class="px-4 py-3 {{ $batch->isVoided() ? 'opacity-70' : '' }}">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-sm font-medium text-[var(--am-text)]">{{ $batch->label }}</span>
                                    @if($batch->isVoided())
                                        <x-asset-manager-badge color="gray" size="xs">verworfen</x-asset-manager-badge>
                                    @else
                                        <x-asset-manager-badge color="emerald" size="xs">gültig</x-asset-manager-badge>
                                    @endif
                                    <span class="text-xs text-[var(--am-text-secondary)]">
                                        Mandant {{ $batch->client }} · {{ $batch->bookings_count }} Buchungen · {{ $euro($batch->total_cents) }}
                                        · {{ count($batch->summary['snapshots'] ?? []) }} Rechnung(en)
                                    </span>

                                    <div class="ml-auto flex items-center gap-2">
                                        <x-asset-manager-button variant="secondary" size="sm" wire:click="download({{ $batch->id }})">
                                            @svg('heroicon-o-arrow-down-tray', 'w-3.5 h-3.5')
                                            {{ $batch->file_name }}
                                        </x-asset-manager-button>
                                        @if($canManage && ! $batch->isVoided() && $voidingId !== $batch->id)
                                            <x-asset-manager-button variant="ghost" size="sm" wire:click="startVoid({{ $batch->id }})">
                                                Verwerfen
                                            </x-asset-manager-button>
                                        @endif
                                    </div>
                                </div>

                                <div class="text-[11px] text-[var(--am-text-muted)] mt-1">
                                    Erzeugt {{ $batch->created_at?->timezone('Europe/Berlin')->format('d.m.Y H:i') }}
                                    @if($batch->user) von {{ $batch->user->name }} @endif
                                    · Rechnungen: {{ implode(', ', array_column($batch->summary['snapshots'] ?? [], 'invoice')) ?: '—' }}
                                    · SHA-256 {{ substr($batch->file_sha256, 0, 12) }}…
                                    @if($batch->isVoided())
                                        <br>Verworfen {{ $batch->voided_at->timezone('Europe/Berlin')->format('d.m.Y H:i') }}
                                        @if($batch->voidedByUser) von {{ $batch->voidedByUser->name }} @endif — {{ $batch->void_reason }}
                                    @endif
                                </div>

                                @if($voidingId === $batch->id)
                                    <div class="mt-2 flex flex-wrap items-center gap-2">
                                        <x-asset-manager-input wire:model="voidReason" size="sm" class="max-w-md"
                                            placeholder="Grund, z. B. „Import in DATEV abgebrochen“" />
                                        <x-asset-manager-button variant="danger" size="sm" wire:click="confirmVoid">Verwerfen</x-asset-manager-button>
                                        <x-asset-manager-button variant="ghost" size="sm" wire:click="cancelVoid">Abbrechen</x-asset-manager-button>
                                        <span class="text-[11px] text-[var(--am-text-muted)]">
                                            Gibt die Rechnungen wieder frei. Ein schon importierter Stapel muss in DATEV storniert werden.
                                        </span>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-asset-manager-panel>

            {{-- Monatsverlauf (#972): Rechnungskosten je Kostenstelle × Leistungsmonat aus den Monatsständen. --}}
            @if($history['rows'] !== [])
                <x-asset-manager-panel title="Verlauf je Kostenstelle" subtitle="Rechnungen der letzten 6 Leistungsmonate laut Monatsstand" bodyClass="p-0">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-xs">
                            <thead>
                                <tr class="text-left text-[var(--am-text-muted)] border-b border-[color:var(--am-border)]">
                                    <th class="px-4 py-2">Kostenstelle</th>
                                    @foreach($history['months'] as $m)
                                        <th class="px-3 py-2 text-right">{{ $m }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($history['rows'] as $code => $values)
                                    <tr class="border-b border-[color:var(--am-border)] last:border-0">
                                        <td class="px-4 py-1.5 font-medium text-[var(--am-text)]">{{ $code }}</td>
                                        @foreach($history['months'] as $m)
                                            <td class="px-3 py-1.5 text-right tabular-nums text-[var(--am-text-secondary)]">{{ $values[$m] != 0 ? number_format($values[$m], 2, ',', '.') : '—' }}</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                                <tr class="bg-[var(--am-bg)] font-semibold">
                                    <td class="px-4 py-1.5 text-[var(--am-text)]">Summe</td>
                                    @foreach($history['months'] as $m)
                                        <td class="px-3 py-1.5 text-right tabular-nums text-[var(--am-text)]">{{ number_format($history['totals'][$m], 2, ',', '.') }}</td>
                                    @endforeach
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </x-asset-manager-panel>
            @endif
        </div>
    </div>
</x-ui-page>
