<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_categories', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('icon')->nullable();
            $table->boolean('is_synced')->default(false);
            $table->unsignedInteger('default_depreciation_months')->nullable();
            $table->unsignedInteger('sort_order')->default(100);
            $table->timestamps();
        });

        // Default-Kategorien
        DB::table('asset_categories')->insert([
            ['key' => 'laptop',     'name' => 'Laptop',         'icon' => 'heroicon-o-computer-desktop', 'is_synced' => true,  'default_depreciation_months' => 36, 'sort_order' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'desktop',    'name' => 'Desktop-PC',     'icon' => 'heroicon-o-computer-desktop', 'is_synced' => true,  'default_depreciation_months' => 48, 'sort_order' => 20, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'mobile',     'name' => 'Smartphone',     'icon' => 'heroicon-o-device-phone-mobile', 'is_synced' => true,  'default_depreciation_months' => 24, 'sort_order' => 30, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'tablet',     'name' => 'Tablet',         'icon' => 'heroicon-o-device-tablet',    'is_synced' => true,  'default_depreciation_months' => 36, 'sort_order' => 40, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'monitor',    'name' => 'Monitor',        'icon' => 'heroicon-o-tv',               'is_synced' => false, 'default_depreciation_months' => 60, 'sort_order' => 50, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'dock',       'name' => 'Docking-Station','icon' => 'heroicon-o-rectangle-stack', 'is_synced' => false, 'default_depreciation_months' => 60, 'sort_order' => 60, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'keyboard',   'name' => 'Tastatur',       'icon' => 'heroicon-o-cube',             'is_synced' => false, 'default_depreciation_months' => 36, 'sort_order' => 70, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'mouse',      'name' => 'Maus',           'icon' => 'heroicon-o-cursor-arrow-rays', 'is_synced' => false, 'default_depreciation_months' => 36, 'sort_order' => 80, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'headset',    'name' => 'Headset',        'icon' => 'heroicon-o-microphone',       'is_synced' => false, 'default_depreciation_months' => 24, 'sort_order' => 90, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'webcam',     'name' => 'Webcam',         'icon' => 'heroicon-o-video-camera',     'is_synced' => false, 'default_depreciation_months' => 36, 'sort_order' => 100, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'printer',    'name' => 'Drucker',        'icon' => 'heroicon-o-printer',          'is_synced' => false, 'default_depreciation_months' => 60, 'sort_order' => 110, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'other',      'name' => 'Sonstiges',      'icon' => 'heroicon-o-cube-transparent','is_synced' => false, 'default_depreciation_months' => 36, 'sort_order' => 999, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_categories');
    }
};
