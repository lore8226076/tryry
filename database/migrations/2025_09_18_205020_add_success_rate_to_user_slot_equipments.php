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
        Schema::table('user_slot_equipments', function (Blueprint $table) {
            $table->unsignedInteger('success_rate')->default(40)->after('refine_level')->comment('精煉成功率(萬分比)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_slot_equipments', function (Blueprint $table) {
            $table->dropColumn('success_rate');
        });
    }
};
