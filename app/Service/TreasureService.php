<?php

namespace App\Service;

use App\Models\GddbItems;
use App\Models\GddbSurgameTreasure as GddbTreasure;
use App\Models\Users;
use DB;
use RuntimeException;

class TreasureService
{
    /**
     * 寶物合成
     *
     * @param  int  $uid  玩家UID
     * @param  int  $targetItemId  合成道具item_id
     */
    public function fuseTreasure(Users $user, $targetItemId, $materialIds = [])
    {
        return DB::transaction(function () use ($user, $targetItemId, $materialIds) {
            // 1) 扣除材料道具
            foreach ($materialIds as $itemId) {
                $deductResult = $this->deductItem($user, $itemId);
                if (! $deductResult['success']) {
                    throw new RuntimeException($deductResult['error_code']);
                }
            }

            // 2) 給予道具
            $giveTargetItemResult = $this->giveTargetItem($user, $targetItemId);
            if (! $giveTargetItemResult['success']) {
                throw new RuntimeException($deductResult['error_code']);
            }

            return ['success' => true];
        });
    }

    /**
     * 寶物退回
     */
    public function reset($user, $itemId)
    {
        // 此道具前一等道具
        $downgradeItemId = $this->getDowngradeItemId($itemId);
        // 是否退兩份道具 (檢查之前合成時是否需要兩份材料)
        $refundTwoMaterial = $this->needTwoMaterial($downgradeItemId);
        // 取得可退的
        $refundItemId = $this->getRefundItemId($downgradeItemId);

        return DB::transaction(function () use ($user, $itemId, $downgradeItemId, $refundItemId, $refundTwoMaterial) {
            // 1) 扣除當前道具
            $deductResult = $this->deductItem($user, $itemId, true);
            if (! $deductResult['success']) {
                return ['success' => false, 'error_code' => $deductResult['error_code']];
            }
            // 2) 退回前一等道具
            $refundResult = $this->giveTargetItem($user, $refundItemId);
            if (! $refundResult['success']) {
                return ['success' => false, 'error_code' => $refundResult['error_code']];
            }

            // 3) 退回兩份材料
            if ($refundTwoMaterial) {
                $targetResult = $this->giveTargetItem($user, $refundItemId);
            } else {
                // 退回單一材料
                $targetResult = $this->giveTargetItem($user, $refundItemId);
            }
            if (! $targetResult['success']) {
                return ['success' => false, 'error_code' => $targetResult['error_code']];
            }

            return ['success' => true];
        });
    }

    /**
     * 一鍵合成
     */

    /**
     * 檢查是否有不合法道具
     *
     * @param  array  $allowed  允許使用的清單
     * @param  array  $used  實際使用的清單
     * @return array 真正錯誤的清單
     */
    public function checkInvalidItems($currentItemId, array $materialIds): array
    {
        // 非通用材料
        $allowed = $this->getFuseMaterialItemId($currentItemId);
        $extra = array_diff($materialIds, $allowed);

        // 通用材料
        $candidate = [$this->getFuseCommonMaterial($currentItemId)];
        $realError = array_diff($extra, $candidate);

        // 回傳錯誤材料
        return array_values($realError);
    }

    /** 取得對應強化所需的指定材料 item_ids */
    public function getFuseMaterialItemId($itemId)
    {
        $treasure = GddbTreasure::with('item')->where('item_id', $itemId)->first();

        // 單一指定材料
        if (! is_null($treasure->fuse_material_item_id)) {
            return [$treasure->fuse_material_item_id];
        }

        // 多材料+0道具
        $qualityMap = [
            'SR' => 3,
            'SSR' => 6,
            'MR' => 10,
        ];

        $qualityLevel = $qualityMap[$treasure->item->rarity] ?? null;
        if (! $qualityLevel) {
            return [];
        }

        return GddbTreasure::where('element', $treasure->element)
            ->where('quality_level', $qualityLevel)
            ->pluck('item_id')
            ->toArray();
    }

    /** 取得對應強化所需的通用材料 */
    public function getFuseCommonMaterial($itemId): ?int
    {
        return GddbTreasure::where('item_id', $itemId)
            ->whereNotNull('fuse_common_material_item_id')
            ->first()?->fuse_common_material_item_id;
    }

