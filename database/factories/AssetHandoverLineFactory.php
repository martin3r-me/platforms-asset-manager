<?php

namespace Platform\AssetManager\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Platform\AssetManager\Models\AssetHandoverLine;

/**
 * @extends Factory<AssetHandoverLine>
 */
class AssetHandoverLineFactory extends Factory
{
    protected $model = AssetHandoverLine::class;

    public function definition(): array
    {
        return [
            'handover_id'         => null,
            'asset_device_id'     => null,
            'accessories'         => [],
            'notes'               => null,
            'returned_at'         => null,
            'return_condition'    => null,
            'returned_by_user_id' => null,
            'device_snapshot'     => null,
            'status'              => AssetHandoverLine::STATUS_ISSUED,
        ];
    }

    /** Zeile als zurückgegeben markieren. */
    public function returned(): static
    {
        return $this->state(fn () => [
            'returned_at' => now()->toDateString(),
            'status'      => AssetHandoverLine::STATUS_RETURNED,
        ]);
    }
}
