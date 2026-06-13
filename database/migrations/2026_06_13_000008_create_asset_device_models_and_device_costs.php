<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Geräte-Modelle: Default-Kosten je (Hersteller, Modell). Leasing-Rate ODER Kauf+AfA.
        Schema::create('asset_device_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->decimal('monthly_cost', 12, 2)->nullable();      // Leasing-Rate pro Monat
            $table->decimal('purchase_price', 12, 2)->nullable();    // Kaufpreis (AfA)
            $table->unsignedInteger('depreciation_months')->nullable();
            $table->unsignedBigInteger('cost_type_id')->nullable();
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'manufacturer', 'model']);
            $table->foreign('cost_type_id')->references('id')->on('asset_cost_types')->nullOnDelete();
            $table->foreign('vendor_id')->references('id')->on('asset_vendors')->nullOnDelete();
        });

        // Override-Kosten direkt am einzelnen Gerät (Vorrang vor dem Modell-Default).
        Schema::table('asset_devices', function (Blueprint $table) {
            $table->decimal('monthly_cost', 12, 2)->nullable()->after('serial_number');
            $table->decimal('purchase_price', 12, 2)->nullable()->after('monthly_cost');
            $table->unsignedInteger('depreciation_months')->nullable()->after('purchase_price');
            $table->date('purchase_date')->nullable()->after('depreciation_months');
            $table->unsignedBigInteger('cost_type_id')->nullable()->after('purchase_date');
            $table->unsignedBigInteger('cost_center_id')->nullable()->after('cost_type_id');

            $table->foreign('cost_type_id')->references('id')->on('asset_cost_types')->nullOnDelete();
            $table->foreign('cost_center_id')->references('id')->on('asset_cost_centers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('asset_devices', function (Blueprint $table) {
            $table->dropForeign(['cost_type_id']);
            $table->dropForeign(['cost_center_id']);
            $table->dropColumn(['monthly_cost', 'purchase_price', 'depreciation_months', 'purchase_date', 'cost_type_id', 'cost_center_id']);
        });
        Schema::dropIfExists('asset_device_models');
    }
};
