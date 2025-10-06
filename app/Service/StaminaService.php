<?php

namespace App\Service;

use App\Models\Users;
use App\Models\UserStaminaLog;
use App\Models\UserStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class StaminaService
{
    const RECOVER_SECONDS = 600; // 每次恢復體力的秒數 (3分鐘)

    const REDIS_LOCK_SECONDS = 2;   // Redis鎖定時間，避免同時更新

    // 體力預設上限, 體力購買回復, 體力購買價格, 購買道具的貨幣id 100, 購買的道具id 200
    const STAMINA_MAX = 50;

    const STAMINA_PURCHASE_RECOVER = 50;

    const STAMINA_PURCHASE_PRICE = 30;

    const STAMINA_PURCHASE_CURRENCY_ITEM_ID = 100;

    const STAMINA_PURCHASE_ITEM_ID = 200;

    /**
     * 取得目前體力狀態（直接查UserStatus＋自動回復判斷）
     */
    public static function getStamina($uid, $fullData = false)
    {
        $status = UserStatus::where('uid', $uid)->first();
        if (! $status) {
            $status = self::initStamina($uid);
        }

        $now = now();
        $current = $status->stamina;
        $maxStamina = $status->stamina_max;
        $nextRecoverAt = $status->next_recover_at ? $status->next_recover_at->timestamp : null;

        // 新增掃蕩次數
        $sweepCount = $status->sweep_count;
        $sweepMax = $status->sweep_max;

        if ($current >= $maxStamina) {
            $recoverCount = 0;
            $shouldRecover = 0;
            $nextRecoverAt = null;
        } else {
            if (! $nextRecoverAt) {
                $nextRecoverAt = $now->timestamp;
            }
            if ($nextRecoverAt > $now->timestamp) {
                $recoverCount = 0;
                $shouldRecover = 0;
            } else {
                $passedSeconds = $now->timestamp - $nextRecoverAt;
                $recoverCount = intdiv($passedSeconds, self::RECOVER_SECONDS);
                $shouldRecover = min($recoverCount, $maxStamina - $current);
                if (now()->timestamp > $nextRecoverAt) {
                    $current += 1;
                }
                $current += $shouldRecover;
                if ($current >= $maxStamina) {
                    $current = $maxStamina;
                    $nextRecoverAt = null;
                } else {
                    $remainSeconds = $passedSeconds - $shouldRecover * self::RECOVER_SECONDS;
                    $nextRecoverAt = $now->timestamp - $remainSeconds + self::RECOVER_SECONDS;
                }

            }
        }

        // 計算下次回復剩餘時間
        $nextRecoverLeftSeconds = $nextRecoverAt ? max(0, $nextRecoverAt - $now->timestamp) : 0;

        $data = [
            'current' => $current,
            'next_recover_at' => $nextRecoverAt,
            'get_info_timestamp' => $now->timestamp,
            'max_stamina' => $maxStamina,
            'sweep_count' => $sweepCount,
            'sweep_max' => $sweepMax,
            'recover_seconds' => self::RECOVER_SECONDS,
            'next_recover_left_seconds' => $nextRecoverLeftSeconds,
        ];
        if ($fullData) {
            $data['need_recover'] = $shouldRecover;
            $data['before'] = $status->stamina;
        }

        return $data;
    }

    /**
     * 同步並更新體力狀態（自然回復才會寫入）
     */
    public static function syncStamina($uid)
    {
        $status = UserStatus::where('uid', $uid)->first();
        if (! $status) {
            $status = self::initStamina($uid);
        }

        if ($status->stamina >= $status->stamina_max) {
            // next_recover_at 要強制設為 null
            if ($status->next_recover_at !== null) {
                $status->next_recover_at = null;
                $status->save();
            }

            return $status->stamina;
        }

        $stamina = self::getStamina($uid, true);
        $recover = $stamina['need_recover'];
        $before = $status->stamina;
        $after = $before + $recover;
        $maxStamina = $status->stamina_max;
        $nextRecoverAt = $stamina['next_recover_at'];

        if ($after < $maxStamina && $nextRecoverAt === null) {
            $nextRecoverAt = now()->addSeconds(self::RECOVER_SECONDS);
        }

        // 有自然回復才寫入（Log跟Status都要動）
        if ($recover > 0) {
            self::logStaminaChange(
                $uid,
                $recover,
                $before,
                $after,
                null,
                '自然回復',
                'auto',
                $nextRecoverAt
            );
            $status->stamina = $after;
            $status->next_recover_at = $nextRecoverAt ? Carbon::createFromTimestamp($nextRecoverAt) : null;
            $status->save();
        }

        return $after;
    }

    /**
     * 主動扣除/增加體力
     */
    public static function changeStamina($uid, $change, $remark = '', $type = 'manual', $stageId = null)
    {
        try {
            return DB::transaction(function () use ($uid, $change, $remark, $type, $stageId) {
                $status = UserStatus::where('uid', $uid)->lockForUpdate()->first();
                if (! $status) {
                    $status = self::initStamina($uid);
                    $status = UserStatus::where('uid', $uid)->lockForUpdate()->first();
                }

                $now = Carbon::now();
                $before = (int) $status->stamina;
                $maxStamina = (int) $status->stamina_max;
                $after = $before + (int) $change;

                // 非 auto 的扣款不能變負
                if ($after < 0 && $type !== 'auto') {
                    \Log::error('主動扣除體力失敗', compact('uid', 'change', 'before') + ['after' => $after]);

                    return false;
                }

                // 底線：不可小於 0
                $after = max($after, 0);

                // 非auto的其他類型（購買/補給）可超上限
                if ($type === 'auto') {
                    $after = min($after, $maxStamina);
                }

                // 若扣到低於上限 啟動回復計時
                if ($change < 0 && $after < $maxStamina && $status->next_recover_at === null) {
                    $status->next_recover_at = $now->copy()->addSeconds(self::RECOVER_SECONDS);
                }

                // 只要到達或超過上限 清除回復計時
                if ($after >= $maxStamina) {
                    $status->next_recover_at = null;
                }

                // 紀錄
                self::logStaminaChange(
                    $uid,
                    (int) $change,
                    $before,
                    $after,
                    $stageId,
                    $remark,
                    $type,
                    $status->next_recover_at
                );

                // 寫入
                $status->stamina = $after;
                $status->save();

                return $status->stamina;
            });
        } catch (\Throwable $e) {
            \Log::error('主動扣除/增加體力變更失敗: '.$e->getMessage(), ['uid' => $uid]);

            return false;
        }
    }

    /**
     * 寫入體力異動記錄（Log表）
     */
    public static function logStaminaChange($uid, $change, $before, $after, $stageId = null, $remark = '', $type = 'auto', $nextRecoverAt = null)
    {
        // nextRecoverAt 統一格式
        if ($nextRecoverAt instanceof \DateTime) {
            $nextRecoverAt = $nextRecoverAt->format('Y-m-d H:i:s');
        } elseif (is_numeric($nextRecoverAt)) {
            $nextRecoverAt = $nextRecoverAt > 0 ? Carbon::createFromTimestamp($nextRecoverAt)->format('Y-m-d H:i:s') : null;
        } elseif (empty($nextRecoverAt) || $nextRecoverAt == 0 || $nextRecoverAt == '1970-01-01 08:00:00') {
            $nextRecoverAt = null;
        }

        UserStaminaLog::create([
            'uid' => $uid,
            'change_stamina' => $change,
            'before_stamina' => $before,
            'after_stamina' => $after,
            'stage_id' => $stageId,
            'remark' => $remark,
            'type' => $type,
            'next_recover_at' => $nextRecoverAt,
        ]);
    }

    /** 初始化體力資料 */
    public static function initStamina($uid)
    {
        return UserStatus::create([
            'uid' => $uid,
            'stamina' => self::STAMINA_MAX,
            'stamina_max' => self::STAMINA_MAX,
            'next_recover_at' => null,
        ]);
    }

    /** 取得體力資訊 */
    public static function getStaminaInfo($uid)
    {
        // 查詢玩家資訊
        $user = Users::find($uid);
        $status = UserStatus::where('uid', $uid)->first();
        if (empty($status)) {
            $status = self::initStamina($uid);
        }

        $stamina_purchase_recover = $status->stamina_max;
        $stamina_purchase_price = self::STAMINA_PURCHASE_PRICE;

        return [
            'stamina_purchase_recover' => $stamina_purchase_recover,
            'stamina_purchase_price' => $stamina_purchase_price,
            'stamina_purchase_currency_item_id' => self::STAMINA_PURCHASE_CURRENCY_ITEM_ID,
            'stamina_purchase_item_id' => self::STAMINA_PURCHASE_ITEM_ID,
        ];
    }

    // 將體力道具轉換成實際體力
    public static function convertStamina($uid, $qty)
    {
        try {
            $user = Users::find($uid);
            $status = UserStatus::where('uid', $uid)->first();
            if (empty($status)) {
                $status = self::initStamina($uid);
            }

            $beforeStamina = $status->stamina;

            // 更新體力與最大值
            $status->stamina += $qty;
            $status->save();

            if ($status->stamina > $status->stamina_max) {
                $status->next_recover_at = null;
                $status->save();
            }

            // 寫入體力異動記錄
            self::logStaminaChange(
                $uid,
                $qty,
                $beforeStamina,
                $status->stamina,
                null,
                '體力道具轉換',
                'manual',
                null
            );
        } catch (\Throwable $e) {
            \Log::error('體力道具轉換失敗', [
                'uid' => $uid,
                'qty' => $qty,
                'error' => $e->getMessage(),
            ]);

            return ['success' => 0, 'error_code' => 'STAMINA:0003'];
        }

        return ['success' => 1, 'error_code' => ''];
    }

    /**
     * 指定扣除體力
     *
     * @param  int  $uid  玩家ID
     * @param  int  $amount  要扣除的體力數量 (正數)
     * @param  string  $remark  備註
     */
    public static function deductStamina($uid, $amount, $remark = '體力消耗')
    {
        $cost = (int) $amount;
        if ($cost <= 0) {
            return ['success' => 0, 'error_code' => 'STAMINA:0002'];
        }

        // 1. 先同步體力 (補上自然恢復的體力)
        self::syncStamina($uid);

        // 2. 取得同步後的最新體力值
        $stamina = self::getStamina($uid);
        $current = $stamina['current'];

        // 3. 檢查體力是否足夠
        if ($current < $cost) {
            return ['success' => 0, 'error_code' => 'STAMINA:0001']; // 體力不足
        }

        // 4. 執行扣除
        self::changeStamina($uid, -$cost, $remark, 'manual');

        return ['success' => 1, 'error_code' => ''];
    }
}
