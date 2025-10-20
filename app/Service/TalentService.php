<?php

namespace App\Service;

use App\Models\GddbSurgameTalentDraw;
use App\Models\UserTalentPoolSession;
use App\Models\UserTalentSessionLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TalentService
{
    // 抽取天賦
    public function executeDraw($uid, $sessionId, $talents = [])
    {
        try {
            DB::beginTransaction();

            // 抽獎
            $itemCode = $this->drawTalent($uid, $talents);
            if ($itemCode === null) {
                DB::rollBack();

                return [
                    'success' => 0,
                    'error_code' => 'TALENT:0003',
                ];
            }

            // 扣除獎池天賦
            $deductResult = $this->deductTalentPool($uid, $sessionId, $itemCode);
            if (! $deductResult) {
                DB::rollBack();

                return [
                    'success' => 0,
                    'error_code' => 'TALENT:0007',
                ];
            }

            // 紀錄抽獎結果
            $logResult = $this->logDrawnTalent($uid, $sessionId, $itemCode);
            if (! $logResult) {
                DB::rollBack();

                return [
                    'success' => 0,
                    'error_code' => 'TALENT:0004',
                ];
            }

            DB::commit();

            return [
                'success' => 1,
                'data' => $itemCode,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('抽獎流程失敗', [
                'uid' => $uid,
                '錯誤訊息' => $e->getMessage(),
            ]);

            return [
                'success' => 0,
                'error_code' => 'TALENT:0007',
            ];
        }
    }

    public function drawTalent($uid, $talents = [])
    {
        if (count($talents) === 0) {
            return null;
        }
        $key = array_rand($talents); // 隨機拿 key

        return $talents[$key];
    }

    // 扣除對應獎池的天賦
    public function deductTalentPool($uid, $sessionId, $itemCode)
    {
        $talentPool = UserTalentPoolSession::where([
            'uid' => $uid,
            'id' => $sessionId,
            'status' => 'active',
        ])->first();
        if (! $talentPool) {
            return false;
        }
        if ($talentPool->status === 'completed') {
            return false;
        }
        $currentRemaining = $talentPool->current_remaining;
        if (! is_array($currentRemaining) || count($currentRemaining) === 0) {
            return false;
        }
        $itemKey = array_search($itemCode, $currentRemaining);
        if ($itemKey === false) {
            return false;
        }
        unset($currentRemaining[$itemKey]);
        $currentRemaining = array_values($currentRemaining); // 重建索引
        $updateData = [
            'current_remaining' => $currentRemaining,
        ];
        if (count($currentRemaining) === 0) {
            $updateData['status'] = 'completed';
        }
        try {
            $talentPool->update($updateData);
        } catch (\Exception $e) {
            Log::error('扣除獎池天賦失敗：資料庫錯誤', [
                'uid' => $uid,
                'session_id' => $sessionId,
                'item_code' => $itemCode,
                '錯誤訊息' => $e->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    // 紀錄被抽取天賦
    public function logDrawnTalent($uid, $sessionId, $itemCode)
    {
        try {
            UserTalentSessionLog::create([
                'uid' => $uid,
                'session_id' => $sessionId,
                'item_code' => $itemCode,
            ]);
        } catch (\Exception $e) {
            Log::error('紀錄被抽取天賦失敗：資料庫錯誤', [
                'uid' => $uid,
                'item_code' => $itemCode,
                '錯誤訊息' => $e->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    // 當前可抽取的資源 (等級以下的等級都能抽, 最低層要抽完才能再往上一層)
    public function getAvailableTalent($uid)
    {
        $talentAry = [];
        $uTalentPool = UserTalentPoolSession::where(['uid' => $uid, 'status' => 'active'])->first();
        if ($uTalentPool === null) {
            return null;
        }
        $talentAry = $uTalentPool->current_remaining;
        if (! is_array($talentAry) || count($talentAry) === 0) {
            return null;
        }
        $talentSessionId = $uTalentPool->id;

        return [
            'session_id' => $talentSessionId,
            'items' => $talentAry,
        ];
    }

    public function createTalentPool($uid, $level): array
    {
        $existsUserTalentPool = UserTalentPoolSession::where([
            'uid' => $uid,
            'level_at_bind' => $level,
        ])->first();

        if ($existsUserTalentPool !== null) {
            Log::warning('建立獎池失敗：該等級的獎池已存在', compact('uid', 'level'));

            return [
                'success' => false,
                'error_code' => 'TALENT:0005',
                'message' => '獎池已存在',
            ];
        }

        // 找最低等級的可用池 (且未建立過)
        $drawInfo = GddbSurgameTalentDraw::where('account_lv', '<=', $level)
            ->whereNotIn('account_lv', function ($query) use ($uid) {
                $query->select('level_at_bind')
                    ->from('user_talent_pool_sessions')
                    ->where('uid', $uid);
            })
            ->orderBy('account_lv', 'asc')
            ->first();

        if ($drawInfo === null) {
            Log::error('建立獎池失敗：找不到任何符合的等級資料', compact('uid', 'level'));

            return [
                'success' => false,
                'error_code' => 'TALENT:0006',
                'message' => '沒有符合的等級資料',
            ];
        }

        // 確保卡池是陣列
        $cardPool = $drawInfo->card_pool;
        if (is_string($cardPool)) {
            $cardPool = json_decode($cardPool, true) ?: [];
        }

        if (! is_array($cardPool) || empty($cardPool)) {
            Log::error('建立獎池失敗：卡池內容無效', [
                'uid' => $uid,
                '等級' => $drawInfo->account_lv,
                'card_pool' => $drawInfo->card_pool,
            ]);

            return [
                'success' => false,
                'error_code' => 'TALENT:0007',
                'message' => '卡池內容無效',
            ];
        }

        try {
            UserTalentPoolSession::create([
                'uid' => $uid,
                'talent_draw_id' => $drawInfo->id,
                'level_at_bind' => $drawInfo->account_lv,
                'current_remaining' => $cardPool,
                'status' => 'active',
            ]);
        } catch (\Exception $e) {
            Log::error('建立獎池失敗：資料庫錯誤', [
                'uid' => $uid,
                '等級' => $drawInfo->account_lv,
                '錯誤訊息' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error_code' => 'TALENT:0008',
                'message' => '資料庫錯誤',
            ];
        }

        return [
            'success' => true,
            'level' => $drawInfo->account_lv,
        ];
    }

    // 檢查特定等級的天賦資料
    public function checkTalentPoolExists($uid, $level)
    {
        $existsUserTalentPool = UserTalentPoolSession::where(['uid' => $uid, 'level_at_bind' => $level])->first();

        return $existsUserTalentPool !== null;
    }

    // 取得玩家天賦
    public function getUserTalents($uid)
    {
        $results = [];
        UserTalentSessionLog::where('uid', $uid)
            ->orderBy('created_at', 'DESC')
            ->chunk(100, function ($items) use (&$results) {
                foreach ($items as $item) {
                    $key = $item->item_code;

                    if (! isset($results[$key])) {
                        $results[$key] = [
                            'item_id' => $key,
                            'amount' => 1,
                        ];
                    } else {
                        $results[$key]['amount']++;
                    }
                }
            });

        $results = array_values($results);
        // 排序：R < SR < SSR，且 001~008
        usort($results, function ($a, $b) {
            // 取稀有度 (R / SR / SSR)
            $rarityOrder = ['R' => 1, 'SR' => 2, 'SSR' => 3];

            // 抓出前綴 (R/SR/SSR)
            preg_match('/^(SSR|SR|R)/', $a['item_id'], $matchA);
            preg_match('/^(SSR|SR|R)/', $b['item_id'], $matchB);
            $rarityA = $rarityOrder[$matchA[1]] ?? 999;
            $rarityB = $rarityOrder[$matchB[1]] ?? 999;

            if ($rarityA !== $rarityB) {
                return $rarityA - $rarityB;
            }

            // 抓出數字部分排序
            $numA = intval(preg_replace('/\D/', '', $a['item_id']));
            $numB = intval(preg_replace('/\D/', '', $b['item_id']));

            return $numA - $numB;
        });

        return $results;
    }

    // 檢查是否有玩家當前等級的獎池
    public function checkMaxLevelTalentPool($surgameinfo)
    {
        $uid = $surgameinfo->uid;
        $userMaxLevel = $surgameinfo->main_character_level;
        $dataMaxLevel = GddbSurgameTalentDraw::max('account_lv');

        // 滿級玩家
        if ($userMaxLevel >= $dataMaxLevel) {
            $pool = UserTalentPoolSession::where([
                'uid' => $uid,
                'level_at_bind' => $dataMaxLevel,
            ])->first();

            if ($pool === null) {
                return [
                    'success' => 1,
                    'error_code' => null,
                    'level' => $dataMaxLevel,
                    'status' => 'pending',
                ];
            }

            if ($pool->status === 'completed') {
                return [
                    'success' => 0,
                    'error_code' => 'TALENT:0003', // 滿級池子已完成
                    'level' => $dataMaxLevel,
                    'status' => 'completed',
                ];
            }

            return [
                'success' => 1,
                'error_code' => null,
                'level' => $dataMaxLevel,
                'status' => 'active',
            ];
        }

        // 找 <= 玩家等級的最大等級
        $maxLevel = GddbSurgameTalentDraw::where('account_lv', '<=', $userMaxLevel)
            ->orderBy('account_lv', 'desc')
            ->value('account_lv');

        if ($maxLevel === null) {
            return [
                'success' => 0,
                'error_code' => 'TALENT:0007', // 沒有符合的獎池定義
                'level' => null,
                'status' => 'not_found',
            ];
        }

        $pool = UserTalentPoolSession::where([
            'uid' => $uid,
            'level_at_bind' => $maxLevel,
        ])->first();

        if ($pool === null) {
            return [
                'success' => 1,
                'error_code' => null,
                'level' => $maxLevel,
                'status' => 'pending',
            ];
        }

        if ($pool->status === 'completed') {
            return [
                'success' => 0,
                'error_code' => 'TALENT:0003', // 該池已完成
                'level' => $maxLevel,
                'status' => 'completed',
            ];
        }

        return [
            'success' => 1,
            'error_code' => null,
            'level' => $maxLevel,
            'status' => 'active',
        ];
    }

    // 抽獎結果formatter
    public function formatDrawResult($uid, $itemCode)
    {
        // 查詢目前該獎項在玩家身上數量
        $itemAmt = UserTalentSessionLog::where([
            'uid' => $uid,
            'item_code' => $itemCode,
        ])->count();

        return [
            'item_id' => $itemCode,
            'amount' => $itemAmt,
        ];
    }

    // 取得當前卡池花費
    public function getCurrentPoolCost($accountLv = 1)
    {
        $talentDraw = GddbSurgameTalentDraw::where('account_lv', $accountLv)->first();
        if ($talentDraw === null) {
            return null;
        }

        return ['cost' => $talentDraw->cost, 'amount' => $talentDraw->amount];
    }

    // 檢查玩家是否已經最高等級
    public function isUserMaxLevel($surgameinfo)
    {
        $userMaxLevel = $surgameinfo->main_character_level;
        $dataMaxLevel = GddbSurgameTalentDraw::max('account_lv');

        return $userMaxLevel >= $dataMaxLevel;
    }
}
