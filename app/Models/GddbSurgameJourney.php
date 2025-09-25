<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GddbSurgameJourney extends Model
{
    protected $table = 'gddb_surgame_journeys';

    public $timestamps = false;

    protected $fillable = [
        'unique_id',
        'name',
        'stage_id',
        'over_power',
    ];

    /**
     * 取得關聯的獎勵
     */
    public function rewards()
    {
        return $this->hasOne(GddbSurgameJourneyReward::class, 'journey_id', 'unique_id');
    }

    /**
     * 取得關聯的關卡
     */
    public function stage()
    {
        return $this->belongsTo(GddbSurgameStages::class, 'stage_id', 'id');
    }

    /**
     * 玩家的章節記錄
     */
    public function userRecords()
    {
        return $this->hasMany(UserJourneyRecord::class, 'current_journey_id', 'unique_id');
    }
}
