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
        Schema::create('gddb_surgame_treasure', function (Blueprint $table) {
            $table->id();
            $table->integer('item_id')->nullable()->comment('對應道具ID');
            $table->integer('unique_id')->unique()->comment('寶物ID');
            $table->string('name')->comment('寶物名稱');
            $table->integer('quality')->nullable()->comment('品質等級');
            $table->string('show_label')->nullable()->comment('顯示標籤');
            $table->integer('element')->nullable()->comment('屬性元素');
            $table->integer('target_hero')->nullable()->comment('目標英雄');
            $table->decimal('atk_bonus', 8, 2)->default(0)->comment('攻擊力加成');
            $table->decimal('hp_bonus', 8, 2)->default(0)->comment('生命值加成');
            $table->decimal('def_bonus', 8, 2)->default(0)->comment('防禦力加成');
            $table->text('description')->nullable()->comment('描述');
            $table->string('func_card')->nullable()->comment('功能卡片');
            $table->string('fuse_material_type')->nullable()->comment('融合材料類型');
            $table->integer('fuse_material_id')->default(0)->comment('融合材料ID');
            $table->integer('need_two_material')->default(0)->comment('是否需要兩種材料');
            $table->decimal('atk_add_p', 5, 2)->default(0)->comment('攻擊力加成百分比');
            $table->decimal('hp_add_p', 5, 2)->default(0)->comment('生命值加成百分比');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gddb_surgame_treasure');
    }
};
