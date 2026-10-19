<?php

namespace Platform\AssetManager\Services;

use Carbon\Carbon;
use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Models\AssetCostLine;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Models\AssetVendor;

/**
 * Manuelle Kostenpositionen anlegen und ändern — die **eine** Wahrheit für UI und MCP-Tools (#938).
 *
 * Vorher hatten der Editor ({@see \Platform\AssetManager\Livewire\CostLines\Index::save()}) und die
 * Tools `asset-manager.cost-lines.POST`/`.PUT` je eigene Regeln, und sie liefen auseinander: das
 * Create-Tool setzte kein `tenant_id` (seit der NOT-NULL-Welle scheiterte jeder Aufruf), das
 * Update-Tool prüfte den Minusbetrag nur bei übergebenem `amount` und ließ beim Wechsel auf EUR
 * einen alten Kurs stehen. Ein Regelsatz an einer Stelle verhindert das strukturell.
 *
 * Rückgabeform wie im übrigen Service-Layer: `['ok' => bool, 'errors' => [feld => meldung], 'line' => …]`.
 * Die Feldnamen sind die Spaltennamen; der Aufrufer übersetzt sie in seine Formularfelder.
 *
 * **Autorisierung und Tenant-Auflösung sind Aufruferpflicht** (Livewire über `Gate::authorize`,
 * Tool über den ToolContext) — wie beim {@see CostLineReassignService}.
 */
class CostLineWriteService
{
    public const FREQUENCIES = ['monthly', 'quarterly', 'yearly', 'once'];

    /** Felder, die ein Aufruf setzen darf. Alles andere wird ignoriert. */
    private const FIELDS = [
        'cost_type_id', 'cost_center_id', 'vendor_id', 'assignee_id', 'label', 'amount',
        'currency', 'fx_rate', 'frequency', 'valid_from', 'valid_to', 'active',
    ];

    /**
     * @param  array<string, mixed>  $input
     * @param  bool  $requireCostLineSource  Kostenart muss `aggregation_source=cost_line` haben. Das Tool
     *         erzwingt das, weil eine Position auf einer anderen Kostenart still aus der Aufteilung fällt.
     * @return array{ok: bool, errors: array<string, string>, line: AssetCostLine|null}
     */
    public function create(int $teamId, int $tenantId, array $input, bool $requireCostLineSource = false): array
    {
        $attrs = array_merge(['currency' => 'EUR', 'active' => true], $this->only($input));

        return $this->persist(new AssetCostLine(), $teamId, $tenantId, $attrs, $requireCostLineSource);
    }

