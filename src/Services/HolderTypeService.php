<?php

namespace Platform\AssetManager\Services;

use Illuminate\Support\Facades\DB;
use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Models\AssetLicenseContractLine;
use Platform\AssetManager\Support\LicensePriceBook;

/**
 * Trägertyp setzen (ADR 0017) — die **eine** Wahrheit für UI, Detailseite und MCP-Tool.
 *
 * Der Typ ist kein gewöhnliches Feld: an ihm hängt die Lizenz-Verteilungskaskade (ADR 0019). Ein
 * Wechsel von oder zu `function` verschiebt Mengen zwischen Trägern und Sammel-Kostenstellen und
 * muss eine Neuverteilung nach sich ziehen; dazu gehört das Nullen von `function_system`, das nur
 * an einem Funktionskonto einen Sinn hat.
 *
 * Diese Regel stand bisher **nur** in {@see \Platform\AssetManager\Livewire\Holders\Show} — das
 * MCP-Tool schrieb `holder_type` in einer Feldschleife und ignorierte beides, hinterließ also ein
 * totes `function_system` und eine Verteilung, die die Stammdaten nicht mehr hergaben. Eine dritte
 * Kopie für die Sammelaktion hätte den Fehler verdreifacht. Deshalb hier, hinter einer Signatur, die
 * auch die Regel ausdrücken kann, die eine Komponentenmethode nicht kennt: **die Neuverteilung läuft
 * genau einmal pro Aufruf, am Ende** — nicht je geänderter Zeile. `allocateForPeriod()` rechnet
 * ohnehin die ganze Periode; n Aufrufe wären dieselbe Arbeit n-mal.
 *
 * Bewusst **nicht** in {@see HolderService}: der handelt von Identität und Lebenszyklus
 * (`findOrCreateByUpn`, `applyGraphProfile`, `anonymize`) — dem Sync- und PII-Pfad. Den
 * Abrechnungspfad dort einzuhängen wäre die falsche Achse.
 *
 * Rückgabeform wie im übrigen Service-Layer (siehe {@see CostLineReassignService}):
 * `ok = false` heißt **bewusst blockiert** mit Begründung, nicht „Absturz".
 * **Autorisierung ist Aufruferpflicht** — Livewire über `Gate::authorize('asset-manager.manage')`,
 * das Tool über den ToolContext.
 */
class HolderTypeService
{
    public function __construct(
        protected HolderClassifier $classifier,
        protected LicenseSeatAllocator $allocator,
    ) {}

