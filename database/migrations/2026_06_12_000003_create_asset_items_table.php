<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('asset_categories');
            $table->string('source')->default('manual'); // 'manual'|'intune'
            $table->string('external_id')->nullable();   // Intune ID etc.
            $table->string('name');
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable();
            $table->unsignedBigInteger('assignee_id')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->string('status')->default('in_stock'); // in_stock|assigned|retired|lost
            $table->text('notes')->nullable();

            // Kosten (Phase 4 vorbereitet)
            $table->decimal('purchase_price', 10, 2)->nullable();
            $table->date('purchase_date')->nullable();
            $table->unsignedInteger('depreciation_months')->nullable();

            $table->json('raw_data')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['team_id', 'status']);
            $table->index(['team_id', 'assignee_id']);
            $table->index(['team_id', 'category_id']);
            $table->index(['team_id', 'source']);
            // (team_id, source, external_id): bewusst nur Index, KEIN Unique. Es wäre der Naturschlüssel
            // einer asset_items↔asset_devices-Brücke (source='intune'), die jedoch inert ist (kein Code legt
            // intune-Items an — siehe project_inventory_unification / NP2). Solange diese Brücke tot ist,
            // wird kein Unique benötigt; erst beim Aktivieren der Brücke wäre er nachzuziehen.
            $table->index(['team_id', 'source', 'external_id']);

            $table->foreign('assignee_id')->references('id')->on('asset_employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_items');
    }
};
