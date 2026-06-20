<?php

namespace Platform\AssetManager\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Platform\AssetManager\Models\AssetEmployee;

/**
 * @extends Factory<AssetEmployee>
 */
class AssetEmployeeFactory extends Factory
{
    protected $model = AssetEmployee::class;

    public function definition(): array
    {
        // team_id/tenant_id sind die Skopierungs-Achsen — vom Test gesetzt (->for(...) / ['team_id'=>…]).
        // Platzhalter 1, damit ::factory()->make() ohne Overrides nicht an NOT-NULL scheitert.
        $upn = $this->faker->unique()->safeEmail();

        return [
            'team_id'             => 1,
            'tenant_id'           => 1,
            'user_principal_name' => $upn,
            'display_name'        => $this->faker->name(),
            'email'               => $upn,
            'department'          => $this->faker->randomElement(['IT', 'Vertrieb', 'Buchhaltung', null]),
            'cost_center'         => null,
            'cost_center_id'      => null,
            'job_title'           => $this->faker->jobTitle(),
            'is_active'           => true,
            'account_type'        => null,
            'source'              => 'derived',
            'graph_id'            => null,
            'raw_data'            => null,
            'synced_at'           => null,
        ];
    }

    /** Inaktiver (ausgeschiedener) Mitarbeiter. */
    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /** Synthetisches Funktionskonto (kein echter Mitarbeiter). */
    public function functionAccount(): static
    {
        return $this->state(fn () => ['account_type' => 'function']);
    }
}
