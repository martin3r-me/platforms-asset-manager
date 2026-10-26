<?php

namespace Platform\AssetManager\Services;

use Illuminate\Support\Collection;
use Platform\AssetManager\Models\AssetCostLine;
use Platform\AssetManager\Models\AssetCostLineHistory;

/**
 * Preis-Historie der Kostenpositionen aus Rechnungsimporten (ADR 0029).
 *
 * Die Rechnungsimporter (Vodafone Mobilfunk, Vodafone Festnetz/RingCentral) aktualisieren ihre
 * Kostenpositionen in place. Dieser Dienst hält je Kostenposition und Rechnung fest, welchen Betrag die
 * Rechnung auswies — und beantwortet daraus „was kostet diese Nummer normalerweise?".
 *
 * Schreibwege:
 *  - scharfer Import: in der Transaktion des Imports ({@see rows()} + {@see store()})
 *  - „Historie nachtragen": der Importer rechnet wie immer, rollt zurück, und nur die hier gesammelten
 *    Zeilen werden geschrieben — aktuelle Kosten und DATEV-Monatsstände bleiben unberührt
 */
class CostLineHistoryService
{
    /**
     * Verlaufszeilen aus den in einem Lauf geschriebenen Kostenpositionen (nach dem Rundungsausgleich).
     *
     * @param  array<string, AssetCostLine>  $written
     * @param  array{source: string, account: ?string, invoice: ?string, from: mixed, to: mixed, booking_month: mixed}  $meta
     * @return list<array<string, mixed>>
     */
    public function rows(array $written, array $meta): array
    {
        $invoice = (string) ($meta['invoice'] ?? '');
        if ($invoice === '') {
            return [];
        }

        $month = self::month($meta['booking_month'] ?? $meta['to'] ?? null);
        $rows  = [];

        foreach ($written as $line) {
            if (! $line instanceof AssetCostLine || (string) $line->import_hash === '') {
                continue;
            }
            $rows[] = [
                'cost_line_id'   => $line->id,
                'import_hash'    => (string) $line->import_hash,
                'source'         => (string) $meta['source'],
                'account'        => (string) ($meta['account'] ?? ''),
                'invoice'        => $invoice,
                'period_from'    => self::date($meta['from'] ?? null),
                'period_to'      => self::date($meta['to'] ?? null),
                'booking_month'  => $month,
                'label'          => (string) $line->label,
                'amount'         => round((float) $line->amount, 2),
                'cost_type_id'   => $line->cost_type_id,
                'cost_center_id' => $line->cost_center_id,
                'assignee_id'    => $line->assignee_id,
            ];
        }

        return $rows;
    }

    /**
     * Verlaufszeilen schreiben — derselbe Lauf derselben Rechnung überschreibt seinen Eintrag.
     *
     * `$resolveLineIds` für „Historie nachtragen": die IDs aus dem zurückgerollten Lauf gibt es nicht
     * mehr; die Zeile hängt sich an die heutige Kostenposition gleicher Identität (oder an keine, wenn
     * die Nummer inzwischen weg ist — der Verlauf bleibt über den Hash auffindbar).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function store(int $teamId, int $tenantId, array $rows, bool $resolveLineIds = false): int
    {
        // Forge spielt neue Migrationen erst im zweiten Deploy-Lauf ein — bis dahin darf ein
        // Rechnungsimport nicht an der fehlenden Tabelle scheitern (Muster wie AssetHolderEvent).
        if ($rows === [] || ! self::tableExists()) {
            return 0;
        }

        $lineIds = $resolveLineIds
            ? AssetCostLine::withoutTenantScope()->withTrashed()
                ->where('team_id', $teamId)
                ->where('tenant_id', $tenantId)
                ->whereIn('import_hash', array_column($rows, 'import_hash'))
                ->pluck('id', 'import_hash')
                ->all()
            : [];

        $values = [];
        foreach ($rows as $row) {
            if ($resolveLineIds) {
                $row['cost_line_id'] = $lineIds[$row['import_hash']] ?? null;
            }
            // Schlüssel im Array: eine doppelte Identität im selben Lauf schreibt wie vorher der letzte Stand.
            $values[$row['import_hash'] . '|' . $row['invoice']] = $row + ['team_id' => $teamId, 'tenant_id' => $tenantId];
        }

        // Ein Upsert je 200 Zeilen statt SELECT + INSERT/UPDATE je Position — beim Nachtragen von 20 Rechnungen
        // sonst mehrere tausend Queries. Identität wie der Unique-Index (tenant_id, import_hash, invoice).
        $update = array_values(array_diff(array_keys(reset($values)), ['tenant_id', 'import_hash', 'invoice']));
        foreach (array_chunk(array_values($values), 200) as $chunk) {
            AssetCostLineHistory::withoutTenantScope()->upsert($chunk, ['tenant_id', 'import_hash', 'invoice'], $update);
        }

        return count($rows);
    }

    /**
     * Verlauf je Identität (import_hash), älteste zuerst.
     *
     * @param  list<string>  $hashes
     * @return array<string, Collection<int, AssetCostLineHistory>>
     */
    public function forHashes(int $tenantId, array $hashes, int $months = 13): array
    {
        $hashes = array_values(array_unique(array_filter($hashes)));
        if ($hashes === [] || ! self::tableExists()) {
            return [];
        }

        $since = now()->startOfMonth()->subMonths($months);

        return AssetCostLineHistory::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->whereIn('import_hash', $hashes)
            ->where(fn ($q) => $q->whereNull('booking_month')->orWhere('booking_month', '>=', $since->toDateString()))
            ->orderBy('booking_month')
            ->orderBy('id')
            ->get()
            ->groupBy('import_hash')
            ->all();
    }

