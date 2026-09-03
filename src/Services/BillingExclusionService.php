<?php

namespace Platform\AssetManager\Services;

use Illuminate\Support\Facades\DB;
use Platform\AssetManager\Models\AssetBillingProfile;
use Platform\AssetManager\Models\AssetHolder;

/**
 * Träger von der Weiterberechnung ausnehmen — und die Ausnahme wieder aufheben (ADR 0024).
 *
 * Die **eine** Wahrheit für Detailseite, Sammelaktion in der Trägerliste und MCP-Tool. Dass es
 * dieser Service ist und nicht drei Feldzuweisungen an drei Stellen, hat zwei Gründe:
 *
 * 1. **Die Ausnahme ist nie ein Feld allein.** Zeitstempel, Grund und Urheber gehören zusammen
 *    gesetzt und zusammen geleert. Ein Grund ohne Datum wäre ein Kommentar, ein Datum ohne Grund
 *    eine Behauptung, und eine halb geleerte Ausnahme (`at = null`, Grund bleibt stehen) sähe in
 *    der Oberfläche wie eine gültige aus.
 * 2. **Die Wirkung ist Geld.** Jede Ausnahme verschiebt die Menge auf einer Kundenrechnung. Diese
 *    Zahl muss *vor* dem Schreiben danebenstehen — ein Ja/Nein-Dialog, der „12 Träger ändern?"
 *    fragt, verschweigt genau die Information, auf die es ankommt: „Menge 152 → 140, −960 € netto".
 *
 * Die Menge kommt aus dem {@see BillableHolderResolver} selbst, mit übersteuerten Ausnahmen — nicht
 * aus einer nachgebauten Schnittmenge. Ein zweiter Satz Zählregeln hier wäre genau der Fehler, den
 * ADR 0021 vermeiden wollte: eine Vorschau, die etwas anderes rechnet als der Lauf.
 *
 * Rückgabeform wie im übrigen Service-Layer (siehe {@see HolderTypeService}): `ok = false` heißt
 * **bewusst blockiert** mit Begründung, nicht „Absturz". **Autorisierung ist Aufruferpflicht** —
 * Livewire über `Gate::authorize('asset-manager.manage')`, das Tool über den ToolContext.
 */
class BillingExclusionService
{
    /** Längster zulässiger Grund — die Spalte ist ein `string`, und ein Aufsatz gehört nicht hinein. */
    public const REASON_MAX = 255;

    public function __construct(
        protected BillableHolderResolver $holders,
        protected BillingUnitPriceResolver $prices,
    ) {}

