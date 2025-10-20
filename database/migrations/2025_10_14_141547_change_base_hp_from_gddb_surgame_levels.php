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
        Schema::table('gddb_surgame_levels', function (Blueprint $table) {
            // base_hp 最大值可以為100000000000000000
            $table->unsignedBigInteger('base_hp')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gddb_surgame_levels', function (Blueprint $table) {
            $table->integer('base_hp')->change();
        });
    }
};
