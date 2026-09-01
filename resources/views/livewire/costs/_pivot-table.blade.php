{{--
    Pivot Kostenstellen-Baum × Kostenart, pro Nutzer anpassbar (docs/adr/0020).

    Erwartet:
      $pivot — aus CostAggregationService::costCenterByType() (Beträge, Spalten-/Gesamtsummen)
      $grid  — aus PivotTableShaper::shape() (Reihenfolge, Breiten, Vorfahren, Flags)

    Arbeitsteilung: der Server rendert **alle** Spalten und **alle** Zeilen und markiert sie
    (data-col / data-row / data-ancestors). Aus-/Einblenden, Faltung und Spaltenbreiten macht der
    Browser als eine einzige generierte CSS-Regel — kein Roundtrip, kein Neu-Rendern. Gespeichert
    wird gebündelt im Hintergrund (syncTableView + skipRender). Nur das Verschieben einer Spalte und
    das Zurücksetzen rendern neu, weil sich dabei das DOM ändern muss.
--}}
@php
    $columns  = $grid['columns'];
    $rows     = $grid['rows'];
    $defaults = $grid['defaults'];
    $labelKey = \Platform\AssetManager\Support\PivotTableShaper::LABEL_KEY;
    $totalKey = \Platform\AssetManager\Support\PivotTableShaper::TOTAL_KEY;

    // Modell für den Browser: nur Schlüssel, Default-Breiten und die Flags, die CSS-Regeln und Zähler
    // brauchen. Absichtlich schlank — die Beträge stehen schon im HTML.
    $config = [
        'scope' => '.am-grid--costs-allocation',
        'view'  => $grid['view'],
        'fixed' => [
            ['key' => $labelKey, 'def' => $defaults['label']],
            ['key' => $totalKey, 'def' => $defaults['total']],
        ],
        'cols'  => array_map(fn ($c) => [
            'key'   => $c['key'],
            'def'   => $defaults['column'],
            'empty' => $c['empty'],
        ], $columns),
        'rows'  => array_map(fn ($r) => [
            'key'   => $r['key'],
            'empty' => $r['empty'],
            'anc'   => $r['ancestors'] === '' ? [] : explode(' ', $r['ancestors']),
        ], $rows),
        'min'   => \Platform\AssetManager\Support\TableViewState::MIN_WIDTH,
        'max'   => \Platform\AssetManager\Support\TableViewState::MAX_WIDTH,
    ];

    $initialWidth = $grid['labelWidth'] + $grid['totalWidth'] + array_sum(array_column($columns, 'width'));

    // Fingerprint des servergerenderten Zustands. Eine gemorphte Alpine-Komponente wertet ihr x-data
    // NICHT neu aus — ohne diesen Schluessel behielte der Browser nach „Spalte verschieben" oder
    // „Ansicht zuruecksetzen" seinen alten Spiegel. Aendert sich der Schluessel, ersetzt Livewire das
    // Element und Alpine startet mit dem geprueften Zustand neu. Bei den haeufigen Aktionen (Ausblenden,
    // Falten, Breiten) rendert der Server gar nicht, der Schluessel bleibt und nichts wird ersetzt.
    $fingerprint = substr(md5(json_encode([
        $grid['view'], array_column($columns, 'key'), count($rows),
    ])), 0, 12);
@endphp

@verbatim
<style>
    /* Zieh-Zustand: der Cursor darf nicht flackern, wenn die Maus den schmalen Greifer verlässt. */
    body.am-col-resizing, body.am-col-resizing * { cursor: col-resize !important; user-select: none !important; }
    /* Einfügemarke beim Spalten-Verschieben — box-shadow, weil Tabellenzellen keinen Outline-Platz haben. */
    .am-drop-before { box-shadow: inset 2px 0 0 0 var(--am-primary); }
    .am-drop-after  { box-shadow: inset -2px 0 0 0 var(--am-primary); }
    /* Greifer: breiter Trefferbereich, schmale sichtbare Linie. */
    .am-grip { width: 10px; }
    .am-grip::after { content: ''; position: absolute; top: 5px; bottom: 5px; right: 4px; width: 2px; background: transparent; border-radius: 1px; }
    .am-grip:hover::after, .am-grip.is-active::after { background: var(--am-primary); }
