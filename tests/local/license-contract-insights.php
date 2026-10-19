<?php

/**
 * Überhang-Ersparnis (#882) und Kündigungs-Radar (#883): {@see \Platform\AssetManager\Support\LicenseContractInsights}.
 *
 * Aufruf: php tests/local/license-contract-insights.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Support\Carbon;
use Platform\AssetManager\Models\AssetLicenseContractLine as Line;
use Platform\AssetManager\Support\LicenseContractInsights;

$line = function (int $id, ?string $sku, string $binding, float $price, float $qty, float $overflow = 0, ?string $cancellable = null, string $match = 'auto'): Line {
    $l = new Line();
    $l->forceFill([
        'id' => $id, 'sku_id' => $sku, 'binding' => $binding, 'unit_price' => $price, 'quantity' => $qty,
        'overflow_quantity' => $overflow, 'cancellable_at' => $cancellable, 'match_state' => $match,
    ]);

    return $l;
};

$lines = collect([
    $line(1, 'E3', Line::BINDING_MONTHLY, 39.60, 10, overflow: 3),   // Jahrespreis 33,00 → 3 × 6,60 = 19,80
    $line(2, 'E3', Line::BINDING_YEARLY, 33.00, 50, cancellable: '2026-12-01'),
    $line(3, 'BP', Line::BINDING_MONTHLY, 26.40, 5, overflow: 2),    // kein Jahrespreis → nicht bestimmbar
    $line(4, 'BB', Line::BINDING_YEARLY, 6.00, 20, cancellable: '2027-08-01'), // außerhalb 90 Tage
    $line(5, 'EX', Line::BINDING_YEARLY, 4.00, 10),                  // Datum fehlt
    $line(6, 'XX', Line::BINDING_YEARLY, 1.00, 1, match: Line::MATCH_IGNORED), // ignoriert
]);

$o = LicenseContractInsights::overflowSavings($lines);
check('Ersparnis = (39,60 − 33,00) × 3', 19.8, $o['savings']);
check('Überhang-Betrag (3 × 39,60 + 2 × 26,40)', 171.6, $o['overflow_amount']);
check('Überhang-Menge', 5.0, $o['overflow_quantity']);
check('Zeilen ohne Jahrespreis gezählt', 1, $o['without_yearly_price']);

$r = LicenseContractInsights::cancellationRadar($lines, Carbon::parse('2026-10-19'));
check('Fenster ≤ 90 Tage: nur Zeile 2', [2], $r['soon_ids']);
check('Betrag der Zeile 2 (33 × 50)', 1650.0, $r['soon_amount']);
check('Datum fehlt: nur Zeile 5 (ignorierte nicht)', [5], $r['missing_ids']);
check('Monatsbindungen zählen nicht', false, in_array(1, array_merge($r['soon_ids'], $r['missing_ids']), true));

check_summary();
