<?php

namespace Platform\AssetManager\Services;

use Illuminate\Support\Facades\DB;
use Platform\AssetManager\Models\AssetCategory;
use Platform\AssetManager\Models\AssetCostLine;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Models\AssetItem;
use Platform\AssetManager\Models\AssetTeamSetting;
use Platform\AssetManager\Models\AssetTelephonySeat;
use Platform\AssetManager\Models\AssetVendor;
use Platform\AssetManager\Support\VodafoneFixedNetCsv;

/**
 * Import der Vodafone-„Rechnung CSV" eines Festnetzkontos → Kostenpositionen (Dev-Issue #931).
 *
 * Die Blöcke liefert {@see VodafoneFixedNetCsv}; hier werden sie nach den Regeln vom 14.10.2026
 * gebucht:
 *
 *   Internetleitung        → Kostenart `internet`, an der Leitung im Inventar (Kategorie internet,
 *                            `external_id` = Anschlusskennung), Kostenstelle der bisherigen Zeile
 *   Telefonie-Lizenzen     → je belegtem Platz mit Träger eine Zeile zum Netto-Stückpreis
 *                            (Lizenz − Lizenzrabatt) / Rechnungsmenge, auf die Kostenstelle des Trägers;
 *                            der Rest der Rechnungsmenge + Rundung als **eine** Pool-Zeile
 *   Anlagenanschluss       → Kostenart `telefonie`, Telefonie-Sammelstelle
 *   Verbindungen           → Kostenart `telefonie`, Telefonie-Sammelstelle (monatlich variabel)
 *
 * Wer einen Platz belegt, kommt aus der Benutzerliste des Anbieters ({@see TelephonySeatImportService}),
 * nicht aus der Rechnung — die nennt nur die Menge.
 *
 * Übernommene Festlegungen des Mobilfunk-Imports (ADR 0018, Bug #930):
 *  - `import_hash` trägt nur die **Identität** (Konto + Schlüssel), nie den Betrag — die Zeile behält
 *    über die Monate ihre ID.
 *  - **Keine Gültigkeit** aus dem Rechnungszeitraum; der Zeitraum steht in `period_label`.
 *  - Ein Lauf nimmt einer bestehenden Zeile nie die Kostenstelle weg.
 *  - Abgelöste `excel_import`-Zeilen werden beim Lauf entfernt (Quelle je Kostenart, ADR 0001).
 *  - Prune nur **innerhalb desselben Rechnungskontos** — die Konten kommen als getrennte Dateien.
 *
 * Eine Rechnung des Lizenzkontos („Software as a Service") wird abgewiesen: dieselben Kosten kommen
 * bereits über die Lizenzabrechnung (ADR 0019), ein zweiter Import wäre eine Doppelzählung.
 */
class VodafoneFixedNetImportService
{
    public const SOURCE = 'vodafone_fixednet';

    /** Kostenart der Lizenzen, falls vorhanden; sonst landen sie auf `telefonie`. */
    public const LICENSE_TYPE_KEY = 'vf_lizenz_rc';

    /** Excel-Kostenarten, die dieser Import ablöst, sobald eine Rechnung mit Telefonie kommt. */
    public const SUPERSEDED_TELEPHONY_TYPES = ['vf_lizenz_rc', 'vf_lizenz_rc_rab'];

    protected int $teamId = 0;
    protected int $tenantId = 0;

    /** @var list<string> */
    protected array $writtenHashes = [];

    /** @var list<string> */
    protected array $findings = [];

    public function __construct(protected VodafoneFixedNetCsv $parser = new VodafoneFixedNetCsv()) {}

