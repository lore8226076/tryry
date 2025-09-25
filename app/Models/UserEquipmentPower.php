<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Users;
use App\Models\UserEquipmentSession;
use App\Models\UserSurGameInfo;

class UserEquipmentPower extends Model
{
    protected $table = 'user_equipment_powers';

    protected $fillable = [
        'uid',
        'equipment_id',
        'position',
        'power',
    ];

    /**
     * 取得關聯的使用者
     */
    public function user()
    {
        return $this->belongsTo(Users::class, 'uid', 'id');
    }

    /**
     * 取得關聯的裝備
     */
    public function equipment()
    {
        return $this->belongsTo(UserEquipmentSession::class, 'equipment_id', 'id');
    }

    /**
     * 取得關聯的角色資訊
     */
    public function surgameInfo()
    {
        return $this->belongsTo(UserSurGameInfo::class, 'uid', 'uid');
    }
}
