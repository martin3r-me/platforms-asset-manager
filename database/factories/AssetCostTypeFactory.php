<?php

namespace Platform\AssetManager\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Platform\AssetManager\Models\AssetCostType;

/**
 * @extends Factory<AssetCostType>
 */
class AssetCostTypeFactory extends Factory
{
    protected $model = AssetCostType::class;

    public function definition(): array
    {
        return [
            'team_id'            => 1,
            'key'                => $this->faker->unique()->slug(2),
            'name'               => $this->faker->words(2, true),
            'sort_order'         => 100,
            'vendor_default_id'  => null,
            'system_default'     => null,
            'frequency_default'  => 'monthly',
            'is_per_employee'    => false,
            // Default-Quelle: manuelle Kostenposition (cost_line). Doppelzählungs-Invariante:
            // genau EINE aggregation_source je Kostenart (ADR 0001).
            'aggregation_source' => AssetCostType::SOURCE_COST_LINE,
            'allow_negative'     => false,
        ];
    }

    /** Kostenart, deren Pivot-Wert aus der Hardware-AfA der asset_items kommt. */
    public function hardwareAfa(): static
    {
        return $this->state(fn () => ['aggregation_source' => AssetCostType::SOURCE_HARDWARE_AFA]);
    }

    /** Kostenart, deren Pivot-Wert aus MS-Lizenz-Zuweisungen × SKU-Preis kommt. */
    public function msLicense(): static
    {
        return $this->state(fn () => ['aggregation_source' => AssetCostType::SOURCE_MS_LICENSE]);
    }

    /** Kostenart, deren Pivot-Wert aus Intune-Geräten (Override → Modell-Default) kommt. */
    public function assetDevice(): static
    {
        return $this->state(fn () => ['aggregation_source' => AssetCostType::SOURCE_ASSET_DEVICE]);
    }

    /** Gutschrift-Kostenart: erlaubt negative Beträge. */
    public function allowNegative(): static
    {
        return $this->state(fn () => ['allow_negative' => true]);
    }
}
