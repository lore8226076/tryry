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
        Schema::table('gddb_surgame_talent', function (Blueprint $table) {
            $table->dropColumn('name');
            $table->dropColumn('icon');
            $table->dropColumn('description');
            $table->dropColumn('func');

            // 新增manager_id, affected, parament, gain_power
            $table->integer('manager_id')->default(0)->after('parament');
            $table->string('affected')->default(0)->after('manager_id');
            $table->integer('gain_power')->default(0)->after('affected');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gddb_surgame_talent', function (Blueprint $table) {
            $table->string('name')->nullable()->after('lv');
            $table->string('icon')->nullable()->after('name');
            $table->text('description')->nullable()->after('icon');
            $table->string('func')->nullable()->after('description');

            // 移除manager_id, affected, parament, gain_power
            $table->dropColumn('manager_id');
            $table->dropColumn('affected');
            $table->dropColumn('gain_power');
        });
    }
};
