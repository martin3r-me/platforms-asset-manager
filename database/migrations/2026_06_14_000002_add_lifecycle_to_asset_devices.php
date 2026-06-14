<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Geräte-Lifecycle als manuelle Overlay-Schicht über dem reinen Intune-Spiegel: Status
     * (in Betrieb / Reserve / Reparatur / ausgemustert / verloren), Garantie- und Leasing-Ende,
     * Beschaffung (Lieferant, Bestell-Nr./-datum) und Standort. Bleibt beim Sync erhalten — der
     * Intune-Sync schreibt via mapDevice() ausschließlich Graph-Felder und fasst diese Spalten nie an.
     */
    public function up(): void
    {
        Schema::table('asset_devices', function (Blueprint $table) {
            $table->string('lifecycle_status')->nullable()->after('notes'); // in_use|spare|repair|retired|lost
            $table->date('warranty_until')->nullable()->after('lifecycle_status');
            $table->date('lease_until')->nullable()->after('warranty_until');
            $table->unsignedBigInteger('vendor_id')->nullable()->after('lease_until');
            $table->string('order_no')->nullable()->after('vendor_id');
            $table->date('order_date')->nullable()->after('order_no');
            $table->string('location')->nullable()->after('order_date');

            $table->foreign('vendor_id')->references('id')->on('asset_vendors')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('asset_devices', function (Blueprint $table) {
            $table->dropForeign(['vendor_id']);
            $table->dropColumn([
                'lifecycle_status', 'warranty_until', 'lease_until',
                'vendor_id', 'order_no', 'order_date', 'location',
            ]);
        });
    }
};