    /**
     * Ausnahme setzen oder aufheben.
     *
     * @param  list<int>  $holderIds
     * @param  bool  $excluded  true = ausnehmen (Grund ist Pflicht), false = Ausnahme aufheben
     * @return array{
     *     ok: bool, code: string|null, reason: string|null, message: string, dry_run: bool,
     *     excluded: bool,
     *     summary: array{updated: int, unchanged: int, missing: int, total: int},
     *     impact: array<int, array{profile_id: int, profile_name: string, quantity_before: int,
     *              quantity_after: int, delta_quantity: int, unit_price_cents: int|null,
     *              delta_net_cents: int|null, currency: string}>,
     *     missing_ids: list<int>,
     *     results: list<array<string, mixed>>
     * }
     */
    public function setExclusion(
        int $teamId,
        int $tenantId,
        array $holderIds,
        bool $excluded,
        ?string $reason = null,
        ?int $userId = null,
        bool $dryRun = false,
    ): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $holderIds))));

        if ($ids === []) {
            return $this->fail('VALIDATION_ERROR', 'Keine Asset-Träger ausgewählt.', $dryRun, $excluded, 'no_holders');
        }

        $reason = trim((string) $reason);

        // Der Grund ist Pflicht, und zwar hier und nicht nur im Formular: eine Ausnahme ohne Grund
        // ist in drei Monaten nicht mehr von einem Datenfehler zu unterscheiden. Genau daran ist die
        // Häkchen-Variante in ADR 0021 gescheitert.
        if ($excluded && $reason === '') {
            return $this->fail(
                'VALIDATION_ERROR',
                'Bitte einen Grund angeben — eine Ausnahme ohne Grund ist später nicht mehr erklärbar.',
                $dryRun,
                $excluded,
                'no_reason',
            );
        }

        if (mb_strlen($reason) > self::REASON_MAX) {
            return $this->fail(
                'VALIDATION_ERROR',
                'Der Grund ist zu lang (maximal ' . self::REASON_MAX . ' Zeichen).',
                $dryRun,
                $excluded,
                'reason_too_long',
            );
        }

        // Explizit tenant-gebunden statt über den Global Scope: eine Sammelaktion darf nicht davon
        // abhängen, welchen Tenant der Bediener gerade in der Sidebar stehen hat.
        $holders = AssetHolder::query()
            ->withoutTenantScope()
            ->forTenant($tenantId)
            ->where('team_id', $teamId)
            ->whereIn('id', $ids)
            ->get();

        $missing = array_values(array_diff($ids, $holders->pluck('id')->all()));

        $results   = [];
        $pending   = [];
        $unchanged = 0;

        foreach ($holders as $holder) {
            $isExcluded = $holder->isBillingExcluded();

            // Ein bereits ausgenommener Träger mit **anderem** Grund gilt als Änderung: der Grund ist
            // der eigentliche Inhalt der Ausnahme, und ihn zu überschreiben ist eine bewusste
            // Korrektur („Elternzeit" → „ausgeschieden"). Nur die Wiederholung derselben Angabe ist
            // ein Nichtereignis.
            $sameReason = $isExcluded && trim((string) $holder->billing_excluded_reason) === $reason;

            if ($excluded === $isExcluded && ($excluded === false || $sameReason)) {
                $unchanged++;
                $results[] = [
                    'id'     => $holder->id,
                    'label'  => $holder->display_name ?: $holder->user_principal_name,
                    'upn'    => $holder->user_principal_name,
                    'status' => 'unchanged',
                ];
                continue;
            }

            $pending[] = $holder;
            $results[] = [
                'id'              => $holder->id,
                'label'           => $holder->display_name ?: $holder->user_principal_name,
                'upn'             => $holder->user_principal_name,
                'status'          => $dryRun ? 'would_update' : 'updated',
                'was_excluded'    => $isExcluded,
                'previous_reason' => $isExcluded ? $holder->billing_excluded_reason : null,
            ];
        }

        // Die Wirkung auf die Rechnung wird IMMER gerechnet, auch beim echten Schreiben: sie geht in
        // die Meldung danach ein. Vor dem Schreiben, weil der Vergleich „vorher/nachher" sonst zwei
        // Zustände bräuchte, von denen einer schon weg ist.
        $impact = $this->impact($teamId, $tenantId, $pending, $excluded);

        // Alles oder nichts: eine halb geschriebene Sammelaktion hinterließe eine Rechnungsmenge,
        // die niemand mehr gegen die Auswahl prüfen kann.
        if (! $dryRun && $pending !== []) {
            $timestamp = now();

            DB::transaction(function () use ($pending, $excluded, $reason, $userId, $timestamp): void {
                foreach ($pending as $holder) {
                    // Über Model-`save()`, nie per Query-Builder-`update()`: sonst fielen `updated_at`
                    // und die Events des LogsActivity-Traits aus — und die Ausnahme wäre nirgends
                    // protokolliert außer in sich selbst.
                    $holder->billing_excluded_at     = $excluded ? $timestamp : null;
                    $holder->billing_excluded_reason = $excluded ? $reason : null;
                    $holder->billing_excluded_by     = $excluded ? $userId : null;
                    $holder->save();
                }
            });
        }

        $updated = count($pending);

        return [
            'ok'          => true,
            'code'        => null,
            'reason'      => null,
            'message'     => $this->message($updated, $excluded, $dryRun, $impact),
            'dry_run'     => $dryRun,
            'excluded'    => $excluded,
            'summary'     => [
                'updated'   => $updated,
                'unchanged' => $unchanged,
                'missing'   => count($missing),
                'total'     => count($ids),
            ],
            'impact'      => $impact,
            'missing_ids' => $missing,
            'results'     => $results,
        ];
    }

    /**
     * Wirkung der anstehenden Änderung auf jedes aktive Abrechnungsprofil des Tenants.
     *
     * Gerechnet wird mit der Produktionsregel selbst: einmal, wie es jetzt steht, und einmal mit
     * übersteuerten Ausnahmen für genau die anstehenden Träger. Ein nachgebauter Mengenvergleich
     * (etwa „Schnittmenge aus Auswahl und `principals`") wäre in dem Moment falsch, in dem derselbe
     * Mensch zweimal im Verzeichnis steht und nur eine der beiden Zeilen ausgenommen wird.
     *
     * Inaktive Profile bleiben außen vor — sie erzeugen keine Rechnung, und ihre Zahl in der
     * Vorschau würde nur ablenken.
     *
     * @param  list<AssetHolder>  $pending
     * @return array<int, array{profile_id: int, profile_name: string, quantity_before: int,
     *          quantity_after: int, delta_quantity: int, unit_price_cents: int|null,
     *          delta_net_cents: int|null, currency: string}>
     */
    protected function impact(int $teamId, int $tenantId, array $pending, bool $excluded): array
    {
        if ($pending === []) {
            return [];
        }

        $overrides = [];
        foreach ($pending as $holder) {
            $overrides[(int) $holder->id] = $excluded;
        }

        $profiles = AssetBillingProfile::query()
            ->withoutTenantScope()
            ->forTenant($tenantId)
            ->where('team_id', $teamId)
            ->where('active', true)
            ->orderBy('name')
            ->get();

        $impact = [];

        foreach ($profiles as $profile) {
            $before = count($this->holders->resolve($profile)['principals']);
            $after  = count($this->holders->resolve($profile, $overrides)['principals']);
            $cents  = $this->prices->resolve($profile)['cents'];

            $impact[] = [
                'profile_id'       => (int) $profile->id,
                'profile_name'     => (string) $profile->name,
                'quantity_before'  => $before,
                'quantity_after'   => $after,
                'delta_quantity'   => $after - $before,
                'unit_price_cents' => $cents,
                // Ohne ermittelbaren Stückpreis bleibt der Betrag `null` statt 0 — „0 €" wäre eine
                // Aussage über die Wirkung, und die wäre falsch.
                'delta_net_cents'  => $cents === null ? null : ($after - $before) * $cents,
                'currency'         => $profile->currency ?: 'EUR',
            ];
        }

        return $impact;
    }

    /**
     * Die Meldung, die Vorschau und Ergebnis begleitet — mit der Mengen- und Geldwirkung, sofern es
     * genau ein betroffenes Profil gibt. Bei mehreren steht die Aufstellung ohnehin daneben; sie in
     * einen Satz zu falten würde nur Zahlen vermischen, die zu verschiedenen Kunden gehören.
     *
     * @param  array<int, array<string, mixed>>  $impact
     */
    protected function message(int $updated, bool $excluded, bool $dryRun, array $impact): string
    {
        $verb = $excluded ? 'von der Weiterberechnung ausgenommen' : 'wieder in die Weiterberechnung aufgenommen';

        $head = $dryRun
            ? "Vorschau: {$updated} Träger würden {$verb}. Kein Schreibvorgang."
            : "{$updated} Träger {$verb}.";

        if (count($impact) !== 1 || $updated === 0) {
            return $head;
        }

        $row = $impact[0];

        if ($row['delta_quantity'] === 0) {
            return $head . ' Die abgerechnete Menge ändert sich dadurch nicht'
                . ' — diese Träger hält die Zählregel ohnehin heraus.';
        }

        $money = $row['delta_net_cents'] === null
            ? ''
            : ' (' . ($row['delta_net_cents'] > 0 ? '+' : '−')
                . number_format(abs($row['delta_net_cents']) / 100, 2, ',', '.') . ' ' . $row['currency'] . ' netto)';

        return $head . ' Abgerechnete Menge ' . $row['quantity_before'] . ' → ' . $row['quantity_after']
            . $money . '.';
    }

    /**
     * @return array{ok: bool, code: string, reason: string, message: string, dry_run: bool,
     *     excluded: bool, summary: array{updated: int, unchanged: int, missing: int, total: int},
     *     impact: array<int, array<string, mixed>>, missing_ids: list<int>, results: list<array<string, mixed>>}
     */
    protected function fail(string $code, string $message, bool $dryRun, bool $excluded, string $reason): array
    {
        return [
            'ok'          => false,
            'code'        => $code,
            'reason'      => $reason,
            'message'     => $message,
            'dry_run'     => $dryRun,
            'excluded'    => $excluded,
            'summary'     => ['updated' => 0, 'unchanged' => 0, 'missing' => 0, 'total' => 0],
            'impact'      => [],
            'missing_ids' => [],
            'results'     => [],
        ];
    }
}
