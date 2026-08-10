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
        // tenant_id ist seit S1 Pflicht (2026_08_07_000004) — die Zeile erbt ihn sonst erst über den
        // Migrations-Backfill vom Kopf. Platzhalter 1, vom Test zu überschreiben.
        return [
            'tenant_id'           => 1,
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
