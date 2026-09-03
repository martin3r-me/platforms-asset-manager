<?php

namespace Platform\AssetManager\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Platform\AssetManager\Models\AssetBillingProfile;
use Platform\AssetManager\Models\AssetBillingRun;
use RuntimeException;
use Throwable;

/**
 * Der Rechnungslauf: aus einem Abrechnungsprofil und einem Monat wird ein Beleg-Entwurf.
 *
 * Zwei Wege, absichtlich getrennt:
 *
 * - {@see preview()} rechnet alles durch und baut den vollständigen Beleg, **ohne** ihn zu senden.
 *   Das ist nicht nur eine Bequemlichkeit: es ist der einzige Weg, die Zählregel und den Preis zu
 *   prüfen, ohne dass ein Beleg in einem fremden System entsteht. Auf Umgebungen ohne
 *   easybill-Verbindung ist es der einzige Weg überhaupt.
 * - {@see createDraft()} tut dasselbe und übergibt das Ergebnis ans Rechnungssystem.
 *
 * Was hier **nicht** passiert: festschreiben und versenden. Siehe {@see EasybillBillingGateway}.
 */
class BillingRunService
{
    /** Monatsnamen ausgeschrieben — nicht über die Carbon-Lokalisierung, die nicht garantiert gesetzt ist. */
    protected const MONTHS = [
        1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April', 5 => 'Mai', 6 => 'Juni',
        7 => 'Juli', 8 => 'August', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember',
    ];

    public function __construct(
        protected BillableHolderResolver $holders,
        protected BillingUnitPriceResolver $prices,
        protected BillingGateway $gateway,
    ) {}

    /**
     * Rechnet den Lauf durch, ohne etwas zu senden oder zu speichern.
     *
     * @return array{
     *     quantity: int, unit_price_cents: int|null, total_net_cents: int, price_source: string|null,
     *     principals: string[], skipped: array<int, array{upn: string, reason: string}>,
     *     document: array<string, mixed>|null, problems: string[], period_label: string
     * }
     */
    public function preview(AssetBillingProfile $profile, string $period): array
    {
        $period = $this->normalizePeriod($period);

        $counted = $this->holders->resolve($profile);
        $price   = $this->prices->resolve($profile);

        $quantity = count($counted['principals']);
        $cents    = $price['cents'];

        $problems = [];

        if ($cents === null) {
            $problems[] = $price['note'] ?? 'Kein Stückpreis ermittelbar.';
        }

        if ($quantity === 0) {
            $problems[] = 'Die Zählregel trifft auf keinen einzigen Asset-Träger zu — es gibt nichts abzurechnen.';
        }

        if (! $profile->easybill_customer_id) {
            $problems[] = 'Dem Profil ist kein easybill-Kunde zugeordnet.';
        }

        // Ein bereits gesendeter Beleg für denselben Monat ist der wahrscheinlichste teure Fehler:
        // niemand sieht der Oberfläche an, dass letzte Woche schon jemand auf den Knopf gedrückt hat.
        if ($existing = $this->existingDraft($profile, $period)) {
            $problems[] = 'Für ' . $this->periodLabel($period) . ' wurde bereits ein Beleg erzeugt (easybill #'
                . $existing->easybill_document_id . ', angelegt am ' . $existing->created_at->format('d.m.Y') . ').';
        }

        $total = $cents === null ? 0 : $cents * $quantity;

        return [
            'quantity'         => $quantity,
            'unit_price_cents' => $cents,
            'total_net_cents'  => $total,
            'price_source'     => $price['source'],
            'price_label'      => $price['label'],
            'price_note'       => $price['note'],
            'principals'       => $counted['principals'],
            // Dieselbe Menge wie `principals`, nur mit Name und Abteilung — für die Liste hinter der
            // Kachel. Eine Aufstellung aus 135 nackten UPNs liest niemand gegen.
            'billable'         => $counted['rows'],
            'skipped'          => $counted['skipped'],
            'period_label'     => $this->periodLabel($period),
            'problems'         => $problems,
            'document'         => $cents === null || $quantity === 0
                ? null
                : $this->buildDocument($profile, $period, $quantity, $cents, $price),
        ];
    }

