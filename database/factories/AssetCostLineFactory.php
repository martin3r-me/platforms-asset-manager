<?php

namespace Platform\AssetManager\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Platform\AssetManager\Models\AssetCostLine;

/**
 * @extends Factory<AssetCostLine>
 */
class AssetCostLineFactory extends Factory
{
    protected $model = AssetCostLine::class;

    public function definition(): array
    {
        // monthly_amount NICHT setzen — wird vom saving-Hook (computeMonthlyAmount) aus amount/fx_rate/frequency
        // abgeleitet. cost_type_id vom Test setzen (->for(AssetCostType, 'costType') oder ['cost_type_id'=>…]).
        return [
            'team_id'             => 1,
            'cost_type_id'        => 1,
            'vendor_id'           => null,
            'cost_center_id'      => null,
            'assignee_id'         => null,
            'asset_item_id'       => null,
            'label'               => $this->faker->sentence(3),
            'amount'              => $this->faker->randomFloat(2, 5, 500),
            'currency'            => 'EUR',
            'fx_rate'             => null,
            'frequency'           => 'monthly',
            'gl_account'          => null,
            'gl_contra_account'   => null,
            'debit_credit'        => null,
            'accounting_system'   => null,
            'distribution_factor' => null,
            'source'              => 'manual',
            'period_label'        => null,
            'valid_from'          => null,
            'valid_to'            => null,
            'active'              => true,
            'import_batch_id'     => null,
            'import_hash'         => null,
            'raw_data'            => null,
        ];
    }

    /** Aus dem Excel-Import stammende Zeile mit gesetztem Hash (für Idempotenz-/Prune-Tests). */
    public function imported(string $hash, string $batchId = 'excel-bootstrap'): static
    {
        return $this->state(fn () => [
            'source'          => 'excel_import',
            'import_hash'     => $hash,
            'import_batch_id' => $batchId,
        ]);
    }

    /** Einmalkosten (frequency=once → monthly_amount=0). */
    public function once(): static
    {
        return $this->state(fn () => ['frequency' => 'once']);
    }
}
