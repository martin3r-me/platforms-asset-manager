<?php

namespace Platform\AssetManager\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Platform\AssetManager\Models\AssetCostCenter;

/**
 * @extends Factory<AssetCostCenter>
 */
class AssetCostCenterFactory extends Factory
{
    protected $model = AssetCostCenter::class;

    public function definition(): array
    {
        return [
            'team_id'    => 1,
            'company_id' => null,
            // Code ist (team_id, code)-unique → eindeutige numerische Kennung erzeugen.
            'code'       => (string) $this->faker->unique()->numberBetween(1000, 9999),
            'name'       => strtoupper($this->faker->city()),
            'is_active'  => true,
            'sort_order' => 100,
            'notes'      => null,
        ];
    }
}
