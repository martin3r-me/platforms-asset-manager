<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_devices', function (Blueprint $table) {
            $table->string('tenant_id')->nullable()->after('team_id');
            $table->string('source')->default('intune')->after('intune_id');
        });
    }

    public function down(): void
    {
        Schema::table('asset_devices', function (Blueprint $table) {
            $table->dropColumn(['tenant_id', 'source']);
        });
    }
};
