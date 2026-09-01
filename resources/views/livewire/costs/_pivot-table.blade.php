{{--
    Pivot Kostenstellen-Baum × Kostenart, pro Nutzer anpassbar (docs/adr/0020).

    Erwartet:
      $pivot — aus CostAggregationService::costCenterByType() (Beträge, Spalten-/Gesamtsummen)
      $grid  — aus PivotTableShaper::shape() (Reihenfolge, Breiten, Sichtbarkeit, Faltung)

    Arbeitsteilung: Reihenfolge, Sichtbarkeit und Faltung entscheidet der Shaper, das Speichern der
    Trait CustomizesTableView. Im Browser läuft nur das Ziehen der Spaltenbreiten (Alpine) — bewusst
    ohne Server-Roundtrip pro Mausbewegung; gespeichert wird gebündelt beim Loslassen.
--}}
@php
    $columns    = $grid['visibleColumns'];
    $rows       = $grid['rows'];
    $stats      = $grid['stats'];
    $labelKey   = \Platform\AssetManager\Support\PivotTableShaper::LABEL_KEY;
    $totalKey   = \Platform\AssetManager\Support\PivotTableShaper::TOTAL_KEY;

    // Spaltenliste für das Browser-seitige Breiten-Modell: Label-Spalte, sichtbare Kostenarten,
    // Summen-Spalte. Daraus rechnet Alpine die Tabellenbreite beim Ziehen live nach.
    $gridColumns = array_merge(
        [['key' => $labelKey, 'width' => $grid['labelWidth']]],
        array_map(fn ($c) => ['key' => $c['key'], 'width' => $c['width']], $columns),
        [['key' => $totalKey, 'width' => $grid['totalWidth']]],
    );
@endphp

@verbatim
<script>
    // Breiten-Ziehen, Autofit und Spalten-Drag der anpassbaren Tabellen. Absichtlich winzig und
    // ohne Abhängigkeiten: Alpine ist über die Host-App vorhanden, das Modul bringt kein Bundling
    // mit. Die Wahrheit über die Ansicht liegt auf dem Server — hier lebt nur der Spiegel, der das
    // Ziehen flüssig macht, plus das Nachziehen per `am-table-view-updated`.
    window.amPivotGrid = window.amPivotGrid || function (config) {
        return {
            widths: Object.assign({}, config.widths || {}),
            columns: config.columns || [],
            min: config.min || 56,
            max: config.max || 720,
            resizing: null,
            dragged: null,
            dropTarget: null,

            clamp: function (px) {
                return Math.max(this.min, Math.min(this.max, Math.round(px)));
            },

            /** Breite einer Spalte, sonst die vom Server gerenderte Default-Breite. */
            columnStyle: function (key, fallback) {
                return 'width: ' + (this.widths[key] || fallback) + 'px';
            },

            /** Tabellenbreite = Summe der Spalten. Ohne das würde table-fixed beim Ziehen quetschen. */
            tableStyle: function () {
                var self = this;
                var total = this.columns.reduce(function (sum, column) {
                    return sum + (self.widths[column.key] || column.width);
                }, 0);

                return 'width: ' + total + 'px';
            },

            /**
             * `direction` ist -1, wenn der Greifer links der Spalte sitzt (die Summen-Spalte ist die
             * letzte, rechts von ihr ist das Tabellenende). Ohne das wuerde sie beim Ziehen nach
             * links schmaler statt breiter.
             */
            startResize: function (event, key, fallback, direction) {
                var self = this;
                var startX = event.clientX;
                var startWidth = this.widths[key] || fallback;
                var sign = direction === -1 ? -1 : 1;

                this.resizing = key;

                var move = function (e) {
                    self.widths[key] = self.clamp(startWidth + sign * (e.clientX - startX));
                };

                var stop = function () {
                    document.removeEventListener('mousemove', move);
                    document.removeEventListener('mouseup', stop);
                    self.resizing = null;
                    self.persist();
                };

                document.addEventListener('mousemove', move);
                document.addEventListener('mouseup', stop);
            },

            /**
             * Doppelklick = Inhaltsbreite. Gemessen werden die unsichtbaren [data-measure]-Spans der
             * Spalte: sie tragen den vollen Text (die sichtbaren sind abgeschnitten und würden immer
             * die aktuelle Spaltenbreite zurückmelden).
             */
            autofit: function (key) {
                var widest = 0;

                this.$el.querySelectorAll('[data-col="' + key + '"] [data-measure]').forEach(function (el) {
                    widest = Math.max(widest, el.getBoundingClientRect().width);
                });

                if (widest > 0) {
                    this.widths[key] = this.clamp(Math.ceil(widest) + 28); // + Zellen-Innenabstand
                    this.persist();
                }
            },

            drop: function (target) {
                var column = this.dragged;

                this.dragged = null;
                this.dropTarget = null;

                if (column && column !== target) {
                    this.$wire.moveColumn(column, target);
                }
            },

            persist: function () {
                this.$wire.setColumnWidths(this.widths);
            },
        };
    };