    /**
     * Teil-Update: nicht übergebene Felder behalten ihren Wert, geprüft wird aber immer der
     * **effektive** Stand nach dem Update (Kostenart × Betrag, Währung × Kurs, ab × bis).
     *
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, errors: array<string, string>, line: AssetCostLine|null}
     */
    public function update(AssetCostLine $line, array $input, bool $requireCostLineSource = false): array
    {
        $current = [
            'cost_type_id'   => $line->cost_type_id,
            'cost_center_id' => $line->cost_center_id,
            'vendor_id'      => $line->vendor_id,
            'assignee_id'    => $line->assignee_id,
            'label'          => $line->label,
            'amount'         => $line->amount,
            'currency'       => $line->currency,
            'fx_rate'        => $line->fx_rate,
            'frequency'      => $line->frequency,
            'valid_from'     => $line->valid_from?->format('Y-m-d'),
            'valid_to'       => $line->valid_to?->format('Y-m-d'),
            'active'         => $line->active,
        ];

        return $this->persist(
            $line,
            (int) $line->team_id,
            (int) $line->tenant_id,
            array_merge($current, $this->only($input)),
            $requireCostLineSource,
            vendorExplicit: array_key_exists('vendor_id', $input),
        );
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array{ok: bool, errors: array<string, string>, line: AssetCostLine|null}
     */
    private function persist(
        AssetCostLine $line,
        int $teamId,
        int $tenantId,
        array $a,
        bool $requireCostLineSource,
        bool $vendorExplicit = true,
    ): array {
        $errors = [];

        // --- Kostenart ---------------------------------------------------------------------------
        $type = $this->find(AssetCostType::class, $teamId, $tenantId, $a['cost_type_id'] ?? null);
        if ($type === null) {
            $errors['cost_type_id'] = empty($a['cost_type_id'])
                ? 'Kostenart ist erforderlich.'
                : 'Kostenart gehört nicht zu diesem Team/Tenant.';
        } elseif ($requireCostLineSource && $type->aggregation_source !== AssetCostType::SOURCE_COST_LINE) {
            $errors['cost_type_id'] = "Kostenart '{$type->name}' hat aggregation_source='{$type->aggregation_source}' — "
                . 'eine manuelle Position darauf fiele still aus der Kostenaufteilung.';
        }

        // --- Bezeichnung, Frequenz ---------------------------------------------------------------
        $label = trim((string) ($a['label'] ?? ''));
        if ($label === '') {
            $errors['label'] = 'Bezeichnung ist erforderlich.';
        } elseif (mb_strlen($label) > 255) {
            $errors['label'] = 'Bezeichnung ist zu lang (max. 255 Zeichen).';
        }

        if (! in_array($a['frequency'] ?? null, self::FREQUENCIES, true)) {
            $errors['frequency'] = 'Frequenz muss eines von: ' . implode(', ', self::FREQUENCIES) . '.';
        }

        // --- Betrag: gegen die EFFEKTIVE Kostenart -----------------------------------------------
        // Auch wenn nur die Kostenart wechselt: ein Minusbetrag auf einer Art ohne allow_negative
        // wäre sonst still ins Netting gerutscht.
        if (! isset($a['amount']) || ! is_numeric($a['amount'])) {
            $errors['amount'] = 'Betrag (Zahl) ist erforderlich.';
        } else {
            $amount = (float) $a['amount'];
            if ($amount == 0.0) {
                $errors['amount'] = 'Betrag darf nicht 0,00 sein.';
            } elseif ($amount < 0 && $type !== null && ! $type->allow_negative) {
                $errors['amount'] = "Negativer Betrag ist nur für Gutschrift-Kostenarten (allow_negative) zulässig — '{$type->name}' ist keine.";
            }
        }

        // --- Währung und Kurs (ADR 0002) ---------------------------------------------------------
        $currency = strtoupper(trim((string) ($a['currency'] ?? ''))) ?: 'EUR';
        if (strlen($currency) !== 3) {
            $errors['currency'] = 'Währung muss ein dreistelliger Code sein (z. B. EUR, USD).';
        }
        $fxRate = null;
        if ($currency !== 'EUR') {
            if (! is_numeric($a['fx_rate'] ?? null) || (float) $a['fx_rate'] <= 0) {
                $errors['fx_rate'] = "Nicht-EUR-Position ({$currency}) braucht einen positiven Umrechnungskurs zu EUR — sonst würde der Betrag still 1:1 als EUR gewertet.";
            } else {
                $fxRate = (float) $a['fx_rate'];
            }
        }

        // --- Gültigkeit --------------------------------------------------------------------------
        $from = $this->date($a['valid_from'] ?? null, 'valid_from', 'Gültig ab', $errors);
        $to   = $this->date($a['valid_to'] ?? null, 'valid_to', 'Gültig bis', $errors);
        if ($from !== null && $to !== null && $to->lt($from)) {
            $errors['valid_to'] = '„Gültig bis“ liegt vor „Gültig ab“.';
        }

        // --- Referenzen: Team UND Tenant ---------------------------------------------------------
        $refs = [];
        foreach ([
            'vendor_id'      => [AssetVendor::class, 'Kreditor'],
            'cost_center_id' => [AssetCostCenter::class, 'Kostenstelle'],
            'assignee_id'    => [AssetHolder::class, 'Asset-Träger'],
        ] as $field => [$class, $name]) {
            $refs[$field] = null;
            if (! empty($a[$field])) {
                $model = $this->find($class, $teamId, $tenantId, $a[$field]);
                if ($model === null) {
                    $errors[$field] = "{$name} gehört nicht zu diesem Team/Tenant.";
                } else {
                    $refs[$field] = $model->id;
                }
            }
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'line' => null];
        }

        // Kreditor-Vorgabe der Kostenart nur, wenn keiner gesetzt ist — beim Teil-Update eines
        // anderen Feldes bleibt ein bewusst geleerter Kreditor leer.
        $vendorId = $refs['vendor_id'] ?? ($vendorExplicit || ! $line->exists ? $type->vendor_default_id : null);

        $line->fill([
            'cost_type_id'      => $type->id,
            'vendor_id'         => $vendorId,
            'cost_center_id'    => $refs['cost_center_id'],
            'assignee_id'       => $refs['assignee_id'],
            'label'             => $label,
            'amount'            => (float) $a['amount'],
            'currency'          => $currency,
            // Bei EUR explizit NULL: sonst bliebe nach USD → EUR ein Zombie-Kurs stehen.
            'fx_rate'           => $fxRate,
            'frequency'         => $a['frequency'],
            'valid_from'        => $from?->format('Y-m-d'),
            'valid_to'          => $to?->format('Y-m-d'),
            'accounting_system' => $type->system_default,
            'active'            => (bool) ($a['active'] ?? true),
        ]);

        if (! $line->exists) {
            $line->team_id   = $teamId;
            $line->tenant_id = $tenantId;
            $line->source    = 'manual';
        }

        $line->save();

        return ['ok' => true, 'errors' => [], 'line' => $line];
    }

    /** @return array<string, mixed> */
    private function only(array $input): array
    {
        $out = array_intersect_key($input, array_flip(self::FIELDS));

        foreach ($out as $key => $value) {
            if ($value === '') {
                $out[$key] = null;
            }
        }

        return $out;
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     * @param  class-string<T>  $class
     * @return T|null
     */
    private function find(string $class, int $teamId, int $tenantId, mixed $id): mixed
    {
        if (empty($id) || ! is_numeric($id)) {
            return null;
        }

        return $class::withoutTenantScope()
            ->where('team_id', $teamId)
            ->where('tenant_id', $tenantId)
            ->whereKey((int) $id)
            ->first();
    }

    /** @param array<string, string> $errors */
    private function date(mixed $value, string $field, string $label, array &$errors): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->startOfDay();
        } catch (\Throwable) {
            $errors[$field] = "{$label} ist kein gültiges Datum (YYYY-MM-DD).";

            return null;
        }
    }
}
