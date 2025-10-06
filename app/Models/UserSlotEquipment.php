<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Users;
use App\Models\UserSurGameInfo;
use App\Models\CharacterDeploySlot;

class UserSlotEquipment extends Model
{
    protected $table = 'user_slot_equipments';

    protected $fillable = [
        'uid',
        'slot_id',
        'position',
        'enhance_level',
        'refine_level',
        'success_rate'
    ];

    /**
     * 關聯到使用者
     */
    public function user()
    {
        return $this->belongsTo(Users::class, 'uid', 'uid');
    }

    /**
     * 關聯到角色資訊
     */
    public function surgameInfo()
    {
        return $this->belongsTo(UserSurGameInfo::class, 'uid', 'uid');
    }

    /**
     * 關聯到角色部署陣位
     */
    public function deploySlot()
    {
        return $this->belongsTo(CharacterDeploySlot::class, 'slot_id', 'id');
    }
}
