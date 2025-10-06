<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GddbSurgameTreasure extends Model
{
    protected $table = 'gddb_surgame_treasure';

    public $timestamps = true;

    protected $fillable = [
        'item_id',
        'unique_id',
        'name',
        'quality_level',
        'show_label',
        'element',
        'target_hero',
        'atk_bonus',
        'hp_bonus',
        'def_bonus',
        'description',
        'func_card',
        'fuse_material_type',
        'need_two_material',
        'atk_add_p',
        'hp_add_p',
        'material_item_id',
        'fuse_material_item_id',
        'fuse_common_material_item_id',
    ];

    protected $casts = [
        'atk_bonus' => 'decimal:2',
        'hp_bonus' => 'decimal:2',
        'def_bonus' => 'decimal:2',
        'atk_add_p' => 'decimal:2',
        'hp_add_p' => 'decimal:2',
        'element' => 'integer',
        'target_hero' => 'integer',
        'need_two_material' => 'integer',
    ];

    /**
     * 取得關聯的目標英雄
     */
    public function targetHero()
    {
        return $this->belongsTo(GddbSurgameHeroes::class, 'target_hero', 'id');
    }

    /**
     * 道具item
     */
    public function item()
    {
        return $this->belongsTo(GddbItems::class, 'item_id', 'item_id');
    }

}
