<?php

namespace Platform\AssetManager\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Platform\AssetManager\Models\AssetLicenseContractLine;

/**
 * Kennzahlen über die Vertragszeilen eines Monats, die bisher nur angezeigt, nie ausgewertet wurden
 * (ADR 0019): was eine Umstellung des Überhangs auf Jahresbindung spart (#882) und wann sich ein
 * Kündigungsfenster öffnet (#883).
 *
 * Reine Rechnung über die übergebenen Zeilen — keine Queries, damit die Seite sie mit den ohnehin
 * geladenen Zeilen füttert und der Test sie ohne Datenbank prüfen kann.
 */
final class LicenseContractInsights
{
    /** Vorlauf des Kündigungs-Radars in Tagen. */
    public const CANCEL_WINDOW_DAYS = 90;

    /**
     * Ersparnis, wenn der Überhang (Monats-Seats über Bedarf) auf Jahresbindung umgestellt würde:
     * je Lizenz (Monatspreis − Jahrespreis derselben Lizenz) × Überhangmenge. Ohne Jahrespreis in
     * diesem Monat ist die Ersparnis nicht bestimmbar — die Zeile wird gezählt statt geschätzt.
     *
     * @param  Collection<int, AssetLicenseContractLine>  $lines  Zeilen EINES Abrechnungsmonats
     * @return array{savings: float, overflow_amount: float, overflow_quantity: float, without_yearly_price: int}
     */
    public static function overflowSavings(Collection $lines): array
    {
        $yearlyPrice = $lines
            ->filter(fn (AssetLicenseContractLine $l) => $l->binding === AssetLicenseContractLine::BINDING_YEARLY && $l->sku_id)
            ->groupBy('sku_id')
            ->map(fn (Collection $g) => (float) $g->min(fn ($l) => (float) $l->unit_price));

        $savings  = 0.0;
        $amount   = 0.0;
        $quantity = 0.0;
        $without  = 0;

        foreach ($lines as $line) {
            $over = (float) $line->overflow_quantity;
            if ($over <= 0) {
                continue;
            }

            $price     = (float) $line->unit_price;
            $amount   += $price * $over;
            $quantity += $over;

            $yearly = $line->sku_id !== null ? ($yearlyPrice[$line->sku_id] ?? null) : null;
            if ($yearly === null) {
                $without++;
                continue;
            }

            $savings += max(0.0, $price - $yearly) * $over;
        }

        return [
            'savings'              => round($savings, 2),
            'overflow_amount'      => round($amount, 2),
            'overflow_quantity'    => round($quantity, 2),
            'without_yearly_price' => $without,
        ];
    }

    /**
     * Kündigungs-Radar: Jahresbindungen, deren Kündigungsfenster in den nächsten 90 Tagen liegt — und
     * getrennt die Jahresbindungen OHNE gepflegtes Datum, damit die Pflegelücke sichtbar bleibt statt in
     * der Zahl zu verschwinden. Monatsbindungen sind jederzeit kündbar und zählen nicht.
     *
     * @param  Collection<int, AssetLicenseContractLine>  $lines
     * @return array{soon_ids: list<int>, soon_count: int, soon_amount: float, missing_ids: list<int>, missing_count: int}
     */
    public static function cancellationRadar(Collection $lines, CarbonInterface $today): array
    {
        $from = $today->copy()->startOfDay();
        $to   = $today->copy()->addDays(self::CANCEL_WINDOW_DAYS)->endOfDay();

        $yearly = $lines->filter(fn (AssetLicenseContractLine $l) => $l->binding === AssetLicenseContractLine::BINDING_YEARLY
            && $l->match_state !== AssetLicenseContractLine::MATCH_IGNORED);

        $soon = $yearly->filter(fn (AssetLicenseContractLine $l) => $l->cancellable_at !== null
            && $l->cancellable_at->betweenIncluded($from, $to));

        $missing = $yearly->filter(fn (AssetLicenseContractLine $l) => $l->cancellable_at === null);

        return [
            'soon_ids'      => $soon->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'soon_count'    => $soon->count(),
            'soon_amount'   => round($soon->sum(fn (AssetLicenseContractLine $l) => $l->monthlyAmount()), 2),
            'missing_ids'   => $missing->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'missing_count' => $missing->count(),
        ];
    }
}
