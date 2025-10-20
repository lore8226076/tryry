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
        if (Schema::hasColumn('gddb_surgame_player_lv_up', 'reward')) {
            Schema::table('gddb_surgame_player_lv_up', function (Blueprint $table) {
                $table->dropColumn('reward');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gddb_surgame_player_lv_up', function (Blueprint $table) {
            $table->text('reward')->nullable()->after('xp');
        });
    }
};
