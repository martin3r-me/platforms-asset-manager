<?php

namespace Platform\AssetManager\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Platform\AssetManager\Models\AssetCostLine;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Models\AssetVendor;
use Platform\AssetManager\Support\PhoneNumber;
use Platform\AssetManager\Support\XlsxReader;

/**
 * Import der Vodafone-Rechnungsanalyse (Firmenkundenportal → Export „Rechnungsdaten").
 *
 * ## Dateiformat (verifiziert an einem echten Export, 07/2026)
 *
 * Ein Blatt `Rechnungsdaten`, Zeile 3 = Kopfzeile, danach Datenzeilen. Spalte A trennt drei Typen:
 *
 *   | Typ              | Bedeutung                                              |
 *   |------------------|--------------------------------------------------------|
 *   | `Rechnung`       | Rechnungskopf — Gesamtbetrag, dient als Prüfsumme       |
 *   | `Rufnummer`      | eine Zeile je Anschluss (Spalte K = D2-Nummer)          |
 *   | `Rechnungsposten`| kontobezogen ohne Nummer (z. B. VPN-Grundpreis, Rabatt) |
 *
 * Spalten: B Rechnungskonto · C Rechnungs-Nummer · D Rechnungs-Datum · E/F Abrechnungszeitraum ·
 * G Brutto · H USt-Satz · I USt · J **Netto** · K D2-Nummer bzw. Beschreibung · L Beschreibung ·
 * M Kostenstelle (im Format `2599 - HKS Team Düsseldorf`).
 *
 * Gerechnet wird mit **Netto** (Spalte J) — die Kostenaufteilung ist eine Netto-Sicht.
 *
 * ## Modell (ADR 0014, ADR 0001)
 *
 * Eine {@see AssetCostLine} je Rufnummer, Kostenart `mobilfunk`, `source='vodafone_invoice'`.
 * **Keine** eigene Vertrags- oder SIM-Entität: eine Nummer je Person, keine Pool-/M2M-SIMs.
 *
 * Drei Entscheidungen, die vom Excel-Importer abweichen:
 *
 * 1. **Der `import_hash` enthält den Betrag NICHT.** Beim Excel-Import führt eine Preisänderung zu
 *    einem neuen Hash, also zu einer neuen Zeile plus Wegräumen der alten. Hier ist der Preis die
 *    Größe, die sich jeden Monat ändert (Zusatzbuchungen, Verbrauch) — die Zeile bekäme jedes Mal
 *    eine neue ID. Gehasht wird deshalb die *Identität*: Team, Tenant, Kostenart, Rufnummer.
 * 2. **Die Kostenstelle kommt vom Asset-Träger**, nicht aus dem Export. Der Vodafone-Wert wird als
 *    Referenz in `raw_data` mitgeschrieben und Abweichungen werden gemeldet, aber nicht übernommen —
 *    unsere Zuordnung ist die gepflegte Wahrheit. Nur wenn kein Träger matcht, greift die
 *    Kostenstelle aus dem Export als Fallback.
 * 3. **Nicht zuordenbare Nummern werden trotzdem angelegt** (ohne `assignee`, markiert in
 *    `raw_data.unmatched`). Ein Betrag, der still verschwindet, ist schlimmer als eine Zeile, die
 *    auffällt — dieselbe Begründung wie die Auffangzeile im Pivot.
 */
class VodafoneInvoiceImportService
{
    public const SOURCE     = 'vodafone_invoice';
    public const VENDOR     = 'Vodafone';
    public const TYPE_KEY   = 'mobilfunk';

    protected int     $teamId   = 0;
    protected ?int    $tenantId = null;
    protected string  $batchId  = '';
    protected bool    $dryRun   = true;

    /** @var list<string> import_hash jeder in diesem Lauf geschriebenen Zeile — Basis des Prune. */
    protected array $writtenHashes = [];

    public function __construct(protected XlsxReader $reader = new XlsxReader()) {}

