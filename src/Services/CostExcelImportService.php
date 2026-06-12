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

    public function __construct(
        protected CostBootstrapService $bootstrap
    ) {}

    /**
     * @return array Statistik je Sheet
     */
    public function import(int $teamId, string $path, string $batchId = 'excel-bootstrap', bool $dryRun = false): array
    {
        $this->teamId  = $teamId;
        $this->batchId = $batchId;
        $this->dryRun  = $dryRun;
        $this->stats   = [];

        // Stammdaten sicherstellen
        $this->bootstrap->seedForTeam($teamId);

        $this->costTypes = AssetCostType::where('team_id', $teamId)->get()->keyBy('key')->all();
        $this->vendors   = AssetVendor::where('team_id', $teamId)->get()->keyBy('name')->all();
        $this->employeesByName = AssetEmployee::where('team_id', $teamId)->get()
            ->keyBy(fn($e) => $this->normName($e->display_name ?: $e->user_principal_name))
            ->all();

        $sheets = $this->readWorkbook($path);

        DB::beginTransaction();
        try {
            $this->importUebersicht($this->findSheet($sheets, ['ubersicht', 'übersicht']));
            $this->importInternet($this->findSheet($sheets, ['internet']));
            $this->importDrucker($this->findSheet($sheets, ['drucker']));
            $this->importBpEvent($this->findSheet($sheets, ['bpevent']));
            $this->importPerCostCenter($this->findSheet($sheets, ['hgk']), 'hgk', true);
            $this->importPerCostCenter($this->findSheet($sheets, ['necta']), 'necta', false);
            $this->importLaptops($this->findSheet($sheets, ['laptops', 'lap+dock', 'laptop']));
            $this->importSeatSheet($this->findSheet($sheets, ['chatgpt']), 'chatgpt', 'E'); // Kosten in Euro
            $this->importSeatSheet($this->findSheet($sheets, ['canva']), 'canva', 'D');

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
            $name = trim((string) ($row['A'] ?? ''));
            $code = $this->cc($row['B'] ?? null);
            if ($name === '') continue;

            $employee = $this->resolveEmployee($name, $code);
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
        $cat  = $this->category('internet', 'Internet', 'heroicon-o-wifi');

        $count = 0;
        foreach ($rows as $i => $row) {
            if ($i === 1) continue;
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
        $cat  = $this->category('drucker', 'Drucker', 'heroicon-o-printer');

        $count = 0;
        foreach ($rows as $i => $row) {
            if ($i === 1) continue;
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
                $this->upsertLine('druckerwartung', ['cost_center_id' => $ccId, 'asset_item_id' => $item?->id, 'amount' => $wartung, 'label' => "Wartung {$name}"]);
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

        $count = 0;
        foreach ($rows as $i => $row) {
            if ($i === 1) continue;
            $code = $this->cc($row['A'] ?? null);
            if ($code === null) continue;
            $amount = $this->num($row['G'] ?? null);  // Kosten (Monatsbasis der Pivot)
            if ($amount == 0.0) continue;

            $this->upsertLine('bpevent', [
                'cost_center_id'    => $this->bootstrap->resolveCostCenter($this->teamId, $code)?->id,
                'amount'            => $amount,
                'label'             => "BPEvent {$code}",
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

        $count = 0;
        foreach ($rows as $i => $row) {
            if ($i === 1) continue;
            $label = trim((string) ($row['A'] ?? ''));
            $code  = $this->cc($row['C'] ?? null);
            $amount = $this->num($row['B'] ?? null);
            if ($code === null || $amount == 0.0) continue;
            if (Str::startsWith(mb_strtolower($label), ['summe', 'anzahl', 'kosten pro'])) continue;

            $this->upsertLine($typeKey, [
                'cost_center_id'      => $this->bootstrap->resolveCostCenter($this->teamId, $code)?->id,
                'amount'              => $amount,
                'label'               => $label !== '' ? $label : strtoupper($typeKey),
                'distribution_factor' => $withFactor ? $this->num($row['D'] ?? null) : null,
            ]);
            $count++;
        }
        $this->stats[$typeKey] = $count;
    }

    /** Sheet10: Laptops → AssetItem-Inventar (Kosten kommen aus Übersicht/lap_dock, kein cost_line). */
    protected function importLaptops(?array $rows): void
    {
        if (!$rows) return;
        $cat  = $this->category('laptop', 'Laptop', 'heroicon-o-computer-desktop');

        $count = 0;
        foreach ($rows as $i => $row) {
            if ($i === 1) continue;
            $assetTag = trim((string) ($row['B'] ?? ''));
            if ($assetTag === '') continue;

            $empName = trim((string) ($row['A'] ?? ''));
            $code    = $this->cc($row['D'] ?? null);
            $employee = $empName !== '' ? $this->resolveEmployee($empName, $code) : null;

            $this->upsertItem($cat->id, $assetTag, [
                'model'         => $row['G'] ?? null,
                'serial_number' => $row['C'] ?? null,
                'assignee_id'   => $employee?->id,
                'status'        => $employee ? 'assigned' : 'in_stock',
                'raw_data'      => ['kostenstelle' => $code],
            ]);
            $count++;
        }
        $this->stats['laptops'] = $count;
    }

    /** Sheet11/12: Seat-Listen (ChatGPT/Canva). Spalte $amountCol enthält EUR-Monatsbetrag. */
    protected function importSeatSheet(?array $rows, string $typeKey, string $amountCol): void
    {
        if (!$rows) return;

        $count = 0;
        foreach ($rows as $i => $row) {
            if ($i === 1) continue;
            $name = trim((string) ($row['A'] ?? ''));
            if ($name === '') continue;
            $code = $this->cc($row['C'] ?? null);
            $amount = $this->num($row[$amountCol] ?? null);
            if ($amount == 0.0) continue;

            $employee = $this->resolveEmployee($name, $code, $this->str($row['B'] ?? null));
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
        $hash = sha1(implode('|', [
            $this->teamId, $typeKey,
            $attrs['cost_center_id'] ?? '', $attrs['assignee_id'] ?? '', $attrs['asset_item_id'] ?? '',
            $attrs['label'] ?? '', number_format($amount, 4, '.', ''), 'monthly',
        ]));

        AssetCostLine::updateOrCreate(
            ['team_id' => $this->teamId, 'import_hash' => $hash],
            [
                'cost_type_id'        => $type->id,
                'vendor_id'           => $vendorId,
                'cost_center_id'      => $attrs['cost_center_id'] ?? null,
                'assignee_id'         => $attrs['assignee_id'] ?? null,
                'asset_item_id'       => $attrs['asset_item_id'] ?? null,
                'label'               => $attrs['label'] ?? $type->name,
                'amount'              => $amount,
                'currency'            => 'EUR',
                'frequency'           => 'monthly',
                'gl_account'          => $attrs['gl_account'] ?? null,
                'gl_contra_account'   => $attrs['gl_contra_account'] ?? null,
                'debit_credit'        => $attrs['debit_credit'] ?? null,
                'accounting_system'   => $type->system_default,
                'distribution_factor' => $attrs['distribution_factor'] ?? null,
                'source'              => 'excel_import',
                'active'              => true,
                'import_batch_id'     => $this->batchId,
            ]
        );
    }

    protected function upsertItem(int $categoryId, string $name, array $attrs = []): ?AssetItem
    {
        return AssetItem::updateOrCreate(
            ['team_id' => $this->teamId, 'category_id' => $categoryId, 'name' => $name],
            array_merge([
                'source' => 'manual',
                'status' => $attrs['assignee_id'] ?? null ? 'assigned' : 'in_stock',
            ], array_filter([
                'model'         => $attrs['model'] ?? null,
                'serial_number' => $attrs['serial_number'] ?? null,
                'assignee_id'   => $attrs['assignee_id'] ?? null,
                'status'        => $attrs['status'] ?? null,
                'raw_data'      => $attrs['raw_data'] ?? null,
            ], fn($v) => $v !== null))
        );
    }

    protected function resolveEmployee(string $name, ?string $code, ?string $email = null): AssetEmployee
    {
        $key = $this->normName($name);
        if (isset($this->employeesByName[$key])) {
            $emp = $this->employeesByName[$key];
            // Kostenstelle nachziehen wenn leer
            if ($code && !$emp->cost_center) {
                $emp->cost_center = $code;
                $emp->cost_center_id = $this->bootstrap->resolveCostCenter($this->teamId, $code)?->id;
                $emp->save();
            }
            return $emp;
        }

        $isFunction = $this->looksLikeFunctionAccount($name);
        $upn = $email ?: (Str::slug($name) . '@funktion.import.local');

        $emp = AssetEmployee::firstOrNew(['team_id' => $this->teamId, 'user_principal_name' => $upn]);
        $emp->display_name   = $emp->display_name ?: $name;
        $emp->email          = $emp->email ?: $email;
        $emp->cost_center    = $emp->cost_center ?: $code;
        $emp->cost_center_id = $emp->cost_center_id ?: $this->bootstrap->resolveCostCenter($this->teamId, $code)?->id;
        $emp->account_type   = $isFunction ? 'function' : ($emp->account_type ?? 'person');
        $emp->source         = $emp->exists ? $emp->source : 'manual';
        $emp->is_active      = $emp->exists ? $emp->is_active : true;
        $emp->save();

        $this->employeesByName[$key] = $emp;
        return $emp;
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

    protected function looksLikeFunctionAccount(string $name): bool
    {
        $upper = mb_strtoupper($name);
        // Funktionskonto = kein "VORNAME NACHNAME"-Muster oder bekannte Schlüsselwörter
        $keywords = ['CONTROLLING', 'HELPDESK', 'WEBSHOP', 'TELEFON', 'PROTOKOLL', 'PRAKTIKANT', 'NEWSLETTER',
            'REGISTRIERUNG', 'TROCKENLAGER', 'KUECHE', 'KÜCHE', 'PRODUKTION', 'SUPPORT', 'INVOICE', 'FOOD',
            'TEAM', 'ASSISTENZ', 'ZENTRALE', 'FINANZ', 'PERSONAL', 'LOGISTIK', 'FACILITY', 'SPEISEN',
            'NICHT EINGESETZTE', 'DIGITALER', 'AVATAR', 'TABLET', 'ADMINISTRATOR', 'BANKETTPROFI', 'SHARED'];
        foreach ($keywords as $kw) {
            if (Str::contains($upper, $kw)) return true;
        }
        return false;
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
