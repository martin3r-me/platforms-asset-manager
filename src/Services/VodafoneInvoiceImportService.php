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
 * Gleichwertig angenommen wird die **„Rechnungsdaten CSV"** derselben Rechnung (Detailseite im Portal):
 * identische Spalten ohne Titelzeilen, Windows-1252, Semikolon. Im CSV ist Spalte M (Kostenstelle)
 * leer — deshalb nimmt ein Lauf einer bestehenden Zeile ihre Kostenstelle nie weg (siehe upsertLine).
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

    /** @var array<string, AssetCostLine> in diesem Lauf geschriebene Zeilen je Schlüssel — Basis des Rundungsausgleichs. */
    protected array $written = [];

    /** @var ?array<string, AssetHolder> Rufnummern-Index der Träger, je Lauf neu aufgebaut. */
    protected ?array $holderIndex = null;

    /** @var array<string, int> wie oft eine Posten-Bezeichnung in diesem Lauf schon vorkam (#967). */
    protected array $postenSeen = [];

    /** Rechnungskonto dieses Laufs — Teil der Zeilen-Identität und Grenze des Prune (#942). */
    protected string $account = '';

    public function __construct(
        protected XlsxReader $reader = new XlsxReader(),
        protected InvoiceSnapshotService $snapshots = new InvoiceSnapshotService(),
        protected CostLineHistoryService $history = new CostLineHistoryService(),
    ) {}

    /**
     * Rechnungsdatei einlesen und die Mobilfunk-Kostenpositionen des Tenants darauf ausrichten.
     *
     * `$historyOnly` (ADR 0029): nur die Preis-Historie nachtragen — der Lauf rechnet wie ein Import,
     * wird zurückgerollt, und nur die Verlaufszeilen bleiben. Kosten, Monatsstand und die Sperre gegen
     * ältere Rechnungen (#948) sind davon nicht betroffen, deshalb darf die Rechnung auch älter sein.
     *
     * @return array<string, mixed> Statistik + Prüfsummen + Auffälligkeiten
     */
    public function import(int $teamId, string $path, string $batchId = 'vodafone-upload', bool $dryRun = true, ?int $tenantId = null, bool $historyOnly = false): array
    {
        $this->teamId   = $teamId;
        $this->tenantId = $tenantId ?? TenantContext::defaultTenantId($teamId);
        $this->batchId  = $batchId;
        $this->dryRun   = $dryRun;
        $this->writtenHashes = [];
        $this->written       = [];
        $this->holderIndex   = null;
        $this->postenSeen    = [];
        $this->account       = '';

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
        $this->account = $period['account'];

        if (! $historyOnly) {
            $this->snapshots->assertNotOlderThanLatest($this->tenantId, self::SOURCE, $period['account'], $period['invoice'], $period['to']);
        }

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

            [$rounding, $unbalanced] = $this->balanceRounding(round($this->num($header['J'] ?? null), 2));

            // Preis-Historie (ADR 0029): je Kostenposition der Betrag dieser Rechnung, nach dem Rundungsausgleich.
            $historyRows = $this->history->rows($this->written, [
                'source'        => self::SOURCE,
                'account'       => $period['account'],
                'invoice'       => $period['invoice'],
                'from'          => $period['from'],
                'to'            => $period['to'],
                'booking_month' => $period['to'],
            ]);

            if ($historyOnly) {
                DB::rollBack();
                $stored = $this->history->store($this->teamId, $this->tenantId, $historyRows, resolveLineIds: true);

                return [
                    'dry_run'           => false,
                    'history_only'      => true,
                    'history_rows'      => $stored,
                    'period'            => $period,
                    'stats'             => $stats,
                    'sum_net'           => $sum,
                    'invoice_total_net' => round($this->num($header['J'] ?? null), 2),
                    'reconciles'        => abs($sum - round($this->num($header['J'] ?? null), 2)) < 0.01,
                ];
            }

            if (! $dryRun) {
                $this->history->store($this->teamId, $this->tenantId, $historyRows);
            }

            // Monatsstand für die DATEV-Umbuchung — in der Transaktion, ein Probelauf hinterlässt nichts.
            $snapshot = $this->snapshots->capture($this->teamId, $this->tenantId, [
                'source'       => self::SOURCE,
                'account'      => $period['account'],
                'invoice'      => $period['invoice'],
                'invoice_date' => $period['date'],
                // Leistungsmonat = Monat, in dem der Abrechnungszeitraum endet (15.08.–14.09. → September).
                'booking_month' => $period['to'],
                'period_label' => $period['from'] && $period['to'] ? $period['from']->format('d.m.Y') . '–' . $period['to']->format('d.m.Y') : null,
                'total_net'    => round($this->num($header['J'] ?? null), 2),
            ], $this->snapshots->rowsFromCostLines($this->teamId, $this->tenantId, self::SOURCE, $period['account'], $period['invoice']));

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
                'rounding'           => $rounding,
                'rounding_unbalanced'=> $unbalanced,
                'snapshot'           => InvoiceSnapshotService::summary($snapshot),
                'history_rows'       => count($historyRows),
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

        // Gleich benannte Posten (z. B. zweimal „Rabatt" auf verschiedenen Kostenstellen) sind
        // verschiedene Zeilen. Der erste behält den bisherigen Schlüssel, weitere werden durchnummeriert —
        // vorher überschrieb der zweite den ersten, und ein Betrag ging verloren (#967).
        $n   = $this->postenSeen[$label] = ($this->postenSeen[$label] ?? 0) + 1;
        $key = 'posten:' . $label . ($n > 1 ? '#' . $n : '');

        $this->upsertLine(
            key: $key,
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
        // Identität mit Rechnungskonto (#942): ohne das teilten sich zwei Mobilfunk-Konten eines Tenants
        // die Zeilen gleicher Posten, und der Prune des einen löschte die des anderen.
        $hash   = sha1(implode('|', [self::SOURCE, $this->teamId, $this->tenantId, self::TYPE_KEY, $this->account, $key]));
        // Zeilen aus Läufen vor #942 tragen den Hash ohne Konto. Sie werden übernommen (Hash umgestellt),
        // damit ihre ID, eine im UI gepflegte Kostenstelle und ein bewusstes Löschen erhalten bleiben.
        $legacy = sha1(implode('|', [self::SOURCE, $this->teamId, $this->tenantId, self::TYPE_KEY, $key]));

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
            // Bewusst KEINE Gültigkeit aus dem Rechnungszeitraum (Bug #930): die Auswertungen zählen
            // nur `validOn(now())`, und der Zeitraum einer Rechnung liegt immer in der Vergangenheit —
            // nach seinem Ende fiel der ganze Mobilfunk still aus Pivot und Dashboard. Aktuell hält
            // die Zeilen stattdessen der Import selbst: er aktualisiert die Beträge und räumt
            // abgängige Nummern per Prune ab. Explizit NULL, damit ein Lauf alte Werte zurücksetzt.
            'valid_from'      => null,
            'valid_to'        => null,
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

        if ($existing === null) {
            $existing = AssetCostLine::withTrashed()
                ->where('team_id', $this->teamId)
                ->where('import_hash', $legacy)
                ->first();

            // Nur übernehmen, wenn die Altzeile zu DIESEM Konto gehört (oder noch keins trägt).
            $legacyAccount = (string) ($existing?->raw_data['rechnungskonto'] ?? '');
            if ($existing !== null && $legacyAccount !== '' && $legacyAccount !== $this->account) {
                $existing = null;
            }
            // Im Probelauf nimmt der Rollback die Umstellung wieder mit.
            $existing?->forceFill(['import_hash' => $hash])->saveQuietly();
        }

        if ($existing) {
            // Wie beim Excel-Import: eine im UI bewusst gelöschte Zeile wird NICHT wiederbelebt.
            if ($existing->trashed()) {
                return;
            }
            // Ein Import nimmt nie eine Kostenstelle weg. Ohne Träger kommt sie nur aus der Export-
            // Spalte M — und die ist im CSV-Export leer. Sonst verlören Nummern ohne Träger (und die
            // kontobezogenen Posten) bei jedem Lauf ihre einmal gesetzte oder im UI gepflegte Stelle.
            if ($values['cost_center_id'] === null && $existing->cost_center_id !== null) {
                $values['cost_center_id'] = $existing->cost_center_id;
            }
            $existing->update($values);
            $this->written[$key] = $existing;
        } else {
            $this->written[$key] = AssetCostLine::create($values + [
                'team_id'     => $this->teamId,
                'tenant_id'   => $this->tenantId,
                'import_hash' => $hash,
            ]);
        }

        $this->writtenHashes[] = $hash;
    }

    /**
     * Rundungsausgleich auf das Rechnungsnetto (wie {@see VodafoneFixedNetImportService::balanceRounding()}).
     *
     * Der Export liefert das Netto je Zeile mit vier Nachkommastellen, gebucht wird je Zeile auf den Cent.
     * Dadurch weicht die Summe der Zeilen um einzelne Cent vom Rechnungskopf ab (September 2026:
     * 4.008,50 statt 4.008,52) — und die DATEV-Umbuchung muss exakt den Rechnungsbetrag verteilen. Der
     * Rest geht auf den ersten Kontoposten (keine Person), sonst auf die erste Zeile. Nur echte Rundung
     * wird ausgeglichen: höchstens ½ Cent je Zeile. Alles darüber ist ein Befund (`rounding_unbalanced`).
     *
     * @return array{0: float, 1: ?float} Ausgleich, nicht ausgeglichene Abweichung
     */
    protected function balanceRounding(float $totalNet): array
    {
        if ($this->written === []) {
            return [0.0, null];
        }

        $booked   = array_sum(array_map(fn (AssetCostLine $l) => (float) $l->amount, $this->written));
        $residual = round($totalNet - $booked, 2);

        if ($residual == 0.0) {
            return [0.0, null];
        }

        if (abs((int) round($residual * 100)) > (int) ceil(count($this->written) / 2)) {
            return [0.0, $residual];
        }

        $key = array_key_first(array_filter($this->written, fn ($_, string $k) => str_starts_with($k, 'posten:'), ARRAY_FILTER_USE_BOTH))
            ?? array_key_first($this->written);
        $target = $this->written[$key];
        $target->update(['amount' => round((float) $target->amount + $residual, 2)]);

        return [$residual, null];
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

        // Nur Zeilen DIESES Rechnungskontos (#942) — ein zweites Mobilfunk-Konto desselben Tenants
        // bleibt unberührt. Altzeilen ohne Konto im raw_data zählen zu diesem Lauf.
        return AssetCostLine::withTrashed()
            ->where('team_id', $this->teamId)
            ->where('tenant_id', $this->tenantId)
            ->where('source', self::SOURCE)
            ->where(fn ($q) => $q->where('raw_data->rechnungskonto', $this->account)
                ->orWhereNull('raw_data->rechnungskonto'))
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

    /**
     * @return array<string, AssetHolder> normalisierte Nummer => Träger (memoisiert je Lauf)
     *
     * Bewusst eine Eigenschaft, kein `static`: ein statischer Index überlebte den Lauf und lieferte im
     * selben Prozess (Queue-Worker, Tests) beim nächsten Import Träger mit veralteter Kostenstelle.
     */
    protected function holderPhoneIndex(): array
    {
        if ($this->holderIndex !== null) {
            return $this->holderIndex;
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

        return $this->holderIndex = $index;
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

    /** @return list<array<string, mixed>> Datenzeilen des Exports „Rechnungsdaten" (xlsx oder CSV) */
    protected function readRows(string $path): array
    {
        $sheet = $this->isZip($path) ? $this->readXlsxSheet($path) : $this->readCsvSheet($path);

        // Kopfzeilen (Titel, Leerzeile, Spaltenüberschriften) haben in Spalte A keinen der drei Typen.
        return array_values(array_filter(
            $sheet,
            fn ($row) => in_array(trim((string) ($row['A'] ?? '')), ['Rechnung', 'Rufnummer', 'Rechnungsposten'], true),
        ));
    }

    /**
     * Das Format am Inhalt erkennen, nicht an der Endung: der Upload liegt als Temp-Datei vor, deren
     * Name die Endung nicht verlässlich trägt. Eine .xlsx ist ein ZIP-Archiv und beginnt mit „PK".
     */
    protected function isZip(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Datei konnte nicht gelesen werden.');
        }
        $magic = (string) fread($handle, 2);
        fclose($handle);

        return $magic === 'PK';
    }

    /** @return list<array<string, mixed>> */
    protected function readXlsxSheet(string $path): array
    {
        $sheets = $this->reader->read($path);
        $sheet  = $this->reader->findSheet($sheets, ['Rechnungsdaten', 'Rechnungsdaten Export']);

        if ($sheet === null) {
            throw new \RuntimeException('Blatt „Rechnungsdaten" nicht gefunden.');
        }

        return $sheet;
    }

    /**
     * Portal-Export „Rechnungsdaten CSV": dieselben 13 Spalten wie das Blatt der .xlsx (verifiziert an
     * 14 Rechnungen 08/2025–09/2026), Semikolon, deutsches Zahlenformat, **Windows-1252** — die Zeilen
     * werden auf Spaltenbuchstaben A…M abgebildet, damit der Rest des Imports nichts vom Format weiß.
     *
     * @return list<array<string, string>>
     */
    protected function readCsvSheet(string $path): array
    {
        $raw = (string) file_get_contents($path);
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        if (! mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }

        $rows = [];
        foreach (preg_split('/\r\n|\n|\r/', $raw) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $row = [];
            foreach (str_getcsv($line, ';', '"', '') as $i => $value) {
                $row[$this->columnLetter($i)] = $value;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** 0 → A, 25 → Z, 26 → AA. */
    protected function columnLetter(int $index): string
    {
        $letter = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letter = chr(65 + (($n - 1) % 26)) . $letter;
        }

        return $letter;
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