    /**
     * @param  array<string, int>  $lineMapping  Anschlusskennung => AssetItem-ID für Leitungen, die
     *                                           im Inventar noch keine Kennung tragen (erster Lauf)
     * @return array<string, mixed>
     */
    public function import(int $teamId, string $path, string $batchId = 'fixednet-upload', bool $dryRun = true, ?int $tenantId = null, array $lineMapping = []): array
    {
        $this->teamId        = $teamId;
        $this->tenantId      = $tenantId ?? TenantContext::defaultTenantId($teamId);
        $this->writtenHashes = [];
        $this->findings      = [];

        TenantContext::forceTenant($this->tenantId);

        $bill = $this->parser->parse($path);

        if ($bill['is_saas']) {
            throw new \RuntimeException('Das ist die Lizenzrechnung (Software as a Service, Microsoft 365). Die kommt über „Lizenz-Abrechnung" mit den Abrechnungsdetails — ein zweiter Import würde die Lizenzkosten doppelt zählen.');
        }

        $period = [
            'label'   => $bill['period']['from'] && $bill['period']['to']
                ? $bill['period']['from']->format('d.m.Y') . '–' . $bill['period']['to']->format('d.m.Y')
                : $bill['period']['label'],
            'account' => $bill['account'],
            'invoice' => $bill['invoice'],
            'batch'   => $batchId,
        ];

        $centerId = AssetTeamSetting::where('team_id', $teamId)->value('telephony_cost_center_id');

        DB::beginTransaction();

        try {
            $result = [
                'dry_run'      => $dryRun,
                'account'      => $bill['account'],
                'invoice'      => $bill['invoice'],
                'period'       => $period['label'],
                'month'        => $bill['period']['label'],
                'total_net'    => $bill['total_net'],
                'sum_net'      => $bill['sum_net'],
                'reconciles'   => $bill['reconciles'],
                'difference'   => $bill['difference'],
                'internet'     => [],
                'telephony'    => null,
                'unassigned'   => $bill['unassigned'],
                'unknown_lines'=> [],
                'removed_excel'=> 0,
                'pruned_rows'  => 0,
            ];

            foreach ($bill['internet'] as $kennung => $line) {
                $result['internet'][] = $this->bookInternet((string) $kennung, $line, $period, $lineMapping, $result);
            }

            if ($bill['telephony'] !== null) {
                $result['telephony'] = $this->bookTelephony($bill['telephony'], $period, $centerId);
                $result['removed_excel'] += $this->removeSupersededTelephony();
            }

            if ($bill['unassigned'] !== []) {
                $sum = round(array_sum(array_column($bill['unassigned'], 'amount')), 2);
                $this->line('unassigned', $this->typeFor($bill['telephony'] !== null ? 'telefonie' : 'internet'),
                    'Nicht zugeordnete Rechnungsposten', $sum, $centerId, null, null, $period, ['positions' => $bill['unassigned']]);
                $this->findings[] = count($bill['unassigned']) . ' Rechnungsposition(en) ohne Regel (' . number_format($sum, 2, ',', '.') . ' €) — als Sammelzeile gebucht, bitte prüfen.';
            }

            $result['pruned_rows'] = $this->prune($bill['account']);
            $result['findings']    = $this->findings;

            $dryRun ? DB::rollBack() : DB::commit();

            return $result;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    // ---- Internet ---------------------------------------------------------------------------

    /** @param array{product: string, amount: float, items: list<array{label: string, amount: float}>} $line */
    protected function bookInternet(string $kennung, array $line, array $period, array $lineMapping, array &$result): array
    {
        $category = AssetCategory::firstOrCreate(
            ['key' => 'internet'],
            ['name' => 'Internet', 'icon' => 'heroicon-o-wifi', 'is_synced' => false, 'sort_order' => 200],
        );

        $item = AssetItem::where('team_id', $this->teamId)
            ->where('category_id', $category->id)
            ->where('external_id', $kennung)
            ->first();

        $created = false;

        if (! $item && isset($lineMapping[$kennung])) {
            // Erste Zuordnung: die Leitung existiert schon im Inventar (aus der Excel), nur ohne
            // Kennung. Ab jetzt findet der Import sie über die Kennung.
            $item = AssetItem::where('team_id', $this->teamId)
                ->where('category_id', $category->id)
                ->find($lineMapping[$kennung]);
            $item?->update(['external_id' => $kennung]);
        }

        if (! $item) {
            $item = AssetItem::create([
                'team_id'     => $this->teamId,
                'tenant_id'   => $this->tenantId,
                'category_id' => $category->id,
                'source'      => 'manual',
                'external_id' => $kennung,
                'name'        => $kennung,
                'status'      => 'in_stock',
                'raw_data'    => ['anbieter' => 'Vodafone', 'produkt' => $line['product']],
            ]);
            $created = true;
            $result['unknown_lines'][] = $kennung;
            $this->findings[] = "Leitung „{$kennung}“ war im Inventar unbekannt und wurde angelegt — ohne Zuordnung zu einer bestehenden Leitung zählt deren Excel-Zeile doppelt.";
        }

        // Kostenstelle: die der bisherigen Zeile dieser Leitung (Excel oder früherer Lauf).
        $centerId = AssetCostLine::where('team_id', $this->teamId)
            ->where('asset_item_id', $item->id)
            ->whereNotNull('cost_center_id')
            ->orderByDesc('updated_at')
            ->value('cost_center_id');

        if ($centerId === null) {
            $this->findings[] = "Leitung „{$item->name}“ hat keine Kostenstelle — bitte an der Kostenposition setzen.";
        }

        $amount = round($line['amount'], 2);
        $this->line('internet:' . $kennung, $this->typeFor('internet'), $item->name, $amount, $centerId, null, $item->id, $period,
            ['anschlusskennung' => $kennung, 'positions' => $line['items']]);

        $removed = AssetCostLine::withTrashed()
            ->where('team_id', $this->teamId)
            ->where('asset_item_id', $item->id)
            ->where('source', 'excel_import')
            ->forceDelete();
        $result['removed_excel'] += $removed;

        return ['kennung' => $kennung, 'item' => $item->name, 'amount' => $amount, 'created' => $created, 'cost_center_id' => $centerId];
    }

    // ---- Telefonie --------------------------------------------------------------------------

    /** @param array{licenses: array, fixed: array, usage: array} $tel */
    protected function bookTelephony(array $tel, array $period, ?int $centerId): array
    {
        if ($centerId === null) {
            $this->findings[] = 'Keine Telefonie-Sammelstelle gesetzt (Moduleinstellungen) — Anlage, Verbindungen und Pool stehen ohne Kostenstelle.';
        }

        $telefonie   = $this->typeFor('telefonie');
        $licenseType = AssetCostType::where('team_id', $this->teamId)->where('key', self::LICENSE_TYPE_KEY)->first() ?? $telefonie;
        $this->assertCostLine($licenseType);

        $lic       = $tel['licenses'];
        $invoiceQty = (int) $lic['seats'];
        $net       = round($lic['net'], 2);
        $unit      = $invoiceQty > 0 ? round($lic['net'] / $invoiceQty, 2) : 0.0;

        $seats = AssetTelephonySeat::where('team_id', $this->teamId)
            ->where('provider', AssetTelephonySeat::PROVIDER_RINGCENTRAL)
            ->get();

        if ($seats->isEmpty()) {
            $this->findings[] = 'Keine RingCentral-Benutzerliste hochgeladen — alle Lizenzen stehen im Pool auf der Sammelstelle.';
        }

        $holders = AssetHolder::whereIn('id', $seats->pluck('holder_id')->filter()->unique()->all())
            ->get(['id', 'display_name', 'cost_center_id'])
            ->keyBy('id');

        $perHolder = $seats->whereNotNull('holder_id')->groupBy('holder_id');
        $personLines = 0;
        $personSum   = 0.0;
        $withoutCenter = [];

        foreach ($perHolder as $holderId => $holderSeats) {
            $holder = $holders->get($holderId);
            if (! $holder) {
                continue; // Träger zwischenzeitlich gelöscht → Platz zählt in den Pool
            }

            $count  = $holderSeats->count();
            $amount = round($unit * $count, 2);
            $center = $holder->cost_center_id ?? $centerId;

            if ($holder->cost_center_id === null) {
                $withoutCenter[] = $holder->display_name;
            }

            $this->line('seat:' . $holderId, $licenseType, 'RingCentral-Lizenz', $amount, $center, $holderId, null, $period,
                ['seats' => $count, 'unit_net' => $unit, 'status' => $holderSeats->pluck('status')->unique()->values()->all()]);

            $personLines += $count;
            $personSum   += $amount;
        }

        $poolQty    = $invoiceQty - $personLines;
        $poolAmount = round($net - $personSum, 2);

        if ($poolQty < 0) {
            $this->findings[] = "Mehr Plätze mit Träger ({$personLines}) als Lizenzen auf der Rechnung ({$invoiceQty}) — Benutzerliste prüfen (älter als die Rechnung?).";
        }
        if ($withoutCenter !== []) {
            $this->findings[] = count($withoutCenter) . ' Platz-Inhaber ohne Kostenstelle, auf die Sammelstelle gebucht: ' . implode(', ', $withoutCenter) . '.';
        }

        $notActive = $seats->reject(fn (AssetTelephonySeat $s) => $s->isActive());
        if ($notActive->isNotEmpty()) {
            $this->findings[] = $notActive->count() . ' Plätze sind beim Anbieter nicht aktiviert oder deaktiviert, werden aber bezahlt — Einsparpotenzial ca. '
                . number_format($notActive->count() * $unit, 2, ',', '.') . ' €/Monat.';
        }

        // Pool = Rest der Rechnungsmenge + Rundungsdifferenz der Personenzeilen. Damit ergibt die Summe
        // der Lizenzzeilen auf den Cent den Rechnungsblock, egal wie viele Personen es sind.
        $this->line('pool', $telefonie, 'RingCentral-Lizenzen ohne Person (' . max(0, $poolQty) . ')', $poolAmount, $centerId, null, null, $period,
            ['seats' => $poolQty, 'unit_net' => $unit, 'seats_without_holder' => $seats->whereNull('holder_id')->count()]);

        $fixed = $tel['fixed'];
        $this->line('fixed', $telefonie, 'RingCentral Anlagenanschluss (' . $fixed['channels'] . ' Kanäle)', round($fixed['net'], 2), $centerId, null, null, $period,
            ['gross' => round($fixed['gross'], 2), 'discount' => round($fixed['discount'], 2)]);

        $usage = $tel['usage'];
        $this->line('usage', $telefonie, 'RingCentral Verbindungen', round($usage['net'], 2), $centerId, null, null, $period,
            ['gross' => round($usage['gross'], 2), 'discount' => round($usage['discount'], 2)]);

        return [
            'invoice_seats'  => $invoiceQty,
            'list_seats'     => $seats->count(),
            'person_seats'   => $personLines,
            'pool_seats'     => $poolQty,
            'unit_net'       => $unit,
            'licenses_net'   => $net,
            'persons_amount' => round($personSum, 2),
            'pool_amount'    => $poolAmount,
            'fixed_net'      => round($fixed['net'], 2),
            'channels'       => $fixed['channels'],
            'usage_net'      => round($usage['net'], 2),
            'not_active'     => $notActive->count(),
            'without_center' => $withoutCenter,
        ];
    }

    /** Excel-Zeilen der Telefonie-Lizenzen entfernen — ab jetzt ist die Rechnung die Quelle. */
    protected function removeSupersededTelephony(): int
    {
        $typeIds = AssetCostType::where('team_id', $this->teamId)
            ->whereIn('key', self::SUPERSEDED_TELEPHONY_TYPES)
            ->pluck('id');

        return AssetCostLine::withTrashed()
            ->where('team_id', $this->teamId)
            ->whereIn('cost_type_id', $typeIds)
            ->where('source', 'excel_import')
            ->forceDelete();
    }

    // ---- Schreiben --------------------------------------------------------------------------

    protected function line(string $key, AssetCostType $type, string $label, float $amount, ?int $centerId, ?int $holderId, ?int $itemId, array $period, array $raw): void
    {
        $hash = sha1(implode('|', [self::SOURCE, $this->teamId, $this->tenantId, $period['account'], $key]));

        $values = [
            'cost_type_id'    => $type->id,
            'vendor_id'       => $type->vendor_default_id ?? $this->vendorId(),
            'cost_center_id'  => $centerId,
            'assignee_id'     => $holderId,
            'asset_item_id'   => $itemId,
            'label'           => $label,
            'amount'          => round($amount, 2),
            'currency'        => 'EUR',
            'frequency'       => 'monthly',
            'source'          => self::SOURCE,
            'active'          => true,
            'period_label'    => $period['label'],
            // Bug #930: keine Gültigkeit aus dem Rechnungszeitraum — sonst fällt die Zeile nach dessen
            // Ende aus allen Auswertungen. Aktuell hält sie der nächste Import bzw. der Prune.
            'valid_from'      => null,
            'valid_to'        => null,
            'import_batch_id' => $period['batch'],
            'raw_data'        => $raw + ['rechnungskonto' => $period['account'], 'rechnung' => $period['invoice']],
        ];

        $existing = AssetCostLine::withTrashed()
            ->where('team_id', $this->teamId)
            ->where('import_hash', $hash)
            ->first();

        if ($existing) {
            if ($existing->trashed()) {
                $this->writtenHashes[] = $hash; // bewusst gelöscht: nicht wiederbeleben, aber auch nicht prunen
                return;
            }
            if ($values['cost_center_id'] === null && $existing->cost_center_id !== null) {
                $values['cost_center_id'] = $existing->cost_center_id;
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

    /** Zeilen früherer Läufe DESSELBEN Kontos, die diesmal nicht geschrieben wurden. */
    protected function prune(string $account): int
    {
        if ($this->writtenHashes === []) {
            return 0;
        }

        return AssetCostLine::withTrashed()
            ->where('team_id', $this->teamId)
            ->where('source', self::SOURCE)
            ->where('raw_data->rechnungskonto', $account)
            ->whereNotIn('import_hash', array_values(array_unique($this->writtenHashes)))
            ->forceDelete();
    }

    // ---- Stammdaten -------------------------------------------------------------------------

    protected function typeFor(string $key): AssetCostType
    {
        $type = AssetCostType::where('team_id', $this->teamId)->where('key', $key)->first();

        if (! $type) {
            throw new \RuntimeException("Kostenart „{$key}“ fehlt in diesem Tenant — bitte zuerst in den Stammdaten anlegen.");
        }

        $this->assertCostLine($type);

        return $type;
    }

    protected function assertCostLine(AssetCostType $type): void
    {
        if ($type->aggregation_source !== AssetCostType::SOURCE_COST_LINE) {
            // ADR 0001: eine Kostenart zieht ihren Pivot-Wert aus genau EINER Quelle.
            throw new \RuntimeException("Kostenart „{$type->key}“ ist nicht auf cost_line gestellt — der Import würde nicht in die Kostenaufteilung einfließen.");
        }
    }

    protected function vendorId(): int
    {
        return AssetVendor::firstOrCreate(
            ['team_id' => $this->teamId, 'tenant_id' => $this->tenantId, 'name' => 'Vodafone'],
        )->id;
    }
}