    /** 檢查是否為寶物 (單一 or 多個) */
    public function isTreasure($itemIds): bool
    {
        $uniqueItemIds = is_array($itemIds) ? array_unique($itemIds) : [$itemIds];
        $treasureIds = GddbTreasure::whereIn('item_id', $uniqueItemIds)
            ->pluck('item_id')
            ->toArray();

        $coreIds = GddbItems::whereIn('item_id', $uniqueItemIds)
            ->where('Type', 'Core')
            ->pluck('item_id')
            ->toArray();

        $validIds = array_unique(array_merge($treasureIds, $coreIds));

        return count($validIds) === count($uniqueItemIds);
    }

    /** 取得下一階段道具ids */
    public function getUpgradeItemIds($itemId)
    {
        $cItem = GddbTreasure::where('item_id', $itemId)->first();

        if (! $cItem) {
            return null; // 找不到當前寶物
        }

        $upgradeItemIds = GddbTreasure::where('quality_level', $cItem->quality_level + 1)->first()?->item_id ?? null;

        return $upgradeItemIds;
    }

    /** 取得下一階段道具ids */
    public function getDowngradeItemId($itemId)
    {
        $cItem = GddbTreasure::where('item_id', $itemId)->first();

        if (! $cItem) {
            return null; // 找不到當前寶物
        }

        $upgradeItemIds = GddbTreasure::where('quality_level', $cItem->quality_level - 1)->first()?->item_id ?? null;

        return $upgradeItemIds;
    }

    /** 檢查素材數量是否正確（根據是否需要雙素材） */
    public function validateMaterialCount($itemId, array $materialIds = []): bool
    {
        $needTwoMaterial = $this->needTwoMaterial($itemId);

        // 如果不需要雙素材，則只需一個素材
        if (! $needTwoMaterial) {
            if (count($materialIds) !== 1) {
                return false;
            }

            return true;
        }

        // 需要雙素材時，必須給兩個
        if (count($materialIds) !== 2) {
            return false;
        }

        return true;
    }

    /** 是否需要雙素材 */
    public function needTwoMaterial($itemId)
    {
        $cItem = GddbTreasure::where('item_id', $itemId)->first();
        if (! $cItem) {
            return false;
        }

        return $cItem->need_two_material == 1;
    }

    /** 退回素材 */
    public function getRefundItemId($itemId)
    {
        return GddbTreasure::where('item_id', $itemId)->where('material_item_id', '!=', 0)->first()?->material_item_id;
    }

    /**
     * 從玩家背包扣除指定材料
     *
     * @param  UserItemService  $svc  道具服務（用來操作玩家的道具數據）
     * @param  Users  $user  玩家資料實體
     * @param  int  $itemId  要扣除的道具 item_id
     * @return array 成功或失敗的結果：
     *               - ['success' => true]  扣除成功
     *               - ['success' => false, 'error_code' => xxx] 扣除失敗，回傳錯誤碼
     */
    /**
     * 從玩家背包扣除指定材料
     *
     * @param  Users  $user  玩家資料實體
     * @param  int  $itemId  要扣除的道具 item_id
     * @param  bool  $isRefund  是否為退回操作（預設為 false）
     * @return array 成功或失敗的結果：
     *               - ['success' => true]  扣除成功
     *               - ['success' => false, 'error_code' => xxx] 扣除失敗，回傳錯誤碼
     */
    private function deductItem(Users $user, $itemId, $isRefund = false): array
    {
        $actionCode = $isRefund ? '92' : '91';
        $desc = $isRefund ? '寶物退回移除道具' : '寶物合成消耗道具';

        $results = UserItemService::removeItem($actionCode, $user->id, $user->uid, $itemId, 1, 1, $desc);

        if ($results['success'] == 0) {
            return ['success' => false, 'error_code' => $results['error_code']];
        }

        return ['success' => true];
    }

    /**
     * 將合成完成的目標道具發給玩家
     *
     * @param  UserItemService  $svc  道具服務（用來操作玩家的道具數據）
     * @param  Users  $user  玩家資料實體
     * @param  int  $itemId  要發放的目標道具 item_id
     * @return array 成功或失敗的結果：
     *               - ['success' => true]  發放成功
     *               - ['success' => false, 'error_code' => xxx] 發放失敗，回傳錯誤碼
     */
    private function giveTargetItem(Users $user, $itemId): array
    {
        $results = UserItemService::addItem('91', $user->id, $user->uid, $itemId, 1, 1, '寶物合成獲得道具');
        if ($results['success'] == 0) {
            return ['success' => false, 'error_code' => $results['error_code']];
        }

        return ['success' => true];
    }
}
