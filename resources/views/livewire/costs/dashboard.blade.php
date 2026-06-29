<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    <x-slot name="actionbar">
        <x-asset-manager-page-actionbar :breadcrumbs="[
            ['label' => 'Asset Manager', 'href' => route('asset-manager.dashboard'), 'icon' => 'cube'],
            ['label' => 'Kosten', 'icon' => 'banknotes'],
        ]">
            <x-slot name="actions">
                <x-asset-manager-button variant="ghost" size="sm" wire:click="exportCsv">
                    @svg('heroicon-o-arrow-down-tray', 'w-3.5 h-3.5')
                    CSV-Export
                </x-asset-manager-button>
            </x-slot>
        </x-asset-manager-page-actionbar>
    </x-slot>

    {{-- LINKS: Auswertungen-Navigator --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Auswertungen" icon="heroicon-o-chart-bar" width="w-64" :defaultOpen="true">
            <div class="p-4 space-y-2 bg-[var(--am-bg)]" x-data="{ section: 'overview' }">
                @foreach([
                    ['key' => 'overview',   'label' => 'Übersicht',           'icon' => 'heroicon-o-squares-2x2'],
                    ['key' => 'employees',  'label' => 'Top Mitarbeiter',     'icon' => 'heroicon-o-trophy'],
                    ['key' => 'department', 'label' => 'Abteilung',           'icon' => 'heroicon-o-building-office'],
                    ['key' => 'costcenter', 'label' => 'Kostenstelle',        'icon' => 'heroicon-o-clipboard-document-list'],
                    ['key' => 'category',   'label' => 'Hardware-Kategorien', 'icon' => 'heroicon-o-cube-transparent'],
                    ['key' => 'licenses',   'label' => 'Lizenz-SKUs',         'icon' => 'heroicon-o-key'],
                    ['key' => 'anomalies',  'label' => 'Anomalien',           'icon' => 'heroicon-o-exclamation-triangle'],
                ] as $item)
                    <button @click="document.getElementById('section-{{ $item['key'] }}')?.scrollIntoView({ behavior: 'smooth', block: 'start' }); section = '{{ $item['key'] }}'"
                            :class="section === '{{ $item['key'] }}' ? 'bg-[var(--am-primary)] text-[var(--am-on-primary)] border-[color:var(--am-primary)]' : 'bg-[var(--am-surface)] border-[color:var(--am-border)] text-[var(--am-text-secondary)] hover:bg-[var(--am-bg)]'"
                            class="w-full flex items-center gap-2 px-3 py-2 text-xs font-medium border rounded-lg transition-colors">
                        @svg($item['icon'], 'w-3.5 h-3.5')
                        {{ $item['label'] }}
                    </button>
                @endforeach
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- RECHTS: Anomalien --}}
    <x-slot name="activity">
        <x-ui-page-sidebar title="Anomalien" icon="heroicon-o-exclamation-triangle" width="w-80" :defaultOpen="false" storeKey="activityOpen" side="right">
            <div class="p-4 space-y-3 bg-[var(--am-bg)]">

                <div class="p-3 rounded-lg bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm">
                    <div class="flex items-center gap-2 mb-1">
                        @svg('heroicon-o-archive-box', 'w-4 h-4 text-sky-500')
                        <h3 class="text-[11px] font-semibold text-[var(--am-text-secondary)]">Hardware im Lager</h3>
                    </div>
                    <div class="text-lg font-semibold text-sky-600 tabular-nums">{{ number_format($anomalies['pool']['value'], 2, ',', '.') }} €</div>
                    <div class="text-[10px] text-[var(--am-text-muted)]">{{ $anomalies['pool']['count'] }} Items im Lager, Anschaffungswert gebunden</div>
                </div>

                <div class="p-3 rounded-lg bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm">
                    <div class="flex items-center gap-2 mb-1">
                        @svg('heroicon-o-currency-euro', 'w-4 h-4 text-emerald-500')
                        <h3 class="text-[11px] font-semibold text-[var(--am-text-secondary)]">Ungenutzte Lizenzen</h3>
                    </div>
                    <div class="text-lg font-semibold text-emerald-600 tabular-nums">{{ number_format($anomalies['unused_licenses']['savings'], 2, ',', '.') }} €</div>
                    <div class="text-[10px] text-[var(--am-text-muted)]">{{ $anomalies['unused_licenses']['units'] }} freie Lizenzen über {{ $anomalies['unused_licenses']['count'] }} SKUs — Einsparpotential / Monat</div>
                </div>

                <div class="p-3 rounded-lg bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm">
                    <div class="flex items-center gap-2 mb-1">
                        @svg('heroicon-o-user-minus', 'w-4 h-4 text-red-500')
                        <h3 class="text-[11px] font-semibold text-[var(--am-text-secondary)]">Hardware bei Inaktiven</h3>
                    </div>
                    <div class="text-lg font-semibold text-red-600 tabular-nums">{{ $anomalies['inactive_employees']['count'] }}</div>
                    <div class="text-[10px] text-[var(--am-text-muted)]">Items zugewiesen an inaktive Mitarbeiter — {{ number_format($anomalies['inactive_employees']['monthly'], 2, ',', '.') }} € / Monat. Rückgabe einleiten.</div>
                </div>

            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- HAUPT-CONTENT --}}
    <div class="flex-1 flex flex-col min-h-0 min-w-0">
        <div class="flex-1 overflow-y-auto p-6 space-y-8">

            {{-- ÜBERSICHT --}}
            <section id="section-overview" class="space-y-3 scroll-mt-4">
                <h2 class="text-sm font-semibold text-[var(--am-text)]">Übersicht</h2>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <x-asset-manager-stat-card
                        label="Gesamt / Monat"
                        :value="number_format($totals['total'], 2, ',', '.') . ' €'"
                        :sub="number_format($totals['total'] * 12, 2, ',', '.') . ' € / Jahr'"
                        accent="navy" />
                    <div class="rounded-xl bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm p-5">
                        <div class="text-xs uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Hardware (AfA)</div>
                        <div class="text-2xl font-semibold text-sky-700 tabular-nums">{{ number_format($totals['hardware'], 2, ',', '.') }} €</div>
                        @php $pctHw = $totals['total'] > 0 ? round($totals['hardware'] / $totals['total'] * 100) : 0; @endphp
                        <div class="mt-2 w-full h-1.5 rounded-full bg-[var(--am-bg)] overflow-hidden">
                            <div class="h-full bg-sky-500" style="width: {{ $pctHw }}%"></div>
                        </div>
                        <div class="text-[10px] text-[var(--am-text-muted)] mt-1">{{ $pctHw }}% der Gesamtkosten</div>
                    </div>
                    <div class="rounded-xl bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm p-5">
                        <div class="text-xs uppercase tracking-wider text-[var(--am-text-muted)] mb-1">Lizenzen</div>
                        <div class="text-2xl font-semibold text-emerald-700 tabular-nums">{{ number_format($totals['licenses'], 2, ',', '.') }} €</div>
                        @php $pctLic = $totals['total'] > 0 ? round($totals['licenses'] / $totals['total'] * 100) : 0; @endphp
                        <div class="mt-2 w-full h-1.5 rounded-full bg-[var(--am-bg)] overflow-hidden">
                            <div class="h-full bg-emerald-500" style="width: {{ $pctLic }}%"></div>
                        </div>
                        <div class="text-[10px] text-[var(--am-text-muted)] mt-1">{{ $pctLic }}% der Gesamtkosten</div>
                    </div>
                </div>
            </section>

            {{-- TOP MITARBEITER --}}
            <section id="section-employees" class="space-y-3 scroll-mt-4">
                <h2 class="text-sm font-semibold text-[var(--am-text)]">Top 10 Mitarbeiter nach Monatskosten</h2>

                <x-asset-manager-panel body-class="p-0">
                    @if($topEmployees->isEmpty())
                        <div class="p-8 text-center text-sm text-[var(--am-text-secondary)]">
                            Keine Mitarbeiter mit Kostendaten gefunden.
                            <div class="text-xs text-[var(--am-text-muted)] mt-1">Hinweis: Pflege bei Assets <strong>Kaufpreis + AfA-Monate</strong> und bei Lizenz-SKUs den <strong>Stückpreis</strong>, damit hier etwas erscheint.</div>
                        </div>
                    @else
                        <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="bg-[var(--am-bg)]">
                                    <th class="text-left px-5 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">Mitarbeiter</th>
                                    <th class="text-left px-5 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">Abteilung</th>
                                    <th class="text-right px-5 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">Hardware</th>
                                    <th class="text-right px-5 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">Lizenzen</th>
                                    <th class="text-right px-5 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">Gesamt</th>
                                    <th class="text-left px-5 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)] w-32">Verteilung</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[color:var(--am-border)]">
                                @php $maxTotal = $topEmployees->max('total'); @endphp
                                @foreach($topEmployees as $row)
                                    <tr class="hover:bg-[var(--am-bg)]">
                                        <td class="px-5 py-3">
                                            <a href="{{ route('asset-manager.employees.show', $row['employee']) }}" wire:navigate class="flex items-center gap-2 hover:text-[var(--am-accent)]">
                                                <div class="w-7 h-7 rounded-full bg-[var(--am-accent-surface)] text-[var(--am-accent)] flex items-center justify-center text-[10px] font-semibold flex-shrink-0">
                                                    {{ $row['employee']->initials() }}
                                                </div>
                                                <span class="font-medium text-[var(--am-text)]">{{ $row['employee']->name }}</span>
                                            </a>
                                        </td>
                                        <td class="px-5 py-3 text-xs text-[var(--am-text-secondary)]">{{ $row['employee']->department ?? '—' }}</td>
                                        <td class="px-5 py-3 text-right text-sm tabular-nums text-sky-700">{{ number_format($row['hardware'], 2, ',', '.') }} €</td>
                                        <td class="px-5 py-3 text-right text-sm tabular-nums text-emerald-700">{{ number_format($row['licenses'], 2, ',', '.') }} €</td>
                                        <td class="px-5 py-3 text-right text-sm font-semibold tabular-nums text-[var(--am-text)]">{{ number_format($row['total'], 2, ',', '.') }} €</td>
                                        <td class="px-5 py-3">
                                            <div class="w-full h-1.5 rounded-full bg-[var(--am-bg)] overflow-hidden">
                                                <div class="h-full bg-[var(--am-accent)]" style="width: {{ $maxTotal > 0 ? round($row['total'] / $maxTotal * 100) : 0 }}%"></div>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        </div>
                    @endif
                </x-asset-manager-panel>
            </section>

            {{-- ABTEILUNG --}}
            <section id="section-department" class="space-y-3 scroll-mt-4">
                <h2 class="text-sm font-semibold text-[var(--am-text)]">Kosten pro Abteilung</h2>
                @include('asset-manager::livewire.costs._aggregation-table', ['rows' => $byDepartment, 'emptyHint' => 'Pflege Abteilungen in den Mitarbeiter-Profilen, damit hier eine Aggregation entsteht.'])
            </section>

            {{-- KOSTENSTELLE --}}
            <section id="section-costcenter" class="space-y-3 scroll-mt-4">
                <h2 class="text-sm font-semibold text-[var(--am-text)]">Kosten pro Kostenstelle</h2>
                @include('asset-manager::livewire.costs._aggregation-table', ['rows' => $byCostCenter, 'emptyHint' => 'Pflege Kostenstellen in den Mitarbeiter-Profilen für diese Aggregation.'])
            </section>

            {{-- KATEGORIE --}}
            <section id="section-category" class="space-y-3 scroll-mt-4">
                <h2 class="text-sm font-semibold text-[var(--am-text)]">Hardware-Kosten pro Kategorie</h2>
                <x-asset-manager-panel body-class="p-0">
                    @if($byCategory->isEmpty() || $byCategory->sum('monthly') == 0)
                        <div class="p-8 text-center text-sm text-[var(--am-text-secondary)]">Keine Hardware-Kosten erfasst.</div>
                    @else
                        <div class="p-5 space-y-3">
                            @php $maxCat = $byCategory->max('monthly') ?: 1; @endphp
                            @foreach($byCategory as $row)
                                <div>
                                    <div class="flex items-baseline justify-between text-xs mb-1">
                                        <span class="text-[var(--am-text-secondary)] font-medium">{{ $row['label'] }} <span class="text-[var(--am-text-muted)] ml-1">· {{ $row['count'] }} Items</span></span>
                                        <span class="text-[var(--am-text-secondary)] tabular-nums font-semibold">{{ number_format($row['monthly'], 2, ',', '.') }} €</span>
                                    </div>
                                    <div class="w-full h-2 rounded-full bg-[var(--am-bg)] overflow-hidden">
                                        <div class="h-full bg-sky-500" style="width: {{ round($row['monthly'] / $maxCat * 100) }}%"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-asset-manager-panel>
            </section>

            {{-- LIZENZ-SKUS --}}
            <section id="section-licenses" class="space-y-3 scroll-mt-4">
                <h2 class="text-sm font-semibold text-[var(--am-text)]">Lizenz-Kosten pro SKU</h2>
                <x-asset-manager-panel body-class="p-0">
                    @if($bySku->isEmpty())
                        <div class="p-8 text-center text-sm text-[var(--am-text-secondary)]">
                            Noch keine Preise gepflegt. Lege Stückpreise unter <a href="{{ route('asset-manager.licenses.index') }}" wire:navigate class="text-[var(--am-accent)] hover:underline">Lizenzen</a> fest.
                        </div>
                    @else
                        <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="bg-[var(--am-bg)]">
                                    <th class="text-left px-5 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">Lizenz</th>
                                    <th class="text-right px-5 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">Stückpreis</th>
                                    <th class="text-right px-5 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">Genutzt</th>
                                    <th class="text-right px-5 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">Auslastung</th>
                                    <th class="text-right px-5 py-3 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)]">Pro Monat</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[color:var(--am-border)]">
                                @foreach($bySku as $row)
                                    @php $util = $row['utilization']; $c = $util >= 95 ? 'red' : ($util >= 80 ? 'amber' : 'emerald'); @endphp
                                    <tr class="hover:bg-[var(--am-bg)]">
                                        <td class="px-5 py-3 font-medium text-[var(--am-text)]">{{ $row['label'] }}</td>
                                        <td class="px-5 py-3 text-right text-sm tabular-nums">{{ number_format((float)($row['unit_price'] ?? 0), 2, ',', '.') }} €</td>
                                        <td class="px-5 py-3 text-right text-sm tabular-nums">{{ $row['consumed'] }} / {{ $row['purchased'] }}</td>
                                        <td class="px-5 py-3 text-right">
                                            <x-asset-manager-badge :color="$c" size="xs" class="min-w-[3rem] justify-center tabular-nums">{{ $util }}%</x-asset-manager-badge>
                                        </td>
                                        <td class="px-5 py-3 text-right text-sm font-semibold tabular-nums text-emerald-700">{{ number_format($row['monthly'], 2, ',', '.') }} €</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        </div>
                    @endif
                </x-asset-manager-panel>
            </section>

            {{-- ANOMALIEN-INLINE --}}
            <section id="section-anomalies" class="space-y-3 scroll-mt-4">
                <h2 class="text-sm font-semibold text-[var(--am-text)]">Anomalien & Einsparpotentiale</h2>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="rounded-xl bg-sky-50 border border-sky-200 shadow-sm p-5">
                        <div class="flex items-center gap-2 mb-2">
                            @svg('heroicon-o-archive-box', 'w-5 h-5 text-sky-500')
                            <span class="text-xs uppercase tracking-wider text-[var(--am-text-muted)]">Hardware im Lager</span>
                        </div>
                        <div class="text-2xl font-semibold text-sky-700 tabular-nums">{{ number_format($anomalies['pool']['value'], 2, ',', '.') }} €</div>
                        <div class="text-xs text-[var(--am-text-secondary)] mt-1">{{ $anomalies['pool']['count'] }} Items mit Anschaffungswert</div>
                        <a href="{{ route('asset-manager.assets.index', ['filterStatus' => 'in_stock']) }}" wire:navigate class="text-xs text-[var(--am-accent)] hover:underline mt-2 inline-block">→ Lager-Items ansehen</a>
                    </div>

                    <div class="rounded-xl bg-emerald-50 border border-emerald-200 shadow-sm p-5">
                        <div class="flex items-center gap-2 mb-2">
                            @svg('heroicon-o-currency-euro', 'w-5 h-5 text-emerald-500')
                            <span class="text-xs uppercase tracking-wider text-[var(--am-text-muted)]">Ungenutzte Lizenzen</span>
                        </div>
                        <div class="text-2xl font-semibold text-emerald-700 tabular-nums">{{ number_format($anomalies['unused_licenses']['savings'], 2, ',', '.') }} €</div>
                        <div class="text-xs text-[var(--am-text-secondary)] mt-1">{{ $anomalies['unused_licenses']['units'] }} freie Lizenzen / Monat</div>
                        <a href="{{ route('asset-manager.licenses.index') }}" wire:navigate class="text-xs text-[var(--am-accent)] hover:underline mt-2 inline-block">→ Lizenzen prüfen</a>
                    </div>

                    <div class="rounded-xl bg-red-50 border border-red-200 shadow-sm p-5">
                        <div class="flex items-center gap-2 mb-2">
                            @svg('heroicon-o-user-minus', 'w-5 h-5 text-red-500')
                            <span class="text-xs uppercase tracking-wider text-[var(--am-text-muted)]">Hardware bei Inaktiven</span>
                        </div>
                        <div class="text-2xl font-semibold text-red-700 tabular-nums">{{ $anomalies['inactive_employees']['count'] }}</div>
                        <div class="text-xs text-[var(--am-text-secondary)] mt-1">Items, {{ number_format($anomalies['inactive_employees']['monthly'], 2, ',', '.') }} € / Monat</div>
                        <a href="{{ route('asset-manager.employees.index') }}" wire:navigate class="text-xs text-[var(--am-accent)] hover:underline mt-2 inline-block">→ Mitarbeiter prüfen</a>
                    </div>
                </div>
            </section>

        </div>
    </div>
</x-ui-page>
