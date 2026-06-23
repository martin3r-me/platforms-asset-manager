<?php

namespace Platform\AssetManager\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Platform\AssetManager\Models\AssetHandover;

/**
 * @extends Factory<AssetHandover>
 */
class AssetHandoverFactory extends Factory
{
    protected $model = AssetHandover::class;

    public function definition(): array
    {
        return [
            'team_id'            => 1,
            'tenant_id'          => 1,
            'employee_id'        => null,
            'created_by_user_id' => null,
            'issued_at'          => now()->toDateString(),
            'signer_name'        => null,
            'signature_data'     => null,
            'signed_at'          => null,
            'notes'              => null,
            'status'             => AssetHandover::STATUS_OPEN,
        ];
    }

    /** Mit erfasster Unterschrift (winziges gültiges PNG als base64-Platzhalter). */
    public function signed(): static
    {
        return $this->state(fn () => [
            'signer_name'    => $this->faker->name(),
            'signature_data' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
            'signed_at'      => now(),
        ]);
    }
}
