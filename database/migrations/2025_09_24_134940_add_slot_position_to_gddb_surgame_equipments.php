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
        Schema::table('gddb_surgame_equipment', function (Blueprint $table) {
            $table->integer('slot_position')->default(0)->comment('裝備可裝備位置');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gddb_surgame_equipment', function (Blueprint $table) {
            $table->dropColumn('slot_position');
        });
    }
};
