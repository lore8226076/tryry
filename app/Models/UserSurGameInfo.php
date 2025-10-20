<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserSurGameInfo extends Model
{
    use HasFactory;

    protected $table = 'user_surgame_infos';

    protected $fillable = [
        'uid',
        'main_character_level',
        'current_exp',
        'grade_level',
    ];

    protected $appends = ['main_chapter'];

    protected $hidden = [
        'id',
        'created_at',
        'updated_at',
    ];

    /**
     * 為新用戶創建初始遊戲資料
     */
    public static function createInitialData($uid)
    {
        return self::create([
            'uid' => $uid,
            'main_character_level' => 1,
            'current_exp' => 0,
            'grade_level' => 1,
        ]);
    }

    public function getMainChapterAttribute()
    {
        $record = UserJourneyRecord::where('uid', $this->uid)->first();

        return $record?->current_journey_id ?? 1;
    }

    public function user()
    {
        return $this->belongsTo(Users::class, 'uid', 'uid');
    }

    public function gddbSurgameGrade()
    {
        return $this->belongsTo(GddbSurgameGrade::class, 'grade_level', 'related_level');
    }

    public function talentSessions()
    {
        return $this->hasMany(UserTalentPoolSession::class, 'uid', 'uid');
    }

    public function slotEquipments()
    {
        return $this->hasMany(UserSlotEquipment::class, 'uid', 'uid');
    }
}