    /**
     * Trägertyp für mehrere Träger setzen.
     *
     * Geschrieben wird über Model-`save()`, nie per Query-Builder-`update()` — sonst fielen
     * `updated_at` und sämtliche Model-Events aus. Und ausdrücklich **nicht** `saveQuietly()`: das
     * nutzt der {@see \Platform\AssetManager\Console\Commands\ClassifyHoldersCommand}, weil dort eine
     * Maschine reklassifiziert. Hier entscheidet ein Mensch.
     *
     * @param  list<int>  $holderIds
     * @return array{
     *     ok: bool,
     *     code: string|null,
     *     reason: string|null,
     *     message: string,
     *     target: string|null,
     *     dry_run: bool,
     *     summary: array{updated: int, unchanged: int, missing: int, total: int,
     *                    clears_system: int, needs_system: int, reverts_on_sync: int},
     *     missing_ids: list<int>,
     *     results: list<array<string, mixed>>
     * }
     */
    public function setType(
        int $teamId,
        int $tenantId,
        array $holderIds,
        string $type,
        bool $dryRun = false,
    ): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $holderIds))));

        if ($ids === []) {
            return $this->fail('VALIDATION_ERROR', 'Keine Asset-Träger ausgewählt.', $dryRun, 'no_holders');
        }

        if (! in_array($type, AssetHolder::TYPES, true)) {
            return $this->fail(
                'VALIDATION_ERROR',
                'Unbekannter Träger-Typ. Erlaubt: ' . implode(', ', AssetHolder::TYPES) . '.',
                $dryRun,
                'bad_type',
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

        $targetIsFunction = $type === AssetHolder::TYPE_FUNCTION;
        $targetLabel      = AssetHolder::holderTypeLabelFor($type);

        $results   = [];
        $updated   = 0;
        $unchanged = 0;
        $clears    = 0;
        $needs     = 0;
        $reverts   = 0;

        $touched = [];   // Träger, die die Funktionskonto-Grenze überqueren
        $pending = [];   // zu schreibende Träger — erst auswerten, dann in EINER Transaktion schreiben

        foreach ($holders as $holder) {
            $from = $holder->holder_type;

            if ($from === $type) {
                $unchanged++;
                $results[] = [
                    'id'     => $holder->id,
                    'label'  => $holder->display_name ?: $holder->user_principal_name,
                    'status' => 'unchanged',
                    'to'     => $targetLabel,
                ];
                continue;
            }

            // Das System gehört nur an ein Funktionskonto (spiegelt Holders\Show::save()). Beim
            // Wechsel weg davon geht es verloren — ohne Undo, deshalb wird es gezählt und in der
            // Vorschau ausgewiesen. Beim Wechsel HIN zu `function` erfindet die Sammelaktion kein
            // System: dasselbe für 40 Konten wäre fast immer falsch.
            $losesSystem = ! $targetIsFunction && $holder->function_system !== null;
            $lacksSystem = $targetIsFunction && $holder->function_system === null;

            if ($losesSystem) {
                $clears++;
            }

            if ($lacksSystem) {
                $needs++;
            }

            $revertsOnSync = $this->revertsOnSync($holder, $type, $tenantId);

            if ($revertsOnSync) {
                $reverts++;
            }

            $pending[] = $holder;

            if ($this->affectsAllocation($from, $type)) {
                $touched[] = $holder;
            }

            $updated++;
            $results[] = [
                'id'              => $holder->id,
                'label'           => $holder->display_name ?: $holder->user_principal_name,
                'upn'             => $holder->user_principal_name,
                'status'          => $dryRun ? 'would_update' : 'updated',
                'from'            => AssetHolder::holderTypeLabelFor($from),
                'to'              => $targetLabel,
                'clears_system'   => $losesSystem,
                'needs_system'    => $lacksSystem,
                'reverts_on_sync' => $revertsOnSync,
            ];
        }

        // Alles oder nichts: bricht die Schleife bei Träger 200 von 500 ab, wäre der Bestand halb
        // umgetypt und die Neuverteilung nie gelaufen — der Allocator-Stand passte dann nicht mehr
        // zu den Stammdaten. Die Neuverteilung selbst läuft NACH dem Commit: sie rechnet eine ganze
        // Periode und hätte in der Transaktion nichts zu suchen.
        if (! $dryRun && $pending !== []) {
            DB::transaction(function () use ($pending, $type, $targetIsFunction): void {
                foreach ($pending as $holder) {
                    $holder->holder_type     = $type;
                    $holder->function_system = $targetIsFunction ? $holder->function_system : null;
                    $holder->save();
                }
            });
        }

        $redistributed = false;

        if (! $dryRun && $touched !== []) {
            $redistributed = $this->redistribute($teamId, $tenantId);
        }

        return [
            'ok'      => true,
            'code'    => null,
            'reason'  => null,
            'message' => $dryRun
                ? "Vorschau: {$updated} Träger würden auf '{$targetLabel}' gesetzt. Kein Schreibvorgang."
                : "{$updated} Träger auf '{$targetLabel}' gesetzt."
                    . ($redistributed ? ' Lizenzverteilung neu berechnet.' : ''),
            'target'  => $targetLabel,
            'dry_run' => $dryRun,
            'summary' => [
                'updated'         => $updated,
                'unchanged'       => $unchanged,
                'missing'         => count($missing),
                'total'           => count($ids),
                'clears_system'   => $clears,
                'needs_system'    => $needs,
                'reverts_on_sync' => $reverts,
            ],
            'missing_ids' => $missing,
            'results'     => $results,
        ];
    }

    /**
     * Berührt dieser Wechsel die Lizenz-Verteilung?
     *
     * Nur der Übertritt über die Funktionskonto-Grenze tut das — die Kaskade behandelt
     * Funktionskonten bevorzugt (ADR 0019). Ein Wechsel `admin` → `service` verschiebt nichts.
     */
    public function affectsAllocation(?string $from, ?string $to): bool
    {
        return ($from === AssetHolder::TYPE_FUNCTION) !== ($to === AssetHolder::TYPE_FUNCTION);
    }

    /**
     * Lizenzmengen des jüngsten Abrechnungsmonats neu verteilen.
     *
     * Läuft nur, wenn überhaupt Vertragszeilen existieren — ohne Rechnungsimport gibt es nichts zu
     * verteilen, und ein Lauf ins Leere würde nur Zeit kosten.
     *
     * @return bool true, wenn tatsächlich verteilt wurde.
     */
    public function redistribute(int $teamId, int $tenantId): bool
    {
        $period = AssetLicenseContractLine::withoutTenantScope()
            ->where('team_id', $teamId)
            ->where('tenant_id', $tenantId)
            ->max('period_label');

        if ($period === null) {
            return false;
        }

        $this->allocator->allocateForPeriod($teamId, $tenantId, (string) $period, false);

        LicensePriceBook::flush();

        return true;
    }

    /**
     * Würde der nächste Sync diesen Typ wieder überschreiben?
     *
     * Nur bei Ziel `person` möglich: {@see HolderClassifier::classify()} lässt einen gesetzten
     * Nicht-`person`-Typ jeden Sync überleben, aber `person` fällt zurück in die Tenant-Regeln — und
     * bei einer synthetischen `@funktion.import.local`-UPN sogar garantiert auf `function`.
     *
     * Beantwortet wird die Frage mit der Produktionsregel selbst, an einem **Klon**: `classify()`
     * schreibt nicht (nur `apply()` tut das), und der Klon wird nie gespeichert. Ein nachgebauter
     * Regelvergleich würde beim ersten Regel-Update auseinanderlaufen.
     */
    protected function revertsOnSync(AssetHolder $holder, string $type, int $tenantId): bool
    {
        if ($type !== AssetHolder::TYPE_PERSON) {
            return false;
        }

        $probe              = clone $holder;
        $probe->holder_type = AssetHolder::TYPE_PERSON;

        return $this->classifier->classify($probe, $tenantId) !== AssetHolder::TYPE_PERSON;
    }

    /**
     * @return array{ok: bool, code: string, reason: string, message: string, target: null, dry_run: bool,
     *     summary: array{updated: int, unchanged: int, missing: int, total: int,
     *                    clears_system: int, needs_system: int, reverts_on_sync: int},
     *     missing_ids: list<int>, results: list<array<string, mixed>>}
     */
    protected function fail(string $code, string $message, bool $dryRun, string $reason): array
    {
        return [
            'ok'          => false,
            'code'        => $code,
            'reason'      => $reason,
            'message'     => $message,
            'target'      => null,
            'dry_run'     => $dryRun,
            'summary'     => [
                'updated'         => 0,
                'unchanged'       => 0,
                'missing'         => 0,
                'total'           => 0,
                'clears_system'   => 0,
                'needs_system'    => 0,
                'reverts_on_sync' => 0,
            ],
            'missing_ids' => [],
            'results'     => [],
        ];
    }
}
