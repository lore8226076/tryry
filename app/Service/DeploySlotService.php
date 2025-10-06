<?php
namespace App\Service;

use App\Models\CharacterDeploySlot;
use App\Models\GddbSurgameEqEnhance; // 陣位等級
use App\Models\GddbSurgameEqRefine;  // 強化
use App\Models\GddbSurgameLevelUps;  //精煉
use App\Models\Users;
use App\Models\UserSlotEquipment;
use App\Service\UserItemService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeploySlotService
{

    protected $userItemService;

    public function __construct(UserItemService $userItemService)
    {
        $this->userItemService = $userItemService;
    }

    // 依據陣位順序取得陣位ID
    public function getSlotIdByPosition($uid, $position)
    {
        return CharacterDeploySlot::where(['uid' => $uid, 'position' => $position])->value('id');
    }

    // 初始化特定陣位與裝備與精鍊等級
    public function initUserSlotEquipment($uid, $slotId = null, $position = null)
    {
        // 只有uid代表完全初始化
        if ($slotId === null || $position === null) {
            $deployData = CharacterDeploySlot::where('uid', $uid)->get();
            foreach ($deployData as $deploy) {
                for ($i = 0; $i < 6; $i++) {
                    try {
                        UserSlotEquipment::updateOrCreate(
                            [
                                'uid'      => $uid,
                                'slot_id'  => $deploy->id,
                                'position' => $i,
                            ],
                            [
                                'refine_level'  => 0,
                                'enhance_level' => 1,
                            ]
                        );
                    } catch (QueryException $e) {
                        Log::error("建立 UserSlotEquipment 失敗", [
                            'uid'      => $uid,
                            'slot_id'  => $deploy->id,
                            'position' => $i,
                            'error'    => $e->getMessage(),
                        ]);
                    } catch (\Exception $e) {
                        Log::critical("未知錯誤：UserSlotEquipment 建立失敗", [
                            'uid'      => $uid,
                            'slot_id'  => $deploy->id,
                            'position' => $i,
                            'error'    => $e->getMessage(),
                        ]);
                    }
                }
            }

        } else {
            try {
                $userEquipment                = new UserSlotEquipment();
                $userEquipment->uid           = $uid;
                $userEquipment->slot_id       = $slotId;
                $userEquipment->position      = $position;
                $userEquipment->enhance_level = 1;
                $userEquipment->refine_level  = 0;
                return $userEquipment->save();
            } catch (\Exception $e) {
                Log::error('[initUserSlotEquipment] 初始化陣位裝備與精鍊等級失敗', [
                    'uid'      => $uid,
                    'slot_id'  => $slotId,
                    'position' => $position,
                    'error'    => $e->getMessage(),
                ]);
                return false;
            }
        }
    }

    //==========陣位等級============
    public static function getLvMaximum($targetLv = 1): bool
    {
        return GddbSurgameLevelUps::where('target_level', '=', $targetLv)
            ->exists();
    }
    // 取得升級材料
    public static function getLvMaterial($targetLv = 1)
    {
        $gddb = GddbSurgameLevelUps::where('target_level', $targetLv)->first();
        if (! $gddb) {
            return null;
        }

        return [
            'base_item_id'      => $gddb->base_item_id,
            'base_item_amount'  => $gddb->base_item_amount,
            'extra_item_id'     => $gddb->extra_item_id ?? 0,
            'extra_item_amount' => $gddb->extra_item_amount ?? 0,
        ];
    }
    // 檢查升級材料是否足夠
    public static function checkLvMaterial(int $targetLv, Users $user = null): bool
    {
        if (! $user) {
            $user = auth()->guard('api')->user();
        }

        if (! $user) {
            return false;
        }

        $lvMaterial = self::getLvMaterial($targetLv);
        if (! $lvMaterial) {
            return false;
        }

        // 1) 先檢查主要道具
        $baseCheck = $this->userItemService->checkResource(
            $user->id,
            (int) $lvMaterial['base_item_id'],
            (int) $lvMaterial['base_item_amount']
        );
        if (($baseCheck['success'] ?? 0) !== 1) {
            return false;
        }

        // 2) 再檢查額外道具（如果有）
        if (! empty($lvMaterial['extra_item_id']) && (int) $lvMaterial['extra_item_amount'] > 0) {
            $extraCheck = $this->userItemService->checkResource(
                $user->id,
                (int) $lvMaterial['extra_item_id'],
                (int) $lvMaterial['extra_item_amount']
            );
            if (($extraCheck['success'] ?? 0) !== 1) {
                return false;
            }
        }

        return true;
    }

    //==========裝備or精煉==============
    // 精煉裝備
    public function refineEquipment(UserSlotEquipment $userEquipment, int $times = 1): array
    {
        if (! $userEquipment || $times <= 0) {
            return ['success' => 0, 'message' => '參數錯誤'];
        }
        // 只支援 1 次或 10 次（十連 = 最多嘗試 10 次）
        $times = ($times === 10) ? 10 : 1;

        try {
            $res = DB::transaction(function () use ($userEquipment, $times) {
                // 行鎖，避免併發同時精煉同一件
                $eq     = UserSlotEquipment::whereKey($userEquipment->id)->lockForUpdate()->first();
                $userId = (int) ($eq->user->id ?? 0);
                if ($userId <= 0) {
                    throw new \RuntimeException('AUTH:0006');
                }

                $tries     = 0;    // 實際扣了幾次材料（=嘗試次數）
                $leveled   = 0;    // 是否成功升 1 級（0/1）
                $finalRate = null; // 交易結束時的儲存成功率（0~100）

                for ($i = 0; $i < $times; $i++) {
                    $targetLv = (int) $eq->refine_level + 1;

                    // 已達上限就正常結束（不拋例外）
                    if (! self::getEquipmentLvMaximum($targetLv, 'refine')) {
                        break;
                    }

                    // 取「下一級」材料
                    $m = $this->getEnhanceOrRefineMaterial($targetLv, 'refine');
                    if (! $m) {
                        throw new \RuntimeException('REFINE:MATERIAL_NOT_FOUND');
                    }

                    // 扣一次材料（失敗丟例外以回滾）
                    $baseId  = (int) ($m['base_item_id'] ?? 0);
                    $baseAmt = (int) ($m['base_item_amount'] ?? 0);
                    if ($baseId > 0 && $baseAmt > 0) {
                        $this->deductOrThrow(73, $userId, $eq->uid, $baseId, $baseAmt, '裝備精煉扣除', 1);
                    }

                    $tries++;

                    $defaultRate = (int) $this->getRefineSuccessRate($targetLv); // 0~100
                    if ($defaultRate <= 0) {
                        throw new \RuntimeException('REFINE:RATE_INVALID');
                    }

                    $storedRate = (int) $this->getCurrentSuccessRate($eq);
                    $rate       = max($defaultRate, min(100, $storedRate ?: $defaultRate));

                    // 若儲存值 < baseline，先回寫 baseline，保持資料一致
                    if ($storedRate !== $rate) {
                        $this->modifyUserSlotSuccessRate($eq, $rate);
                    }

                    // 擲骰（true=成功）
                    if ($this->rollRefineOnce($rate)) {
                        // 成功：升 1 級並重置成功率為「新等級 baseline」
                        if (! $this->updateUserEquipmentRefineLv($eq, 1)) {
                            throw new \RuntimeException('REFINE:UPDATE_FAIL');
                        }
                        $eq->refresh();
                        $userEquipment->refine_level = $eq->refine_level;
                        $leveled                     = 1;

                        $newDefault = (int) $this->getRefineSuccessRate((int) $eq->refine_level);
                        $newDefault = max(0, min(100, $newDefault));
                        $this->modifyUserSlotSuccessRate($eq, $newDefault);
                        $finalRate = $newDefault;
                        break; // 升上去了就停
                    } else {
                        // 失敗：保底 +1%，上限 100，並回寫
                        $nextRate = min(100, $rate + 1);
                        if ($nextRate !== $rate) {
                            $this->modifyUserSlotSuccessRate($eq, $nextRate);
                        }
                        $finalRate = $nextRate;
                        // 繼續下一輪
                    }
                }

                // 交易內回傳給外層
                return [
                    'tries'        => $tries,
                    'leveled'      => $leveled,
                    'new_level'    => (int) $eq->refine_level,
                    'success_rate' => (int) ($finalRate ?? (int) $this->getCurrentSuccessRate($eq) ?: 0),
                ];
            }, 3);

            // 成功（流程完整，未必升級）
            return [
                'success'      => 1,
                'message'      => '精煉完成',
                'refine_times' => $res['tries'],        // 本次實際嘗試次數（=扣了幾次料）
                'leveled'      => $res['leveled'],      // 是否升上去（0/1）
                'new_level'    => $res['new_level'],    // 結束時等級
                'success_rate' => $res['success_rate'], // 結束時儲存的成功率（0~100）
            ];

        } catch (\Throwable $e) {
            \Log::error('[refineEquipment] 裝備精煉失敗', [
                'uid'   => $userEquipment->uid,
                'times' => $times,
                'err'   => $e->getMessage(),
            ]);
            return ['success' => 0, 'message' => '精煉失敗'];
        }
    }
    // 強化裝備
    public function enhanceEquipment($slotId, ?int $position = null): array
    {
        try {
            // -------------------------
            // case 1: 單件強化
            // -------------------------
            if ($position) {
                $eq = UserSlotEquipment::where([
                    'slot_id'  => $slotId,
                    'position' => $position,
                ])->first();
                if (! $eq) {
                    return ['success' => 0, 'message' => '此 slot 無裝備'];
                }
                return $this->enhanceCore($eq);
            }

            // -------------------------
            // case 2: 一鍵強化 (slotId)
            // -------------------------
            if (! $slotId) {
                return ['success' => 0, 'message' => '缺少 slotId', 'EQUIPMENT:0002'];
            }
            // 取得該 slot 的所有裝備
            $userEquipments = UserSlotEquipment::where('slot_id', $slotId)->get();
            if ($userEquipments->isEmpty()) {
                return ['success' => 0, 'message' => '此 slot 無裝備', 'EQUIPMENT:0003'];
            }

            // 找出最低強化等級
            $minLv = $userEquipments->min('enhance_level');
            // 篩出所有「等級 == minLv」的裝備
            $targetEqs = $userEquipments->filter(fn($eq) => $eq->enhance_level == $minLv);

            // 檢查道具至少要能夠強化一次
            foreach ($targetEqs as $eq) {
                if (! $this->canEnhanceTimes($eq)) {
                    return ['success' => 0, 'message' => '道具不足，無法一鍵強化', 'error_code' => 'EQUIPMENT:0011'];
                }
            }

            $results = [];
            foreach ($targetEqs as $eq) {
                $results[] = $this->enhanceCore($eq);
            }

            return [
                'success' => 1,
                'message' => '一鍵強化完成',
                'results' => $results,
            ];
        } catch (\Throwable $e) {
            \Log::error('[enhance] 強化失敗', [
                'equipmentId' => $equipmentId,
                'slotId'      => $slotId,
                'err'         => $e->getMessage(),
            ]);
            return ['success' => 0, 'message' => '強化失敗', 'error_code' => 'EQUIPMENT:0013'];
        }
    }

    private function enhanceCore(UserSlotEquipment $userEquipment): array
    {
        if (! $userEquipment) {
            return ['success' => 0, 'message' => '參數錯誤'];
        }

        try {
            $res = DB::transaction(function () use ($userEquipment) {
                $eq     = UserSlotEquipment::whereKey($userEquipment->id)->lockForUpdate()->first();
                $userId = (int) ($eq->user->id ?? 0);
                if ($userId <= 0) {
                    throw new \RuntimeException('AUTH:0006');
                }

                $targetLv = (int) $eq->enhance_level + 1;

                // 已達上限
                if (! self::getEquipmentLvMaximum($targetLv, 'enhance')) {
                    return ['leveled' => 0, 'new_level' => (int) $eq->enhance_level];
                }

                // 下一級材料
                $m = $this->getEnhanceOrRefineMaterial($targetLv, 'enhance');
                if (! $m) {
                    throw new \RuntimeException('ENHANCE:MATERIAL_NOT_FOUND');
                }

                // 扣材料
                $baseId  = (int) ($m['base_item_id'] ?? 0);
                $baseAmt = (int) ($m['base_item_amount'] ?? 0);
                if ($baseId > 0 && $baseAmt > 0) {
                    $this->deductOrThrow(73, $userId, $eq->uid, $baseId, $baseAmt, '裝備強化扣除', 1);
                }
                $extraId  = (int) ($m['extra_item_id'] ?? 0);
                $extraAmt = (int) ($m['extra_item_amount'] ?? 0);
                if ($extraId > 0 && $extraAmt > 0) {
                    $this->deductOrThrow(73, $userId, $eq->uid, $extraId, $extraAmt, '裝備強化扣除', 1);
                }

                // 升級
                if (! $this->updateUserEquipmentLv($eq)) {
                    throw new \RuntimeException('ENHANCE:UPDATE_FAIL');
                }

                $eq->refresh();
                $userEquipment->enhance_level = $eq->enhance_level;

                return ['leveled' => 1, 'new_level' => (int) $eq->enhance_level];
            }, 3);

            return [
                'success'   => 1,
                'message'   => '強化完成',
                'leveled'   => $res['leveled'],
                'new_level' => $res['new_level'],
            ];
        } catch (\Throwable $e) {
            \Log::error('[enhanceCore] 單件強化失敗', [
                'uid' => $userEquipment->uid ?? null,
                'err' => $e->getMessage(),
            ]);
            return ['success' => 0, 'message' => '強化失敗'];
        }
    }

    // 檢查精煉材料是否足夠
    public function canRefineTimes(UserSlotEquipment $userEquipment, int $times = 1): bool
    {
        if (! $userEquipment || $times <= 0) {
            return false;
        }

        $userId = (int) ($userEquipment->user->id ?? 0);
        if ($userId <= 0) {
            return false;
        }

        // 只檢查「下一級」
        $targetLv = ((int) $userEquipment->refine_level) + 1;

        // 取得下一級的材料
        $m = $this->getEnhanceOrRefineMaterial($targetLv, 'refine');
        if (! $m) {
            return false; // 沒有資料
        }

        // 需求量：times=10 就乘 10；其餘一律視為 1
        $mult = ($times === 10) ? 10 : 1;

        $baseId  = (int) ($m['base_item_id'] ?? 0);
        $baseAmt = (int) ($m['base_item_amount'] ?? 0) * $mult;

        // 檢查基礎材料
        if ($baseId > 0 && $baseAmt > 0) {
            $chk = $this->userItemService->checkResource($userId, $baseId, $baseAmt);
            if (($chk['success'] ?? 0) !== 1) {
                return false;
            }
        }

        return true;
    }
    // 檢查強化材料是否足夠
    public function canEnhanceTimes(UserSlotEquipment $userEquipment): bool
    {
        if (! $userEquipment) {
            return false;
        }

        $userId = (int) ($userEquipment->user->id ?? 0);
        if ($userId <= 0) {
            return false;
        }

        // 只檢查「下一級」
        $targetLv = ((int) $userEquipment->enhance_level) + 1;

        // 取得下一級的材料
        $m = $this->getEnhanceOrRefineMaterial($targetLv, 'enhance');
        if (! $m) {
            return false; // 沒有資料
        }

        $baseId  = (int) ($m['base_item_id'] ?? 0);
        $baseAmt = (int) ($m['base_item_amount'] ?? 0);

        $extraId  = (int) ($m['extra_item_id'] ?? 0);
        $extraAmt = (int) ($m['extra_item_amount'] ?? 0);

        // 檢查基礎材料
        if ($baseId > 0 && $baseAmt > 0) {
            $chk = $this->userItemService->checkResource($userId, $baseId, $baseAmt);
            if (($chk['success'] ?? 0) !== 1) {
                return false;
            }
        }

        // 檢查額外材料
        if ($extraId > 0 && $extraAmt > 0) {
            $chk = $this->userItemService->checkResource($userId, $extraId, $extraAmt);
            if (($chk['success'] ?? 0) !== 1) {
                return false;
            }
        }

        return true;
    }

    // 檢查裝備或精煉等級是否存在
    public static function getEquipmentLvMaximum($targetLv = 1, $type = 'enhance'): bool
    {
        if ($type === 'enhance') {
            return GddbSurgameEqEnhance::where('lv', $targetLv)
                ->exists();
        } elseif ($type === 'refine') {
            return GddbSurgameEqRefine::where('lv', $targetLv)
                ->exists();
        }

        return false;
    }

    // 取得強化或精煉材料
    public function getEnhanceOrRefineMaterial($targetLv = 1, $type = 'refine')
    {
        switch ($type) {
            case 'enhance':
                $gddb = GddbSurgameEqEnhance::where('lv', $targetLv)->first();
                break;
            case 'refine':
                $gddb = GddbSurgameEqRefine::where('lv', $targetLv)->first();
                break;
            default:
                return null;
        }
        if (! $gddb) {
            return null;
        }

        $resultAry = [];
        // enhance才有ex_cost, ex_cost_amount
        $resultAry['base_item_id']     = $gddb->cost;
        $resultAry['base_item_amount'] = $gddb->cost_amount;
        if ($type === 'enhance') {
            $resultAry['extra_item_id']     = $gddb->ex_cost ?? 0;
            $resultAry['extra_item_amount'] = $gddb->ex_cost_amount ?? 0;
        }

        return $resultAry;
    }

    // 精煉是否成功
    public function rollRefineOnce(int $rate): bool
    {
        return random_int(1, 100) <= max(0, min(100, $rate));
    }

    // 陣位(裝備/精煉) 回傳設定 (單陣位 or 多陣位)
    public function formatShowEnhanceData($data, $type = 'single', $refineTimes = null, $isRefine = false)
    {
        $result = [];
        if ($type === 'single') {
            $result = [
                'deploy_index'  => $data->deploySlot->position,
                'equip_index'   => $data->position,
                'enhance_level' => $data->enhance_level,
                'refine_level'  => $data->refine_level,
            ];
            if ($refineTimes !== null) {
                $result['refine_progress'] = [
                    'refine_times' => $refineTimes['refine_times'] ?? 0,
                    'leveled'      => $refineTimes['leveled'] ?? 0,
                    'success_rate' => $refineTimes['success_rate'] ?? 0,
                ];
            }
        } elseif ($type === 'multiple') {
            if ($isRefine) {
                // 多陣位精煉回傳時，依照陣位順序排序
                $data = $data->sortBy(fn($item) => $item->deploySlot->position);
            }
            foreach ($data as $item) {
                $result[] = [
                    'deploy_index'  => $item->deploySlot->position,
                    'equip_index'   => $item->position,
                    'enhance_level' => $item->enhance_level,
                    'refine_level'  => $item->refine_level,
                ];
            }
        }

        return $result;
    }

    // 提升陣位(裝備)等級
    public function updateUserEquipmentLv(UserSlotEquipment $userEquipment)
    {
        try {
            if (! $userEquipment) {
                return false;
            }
            $userEquipment->enhance_level += 1;
            return $userEquipment->save();
        } catch (\Exception $e) {
            Log::error('[updateUserEquipmentLv] 提升裝備等級失敗', [
                'uid'      => $userEquipment->uid,
                'position' => $userEquipment->position,
                'slot_id'  => $userEquipment->slot_id,
                'type'     => $type,
                'error'    => $e->getMessage(),
            ]);
            return false;
        }
    }

    // 提升陣位精煉等級
    public function updateUserEquipmentRefineLv(UserSlotEquipment $userEquipment, int $times = 1)
    {
        try {
            if (! $userEquipment) {
                return false;
            }
            $userEquipment->refine_level += 1;
            return $userEquipment->save();
        } catch (\Exception $e) {
            Log::error('[updateUserEquipmentRefineLv] 提升裝備精煉提升等級失敗', [
                'uid'      => $userEquipment->uid,
                'position' => $userEquipment->position,
                'slot_id'  => $userEquipment->slot_id,
                'error'    => $e->getMessage(),
            ]);
            return false;
        }
    }

    // 精煉扣除
    private function deductOrThrow(int $type, int $userId, string | int $uid, int $itemId, int $qty, string $memo, int $isLock = 1, $userMallOrderId = null, $userPayOrderId = null, $userGachaOrderId = null): void
    {
        $r = UserItemService::removeItem(
            $type, $userId, $uid, $itemId, $qty, $isLock, $memo,
            $userMallOrderId, $userPayOrderId, $userGachaOrderId
        );
        if (($r['success'] ?? 0) !== 1) {
            // 把原本的 error_code 帶出去，方便上層統一處理
            throw new \RuntimeException($r['error_code'] ?? 'ITEM_DEDUCT_FAIL');
        }
    }

    // 取得精煉成功率
    public function getRefineSuccessRate($targetLv = 1): int
    {
        return GddbSurgameEqRefine::where('lv', $targetLv)
            ->value('success_rate') ?? 0;
    }

    // 取得當前成功機率
    public function getCurrentSuccessRate(UserSlotEquipment $userEquipment): int
    {
        if (! $userEquipment) {
            return 0;
        }
        return (int) ($userEquipment->success_rate ?? 0);
    }

    // 調整角色成功機率
    public function modifyUserSlotSuccessRate($userEquipment, $successRate)
    {
        if (! $userEquipment) {
            return false;
        }
        try {
            $userEquipment->success_rate = $successRate;
            return $userEquipment->save();
        } catch (\Exception $e) {
            Log::error('[initUserSlotSuccessRate] 初始化角色成功機率失敗', [
                'uid'      => $userEquipment->uid,
                'position' => $userEquipment->position,
                'slot_id'  => $userEquipment->slot_id,
                'error'    => $e->getMessage(),
            ]);
            return false;
        }
    }
}