    /**
     * Führt den Lauf aus und legt den Entwurf im Rechnungssystem an.
     *
     * Der Lauf wird auch dann gespeichert, wenn das Senden scheitert — ein fehlgeschlagener Versuch
     * ist eine Information, kein Nichtereignis. Ohne die Zeile stünde man vor der Frage, ob der
     * Beleg nun draußen ist oder nicht.
     */
    public function createDraft(AssetBillingProfile $profile, string $period): AssetBillingRun
    {
        $period  = $this->normalizePeriod($period);
        $preview = $this->preview($profile, $period);

        if ($preview['problems'] !== []) {
            throw new RuntimeException(implode(' ', $preview['problems']));
        }

        $base = [
            'team_id'            => $profile->team_id,
            'tenant_id'          => $profile->tenant_id,
            'billing_profile_id' => $profile->id,
            'period'             => $period,
            'quantity'           => $preview['quantity'],
            'unit_price_cents'   => (int) $preview['unit_price_cents'],
            'total_net_cents'    => $preview['total_net_cents'],
            'currency'           => $profile->currency ?: 'EUR',
            'price_source'       => $preview['price_source'],
            'skipped_count'      => count($preview['skipped']),
            'user_id'            => Auth::id(),
            'snapshot'           => [
                'principals' => $preview['principals'],
                // Namen und Abteilung gehören mit in den Snapshot, weil aus ihm der
                // Leistungsnachweis entsteht: ein Nachweis, der Monate später die heutige
                // Belegschaft zeigt statt der damals abgerechneten, wäre keiner.
                'rows'       => $preview['billable'],
                'skipped'    => $preview['skipped'],
                'basis'      => $profile->basis,
                'excluded_domains' => $profile->normalizedExcludeDomains(),
                'counted_at' => Carbon::now()->toIso8601String(),
            ],
        ];

        try {
            $documentId = $this->gateway->createDraftInvoice($preview['document']);
        } catch (Throwable $e) {
            AssetBillingRun::create($base + [
                'status' => AssetBillingRun::STATUS_FAILED,
                'error'  => $e->getMessage(),
            ]);

            throw $e;
        }

        $run = AssetBillingRun::create($base + [
            'status'               => AssetBillingRun::STATUS_DRAFT,
            'easybill_document_id' => $documentId,
        ]);

        $this->attachStatement($run);

        return $run;
    }

    /**
     * Die User-Pauschale als PDF an den eben angelegten Beleg hängen.
     *
     * **Nach** dem Speichern des Laufs und bewusst ohne Ausnahme: der Beleg liegt zu diesem
     * Zeitpunkt schon in easybill. Ein Abbruch hier hinterließe eine Rechnung, von der unser Lauf
     * nichts weiß — und beim nächsten Versuch entstünde eine zweite. Scheitert der Anhang, bleibt
     * `easybill_attachment_id` leer; genau daran erkennt man in der Historie, dass die Aufstellung
     * von Hand nachzulegen ist.
     */
    protected function attachStatement(AssetBillingRun $run): void
    {
        try {
            $pdf = app(BillingStatementPdf::class);

            $attachmentId = $this->gateway->attachToDocument(
                (int) $run->easybill_document_id,
                $pdf->filename($run),
                $pdf->render($run),
            );
        } catch (Throwable $e) {
            // Auch ein Fehler beim Erzeugen des PDFs darf den Lauf nicht umwerfen.
            Log::warning('[asset-manager] Aufstellung konnte nicht angehängt werden', [
                'run_id' => $run->id,
                'error'  => $e->getMessage(),
            ]);

            return;
        }

        if ($attachmentId !== null) {
            $run->update(['easybill_attachment_id' => $attachmentId]);
        }
    }

