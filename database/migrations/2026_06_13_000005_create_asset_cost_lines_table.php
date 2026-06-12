<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_cost_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();

            $table->unsignedBigInteger('cost_type_id');
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->unsignedBigInteger('cost_center_id')->nullable();
            $table->unsignedBigInteger('assignee_id')->nullable();   // asset_employees
            $table->unsignedBigInteger('asset_item_id')->nullable(); // asset_items (Drucker/Internet/Laptop)

            $table->string('label');
            $table->decimal('amount', 12, 2)->default(0);           // Originalbetrag in Originalfrequenz/-währung
            $table->char('currency', 3)->default('EUR');
            $table->decimal('fx_rate', 12, 6)->nullable();          // z.B. 0.85 USD→EUR
            $table->string('frequency')->default('monthly');        // monthly|quarterly|yearly|once
            $table->decimal('monthly_amount', 12, 2)->default(0);   // normalisiert in EUR (saving-Hook)

            $table->string('gl_account')->nullable();               // Konto (BPEvent)
            $table->string('gl_contra_account')->nullable();        // Gegenkonto
            $table->char('debit_credit', 1)->nullable();            // S|H
            $table->string('accounting_system')->nullable();        // HGK|Moss
            $table->decimal('distribution_factor', 8, 4)->nullable(); // HGK Verteilfaktor %

            $table->string('source')->default('manual');            // manual|excel_import|graph
            $table->string('period_label')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->boolean('active')->default(true);

            $table->string('import_batch_id')->nullable();
            $table->string('import_hash')->nullable();

            $table->json('raw_data')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['team_id', 'cost_type_id']);
            $table->index(['team_id', 'cost_center_id']);
            $table->index(['team_id', 'assignee_id']);
            $table->index(['team_id', 'asset_item_id']);
            $table->index(['team_id', 'active']);
            $table->index('import_batch_id');
            $table->index('import_hash');

            $table->foreign('cost_type_id')->references('id')->on('asset_cost_types')->cascadeOnDelete();
            $table->foreign('vendor_id')->references('id')->on('asset_vendors')->nullOnDelete();
            $table->foreign('cost_center_id')->references('id')->on('asset_cost_centers')->nullOnDelete();
            $table->foreign('assignee_id')->references('id')->on('asset_employees')->nullOnDelete();
            $table->foreign('asset_item_id')->references('id')->on('asset_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_cost_lines');
    }
};