    /**
     * Rechnungsdatei einlesen und die Mobilfunk-Kostenpositionen des Tenants darauf ausrichten.
     *
     * @return array<string, mixed> Statistik + Prüfsummen + Auffälligkeiten
     */
    public function import(int $teamId, string $path, string $batchId = 'vodafone-upload', bool $dryRun = true, ?int $tenantId = null): array
    {
        $this->teamId   = $teamId;
        $this->tenantId = $tenantId ?? TenantContext::defaultTenantId($teamId);
        $this->batchId  = $batchId;
        $this->dryRun   = $dryRun;
        $this->writtenHashes = [];

        // Tenant für den Global Scope setzen — der Importer läuft ohne UI-Kontext (Console/Upload).
        TenantContext::forceTenant($this->tenantId);

        $rows = $this->readRows($path);

        $header = $this->firstOfType($rows, 'Rechnung');
        $lines  = $this->ofType($rows, 'Rufnummer');
        $items  = $this->ofType($rows, 'Rechnungsposten');

        if ($lines === [] && $items === []) {
            throw new \RuntimeException('Keine Rechnungszeilen gefunden — ist das der Export „Rechnungsdaten" aus dem Firmenkundenportal?');
        }

        $period = [
            'from'    => $this->date($header['E'] ?? null),
            'to'      => $this->date($header['F'] ?? null),
            'invoice' => (string) ($header['C'] ?? ''),
            'account' => (string) ($header['B'] ?? ''),
            'date'    => $this->date($header['D'] ?? null),
        ];

        DB::beginTransaction();

        try {
            $type   = $this->costType();
            $vendor = $this->vendor();

            $stats = [
                'numbers'          => 0,
                'matched'          => 0,
                'unmatched'        => 0,
                'account_items'    => 0,
                'cost_center_diff' => [],
                'unknown_centers'  => [],
            ];

            foreach ($lines as $row) {
                $this->importNumberRow($row, $type, $vendor, $period, $stats);
            }

            foreach ($items as $row) {
                $this->importAccountRow($row, $type, $vendor, $period, $stats);
            }

            $removedExcel = $this->removeSupersededExcelLines($type);
            $pruned       = $this->pruneStaleLines();

            $sum = round(array_sum(array_map(fn ($r) => $this->num($r['J'] ?? null), array_merge($lines, $items))), 2);

            $result = [
                'dry_run'            => $dryRun,
                'period'             => $period,
                'stats'              => $stats,
                'sum_net'            => $sum,
                'invoice_total_net'  => round($this->num($header['J'] ?? null), 2),
                // Der Export rechnet auf den Cent auf: Posten + Rufnummern = Kopfbetrag. Weicht das ab,
                // wurde beim Export gefiltert oder eine Zeilenart übersehen — dann sind die Zahlen
                // unvollständig, und das muss sichtbar sein statt im Import unterzugehen.
                'reconciles'         => abs($sum - round($this->num($header['J'] ?? null), 2)) < 0.01,
                'removed_excel_rows' => $removedExcel,
                'pruned_rows'        => $pruned,
            ];

            $dryRun ? DB::rollBack() : DB::commit();

            return $result;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    // ---- Zeilenarten -----------------------------------------------------------------------

    /** Eine Rufnummern-Zeile → Kostenposition, wenn möglich einem Asset-Träger zugeordnet. */
    protected function importNumberRow(array $row, AssetCostType $type, AssetVendor $vendor, array $period, array &$stats): void
    {
        $msisdn = PhoneNumber::e164((string) ($row['K'] ?? ''));
        if ($msisdn === null) {
            return;
        }

        $stats['numbers']++;

        $holder      = $this->findHolderByPhone($msisdn);
        $exportCode  = $this->costCenterCode($row['M'] ?? null);
        $exportCenter = $exportCode !== null ? $this->findCostCenterId($exportCode) : null;

        if ($exportCode !== null && $exportCenter === null && ! in_array($exportCode, $stats['unknown_centers'], true)) {
            // Kostenstelle aus dem Export, die es bei uns nicht gibt — melden statt anlegen. Ein
            // Import soll Kosten buchen, nicht heimlich den Stammdaten-Baum erweitern.
            $stats['unknown_centers'][] = $exportCode;
        }

        if ($holder) {
            $stats['matched']++;
            // Unsere Zuordnung gewinnt (Entscheidung 2026-08-10). Der Export-Wert bleibt als
            // Referenz in raw_data, Abweichungen werden gemeldet.
            $centerId = $holder->cost_center_id ?? $exportCenter;

            if ($exportCode !== null && $holder->cost_center_id !== null && $holder->cost_center_id !== $exportCenter) {
                $stats['cost_center_diff'][] = [
                    'msisdn'   => PhoneNumber::national($msisdn),
                    'holder'   => $holder->display_name ?: $holder->user_principal_name,
                    'vodafone' => $exportCode,
                    'ours'     => $holder->costCenter?->code,
                ];
            }
        } else {
            $stats['unmatched']++;
            $centerId = $exportCenter;
        }

        $this->upsertLine(
            key: $msisdn,
            type: $type,
            vendor: $vendor,
            amount: $this->num($row['J'] ?? null),
            label: 'Mobilfunk ' . PhoneNumber::national($msisdn),
            centerId: $centerId,
            holderId: $holder?->id,
            period: $period,
            raw: [
                'msisdn'              => $msisdn,
                'kostenstelle_export' => $row['M'] ?? null,
                'brutto'              => $this->num($row['G'] ?? null),
                'ust_satz'            => $row['H'] ?? null,
                'unmatched'           => $holder === null,
            ],
        );
    }

    /** Kontobezogener Posten (kein Anschluss) → Kostenposition ohne Träger, auf der Export-Kostenstelle. */
    protected function importAccountRow(array $row, AssetCostType $type, AssetVendor $vendor, array $period, array &$stats): void
    {
        $label = trim((string) ($row['L'] ?? $row['K'] ?? ''));
        if ($label === '') {
            return;
        }

        $stats['account_items']++;
        $code = $this->costCenterCode($row['M'] ?? null);

        $this->upsertLine(
            key: 'posten:' . $label,
            type: $type,
            vendor: $vendor,
            amount: $this->num($row['J'] ?? null),
            label: $label,
            centerId: $code !== null ? $this->findCostCenterId($code) : null,
            holderId: null,
            period: $period,
            raw: ['kostenstelle_export' => $row['M'] ?? null, 'account_item' => true],
        );
    }

    // ---- Schreiben -------------------------------------------------------------------------

    /**
     * Kostenposition anlegen oder aktualisieren. Der Hash trägt bewusst NUR die Identität
     * (Team/Tenant/Kostenart/Schlüssel) — der Betrag darf sich monatlich ändern, ohne dass die Zeile
     * ihre ID verliert.
     */
    protected function upsertLine(
        string $key,
        AssetCostType $type,
        AssetVendor $vendor,
        float $amount,
        string $label,
        ?int $centerId,
        ?int $holderId,
        array $period,
        array $raw,
    ): void {
        $hash = sha1(implode('|', [self::SOURCE, $this->teamId, $this->tenantId, self::TYPE_KEY, $key]));

        $values = [
            'cost_type_id'    => $type->id,
            'vendor_id'       => $vendor->id,
            'cost_center_id'  => $centerId,
            'assignee_id'     => $holderId,
            'label'           => $label,
            'amount'          => round($amount, 2),
            'currency'        => 'EUR',
            'frequency'       => 'monthly',
            'source'          => self::SOURCE,
            'active'          => true,
            'period_label'    => $period['from'] && $period['to']
                ? $period['from']->format('d.m.Y') . '–' . $period['to']->format('d.m.Y')
                : null,
            'valid_from'      => $period['from'],
            'valid_to'        => $period['to'],
            'import_batch_id' => $this->batchId,
            'raw_data'        => $raw + [
                'rechnungskonto' => $period['account'],
                'rechnung'       => $period['invoice'],
            ],
        ];

        $existing = AssetCostLine::withTrashed()
            ->where('team_id', $this->teamId)
            ->where('import_hash', $hash)
            ->first();

        if ($existing) {
            // Wie beim Excel-Import: eine im UI bewusst gelöschte Zeile wird NICHT wiederbelebt.
            if ($existing->trashed()) {
                return;
            }
            $existing->update($values);
        } else {
            AssetCostLine::create($values + [
                'team_id'     => $this->teamId,
                'tenant_id'   => $this->tenantId,
                'import_hash' => $hash,
            ]);
        }

        $this->writtenHashes[] = $hash;
    }

    /**
     * Mobilfunk-Zeilen aus dem Excel-Import entfernen — ab jetzt ist die Vodafone-Rechnung die
     * einzige Quelle für diese Kostenart (Entscheidung 2026-08-10).
     *
     * Ohne diesen Schritt lägen beide Quellen nebeneinander und Mobilfunk zählte doppelt: der Prune
     * des Excel-Importers fasst nur `source='excel_import'` an, der hiesige nur `vodafone_invoice`.
     */
    protected function removeSupersededExcelLines(AssetCostType $type): int
    {
        return AssetCostLine::withTrashed()
            ->where('team_id', $this->teamId)
            ->where('tenant_id', $this->tenantId)
            ->where('cost_type_id', $type->id)
            ->where('source', 'excel_import')
            ->forceDelete();
    }

    /** Zeilen aus früheren Vodafone-Läufen, die in dieser Rechnung nicht mehr vorkommen (gekündigte Nummern). */
    protected function pruneStaleLines(): int
    {
        if ($this->writtenHashes === []) {
            return 0; // leere/falsche Datei — nichts löschen (Guard wie beim Excel-Import)
        }

        return AssetCostLine::withTrashed()
            ->where('team_id', $this->teamId)
            ->where('tenant_id', $this->tenantId)
            ->where('source', self::SOURCE)
            ->whereNotIn('import_hash', array_values(array_unique($this->writtenHashes)))
            ->forceDelete();
    }

    // ---- Lookups ---------------------------------------------------------------------------

    /**
     * Asset-Träger über die normalisierte Rufnummer finden. Beide Seiten werden normalisiert, weil
     * Entra `+49 172 …` liefert und der Export `172…` — ein Vergleich der Rohwerte trifft nie.
     */
    protected function findHolderByPhone(string $e164): ?AssetHolder
    {
        return $this->holderPhoneIndex()[$e164] ?? null;
    }

    /** @return array<string, AssetHolder> normalisierte Nummer => Träger (memoisiert je Lauf) */
    protected function holderPhoneIndex(): array
    {
        static $index = null;
        static $forTeam = null;

        if ($index !== null && $forTeam === $this->teamId . ':' . $this->tenantId) {
            return $index;
        }

        $index = [];
        AssetHolder::where('team_id', $this->teamId)
            ->where(fn ($q) => $q->whereNotNull('mobile_phone')->orWhereNotNull('business_phone'))
            ->get()
            ->each(function (AssetHolder $holder) use (&$index) {
                foreach ([$holder->mobile_phone, $holder->business_phone] as $raw) {
                    $key = PhoneNumber::e164($raw);
                    // Erster Treffer gewinnt: die Mobilnummer steht vorn und ist bei Mobilfunk die
                    // richtige. Eine doppelt vergebene Nummer würde sonst je nach Reihenfolge kippen.
                    if ($key !== null && ! isset($index[$key])) {
                        $index[$key] = $holder;
                    }
                }
            });

        $forTeam = $this->teamId . ':' . $this->tenantId;

        return $index;
    }

    /** `2599 - HKS Team Düsseldorf` → `2599`. */
    protected function costCenterCode($raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        return trim(explode('-', $raw, 2)[0]) ?: null;
    }

    protected function findCostCenterId(string $code): ?int
    {
        return \Platform\AssetManager\Models\AssetCostCenter::where('team_id', $this->teamId)
            ->where('code', $code)
            ->value('id');
    }

    protected function costType(): AssetCostType
    {
        $type = AssetCostType::where('team_id', $this->teamId)->where('key', self::TYPE_KEY)->first();

        if (! $type) {
            throw new \RuntimeException('Kostenart „mobilfunk" fehlt in diesem Tenant — bitte zuerst die Stammdaten anlegen.');
        }

        if ($type->aggregation_source !== AssetCostType::SOURCE_COST_LINE) {
            // ADR 0001: eine Kostenart zieht ihren Pivot-Wert aus genau EINER Quelle. Stünde
            // mobilfunk auf einer virtuellen Quelle, würden unsere Zeilen nie ausgewertet.
            throw new \RuntimeException('Kostenart „mobilfunk" ist nicht auf cost_line gestellt — der Import würde nicht in die Kostenaufteilung einfließen.');
        }

        return $type;
    }

    protected function vendor(): AssetVendor
    {
        return AssetVendor::firstOrCreate(
            ['team_id' => $this->teamId, 'tenant_id' => $this->tenantId, 'name' => self::VENDOR],
        );
    }

    // ---- Parsen ----------------------------------------------------------------------------

    /** @return list<array<string, mixed>> Datenzeilen des Blatts „Rechnungsdaten" */
    protected function readRows(string $path): array
    {
        $sheets = $this->reader->read($path);
        $sheet  = $this->reader->findSheet($sheets, ['Rechnungsdaten', 'Rechnungsdaten Export']);

        if ($sheet === null) {
            throw new \RuntimeException('Blatt „Rechnungsdaten" nicht gefunden.');
        }

        // Kopfzeilen (Titel, Leerzeile, Spaltenüberschriften) haben in Spalte A keinen der drei Typen.
        return array_values(array_filter(
            $sheet,
            fn ($row) => in_array(trim((string) ($row['A'] ?? '')), ['Rechnung', 'Rufnummer', 'Rechnungsposten'], true),
        ));
    }

    /** @return list<array<string, mixed>> */
    protected function ofType(array $rows, string $type): array
    {
        return array_values(array_filter($rows, fn ($r) => trim((string) ($r['A'] ?? '')) === $type));
    }

    protected function firstOfType(array $rows, string $type): array
    {
        return $this->ofType($rows, $type)[0] ?? [];
    }

    protected function num($val): float
    {
        if ($val === null || $val === '') {
            return 0.0;
        }
        if (is_numeric($val)) {
            return (float) $val;
        }

        // Deutsches Zahlenformat als Text (1.234,56) — kommt vor, wenn der Export als Text gespeichert wurde.
        return (float) str_replace([' ', '.', ','], ['', '', '.'], (string) $val);
    }

    protected function date($val): ?Carbon
    {
        $val = trim((string) $val);
        if ($val === '') {
            return null;
        }

        // Excel-Seriennummer (Zahl) oder dd.mm.yyyy — der Export liefert das Datum als Text.
        if (is_numeric($val)) {
            return Carbon::create(1899, 12, 30)->addDays((int) $val);
        }

        try {
            return Carbon::createFromFormat('d.m.Y', $val)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
