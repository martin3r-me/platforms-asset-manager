<?php

namespace Platform\AssetManager\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Platform\AssetManager\Models\AssetTenant;

/**
 * @extends Factory<AssetTenant>
 */
class AssetTenantFactory extends Factory
{
    protected $model = AssetTenant::class;

    public function definition(): array
    {
        return [
            'team_id'    => 1,
            'name'       => $this->faker->company(),
            'is_default' => false,
        ];
    }

    /** Default-Tenant des Teams (Backfill-/Import-Ziel; genau einer je Team). */
    public function default(): static
    {
        return $this->state(fn () => ['is_default' => true]);
    }
}
