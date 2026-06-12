<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_employees', function (Blueprint $table) {
            // FK auf die neue Kostenstellen-Entität (String cost_center bleibt als Fallback erhalten)
            $table->unsignedBigInteger('cost_center_id')->nullable()->after('cost_center');
            // person = echter Mitarbeiter, function = Funktionskonto (CONTROLLING, HELPDESK, …)
            $table->string('account_type')->default('person')->after('is_active');

            $table->index(['team_id', 'cost_center_id']);

            $table->foreign('cost_center_id')->references('id')->on('asset_cost_centers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('asset_employees', function (Blueprint $table) {
            $table->dropForeign(['cost_center_id']);
            $table->dropIndex(['team_id', 'cost_center_id']);
            $table->dropColumn(['cost_center_id', 'account_type']);
        });
    }
};
