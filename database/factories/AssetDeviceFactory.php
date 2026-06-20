<?php

namespace Platform\AssetManager\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Platform\AssetManager\Models\AssetDevice;

/**
 * @extends Factory<AssetDevice>
 */
class AssetDeviceFactory extends Factory
{
    protected $model = AssetDevice::class;

    public function definition(): array
    {
        return [
            'team_id'             => 1,
            'tenant_id'           => 1,
            'azure_tenant_id'     => null,
            'intune_id'           => $this->faker->unique()->uuid(),
            'source'              => 'intune',
            'device_name'         => strtoupper($this->faker->bothify('NB-####')),
            'user_display_name'   => $this->faker->name(),
            'user_principal_name' => $this->faker->unique()->safeEmail(),
            'operating_system'    => 'Windows',
            'os_version'          => '10.0.19045',
            'compliance_state'    => 'compliant',
            'management_state'    => 'managed',
            'device_type'         => 'company',
            'manufacturer'        => $this->faker->randomElement(['LENOVO', 'HP', 'Dell']),
            'model'               => $this->faker->bothify('Model-??##'),
            'serial_number'       => strtoupper($this->faker->bothify('SN########')),
            // Kostenfelder bewusst leer — Tests, die Geräte-Kosten prüfen, setzen monthly_cost/purchase_price
            // explizit (nur ein echter >0-Override zählt, siehe AssetDevice::computeMonthlyFrom).
            'monthly_cost'        => null,
            'purchase_price'      => null,
            'depreciation_months' => null,
            'purchase_date'       => null,
            'cost_type_id'        => null,
            'cost_center_id'      => null,
            'notes'               => null,
            'lifecycle_status'    => 'in_use',
            'warranty_until'      => null,
            'lease_until'         => null,
            'vendor_id'           => null,
            'order_no'            => null,
            'order_date'          => null,
            'location'            => null,
            'is_encrypted'        => true,
            'enrollment_type'     => null,
            'free_storage_bytes'  => null,
            'total_storage_bytes' => null,
            'physical_memory_bytes' => null,
            'enrolled_at'         => now()->subMonths(6),
            'last_check_in_at'    => now()->subDay(),
            'raw_data'            => null,
        ];
    }

    /** Leasing-Rate als Monatskosten-Override setzen (>0, sonst kein Override). */
    public function withMonthlyCost(float $amount): static
    {
        return $this->state(fn () => ['monthly_cost' => $amount]);
    }
}