</script>
@endverbatim

<div class="space-y-2">

    @include('asset-manager::livewire.costs._pivot-view-controls', ['grid' => $grid])

    <div class="rounded-xl bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm overflow-hidden"
         x-data="amPivotGrid({
             widths: @js($grid['widths'] ?? []),
             columns: @js($gridColumns),
             min: {{ \Platform\AssetManager\Support\TableViewState::MIN_WIDTH }},
             max: {{ \Platform\AssetManager\Support\TableViewState::MAX_WIDTH }},
         })"
         x-on:am-table-view-updated.window="widths = Object.assign({}, $event.detail.widths || {})"
         x-bind:class="resizing ? 'select-none' : ''">

        @if(empty($columns) || empty($rows))
            <div class="p-8 text-center text-sm text-[var(--am-text-secondary)]">
                @if($stats['customized'])
                    Mit dieser Ansicht ist nichts zu sehen — alle Spalten oder Zeilen sind ausgeblendet.
                    <button type="button" wire:click="resetTableView" class="ml-1 font-medium text-[var(--am-accent)] hover:underline">
                        Ansicht zurücksetzen
                    </button>
                @else
                    Noch keine Kostenpositionen erfasst. Importiere die Excel
                    (<code class="text-xs">asset-manager:import-costs</code>) oder pflege Positionen manuell.
                @endif
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="text-xs border-collapse table-fixed"
                       style="width: {{ $grid['tableWidth'] }}px"
                       x-bind:style="tableStyle()">
                    <colgroup>
                        <col style="width: {{ $grid['labelWidth'] }}px"
                             x-bind:style="columnStyle('{{ $labelKey }}', {{ $grid['labelWidth'] }})">
                        @foreach($columns as $column)
                            <col style="width: {{ $column['width'] }}px"
                                 x-bind:style="columnStyle('{{ $column['key'] }}', {{ $column['width'] }})">
                        @endforeach
                        <col style="width: {{ $grid['totalWidth'] }}px"
                             x-bind:style="columnStyle('{{ $totalKey }}', {{ $grid['totalWidth'] }})">
                    </colgroup>

                    <thead>
                        <tr class="border-b border-[color:var(--am-border)] bg-[var(--am-bg)]">
                            <th data-col="{{ $labelKey }}"
                                class="sticky left-0 z-20 bg-[var(--am-bg)] text-left px-3 py-2 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)] border-r border-[color:var(--am-border)] relative overflow-hidden">
                                <span data-measure class="invisible absolute whitespace-nowrap">Kostenstelle</span>
                                <span class="block truncate">Kostenstelle</span>
                                <span x-on:mousedown.prevent.stop="startResize($event, '{{ $labelKey }}', {{ $grid['labelWidth'] }})"
                                      x-on:dblclick.prevent.stop="autofit('{{ $labelKey }}')"
                                      title="Breite ziehen · Doppelklick = Inhaltsbreite"
                                      class="absolute inset-y-0 right-0 w-1.5 cursor-col-resize hover:bg-[var(--am-primary)]"></span>
                            </th>

                            @foreach($columns as $column)
                                <th data-col="{{ $column['key'] }}"
                                    x-on:dragover.prevent="dropTarget = '{{ $column['key'] }}'"
                                    x-on:dragleave="dropTarget === '{{ $column['key'] }}' ? dropTarget = null : null"
                                    x-on:drop.prevent="drop('{{ $column['key'] }}')"
                                    x-bind:class="dragged && dragged !== '{{ $column['key'] }}' && dropTarget === '{{ $column['key'] }}' ? 'bg-[var(--am-accent-surface)]' : ''"
                                    class="text-right px-3 py-2 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)] relative overflow-hidden">
                                    {{-- Unsichtbarer Zwilling: trägt den vollen Text für den Autofit. --}}
                                    <span data-measure class="invisible absolute whitespace-nowrap">{{ $column['name'] }}</span>
                                    <span draggable="true"
                                          x-on:dragstart="dragged = '{{ $column['key'] }}'"
                                          x-on:dragend="dragged = null; dropTarget = null"
                                          title="{{ $column['name'] }} — ziehen zum Verschieben"
                                          class="block truncate cursor-grab active:cursor-grabbing">{{ $column['name'] }}</span>
                                    <span x-on:mousedown.prevent.stop="startResize($event, '{{ $column['key'] }}', {{ $column['width'] }})"
                                          x-on:dblclick.prevent.stop="autofit('{{ $column['key'] }}')"
                                          title="Breite ziehen · Doppelklick = Inhaltsbreite"
                                          class="absolute inset-y-0 right-0 w-1.5 cursor-col-resize hover:bg-[var(--am-primary)]"></span>
                                </th>
                            @endforeach

                            {{-- Die Summen-Spalte ist zugleich das Drop-Ziel für „ganz nach hinten". --}}
                            <th data-col="{{ $totalKey }}"
                                x-on:dragover.prevent="dropTarget = '__end'"
                                x-on:dragleave="dropTarget === '__end' ? dropTarget = null : null"
                                x-on:drop.prevent="drop(null)"
                                x-bind:class="dragged && dropTarget === '__end' ? 'bg-[var(--am-primary)] text-[var(--am-on-primary)]' : ''"
                                class="text-right px-3 py-2 text-xs font-semibold uppercase tracking-wider text-[var(--am-text)] bg-[var(--am-accent-surface)] relative overflow-hidden">
                                <span data-measure class="invisible absolute whitespace-nowrap">Summe</span>
                                <span class="block truncate">Summe</span>
                                <span x-on:mousedown.prevent.stop="startResize($event, '{{ $totalKey }}', {{ $grid['totalWidth'] }}, -1)"
                                      x-on:dblclick.prevent.stop="autofit('{{ $totalKey }}')"
                                      title="Breite ziehen · Doppelklick = Inhaltsbreite"
                                      class="absolute inset-y-0 left-0 w-1.5 cursor-col-resize hover:bg-[var(--am-primary)]"></span>
                            </th>
                        </tr>

                        {{-- Metazeilen: Kreditor / System · Frequenz --}}
                        <tr class="text-[10px] text-[var(--am-text-muted)] border-b border-[color:var(--am-border)]">
                            <th class="sticky left-0 z-20 bg-[var(--am-surface)] text-left px-3 py-1 font-normal italic border-r border-[color:var(--am-border)] overflow-hidden">
                                <span class="block truncate">Kreditor</span>
                            </th>
                            @foreach($columns as $column)
                                <th class="text-right px-3 py-1 font-normal overflow-hidden">
                                    <span class="block truncate" title="{{ $column['vendor'] ?? '' }}">{{ $column['vendor'] ?? '—' }}</span>
                                </th>
                            @endforeach
                            <th class="bg-[var(--am-accent-surface)]"></th>
                        </tr>
                        <tr class="text-[10px] text-[var(--am-text-muted)] border-b border-[color:var(--am-border)]">
                            <th class="sticky left-0 z-20 bg-[var(--am-surface)] text-left px-3 py-1 font-normal italic border-r border-[color:var(--am-border)] overflow-hidden">
                                <span class="block truncate">System · Frequenz</span>
                            </th>
                            @foreach($columns as $column)
                                @php $sub = trim(($column['system'] ?? '—') . ' · ' . ($column['frequency'] ?? '')); @endphp
                                <th class="text-right px-3 py-1 font-normal overflow-hidden">
                                    <span class="block truncate" title="{{ $sub }}">{{ $sub }}</span>
                                </th>
                            @endforeach
                            <th class="bg-[var(--am-accent-surface)]"></th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach($rows as $row)
                            <tr class="group border-b border-[color:var(--am-border)] hover:bg-[var(--am-bg)] {{ $row['isGroup'] ? 'bg-[var(--am-bg)] font-semibold' : '' }}">
                                <td data-col="{{ $labelKey }}"
                                    class="sticky left-0 z-10 {{ $row['isGroup'] ? 'bg-[var(--am-bg)]' : 'bg-[var(--am-surface)]' }} px-3 py-2 border-r border-[color:var(--am-border)] text-[var(--am-text)] overflow-hidden {{ $row['isGroup'] ? '' : 'font-medium' }}">
                                    {{-- Einrückung als Inline-Style: die Tiefe ist unbegrenzt, dynamische
                                         Tailwind-Klassen (pl-{{ n }}) entstehen im Build nicht. --}}
                                    <span data-measure class="invisible absolute whitespace-nowrap"
                                          style="padding-left: {{ $row['indent'] }}px">{{ $row['code'] }} {{ $row['name'] }}</span>
                                    <div class="flex items-center gap-1" style="padding-left: {{ $row['indent'] }}px">
                                        @if($row['isGroup'])
                                            <button type="button" wire:click="toggleRowGroup('{{ $row['key'] }}')"
                                                    title="{{ $row['collapsed'] ? 'Untergeordnete einblenden' : 'Untergeordnete einklappen' }}"
                                                    class="shrink-0 text-[var(--am-text-muted)] hover:text-[var(--am-text)] transition-colors">
                                                @svg($row['collapsed'] ? 'heroicon-o-chevron-right' : 'heroicon-o-chevron-down', 'w-3.5 h-3.5')
                                            </button>
                                        @elseif($row['depth'] > 0)
                                            <span class="shrink-0 w-3.5 text-center text-[var(--am-text-muted)] select-none">└</span>
                                        @else
                                            <span class="shrink-0 w-3.5"></span>
                                        @endif

                                        <span class="truncate">
                                            {{ $row['code'] }}@if($row['name'])<span class="text-[var(--am-text-secondary)] font-normal ml-1">{{ $row['name'] }}</span>@endif
                                        </span>

                                        @if($row['isGroup'])
                                            <span class="shrink-0 text-[9px] uppercase tracking-wider text-[var(--am-text-muted)] font-normal">{{ $row['collapsed'] ? 'zusammengefasst' : 'inkl. untergeordnete' }}</span>
                                        @endif

                                        <button type="button" wire:click="hideRow('{{ $row['key'] }}')"
                                                title="Zeile ausblenden"
                                                class="ml-auto shrink-0 opacity-0 group-hover:opacity-100 focus:opacity-100 text-[var(--am-text-muted)] hover:text-[var(--am-text)] transition-opacity">
                                            @svg('heroicon-o-eye-slash', 'w-3.5 h-3.5')
                                        </button>
                                    </div>
                                </td>

                                @foreach($columns as $column)
                                    @php $value = $row['cells'][$column['id']] ?? 0; @endphp
                                    <td data-col="{{ $column['key'] }}"
                                        class="text-right px-3 py-2 tabular-nums whitespace-nowrap overflow-hidden {{ $value == 0 ? 'text-[var(--am-text-muted)]' : ($value < 0 ? 'text-red-700' : 'text-[var(--am-text)]') }}">
                                        <span data-measure>{{ $value == 0 ? '–' : number_format($value, 2, ',', '.') }}</span>
                                    </td>
                                @endforeach

                                <td data-col="{{ $totalKey }}"
                                    class="text-right px-3 py-2 font-semibold tabular-nums whitespace-nowrap overflow-hidden text-[var(--am-accent)] bg-[var(--am-accent-surface)]">
                                    <span data-measure>{{ number_format($row['total'], 2, ',', '.') }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>

                    <tfoot>
                        {{-- Summen über ALLE Zeilen und Kostenarten — auch über ausgeblendete und
                             eingeklappte. Eine Ansicht darf keine Auswertung verändern. --}}
                        <tr class="border-t-2 border-[color:var(--am-border)] bg-[var(--am-bg)] font-semibold">
                            <td data-col="{{ $labelKey }}"
                                class="sticky left-0 z-10 bg-[var(--am-bg)] px-3 py-2 text-[var(--am-text)] border-r border-[color:var(--am-border)] overflow-hidden">
                                @php $footLabel = 'Summe' . ($stats['hiddenColumns'] + $stats['hiddenRows'] > 0 ? ' (alle Daten)' : ''); @endphp
                                <span data-measure class="invisible absolute whitespace-nowrap">{{ $footLabel }}</span>
                                <span class="block truncate">{{ $footLabel }}</span>
                            </td>
                            @foreach($columns as $column)
                                <td data-col="{{ $column['key'] }}"
                                    class="text-right px-3 py-2 tabular-nums whitespace-nowrap overflow-hidden text-[var(--am-text)]">
                                    <span data-measure>{{ number_format($pivot['colTotals'][$column['id']] ?? 0, 2, ',', '.') }}</span>
                                </td>
                            @endforeach
                            <td data-col="{{ $totalKey }}"
                                class="text-right px-3 py-2 tabular-nums whitespace-nowrap overflow-hidden text-[var(--am-accent)] bg-[var(--am-accent-surface)]">
                                <span data-measure>{{ number_format($pivot['grandTotal'], 2, ',', '.') }}</span>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </div>
</div>