    /**
     * Kennzahlen eines Verlaufs: Regelpreis = häufigster Betrag (bei Gleichstand der jüngere), letzter
     * Betrag und ob der vom Regelpreis abweicht. Unter drei Monaten gibt es keinen Regelpreis — zwei
     * Werte sagen nicht, welcher die Regel ist.
     *
     * @param  iterable<AssetCostLineHistory>  $entries  älteste zuerst
     * @return array{months: list<array{month: ?string, label: ?string, amount: float, invoice: string}>, regular: ?float, latest: ?float, deviates: bool, count: int}
     */
    public static function summarize(iterable $entries): array
    {
        $months = [];
        foreach ($entries as $e) {
            $months[] = [
                'month'   => $e->booking_month?->format('Y-m'),
                'label'   => $e->period_from && $e->period_to ? $e->period_from->format('d.m.Y') . '–' . $e->period_to->format('d.m.Y') : null,
                'amount'  => round((float) $e->amount, 2),
                'invoice' => (string) $e->invoice,
            ];
        }

        $latest  = $months !== [] ? $months[array_key_last($months)]['amount'] : null;
        $regular = null;

        if (count($months) >= 3) {
            $counts = [];
            $lastSeen = [];
            foreach ($months as $i => $m) {
                $key = number_format($m['amount'], 2, '.', '');
                $counts[$key] = ($counts[$key] ?? 0) + 1;
                $lastSeen[$key] = $i;
            }
            uksort($counts, fn ($a, $b) => [$counts[$b], $lastSeen[$b]] <=> [$counts[$a], $lastSeen[$a]]);
            $regular = (float) array_key_first($counts);
        }

        return [
            'months'   => $months,
            'regular'  => $regular,
            'latest'   => $latest,
            'deviates' => $regular !== null && $latest !== null && abs($latest - $regular) >= 0.01,
            'count'    => count($months),
        ];
    }

    private static ?bool $tableExists = null;

    public static function tableExists(): bool
    {
        return self::$tableExists ??= \Illuminate\Support\Facades\Schema::hasTable('asset_cost_line_history');
    }

    /** Für Tests: nach dem Anlegen der Tabelle neu prüfen. */
    public static function forgetTableCheck(): void
    {
        self::$tableExists = null;
    }

    private static function month(mixed $value): ?string
    {
        $date = self::carbon($value);

        return $date?->startOfMonth()->toDateString();
    }

    private static function date(mixed $value): ?string
    {
        return self::carbon($value)?->toDateString();
    }

    private static function carbon(mixed $value): ?\Illuminate\Support\Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return \Illuminate\Support\Carbon::instance($value);
        }

        try {
            return \Illuminate\Support\Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
