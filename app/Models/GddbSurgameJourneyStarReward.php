<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GddbSurgameJourneyStarReward extends Model
{
    protected $table = 'gddb_surgame_journey_star_rewards';

    public $timestamps = false;

    protected $fillable = [
        'unique_id',
        'type',
        'star_count',
        'rewards',
    ];

    protected $casts = [
        'star_count' => 'integer',
    ];

    /**
     * 解析獎勵內容
     *
     * @return array
     */
    public function getRewardsAttribute($value)
    {
        if (is_string($value)) {
            // 嘗試 decode
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }

            // 如果不是合法 JSON，就手動處理
            $fixed = '['.$value.']'; // 變成 [[170,2],[100,100]]

            return json_decode($fixed, true) ?? [];
        }

        return $value;
    }

    /**
     * 根據類型和星數查詢獎勵
     *
     * @param  string  $type  類型
     * @param  int  $starCount  星數
     * @return self|null
     */
    public static function findByTypeAndStars($type, $starCount)
    {
        return self::where([
            'type' => $type,
            'star_count' => $starCount,
        ])->first();
    }
}
