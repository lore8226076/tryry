<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('gddb_surgame_eq_refine')) {
            Schema::dropIfExists('gddb_surgame_eq_refine');
        }

        Schema::create('gddb_surgame_eq_refine', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('lv')->comment('精煉等級');
            $table->unsignedInteger('min_enhance_lv')->default(0)->comment('最低強化等級');
            $table->unsignedInteger('cost')->comment('消耗道具ID');
            $table->unsignedInteger('cost_amount')->default(0)->comment('消耗數量');
            $table->unsignedInteger('success_rate')->default(40)->comment('成功率(百分比)');
            $table->comment('裝備精煉消耗表');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gddb_surgame_eq_refine');
    }
};