</style>
<script>
    /**
     * Anpassbare Tabellen-Ansicht im Browser (docs/adr/0020).
     *
     * Der Server rendert alles und markiert die Zellen; hier entsteht daraus eine CSS-Regel, die
     * versteckt, faltet und Breiten setzt. Ein Klick kostet damit kein Rendern — der Server erfaehrt
     * den neuen Zustand gebuendelt und antwortet mit skipRender.
     *
     * Warum die Breite auf den Kopfzellen liegt und nicht im <colgroup>: die col-Zuordnung ist
     * positionell, die Kopfzelle traegt ihren Schluessel selbst. So kollabiert eine per display:none
     * ausgeblendete Spalte sauber, ohne die uebrigen Breiten zu verschieben.
     */
    window.amPivotGrid = window.amPivotGrid || function (config) {
        return {
            view: config.view,
            scope: config.scope,
            cols: config.cols,        // Kostenart-Spalten in Anzeige-Reihenfolge
            fixed: config.fixed,      // Kostenstelle + Summe (nicht ausblendbar, nicht verschiebbar)
            rows: config.rows,
            min: config.min,
            max: config.max,

            resizing: null,
            dragged: null,
            dropTarget: null,
            dropAfter: false,

            // --- abgeleiteter Zustand --------------------------------------------------------

            defFor: function (key) {
                var all = this.fixed.concat(this.cols);
                for (var i = 0; i < all.length; i++) {
                    if (all[i].key === key) { return all[i].def; }
                }
                return 96;
            },

            width: function (key) {
                return this.view.widths[key] || this.defFor(key);
            },

            isColHidden: function (col) {
                if (this.view.hidden_columns.indexOf(col.key) !== -1) { return true; }
                return this.view.hide_empty_columns && col.empty;
            },

            isRowHidden: function (row) {
                if (this.view.hidden_rows.indexOf(row.key) !== -1) { return true; }
                if (this.view.hide_empty_rows && row.empty) { return true; }

                for (var i = 0; i < row.anc.length; i++) {
                    if (this.view.collapsed_rows.indexOf(row.anc[i]) !== -1) { return true; }
                }
                return false;
            },

            isCollapsed: function (key) {
                return this.view.collapsed_rows.indexOf(key) !== -1;
            },

            get visibleCols() {
                var self = this;
                return this.cols.filter(function (c) { return ! self.isColHidden(c); });
            },

            get hiddenColCount() { return this.cols.length - this.visibleCols.length; },

            get hiddenRowCount() {
                var self = this;
                return this.rows.filter(function (r) { return self.isRowHidden(r); }).length;
            },

            get collapsedCount() { return this.view.collapsed_rows.length; },

            get visibleRowCount() { return this.rows.length - this.hiddenRowCount; },

            get customized() {
                return Object.keys(this.view.widths).length > 0
                    || this.view.order.length > 0
                    || this.view.hidden_columns.length > 0
                    || this.view.hidden_rows.length > 0
                    || this.view.collapsed_rows.length > 0
                    || this.view.hide_empty_columns
                    || this.view.hide_empty_rows;
            },

            /** Was gerade nicht zu sehen ist, in einem Satz. */
            get hint() {
                var parts = [];
                var cols = this.hiddenColCount;
                var rows = this.hiddenRowCount;

                if (cols > 0) { parts.push(cols + (cols === 1 ? ' Spalte' : ' Spalten') + ' ausgeblendet'); }
                if (rows > 0) { parts.push(rows + (rows === 1 ? ' Zeile' : ' Zeilen') + ' ausgeblendet'); }
                if (this.collapsedCount > 0) {
                    parts.push(this.collapsedCount + (this.collapsedCount === 1 ? ' Gruppe' : ' Gruppen') + ' eingeklappt');
                }

                return parts.join(' · ');
            },

            /** Die eine Regel, die alles macht. */
            get css() {
                var s = this.scope;
                var out = [];
                var total = 0;
                var i, col;

                for (i = 0; i < this.fixed.length; i++) {
                    out.push(s + ' thead th[data-col="' + this.fixed[i].key + '"]{width:' + this.width(this.fixed[i].key) + 'px}');
                    total += this.width(this.fixed[i].key);
                }

                for (i = 0; i < this.cols.length; i++) {
                    col = this.cols[i];

                    if (this.isColHidden(col)) {
                        out.push(s + ' [data-col="' + col.key + '"]{display:none}');
                        continue;
                    }

                    out.push(s + ' thead th[data-col="' + col.key + '"]{width:' + this.width(col.key) + 'px}');
                    total += this.width(col.key);
                }

                for (i = 0; i < this.view.hidden_rows.length; i++) {
                    out.push(s + ' [data-row="' + this.view.hidden_rows[i] + '"]{display:none}');
                }

                // Eine eingeklappte Gruppe versteckt ihren ganzen Teilbaum — inklusive Enkel, ohne
                // Baumlogik im Browser (die Vorfahren stehen als Wortliste an jeder Zeile).
                for (i = 0; i < this.view.collapsed_rows.length; i++) {
                    out.push(s + ' [data-ancestors~="' + this.view.collapsed_rows[i] + '"]{display:none}');
                }

                if (this.view.hide_empty_rows) {
                    out.push(s + ' [data-empty-row="1"]{display:none}');
                }

                out.push(s + ' table{width:' + total + 'px}');

                return out.join('\n');
            },

            // --- Aktionen: sofort im Zustand, dann gebuendelt speichern ----------------------

            toggleCol: function (key) {
                this.toggleIn(this.view.hidden_columns, key);
                this.save();
            },

            showAllCols: function () {
                this.view.hidden_columns = [];
                this.view.hide_empty_columns = false;
                this.save();
            },

            hideRow: function (key) {
                if (this.view.hidden_rows.indexOf(key) === -1) {
                    this.view.hidden_rows.push(key);
                    this.save();
                }
            },

            showAllRows: function () {
                this.view.hidden_rows = [];
                this.view.collapsed_rows = [];
                this.view.hide_empty_rows = false;
                this.save();
            },

            toggleGroup: function (key) {
                this.toggleIn(this.view.collapsed_rows, key);
                this.save();
            },

            toggleFlag: function (flag) {
                this.view[flag] = ! this.view[flag];
                this.save();
            },

            resetWidths: function () {
                this.view.widths = {};
                this.save();
            },

            toggleIn: function (list, value) {
                var at = list.indexOf(value);
                if (at === -1) { list.push(value); } else { list.splice(at, 1); }
            },

            // --- Breite ziehen --------------------------------------------------------------

            clamp: function (px) {
                return Math.max(this.min, Math.min(this.max, Math.round(px)));
            },

            startResize: function (event, key, direction) {
                var self = this;
                var startX = event.clientX;
                var startWidth = this.width(key);
                var sign = direction === -1 ? -1 : 1;
                var frame = null;
                var lastX = startX;

                this.resizing = key;
                document.body.classList.add('am-col-resizing');

                var apply = function () {
                    self.view.widths[key] = self.clamp(startWidth + sign * (lastX - startX));
                };

                var move = function (e) {
                    lastX = e.clientX;
                    if (frame !== null) { return; }   // ein Update je Bildaufbau, nicht je Mausbewegung
                    frame = requestAnimationFrame(function () {
                        frame = null;
                        apply();
                    });
                };

                var stop = function () {
                    document.removeEventListener('mousemove', move);
                    document.removeEventListener('mouseup', stop);
                    document.body.classList.remove('am-col-resizing');
                    if (frame !== null) { cancelAnimationFrame(frame); }
                    apply();
                    self.resizing = null;
                    self.save();
                };

                document.addEventListener('mousemove', move);
                document.addEventListener('mouseup', stop);
            },

            /**
             * Doppelklick = Inhaltsbreite. Gemessen werden die unsichtbaren [data-measure]-Zwillinge:
             * die sichtbaren Zellen sind abgeschnitten und wuerden immer die aktuelle Breite melden.
             */
            autofit: function (key) {
                var widest = 0;

                this.$el.querySelectorAll('[data-col="' + key + '"] [data-measure]').forEach(function (el) {
                    widest = Math.max(widest, el.getBoundingClientRect().width);
                });

                if (widest > 0) {
                    this.view.widths[key] = this.clamp(Math.ceil(widest) + 28); // + Zellen-Innenabstand
                    this.save();
                }
            },

            // --- Spalte verschieben (das Einzige, was neu rendern muss) ----------------------

            dragOver: function (event, key) {
                var box = event.currentTarget.getBoundingClientRect();
                this.dropTarget = key;
                this.dropAfter = (event.clientX - box.left) > box.width / 2;
            },

            dropMark: function (key) {
                if (! this.dragged || this.dropTarget !== key || this.dragged === key) { return ''; }
                return this.dropAfter ? 'am-drop-after' : 'am-drop-before';
            },

            drop: function () {
                var column = this.dragged;
                var target = this.dropTarget;
                var after = this.dropAfter;

                this.dragged = null;
                this.dropTarget = null;

                if (! column || ! target) { return; }

                // Der Server sortiert VOR eine Spalte ein. Wer auf der rechten Haelfte loslaesst, meint
                // „dahinter" — also vor die naechste sichtbare Spalte, bzw. ans Ende (null).
                if (after || target === 'END') {
                    var visible = this.visibleCols;
                    var at = -1;

                    for (var i = 0; i < visible.length; i++) {
                        if (visible[i].key === target) { at = i; break; }
                    }

                    target = (at === -1 || at + 1 >= visible.length) ? null : visible[at + 1].key;
                }

                if (column === target) { return; }

                this.$wire.moveColumn(column, target);
            },

            // --- Speichern ------------------------------------------------------------------

            /**
             * Sofort speichern, nicht gebuendelt: der Aufruf rendert nichts (skipRender), kostet also
             * nur Laden und Schreiben. Ein Sammelfenster koennte dagegen von einem Voll-Render
             * ueberholt werden (Klick auf „Quartal" direkt nach dem Ausblenden) — dann rendert der
             * Server den noch nicht gespeicherten Stand und die Ansicht springt zurueck.
             * Mehrere Aufrufe im selben Tick fasst Livewire selbst zusammen.
             */
            save: function () {
                this.$wire.syncTableView(this.view);
            },
        };
    };
