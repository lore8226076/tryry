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
        // 如果有此欄位才移除
        if (Schema::hasColumn('user_maps', 'view_count')) {
            Schema::table('user_maps', function (Blueprint $table) {
                $table->dropColumn('view_count');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_maps', function (Blueprint $table) {
            $table->integer('view_count')->default(0);
        });
    }
};