    /**
     * Der noch gültige Beleg dieser Periode, falls es einen gibt.
     *
     * Gleicht mit dem Rechnungssystem ab: wird der Entwurf dort gelöscht, darf er die Periode nicht
     * weiter blockieren — sonst ließe sich für diesen Monat nie wieder eine Rechnung erzeugen, und
     * das ohne erkennbaren Grund, weil in easybill ja nichts mehr liegt.
     *
     * Nur ein **nachgewiesenes** Fehlen zählt (`false`). Bei `null` — nicht angebunden, Netzwerk weg,
     * Token abgelaufen — bleibt der Lauf blockierend: aus „ich konnte nicht nachsehen" eine Löschung
     * zu folgern hieße, eine zweite Rechnung für denselben Monat zu riskieren.
     */
    public function existingDraft(AssetBillingProfile $profile, string $period): ?AssetBillingRun
    {
        $run = AssetBillingRun::query()
            ->withoutTenantScope()
            ->where('billing_profile_id', $profile->id)
            ->where('period', $this->normalizePeriod($period))
            ->where('status', AssetBillingRun::STATUS_DRAFT)
            ->whereNotNull('easybill_document_id')
            ->latest('id')
            ->first();

        if ($run === null) {
            return null;
        }

        if ($this->gateway->documentExists((int) $run->easybill_document_id) === false) {
            $run->update([
                'status' => AssetBillingRun::STATUS_DISCARDED,
                'error'  => 'Beleg in easybill gelöscht — festgestellt am ' . Carbon::now()->format('d.m.Y H:i') . '.',
            ]);

            return null;
        }

        return $run;
    }

    /**
     * Baut die Beleg-Nutzdaten in der Sprache von easybill.
     *
     * Alle Beträge als Integer-Cent — das ist die ausdrückliche Anforderung der API. Der
     * Leistungszeitraum wird mitgegeben, weil eine Kopfpauschale sich auf einen Monat bezieht und
     * das Datum der Belegerstellung darüber nichts aussagt.
     *
     * @param array{cents: int|null, source: string|null, label: string|null, booking_account: string|null, note: string|null} $price
     *
     * @return array<string, mixed>
     */
    protected function buildDocument(
        AssetBillingProfile $profile,
        string $period,
        int $quantity,
        int $unitPriceCents,
        array $price,
    ): array {
        $start = Carbon::createFromFormat('Y-m-d', $period . '-01')->startOfMonth();
        $label = $this->periodLabel($period);

        $item = [
            'type'             => 'POSITION',
            'description'      => ($price['label'] ?: $profile->name) . ' — ' . $label,
            'quantity'         => $quantity,
            'single_price_net' => $unitPriceCents,
        ];

        if ($profile->commerce_sku) {
            $item['number'] = $profile->commerce_sku;
        }

        // Nur setzen, wenn bekannt: ein leeres Feld würde in easybill den Kundendefault überschreiben.
        if ($profile->vat_percent !== null) {
            $item['vat_percent'] = (int) $profile->vat_percent;
        }

        if ($price['booking_account']) {
            $item['booking_account'] = (string) $price['booking_account'];
        }

        return [
            'type'         => 'INVOICE',
            'customer_id'  => (int) $profile->easybill_customer_id,
            'title'        => $profile->name . ' — ' . $label,
            'currency'     => $profile->currency ?: 'EUR',
            'service_date' => [
                'type'      => 'SERVICE',
                'date_from' => $start->format('Y-m-d'),
                'date_to'   => $start->copy()->endOfMonth()->format('Y-m-d'),
            ],
            'items' => [$item],
        ];
    }

    /** 'YYYY-MM'; alles andere fällt auf den laufenden Monat zurück. */
    public function normalizePeriod(?string $period): string
    {
        return preg_match('/^\d{4}-\d{2}$/', (string) $period) === 1
            ? $period
            : Carbon::now()->format('Y-m');
    }

    public function periodLabel(string $period): string
    {
        [$year, $month] = array_pad(explode('-', $period), 2, null);

        return (self::MONTHS[(int) $month] ?? $month) . ' ' . $year;
    }
}