</script>
@endverbatim

<div class="space-y-2 am-grid--costs-allocation"
     wire:key="am-grid-{{ $fingerprint }}"
     x-data="amPivotGrid({{ \Illuminate\Support\Js::from($config) }})">

    {{-- Die eine generierte Regel: Sichtbarkeit, Faltung, Breiten. `wire:ignore`, weil ein Morph den
         Regel-Text sonst mit dem leeren Server-HTML ueberschreibt und x-text erst bei der naechsten
         Zustandsaenderung wieder feuern wuerde. --}}
    <style wire:ignore x-text="css"></style>

    @include('asset-manager::livewire.costs._pivot-view-controls', ['grid' => $grid])

    <div class="rounded-xl bg-[var(--am-surface)] border border-[color:var(--am-border)] shadow-sm overflow-hidden">
        @if(empty($columns) || empty($rows))
            <div class="p-8 text-center text-sm text-[var(--am-text-secondary)]">
                Noch keine Kostenpositionen erfasst. Importiere die Excel
                (<code class="text-xs">asset-manager:import-costs</code>) oder pflege Positionen manuell.
            </div>
        @else
            {{-- Alles ausgeblendet: sonst stünde man vor einer leeren Karte ohne Ausweg. --}}
            <div x-show="visibleCols.length === 0 || visibleRowCount === 0" x-cloak
                 class="p-8 text-center text-sm text-[var(--am-text-secondary)]">
                Mit dieser Ansicht ist nichts zu sehen.
                <button type="button" wire:click="resetTableView"
                        class="ml-1 font-medium text-[var(--am-accent)] hover:underline">
                    Ansicht zurücksetzen
                </button>
            </div>

            {{-- Eigener Scrollbereich: so bleiben Kopfzeile, Kostenstellen-Spalte und Summenzeile beim
                 Blättern stehen — bei ~70 Zeilen der Unterschied zwischen Lesen und Suchen. --}}
            <div class="overflow-auto" style="max-height: 70vh"
                 x-show="visibleCols.length > 0 && visibleRowCount > 0">
                <table class="text-xs table-fixed border-separate border-spacing-0"
                       style="width: {{ $initialWidth }}px">
                    <thead>
                        <tr>
                            <th data-col="{{ $labelKey }}"
                                style="width: {{ $grid['labelWidth'] }}px"
                                class="sticky left-0 top-0 z-30 bg-[var(--am-bg)] text-left px-3 py-2 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)] border-r border-b border-[color:var(--am-border)] relative overflow-hidden">
                                <span data-measure class="invisible absolute whitespace-nowrap">Kostenstelle</span>
                                <span class="block truncate">Kostenstelle</span>
                                <span class="am-grip absolute inset-y-0 right-0 cursor-col-resize"
                                      x-bind:class="resizing === '{{ $labelKey }}' ? 'is-active' : ''"
                                      x-on:mousedown.prevent.stop="startResize($event, '{{ $labelKey }}')"
                                      x-on:dblclick.prevent.stop="autofit('{{ $labelKey }}')"
                                      title="Breite ziehen · Doppelklick = Inhaltsbreite"></span>
                            </th>

                            @foreach($columns as $column)
                                <th data-col="{{ $column['key'] }}"
                                    style="width: {{ $column['width'] }}px"
                                    x-on:dragover.prevent="dragOver($event, '{{ $column['key'] }}')"
                                    x-on:dragleave="dropTarget === '{{ $column['key'] }}' ? dropTarget = null : null"
                                    x-on:drop.prevent="drop()"
                                    x-bind:class="dropMark('{{ $column['key'] }}')"
                                    class="sticky top-0 z-20 bg-[var(--am-bg)] text-right px-3 py-2 text-xs font-semibold uppercase tracking-wider text-[var(--am-text-muted)] border-b border-[color:var(--am-border)] relative overflow-hidden">
                                    {{-- Unsichtbarer Zwilling: trägt den vollen Text für den Autofit. --}}
                                    <span data-measure class="invisible absolute whitespace-nowrap">{{ $column['name'] }}</span>
                                    <span draggable="true"
                                          x-on:dragstart="dragged = '{{ $column['key'] }}'"
                                          x-on:dragend="dragged = null; dropTarget = null"
                                          title="{{ $column['name'] }} — ziehen zum Verschieben"
                                          class="block truncate cursor-grab active:cursor-grabbing">{{ $column['name'] }}</span>
                                    <span class="am-grip absolute inset-y-0 right-0 cursor-col-resize"
                                          x-bind:class="resizing === '{{ $column['key'] }}' ? 'is-active' : ''"
                                          x-on:mousedown.prevent.stop="startResize($event, '{{ $column['key'] }}')"
                                          x-on:dblclick.prevent.stop="autofit('{{ $column['key'] }}')"
                                          title="Breite ziehen · Doppelklick = Inhaltsbreite"></span>
                                </th>
                            @endforeach

                            {{-- Die Summen-Spalte ist zugleich das Drop-Ziel für „ganz nach hinten". --}}
                            <th data-col="{{ $totalKey }}"
                                style="width: {{ $grid['totalWidth'] }}px"
                                x-on:dragover.prevent="dropTarget = 'END'; dropAfter = true"
                                x-on:dragleave="dropTarget === 'END' ? dropTarget = null : null"
                                x-on:drop.prevent="drop()"
                                x-bind:class="dragged && dropTarget === 'END' ? 'am-drop-before' : ''"
                                class="sticky top-0 z-20 text-right px-3 py-2 text-xs font-semibold uppercase tracking-wider text-[var(--am-text)] bg-[var(--am-accent-surface)] border-b border-[color:var(--am-border)] relative overflow-hidden">
                                <span data-measure class="invisible absolute whitespace-nowrap">Summe</span>
                                <span class="block truncate">Summe</span>
                                <span class="am-grip absolute inset-y-0 left-0 cursor-col-resize"
                                      x-bind:class="resizing === '{{ $totalKey }}' ? 'is-active' : ''"
                                      x-on:mousedown.prevent.stop="startResize($event, '{{ $totalKey }}', -1)"
                                      x-on:dblclick.prevent.stop="autofit('{{ $totalKey }}')"
                                      title="Breite ziehen · Doppelklick = Inhaltsbreite"></span>
                            </th>
                        </tr>

                        {{-- Metazeilen: Kreditor / System · Frequenz --}}
                        <tr class="text-[10px] text-[var(--am-text-muted)]">
                            <th data-col="{{ $labelKey }}"
                                class="sticky left-0 z-10 bg-[var(--am-surface)] text-left px-3 py-1 font-normal italic border-r border-b border-[color:var(--am-border)] overflow-hidden">
                                <span class="block truncate">Kreditor</span>
                            </th>
                            @foreach($columns as $column)
                                <th data-col="{{ $column['key'] }}" class="text-right px-3 py-1 font-normal border-b border-[color:var(--am-border)] overflow-hidden">
                                    <span class="block truncate" title="{{ $column['vendor'] ?? '' }}">{{ $column['vendor'] ?? '—' }}</span>
                                </th>
                            @endforeach
                            <th data-col="{{ $totalKey }}" class="bg-[var(--am-accent-surface)] border-b border-[color:var(--am-border)]"></th>
                        </tr>
                        <tr class="text-[10px] text-[var(--am-text-muted)]">
                            <th data-col="{{ $labelKey }}"
                                class="sticky left-0 z-10 bg-[var(--am-surface)] text-left px-3 py-1 font-normal italic border-r border-b border-[color:var(--am-border)] overflow-hidden">
                                <span class="block truncate">System · Frequenz</span>
                            </th>
                            @foreach($columns as $column)
                                @php $sub = trim(($column['system'] ?? '—') . ' · ' . ($column['frequency'] ?? '')); @endphp
                                <th data-col="{{ $column['key'] }}" class="text-right px-3 py-1 font-normal border-b border-[color:var(--am-border)] overflow-hidden">
                                    <span class="block truncate" title="{{ $sub }}">{{ $sub }}</span>
                                </th>
                            @endforeach
                            <th data-col="{{ $totalKey }}" class="bg-[var(--am-accent-surface)] border-b border-[color:var(--am-border)]"></th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach($rows as $row)
                            <tr data-row="{{ $row['key'] }}"
                                data-ancestors="{{ $row['ancestors'] }}"
                                @if($row['empty']) data-empty-row="1" @endif
                                class="group {{ $row['isGroup'] ? 'font-semibold' : '' }}">
                                <td data-col="{{ $labelKey }}"
                                    class="sticky left-0 z-10 {{ $row['isGroup'] ? 'bg-[var(--am-bg)]' : 'bg-[var(--am-surface)]' }} px-3 py-2 border-r border-b border-[color:var(--am-border)] text-[var(--am-text)] overflow-hidden {{ $row['isGroup'] ? '' : 'font-medium' }}">
                                    {{-- Einrückung als Inline-Style: die Tiefe ist unbegrenzt, dynamische
                                         Tailwind-Klassen (pl-{{ n }}) entstehen im Build nicht. --}}
                                    <span data-measure class="invisible absolute whitespace-nowrap"
                                          style="padding-left: {{ $row['indent'] }}px">{{ $row['code'] }} {{ $row['name'] }}</span>
                                    <div class="flex items-center gap-1" style="padding-left: {{ $row['indent'] }}px">
                                        @if($row['isGroup'])
                                            <button type="button" x-on:click="toggleGroup('{{ $row['key'] }}')"
                                                    x-bind:title="isCollapsed('{{ $row['key'] }}') ? 'Untergeordnete einblenden' : 'Untergeordnete einklappen'"
                                                    class="shrink-0 text-[var(--am-text-muted)] hover:text-[var(--am-text)] transition-colors">
                                                <span class="block transition-transform duration-150"
                                                      x-bind:class="isCollapsed('{{ $row['key'] }}') ? '-rotate-90' : ''">
                                                    @svg('heroicon-o-chevron-down', 'w-3.5 h-3.5')
                                                </span>
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
                                            <span class="shrink-0 text-[9px] uppercase tracking-wider text-[var(--am-text-muted)] font-normal"
                                                  x-text="isCollapsed('{{ $row['key'] }}') ? 'zusammengefasst' : 'inkl. untergeordnete'">inkl. untergeordnete</span>
                                        @endif

                                        <button type="button" x-on:click="hideRow('{{ $row['key'] }}')"
                                                title="Zeile ausblenden"
                                                class="ml-auto shrink-0 opacity-0 group-hover:opacity-100 focus:opacity-100 text-[var(--am-text-muted)] hover:text-[var(--am-text)] transition-opacity">
                                            @svg('heroicon-o-eye-slash', 'w-3.5 h-3.5')
                                        </button>
                                    </div>
                                </td>

                                @foreach($columns as $column)
                                    @php $value = $row['cells'][$column['id']] ?? 0; @endphp
                                    <td data-col="{{ $column['key'] }}"
                                        class="text-right px-3 py-2 tabular-nums whitespace-nowrap overflow-hidden border-b border-[color:var(--am-border)] {{ $value == 0 ? 'text-[var(--am-text-muted)]' : ($value < 0 ? 'text-red-700' : 'text-[var(--am-text)]') }}">
                                        <span data-measure>{{ $value == 0 ? '–' : number_format($value, 2, ',', '.') }}</span>
                                    </td>
                                @endforeach

                                <td data-col="{{ $totalKey }}"
                                    class="text-right px-3 py-2 font-semibold tabular-nums whitespace-nowrap overflow-hidden border-b border-[color:var(--am-border)] text-[var(--am-accent)] bg-[var(--am-accent-surface)]">
                                    <span data-measure>{{ number_format($row['total'], 2, ',', '.') }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>

                    <tfoot>
                        {{-- Summen über ALLE Zeilen und Kostenarten — auch über ausgeblendete und
                             eingeklappte. Eine Ansicht darf keine Auswertung verändern. --}}
                        <tr class="font-semibold">
                            <td data-col="{{ $labelKey }}"
                                class="sticky left-0 bottom-0 z-20 bg-[var(--am-bg)] px-3 py-2 text-[var(--am-text)] border-r border-t-2 border-[color:var(--am-border)] overflow-hidden">
                                <span data-measure class="invisible absolute whitespace-nowrap">Summe (alle Daten)</span>
                                <span class="block truncate">Summe<span x-show="customized" x-cloak> (alle Daten)</span></span>
                            </td>
                            @foreach($columns as $column)
                                <td data-col="{{ $column['key'] }}"
                                    class="sticky bottom-0 z-10 bg-[var(--am-bg)] text-right px-3 py-2 tabular-nums whitespace-nowrap overflow-hidden border-t-2 border-[color:var(--am-border)] text-[var(--am-text)]">
                                    <span data-measure>{{ number_format($pivot['colTotals'][$column['id']] ?? 0, 2, ',', '.') }}</span>
                                </td>
                            @endforeach
                            <td data-col="{{ $totalKey }}"
                                class="sticky bottom-0 z-10 text-right px-3 py-2 tabular-nums whitespace-nowrap overflow-hidden border-t-2 border-[color:var(--am-border)] text-[var(--am-accent)] bg-[var(--am-accent-surface)]">
                                <span data-measure>{{ number_format($pivot['grandTotal'], 2, ',', '.') }}</span>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </div>
</div>
