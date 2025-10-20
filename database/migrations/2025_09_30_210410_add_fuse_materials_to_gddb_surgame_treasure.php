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
        Schema::table('gddb_surgame_treasure', function (Blueprint $table) {
            // 移除 fuse_material_id 欄位
            if (Schema::hasColumn('gddb_surgame_treasure', 'fuse_material_id')) {
                $table->dropColumn('fuse_material_id');
            }
            if (Schema::hasColumn('gddb_surgame_treasure', 'quality')) {
                $table->dropColumn('quality');
            }
            // 新增 material_item_id 欄位 (退回材料)
            $table->unsignedBigInteger('quality_level')->after('target_hero')->nullable()->comment('品質等級');
            $table->unsignedBigInteger('material_item_id')->nullable()->after('quality_level')->comment('退回材料item_id');
            // 新增 fuse_material_item_id 欄位 (可用合成材料)
            $table->unsignedBigInteger('fuse_material_item_id')->nullable()->after('material_item_id')->comment('可用合成材料item_id');
            // 新增 fuse_common_material_item_id 欄位 (可替代材料，允許為 null)
            $table->unsignedBigInteger('fuse_common_material_item_id')->nullable()->after('fuse_material_item_id')->comment('可替代材料item_id');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gddb_surgame_treasure', function (Blueprint $table) {
            // 還原 fuse_material_id 欄位
            $table->unsignedBigInteger('fuse_material_id')->nullable()->after('target_hero')->comment('融合材料ID');
            // 刪除新增的欄位
            $table->dropColumn('material_item_id');
            $table->dropColumn('fuse_material_item_id');
            $table->dropColumn('fuse_common_material_item_id');
            $table->dropColumn('quality_level');
        });
    }
};
