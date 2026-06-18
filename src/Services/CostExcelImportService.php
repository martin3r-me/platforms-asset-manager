<?php

namespace Platform\AssetManager\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\AssetManager\Models\AssetCategory;
use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Models\AssetCostLine;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Models\AssetEmployee;
use Platform\AssetManager\Models\AssetItem;
use Platform\AssetManager\Models\AssetVendor;

/**
 * Importiert die Kostenaufteilungs-Excel (Kostenaufteilung_IT.xlsx) als Ist-Stand-Bootstrap.
 *
 * Erzeugt cost_lines NUR für Opex, die der Graph-Sync nicht kennt (Mobilfunk, Leasing, Internet,
 * Drucker, Abos, BPEvent, HGK, necta). MS-Lizenzen und Hardware-AfA bleiben Graph-/Inventar-Quelle
 * → keine Doppelzählung. Vollständig idempotent über import_hash.
 */
class CostExcelImportService
{
    protected int $teamId;
    protected string $batchId;
    protected bool $dryRun;

    /** @var array<string,AssetCostType> key => model */
    protected array $costTypes = [];
    /** @var array<string,AssetVendor> name => model */
    protected array $vendors = [];
    /** @var array<string,AssetEmployee> upperName => model */
    protected array $employeesByName = [];

    protected array $stats = [];

    /** import_hash jeder in DIESEM Lauf geschriebenen (angelegten/aktualisierten) Cost-Line — Basis des Prune. */
    protected array $writtenHashes = [];

    /** Default-Tenant des Teams (memoisiert) — Tenant-Ziel aller vom Importer angelegten/aktualisierten Assets (kein UI-Kontext). */
    protected ?int $importTenantId = null;

    /** Herkunft der aktuell verarbeiteten Zeile — wird in raw_data der Cost-Line geschrieben. */
    protected ?string $sheetLabel = null;
    protected ?int    $sheetRow   = null;

    public function __construct(
        protected CostBootstrapService $bootstrap
    ) {}

    /**
     * @return array Statistik je Sheet
     */
    public function import(int $teamId, string $path, string $batchId = 'excel-bootstrap', bool $dryRun = false): array
    {
        $this->teamId        = $teamId;
        $this->batchId       = $batchId;
        $this->dryRun        = $dryRun;
        $this->stats          = [];
        $this->writtenHashes  = [];
        $this->importTenantId = null;

        $sheets = $this->readWorkbook($path);

        DB::beginTransaction();
        try {
            // Stammdaten + Lookups INNERHALB der Transaktion sicherstellen — der BROICH-Excel-Import braucht
            // das BROICH-Set (feste Kostenart-Keys), sonst würden Positionen mit unbekanntem Key übersprungen.
            // Wichtig fürs Dry-Run: so rollt der rollback auch das seedBroichDefaults mit zurück und schreibt
            // keine BROICH-Defaults dauerhaft in (fremde) Teams (Multi-Tenant-Leitplanke).
            $this->bootstrap->seedBroichDefaults($teamId);

            $this->costTypes = AssetCostType::where('team_id', $teamId)->get()->keyBy('key')->all();
            $this->vendors   = AssetVendor::where('team_id', $teamId)->get()->keyBy('name')->all();
            $this->employeesByName = AssetEmployee::where('team_id', $teamId)->get()
                ->keyBy(fn($e) => $this->normName($e->display_name ?: $e->user_principal_name))
                ->all();

            $this->importUebersicht($this->findSheet($sheets, ['ubersicht', 'übersicht']));
            $this->importInternet($this->findSheet($sheets, ['internet']));
            $this->importDrucker($this->findSheet($sheets, ['drucker']));
            $this->importBpEvent($this->findSheet($sheets, ['bpevent']));
            $this->importPerCostCenter($this->findSheet($sheets, ['hgk']), 'hgk', true);
            $this->importPerCostCenter($this->findSheet($sheets, ['necta']), 'necta', false);
            // Laptops bewusst NICHT importiert — sie kommen aus Intune (asset_devices) und sind dort
            // bereits korrekt den Mitarbeitern zugeordnet. Ihre Kosten stecken in der Übersicht (lap_dock).
            $this->importSeatSheet($this->findSheet($sheets, ['chatgpt']), 'chatgpt', 'E'); // Kosten in Euro
            $this->importSeatSheet($this->findSheet($sheets, ['canva']), 'canva', 'D');

            // Idempotenz: verwaiste Import-Zeilen (z. B. geänderter Betrag → neuer Hash → die alte Zeile
            // ist jetzt verwaist) innerhalb DERSELBEN Transaktion entfernen, bevor wir committen/rollen.
            $this->pruneStaleLines();

            if ($this->dryRun) {
                DB::rollBack();
                $this->stats['_mode'] = 'dry-run (rolled back)';
            } else {
                DB::commit();
                $this->stats['_mode'] = 'committed';
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $this->stats;
    }

    // ── Sheet-Importer ────────────────────────────────────────────────────

    /** Sheet3: pro Mitarbeiter mehrere Kostenarten (Spalten). */
    protected function importUebersicht(?array $rows): void
    {
        if (!$rows) return;
        $this->sheetLabel = 'Übersicht';

        // Spalte → Kostenart-Key (MS Lizenz/D bewusst ausgelassen — kommt aus Graph)
        $map = [
            'F' => 'vf_lizenz_rc',
            'G' => 'vf_lizenz_rc_rab',
            'I' => 'lap_dock',
            'J' => 'versicherung',
            'K' => 'o365_backup',
            'L' => 'mobilfunk',
            'M' => 'brevo',
            'N' => 'optisigns',
            'O' => 'firstinvision',
            'P' => 'adobe_indesign',
            'R' => 'mobileiron',
        ];

        $count = 0;
        foreach ($rows as $i => $row) {
            if ($i === 1) continue; // Header
            $this->sheetRow = (int) $i;
            $name = trim((string) ($row['A'] ?? ''));
            $code = $this->cc($row['B'] ?? null);
            if ($name === '') continue;

            $employee = $this->findEmployee($name);
            $center   = $this->bootstrap->resolveCostCenter($this->teamId, $code);

            foreach ($map as $col => $typeKey) {
                $amount = $this->num($row[$col] ?? null);
                if ($amount == 0.0) continue;
                $this->upsertLine($typeKey, [
                    'cost_center_id' => $center?->id,
                    'assignee_id'    => $employee?->id,
                    'amount'         => $amount,
                    'label'          => $this->costTypes[$typeKey]->name ?? $typeKey,
                ]);
                $count++;
            }
        }
        $this->stats['uebersicht'] = $count;
    }

    /** Sheet4: Internet-Anschlüsse (standortbezogen) → AssetItem + cost_line. */
    protected function importInternet(?array $rows): void
    {
        if (!$rows) return;
        $this->sheetLabel = 'Internet';
        $cat  = $this->category('internet', 'Internet', 'heroicon-o-wifi');

        $count = 0;
        foreach ($rows as $i => $row) {
            if ($i === 1) continue;
            $this->sheetRow = (int) $i;
            $anschluss = trim((string) ($row['A'] ?? ''));
            if ($anschluss === '') continue;
            $amount = $this->num($row['E'] ?? null);
            $code   = $this->cc($row['F'] ?? null);

            $item = $this->upsertItem($cat->id, $anschluss, [
                'serial_number' => null,
                'raw_data'      => [
                    'anschrift' => $row['B'] ?? null,
                    'standort'  => $row['C'] ?? null,
                    'anbieter'  => $row['D'] ?? null,
                ],
            ]);
            $this->upsertLine('internet', [
                'cost_center_id' => $this->bootstrap->resolveCostCenter($this->teamId, $code)?->id,
                'asset_item_id'  => $item?->id,
                'amount'         => $amount,
                'label'          => $anschluss,
                'vendor_name'    => $row['D'] ?? null,
            ]);
            $count++;
        }
        $this->stats['internet'] = $count;
    }

    /** Sheet6: Drucker → AssetItem + 2 cost_lines (Wartung + Leasing). */
    protected function importDrucker(?array $rows): void
    {
        if (!$rows) return;
        $this->sheetLabel = 'Drucker';
        $cat  = $this->category('drucker', 'Drucker', 'heroicon-o-printer');

        $count = 0;
        foreach ($rows as $i => $row) {
            if ($i === 1) continue;
            $this->sheetRow = (int) $i;
            $modell = trim((string) ($row['C'] ?? ''));
            $standort = trim((string) ($row['B'] ?? ''));
            if ($modell === '' && $standort === '') continue;
            if (Str::startsWith(mb_strtolower((string) ($row['A'] ?? '')), ['summe', 'anzahl', 'wartungskosten', 'leasingkosten'])) continue;

            $code = $this->cc($row['H'] ?? null);
            $ccId = $this->bootstrap->resolveCostCenter($this->teamId, $code)?->id;

            $name = $standort !== '' ? "{$modell} ({$standort})" : $modell;
            $item = $this->upsertItem($cat->id, $name, [
                'model'         => $modell,
                'serial_number' => $row['E'] ?? null,
                'raw_data'      => [
                    'niederlassung' => $row['A'] ?? null,
                    'server_standort' => $row['B'] ?? null,
                    'id_nr'         => $row['D'] ?? null,
                    'grenke_protect' => $row['I'] ?? null,
                    'spalte_j'      => $row['J'] ?? null,
                ],
            ]);

            $wartung = $this->num($row['F'] ?? null);
            $leasing = $this->num($row['G'] ?? null);
            if ($wartung != 0.0) {
                // Excel-Druckerwerte werden wie bisher monatlich behandelt (druckerwartung-Default ist quarterly,
                // Basis der Excel-Spalte aber unbestätigt) → kein stiller 3×-Effekt durch den Default.
                $this->upsertLine('druckerwartung', ['cost_center_id' => $ccId, 'asset_item_id' => $item?->id, 'amount' => $wartung, 'label' => "Wartung {$name}", 'frequency' => 'monthly']);
                $count++;
            }
            if ($leasing != 0.0) {
                $this->upsertLine('druckerleasing', ['cost_center_id' => $ccId, 'asset_item_id' => $item?->id, 'amount' => $leasing, 'label' => "Leasing {$name}"]);
                $count++;
            }
        }
        $this->stats['drucker'] = $count;
    }

    /** Sheet7: BPEvent je Kostenstelle (+ GL-Konten). */
    protected function importBpEvent(?array $rows): void
    {
        if (!$rows) return;
        $this->sheetLabel = 'BPEvent';

        $count = 0;
        foreach ($rows as $i => $row) {
            if ($i === 1) continue;
            $this->sheetRow = (int) $i;
            $code = $this->cc($row['A'] ?? null);
            if ($code === null) continue;
            $amount = $this->num($row['G'] ?? null);  // Kosten (Monatsbasis der Pivot)
            if ($amount == 0.0) continue;

            $this->upsertLine('bpevent', [
                'cost_center_id'    => $this->bootstrap->resolveCostCenter($this->teamId, $code)?->id,
                'amount'            => $amount,
                'label'             => "BPEvent {$code}",
                'frequency'         => 'monthly', // Spalte G ist bereits Monatsbasis (s. o.), trotz quarterly-Default
                'gl_contra_account' => $this->str($row['K'] ?? null),
                'gl_account'        => $this->str($row['L'] ?? null),
                'debit_credit'      => $this->str($row['M'] ?? null),
            ]);
            $count++;
        }
        $this->stats['bpevent'] = $count;
    }

    /** Sheet8/9: HGK bzw. necta je Kostenstelle. A=Name, B=Kosten mtl., C=Kostenstelle, D=Faktor. */
    protected function importPerCostCenter(?array $rows, string $typeKey, bool $withFactor): void
    {
        if (!$rows) return;
        $this->sheetLabel = strtoupper($typeKey);

        $count = 0;
        foreach ($rows as $i => $row) {
            if ($i === 1) continue;
            $this->sheetRow = (int) $i;
            $label = trim((string) ($row['A'] ?? ''));
            $code  = $this->cc($row['C'] ?? null);
            $amount = $this->num($row['B'] ?? null);
            if ($code === null || $amount == 0.0) continue;
            if (Str::startsWith(mb_strtolower($label), ['summe', 'anzahl', 'kosten pro'])) continue;

            $this->upsertLine($typeKey, [
                'cost_center_id'      => $this->bootstrap->resolveCostCenter($this->teamId, $code)?->id,
                'amount'              => $amount,
                'label'               => $label !== '' ? $label : strtoupper($typeKey),
                'frequency'           => 'monthly', // Spalte B ist „Kosten mtl." (s. o.), trotz quarterly-Default bei necta
                'distribution_factor' => $withFactor ? $this->num($row['D'] ?? null) : null,
            ]);
            $count++;
        }
        $this->stats[$typeKey] = $count;
    }

    /** Sheet11/12: Seat-Listen (ChatGPT/Canva). Spalte $amountCol enthält EUR-Monatsbetrag. */
    protected function importSeatSheet(?array $rows, string $typeKey, string $amountCol): void
    {
        if (!$rows) return;
        $this->sheetLabel = $this->costTypes[$typeKey]->name ?? ucfirst($typeKey);

        $count = 0;
        foreach ($rows as $i => $row) {
            if ($i === 1) continue;
            $this->sheetRow = (int) $i;
            $name = trim((string) ($row['A'] ?? ''));
            if ($name === '') continue;
            $code = $this->cc($row['C'] ?? null);
            $amount = $this->num($row[$amountCol] ?? null);
            if ($amount == 0.0) continue;

            $employee = $this->findEmployee($name);
            $this->upsertLine($typeKey, [
                'cost_center_id' => $this->bootstrap->resolveCostCenter($this->teamId, $code)?->id,
                'assignee_id'    => $employee?->id,
                'amount'         => $amount,
                'label'          => ($this->costTypes[$typeKey]->name ?? $typeKey) . " — {$name}",
            ]);
            $count++;
        }
        $this->stats[$typeKey] = $count;
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    protected function upsertLine(string $typeKey, array $attrs): void
    {
        $type = $this->costTypes[$typeKey] ?? null;
        if (!$type) return;

        $vendorId = null;
        if (!empty($attrs['vendor_name'])) {
            $vendorId = $this->resolveVendor($attrs['vendor_name'])?->id;
        }
        $vendorId = $vendorId ?? $type->vendor_default_id;

        $amount = (float) ($attrs['amount'] ?? 0);

        // Frequenz: expliziter Wert vom Aufrufer (Sheets mit Monatsbasis) > frequency_default der Kostenart
        // > 'monthly'. Behebt die 12×-Überzählung jährlicher Posten (z. B. FirstInVision = yearly). Muss in
        // den Hash, sonst kollidieren monatlich/jährlich derselben Position.
        $frequency = $attrs['frequency'] ?? $type->frequency_default ?? 'monthly';

        // FX-Policy = SNAPSHOT: Der Importer schreibt ausschließlich EUR (Nicht-EUR/USD-Posten werden als
        // manuelle Cost-Lines mit currency+fx_rate gepflegt, siehe docs/adr). currency gehört in den Hash,
        // damit gleiche Position in unterschiedlicher Währung nicht kollidiert. Siehe CreateCostLineTool
        // (lehnt Nicht-EUR ohne fx_rate ab) und AssetCostLine::computeMonthlyAmount (amount × fx_rate).
        $currency = 'EUR';

        $hash = sha1(implode('|', [
            $this->teamId, $typeKey,
            $attrs['cost_center_id'] ?? '', $attrs['assignee_id'] ?? '', $attrs['asset_item_id'] ?? '',
            $attrs['label'] ?? '', number_format($amount, 4, '.', ''), $frequency, $currency,
        ]));

        $values = [
            'cost_type_id'        => $type->id,
            'vendor_id'           => $vendorId,
            'cost_center_id'      => $attrs['cost_center_id'] ?? null,
            'assignee_id'         => $attrs['assignee_id'] ?? null,
            'asset_item_id'       => $attrs['asset_item_id'] ?? null,
            'label'               => $attrs['label'] ?? $type->name,
            'amount'              => $amount,
            'currency'            => $currency,
            'frequency'           => $frequency,
            'gl_account'          => $attrs['gl_account'] ?? null,
            'gl_contra_account'   => $attrs['gl_contra_account'] ?? null,
            'debit_credit'        => $attrs['debit_credit'] ?? null,
            'accounting_system'   => $type->system_default,
            'distribution_factor' => $attrs['distribution_factor'] ?? null,
            'source'              => 'excel_import',
            'active'              => true,
            'import_batch_id'     => $this->batchId,
            'raw_data'            => array_filter([
                'sheet' => $this->sheetLabel,
                'row'   => $this->sheetRow,
            ], fn($v) => $v !== null) ?: null,
        ];

        // Bestehende Zeile inkl. soft-deleted suchen.
        // Policy (restore-or-refuse → refuse): Eine manuell im UI gelöschte Import-Zeile wird beim
        // Re-Import NICHT wiederbelebt — die bewusste Löschung gewinnt. Ihr Hash landet NICHT in
        // writtenHashes, deshalb räumt pruneStaleLines() die soft-gelöschte Zeile anschließend hart
        // weg. Soll sie zurückkommen, vor dem Re-Import restoren (dann greift der update-Zweig).
        $existing = AssetCostLine::withTrashed()
            ->where('team_id', $this->teamId)
            ->where('import_hash', $hash)
            ->first();

        if ($existing && $existing->trashed()) {
            return;
        }

        if ($existing) {
            $existing->update($values);
        } else {
            AssetCostLine::create(array_merge($values, [
                'team_id'     => $this->teamId,
                'import_hash' => $hash,
            ]));
        }

        $this->writtenHashes[] = $hash;
    }

    /**
     * Entfernt alle Import-Zeilen (source='excel_import') des Teams, deren Hash in DIESEM Lauf nicht
     * geschrieben wurde — macht den Re-Import idempotent: eine geänderte Betrags-/Frequenz-Position
     * erzeugt einen neuen Hash, die alte (jetzt verwaiste) Zeile wird hier endgültig entfernt statt als
     * aktive Dublette stehen zu bleiben (kein 84,90 statt 45,00). withTrashed(): auch bereits manuell
     * soft-gelöschte Import-Zwillinge werden hart bereinigt. Läuft INNERHALB der Import-Transaktion,
     * ein Dry-Run rollt das also mit zurück.
     */
    protected function pruneStaleLines(): void
    {
        // Schutz: Hat dieser Lauf KEINE einzige Zeile geschrieben (leere/falsche Datei), nichts löschen —
        // sonst würde whereNotIn('import_hash', []) sämtliche Import-Zeilen des Teams entfernen.
        if (empty($this->writtenHashes)) {
            return;
        }

        AssetCostLine::withTrashed()
            ->where('team_id', $this->teamId)
            ->where('source', 'excel_import')
            ->whereNotIn('import_hash', array_values(array_unique($this->writtenHashes)))
            ->forceDelete();
    }

    protected function upsertItem(int $categoryId, string $name, array $attrs = []): ?AssetItem
    {
        // Importer hat keinen UI-/Tenant-Kontext → Assets landen im Default-Tenant des Teams (memoisiert).
        $tenantId = $this->importTenantId ??= TenantContext::defaultTenantId($this->teamId);

        return AssetItem::updateOrCreate(
            ['team_id' => $this->teamId, 'category_id' => $categoryId, 'name' => $name],
            array_merge([
                'source'    => 'manual',
                'tenant_id' => $tenantId,
                'status'    => $attrs['assignee_id'] ?? null ? 'assigned' : 'in_stock',
            ], array_filter([
                'model'         => $attrs['model'] ?? null,
                'serial_number' => $attrs['serial_number'] ?? null,
                'assignee_id'   => $attrs['assignee_id'] ?? null,
                'status'        => $attrs['status'] ?? null,
                'raw_data'      => $attrs['raw_data'] ?? null,
            ], fn($v) => $v !== null))
        );
    }

    /**
     * Findet einen bestehenden Mitarbeiter über den normalisierten Anzeigenamen — legt NICHTS an
     * und schreibt nichts. Die Kostenstelle am Mitarbeiter ist manuell gepflegte Quelle der Wahrheit
     * (UI), nicht Sache des Imports. Kein Match → null → Cost-Line hängt nur an der Kostenstelle.
     */
    protected function findEmployee(string $name): ?AssetEmployee
    {
        return $this->employeesByName[$this->normName($name)] ?? null;
    }

    protected function resolveVendor(string $name): ?AssetVendor
    {
        $name = trim($name);
        if ($name === '') return null;
        if (isset($this->vendors[$name])) return $this->vendors[$name];

        $vendor = AssetVendor::firstOrCreate(['team_id' => $this->teamId, 'name' => $name]);
        $this->vendors[$name] = $vendor;
        return $vendor;
    }

    protected function category(string $key, string $name, string $icon): AssetCategory
    {
        return AssetCategory::firstOrCreate(
            ['key' => $key],
            ['name' => $name, 'icon' => $icon, 'is_synced' => false, 'sort_order' => 200]
        );
    }

    /**
     * Liest die Arbeitsmappe in ['normname' => [zeilennr => ['A'=>wert, 'B'=>wert, …]]] (1-basiert).
     *
     * Eigener schlanker Reader (ZipArchive + SimpleXML): liest immer den GECACHTEN Zellwert (<v>),
     * also auch das Ergebnis von Formeln — keine Dependency, kein ext-gd.
     */
    protected function readWorkbook(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Excel-Datei konnte nicht geöffnet werden.');
        }

        // Shared Strings
        $shared = [];
        if (($ss = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $xml = simplexml_load_string($ss);
            if ($xml !== false) {
                foreach ($xml->si as $si) {
                    $shared[] = $this->siText($si);
                }
            }
        }

        // Relationship-Map (r:id → Target)
        $relMap = [];
        if (($relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels')) !== false) {
            $rels = simplexml_load_string($relsXml);
            if ($rels !== false) {
                foreach ($rels->Relationship as $rel) {
                    $relMap[(string) $rel['Id']] = (string) $rel['Target'];
                }
            }
        }

        $sheets = [];
        $wb = simplexml_load_string($zip->getFromName('xl/workbook.xml') ?: '');
        if ($wb !== false && isset($wb->sheets)) {
            foreach ($wb->sheets->sheet as $sheet) {
                $name = (string) $sheet['name'];
                $rid  = '';
                foreach ($sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships') as $k => $v) {
                    if ($k === 'id') $rid = (string) $v;
                }
                $target = $relMap[$rid] ?? null;
                if (!$target) continue;
                if (!str_starts_with($target, 'xl/')) {
                    $target = 'xl/' . ltrim($target, '/');
                }
                $data = $zip->getFromName($target);
                if ($data === false) continue;
                $sheets[$this->normName($name)] = $this->parseSheet($data, $shared);
            }
        }
        $zip->close();

        return $sheets;
    }

    /** Text aus <si> (inkl. Rich-Text-Runs <r><t>). */
    protected function siText(\SimpleXMLElement $si): string
    {
        $text = '';
        if (isset($si->t)) $text .= (string) $si->t;
        foreach ($si->r as $r) {
            if (isset($r->t)) $text .= (string) $r->t;
        }
        return $text;
    }

    /** Eine Worksheet-XML → [zeilennr => ['A'=>wert, …]] mit gecachten Werten. */
    protected function parseSheet(string $xml, array $shared): array
    {
        $sx = simplexml_load_string($xml);
        $rows = [];
        if ($sx === false || !isset($sx->sheetData)) return $rows;

        foreach ($sx->sheetData->row as $row) {
            $rn    = (int) $row['r'];
            $assoc = [];
            foreach ($row->c as $c) {
                $ref = (string) $c['r'];
                if (!preg_match('/^([A-Z]+)/', $ref, $m)) continue;
                $col = $m[1];
                $t   = (string) $c['t'];
                $val = null;

                if ($t === 's') {
                    $val = isset($c->v) ? ($shared[(int) $c->v] ?? null) : null;
                } elseif ($t === 'inlineStr') {
                    $val = isset($c->is) ? $this->siText($c->is) : null;
                } elseif (isset($c->v)) {
                    // numerisch / boolean / gecachtes Formelergebnis (t='' | 'n' | 'str' | 'b')
                    $raw = (string) $c->v;
                    $val = is_numeric($raw) ? $raw + 0 : $raw;
                }

                if ($val !== null && $val !== '') {
                    $assoc[$col] = $val;
                }
            }
            if ($assoc) $rows[$rn] = $assoc;
        }
        return $rows;
    }

    protected function findSheet(array $sheets, array $candidates): ?array
    {
        foreach ($candidates as $c) {
            $key = $this->normName($c);
            if (isset($sheets[$key])) return $sheets[$key];
        }
        // Teilstring-Suche als Fallback
        foreach ($sheets as $key => $rows) {
            foreach ($candidates as $c) {
                if (Str::contains($key, $this->normName($c))) return $rows;
            }
        }
        return null;
    }

    protected function normName(?string $s): string
    {
        $s = mb_strtolower(trim((string) $s));
        return str_replace(['ä', 'ö', 'ü', 'ß', ' ', '.'], ['a', 'o', 'u', 'ss', '', ''], $s);
    }

    /** Kostenstellen-Code als String normalisieren (z.B. 2599.0 → "2599", "EFP" bleibt). */
    protected function cc($val): ?string
    {
        if ($val === null || $val === '') return null;
        if (is_numeric($val)) return (string) (int) $val;
        return trim((string) $val);
    }

    protected function num($val): float
    {
        if ($val === null || $val === '') return 0.0;
        if (is_numeric($val)) return (float) $val;
        $clean = str_replace(['€', ' ', "\u{00a0}"], '', (string) $val);
        $clean = str_replace('.', '', $clean);
        $clean = str_replace(',', '.', $clean);
        return is_numeric($clean) ? (float) $clean : 0.0;
    }

    protected function str($val): ?string
    {
        $s = trim((string) ($val ?? ''));
        return $s === '' ? null : $s;
    }
}
